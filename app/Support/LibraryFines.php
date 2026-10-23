<?php

namespace App\Support;

use App\Helpers\Pay;
use App\Helpers\Qs;
use App\Models\BookRequest;
use App\Models\LibraryFine;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use Illuminate\Support\Facades\DB;

/**
 * Library fines → main fees (owner, 2026-10-23): when the library
 * imposes a fine on a STUDENT, the fine must reflect in the
 * student's main fee account. The mirror is a real fee-ledger
 * entry — a "Library Fine" Payment for the student's class plus a
 * PaymentRecord for the fine amount — so the fine shows on the
 * student's fees page, the parent's view, the fee statement, the
 * balance and the defaulters report, and is collected through the
 * normal pay-now flow with a normal receipt. Nothing display-only:
 * every fee surface reads the same ledger.
 *
 * Staff borrowers get the fine record (tracked on the fines page)
 * without a fee mirror — staff have no fee account.
 */
class LibraryFines
{
    /** Daily overdue rate from Settings (Admin-editable key), ₹10 default. */
    public static function dailyRate(): int
    {
        $rate = (int) Qs::getSetting('library_fine_per_day');

        return $rate > 0 ? $rate : 10;
    }

    /**
     * Impose a fine for a loan. Creates the LibraryFine and, for a
     * student borrower, its fee-ledger mirror. Returns the fine.
     */
    public static function impose(BookRequest $loan, int $amount, int $daysOverdue, string $reason, $imposedBy): LibraryFine
    {
        return DB::transaction(function () use ($loan, $amount, $daysOverdue, $reason, $imposedBy) {
            $fine = LibraryFine::create([
                'book_request_id' => $loan->id,
                'user_id' => $loan->user_id,
                'amount' => $amount,
                'days_overdue' => $daysOverdue,
                'reason' => $reason,
                'status' => 'pending',
                'imposed_by' => $imposedBy,
            ]);

            $student = StudentRecord::where('user_id', $loan->user_id)->first();
            if ($student) {
                $year = Qs::getCurrentSession();
                $payment = Payment::create([
                    'title' => 'Library Fine',
                    'amount' => $amount,
                    'my_class_id' => $student->my_class_id,
                    'description' => $reason,
                    'year' => $year,
                    'ref_no' => Pay::genRefCode(),
                    'method' => 'cash',
                ]);
                $record = PaymentRecord::create([
                    'student_id' => $student->user_id,
                    'payment_id' => $payment->id,
                    'amt_paid' => 0,
                    'balance' => $amount,
                    'paid' => 0,
                    'year' => $year,
                    'ref_no' => Pay::genRefCode(),
                ]);
                $fine->update(['payment_record_id' => $record->id]);
            }

            Audit::log('library.fine_imposed', $fine,
                'Library fine of ' . $amount . ' imposed on user ' . $loan->user_id . ' — ' . $reason);

            return $fine;
        });
    }

    /** Keep the fine's status in step with its fee-ledger mirror. */
    public static function syncFromPaymentRecord(PaymentRecord $record): void
    {
        $fine = LibraryFine::where('payment_record_id', $record->id)
            ->where('status', 'pending')->first();
        if ($fine && (int) $record->balance < 1) {
            $fine->update(['status' => 'paid']);
            Audit::log('library.fine_paid', $fine,
                'Library fine of ' . $fine->amount . ' collected through fees (record ' . $record->id . ')');
        }
    }

    /**
     * Waive a pending fine: the fine stays as history (status
     * waived) and its UNPAID fee mirror is removed so the student's
     * dues drop back. A paid fine cannot be waived — the money is
     * already collected; refunds are a separate policy decision.
     */
    public static function waive(LibraryFine $fine, $actorId): bool
    {
        if ($fine->status !== 'pending') {
            return false;
        }

        return DB::transaction(function () use ($fine, $actorId) {
            if ($fine->payment_record_id) {
                $record = PaymentRecord::find($fine->payment_record_id);
                if ($record && (int) $record->amt_paid === 0) {
                    $paymentId = $record->payment_id;
                    $record->delete();
                    // The mirror Payment was created for this fine
                    // alone; remove it once its only record is gone.
                    if ($paymentId && PaymentRecord::where('payment_id', $paymentId)->count() === 0) {
                        Payment::where('id', $paymentId)->delete();
                    }
                }
            }
            $fine->update(['status' => 'waived']);
            Audit::log('library.fine_waived', $fine,
                'Library fine of ' . $fine->amount . ' waived by user ' . $actorId);

            return true;
        });
    }
}
