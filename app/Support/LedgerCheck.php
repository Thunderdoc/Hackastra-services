<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\PaymentRecord;
use Illuminate\Support\Facades\DB;

/**
 * Fee-ledger consistency (program Phase 6): the ledger is only
 * trustworthy if every derived number can be recomputed from the
 * rows themselves. This scanner checks the invariants the rest of
 * the app assumes:
 *  - every record's balance == its payment's amount − amt_paid
 *  - the paid flag agrees with the balance (paid ⇔ balance 0)
 *  - amt_paid never exceeds the payment amount (no overpay drift)
 *  - every record's payment and student still exist (no orphans)
 *  - receipt numbers (ref_no) are present and unique per payment
 * It reports COUNTS and the first few offenders — enough to act
 * on, never a data dump. Read-only: repairing drift is a human
 * decision, not something a scanner does silently.
 */
class LedgerCheck
{
    public static function scan(): array
    {
        $issues = [];

        $records = PaymentRecord::with('payment')->get();
        $balanceDrift = [];
        $flagDrift = [];
        $overpaid = [];
        $orphans = [];
        $studentIds = DB::table('student_records')->pluck('id')->all();
        $studentSet = array_flip($studentIds);

        foreach ($records as $r) {
            if (!$r->payment) {
                $orphans[] = 'Record ' . $r->id . ' points at a deleted payment.';
                continue;
            }
            $expected = (int) $r->payment->amount - (int) $r->amt_paid;
            if ((int) $r->balance !== $expected) {
                $balanceDrift[] = 'Record ' . $r->id . ': balance ' . $r->balance . ' ≠ expected ' . $expected . '.';
            }
            if ((bool) $r->paid !== ($expected <= 0)) {
                $flagDrift[] = 'Record ' . $r->id . ': paid flag disagrees with its balance.';
            }
            if ((int) $r->amt_paid > (int) $r->payment->amount) {
                $overpaid[] = 'Record ' . $r->id . ': paid ' . $r->amt_paid . ' exceeds the fee ' . $r->payment->amount . '.';
            }
            // PaymentRecord.student_id stores the student's USER id
            // (the ledger's long-standing convention).
            if (!isset($studentSet[$r->student_id]) && !DB::table('users')->where('id', $r->student_id)->exists()) {
                $orphans[] = 'Record ' . $r->id . ' points at a missing student.';
            }
        }

        $dupRefs = Payment::select('ref_no', DB::raw('COUNT(*) as c'))
            ->groupBy('ref_no')->having('c', '>', 1)->pluck('ref_no')->all();
        $missingRefs = Payment::whereNull('ref_no')->orWhere('ref_no', '')->count();

        $add = function ($label, $list) use (&$issues) {
            if (!empty($list)) {
                $issues[] = ['label' => $label, 'count' => count($list), 'examples' => array_slice($list, 0, 5)];
            }
        };
        $add('Balances that do not recompute', $balanceDrift);
        $add('Paid flags that disagree with balances', $flagDrift);
        $add('Records paid beyond the fee amount', $overpaid);
        $add('Orphaned records', $orphans);
        if (!empty($dupRefs)) {
            $issues[] = ['label' => 'Duplicate receipt reference numbers', 'count' => count($dupRefs), 'examples' => array_slice($dupRefs, 0, 5)];
        }
        if ($missingRefs > 0) {
            $issues[] = ['label' => 'Payments missing a receipt reference', 'count' => $missingRefs, 'examples' => []];
        }

        return [
            'records' => $records->count(),
            'payments' => Payment::count(),
            'total_paid' => (int) $records->sum('amt_paid'),
            'total_balance' => (int) $records->sum('balance'),
            'issues' => $issues,
            'consistent' => empty($issues),
        ];
    }
}
