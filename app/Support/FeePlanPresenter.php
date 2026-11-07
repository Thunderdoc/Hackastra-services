<?php

namespace App\Support;

use App\Models\FeeHead;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use Carbon\Carbon;

/**
 * F5 (Round 4, design §5) — the ONE shared instalment-plan
 * presenter for a fee record, used by the parent portal
 * (MyController) and the student portal (PortalController).
 *
 * The bridge between the ledger (payment_records) and the
 * structure layer (fee_heads / fee_installments) is the loose
 * (title + my_class_id + year) match FeeStructureController::apply
 * itself uses — a convention, not a constraint (design §2.3).
 * When no head resolves, or the head has no instalments, this
 * returns null and the calling page renders exactly as it did
 * before F5: no plan shell, no fabricated dates.
 *
 * Per-instalment paid figures are a FIFO *presentation*
 * allocation only (design §2.4): instalments in installment_no
 * order are filled from the record's amt_paid until it runs out.
 * They are never stored and never fed into dues math — every
 * amount owed still comes from FeeDefaulter.
 */
class FeePlanPresenter
{
    /** The FeeHead a record's Payment was applied from, if any. */
    public static function matchHeadFor(PaymentRecord $record): ?FeeHead
    {
        $record->loadMissing('payment');
        $payment = $record->payment;
        if (!$payment) {
            return null;
        }

        return FeeHead::with('installments')
            ->where('title', $payment->title)
            ->where('my_class_id', $payment->my_class_id)
            ->where('year', $record->year)
            ->orderBy('id')
            ->first();
    }

    /**
     * FIFO covered amount per installment id: earliest
     * instalments are filled first from $paid. When $cap is
     * given the total coverage is capped at it (the concession
     * net-payable cap of design §5.3).
     *
     * @return array<int,int> installment_id => covered
     */
    public static function fifoCoverage(FeeHead $head, int $paid, $cap = null): array
    {
        $remaining = $paid;
        if ($cap !== null) {
            $remaining = min($remaining, (int) $cap);
        }
        $remaining = max(0, $remaining);

        $coverage = [];
        foreach ($head->installments->sortBy('installment_no') as $inst) {
            $covered = min($remaining, (int) $inst->amount);
            $coverage[$inst->id] = $covered;
            $remaining -= $covered;
        }

        return $coverage;
    }

    public static function forRecord(PaymentRecord $record, StudentRecord $sr): ?array
    {
        $head = self::matchHeadFor($record);
        if (!$head || $head->installments->isEmpty()) {
            return null;
        }
        $record->loadMissing(['payment', 'receipt']);

        $total = (int) $head->amount;
        $concession = (int) round((float) $head->concessionDiscountFor($sr->id));
        $net = max(0, $total - $concession);

        // R-01 rule (design §1.4/§5.2): paid = amt_paid, balance =
        // fee amount − amt_paid, never the stored balance column.
        $paid = (int) ($record->amt_paid ?: 0);
        $paymentAmount = (int) optional($record->payment)->amount;
        $balance = max(0, $paymentAmount - $paid);

        $coverage = self::fifoCoverage($head, $paid, $net);
        $today = Carbon::today();

        $installments = [];
        $coveredCount = 0;
        $nextDue = null;
        foreach ($head->installments->sortBy('installment_no') as $inst) {
            $covered = (int) ($coverage[$inst->id] ?? 0);
            $amount = (int) $inst->amount;
            $due = $inst->due_date ? Carbon::parse($inst->due_date) : null;

            if ($covered >= $amount) {
                $status = 'Paid';
                $coveredCount++;
            } elseif ($covered > 0) {
                $status = 'Partially paid';
            } elseif (!$due) {
                $status = 'No date set';
            } elseif ($due->lt($today)) {
                $status = 'Overdue';
            } elseif ($due->isSameDay($today)) {
                $status = 'Due today';
            } else {
                $status = 'Upcoming';
            }
            if ($covered < $amount && $due && $nextDue === null) {
                $nextDue = $due;
            }

            $installments[] = [
                'installment' => $inst,
                'no' => (int) $inst->installment_no,
                'due_date' => $due,
                'amount' => $amount,
                'covered' => $covered,
                'status' => $status,
            ];
        }

        $paymentMethod = optional($record->payment)->method;
        $history = $record->receipt->sortBy('created_at')->map(function ($receipt) use ($paymentMethod) {
            return [
                'receipt' => $receipt,
                'date' => $receipt->created_at,
                'amount' => (int) $receipt->amt_paid,
                'balance_after' => (int) $receipt->balance,
                // §1.4 resolution: a gateway receipt's method wins,
                // otherwise the fee's own method applies.
                'method' => $receipt->method ?: $paymentMethod,
            ];
        })->values()->all();

        $lastReceipt = $record->receipt->sortByDesc('created_at')->first();

        $summary = $coveredCount . ' of ' . count($installments) . ' instalments covered';
        if ($nextDue) {
            $summary .= ' · next due ' . $nextDue->format('d M Y');
        }

        return [
            'record' => $record,
            'head' => $head,
            'title' => $head->title,
            'total' => $total,
            'concession' => $concession,
            'net' => $net,
            'paid' => $paid,
            'balance' => $balance,
            'outstanding' => FeeDefaulter::outstandingFor($record->student_id, $record->year),
            'installments' => $installments,
            'history' => $history,
            'last_date' => $lastReceipt ? $lastReceipt->created_at : null,
            'last_method' => $lastReceipt ? $lastReceipt->method : null,
            'covered_count' => $coveredCount,
            'total_count' => count($installments),
            'next_due' => $nextDue,
            'summary_line' => $summary,
        ];
    }
}
