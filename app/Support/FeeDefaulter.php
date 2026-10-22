<?php

namespace App\Support;

use App\Models\PaymentRecord;
use App\Models\StudentRecord;

/**
 * The school's ONE fee-defaulter definition (owner ruling 2026-10-22).
 *
 * Extracted verbatim from FeeReportController (the Fee Defaulters
 * page) so the page, its CSV export, and the defaulter document
 * block (admit card / marksheet withholding) can never disagree:
 *
 *  - Population: active students (student_records.grad = 0).
 *  - A fee record counts while paid = 0 in the given session year.
 *  - Its owing amount follows the invoice rule — the record's stored
 *    balance, falling back to the full fee amount when untouched —
 *    so a partially paid record contributes only what remains.
 *  - A student is a defaulter when that total is greater than zero.
 */
class FeeDefaulter
{
    /**
     * Every defaulter of the session, worst first — the Fee
     * Defaulters page rows. Called with no filters it returns
     * exactly the set it always has; the optional filters
     * (section, paid-status, name/admission search) only narrow
     * it. Each row also carries the student's session totals
     * (total_fees / total_paid / status) computed from the same
     * records — additive keys the print document and export
     * simply do not read.
     */
    public static function rows($year, $selected_class = null, array $filters = [])
    {
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->when($selected_class, function ($q) use ($selected_class) {
                return $q->where('my_class_id', $selected_class->id);
            })
            ->when($filters['section_id'] ?? null, function ($q, $section_id) {
                return $q->where('section_id', $section_id);
            })
            ->when($filters['q'] ?? null, function ($q, $term) {
                return $q->where(function ($sub) use ($term) {
                    $sub->where('adm_no', 'like', '%' . $term . '%')
                        ->orWhereHas('user', function ($u) use ($term) {
                            $u->where('name', 'like', '%' . $term . '%');
                        });
                });
            })
            ->orderBy('id')
            ->get();

        $all = PaymentRecord::with('payment')
            ->where('year', $year)
            ->whereIn('student_id', $students->pluck('user_id')->all())
            ->get()
            ->groupBy('student_id');

        return $students
            ->map(function ($student) use ($all, $filters) {
                $records = $all->get($student->user_id);
                if (!$records || $records->isEmpty()) {
                    return null;
                }

                $owing = $records->where('paid', 0);
                if ($owing->isEmpty()) {
                    return null;
                }

                $total_due = (int) $owing->sum(function ($r) {
                    return $r->balance ?: optional($r->payment)->amount;
                });
                if ($total_due <= 0) {
                    return null;
                }

                $total_paid = (int) $records->sum('amt_paid');
                $status = $total_paid > 0 ? 'partial' : 'unpaid';
                if (($filters['status'] ?? null) && $status !== $filters['status']) {
                    return null;
                }

                $oldest = $owing->sortBy('created_at')->first();

                return [
                    'student' => $student,
                    'total_due' => $total_due,
                    'total_fees' => (int) $records->sum(function ($r) {
                        return optional($r->payment)->amount;
                    }),
                    'total_paid' => $total_paid,
                    'status' => $status,
                    'items' => $owing->count(),
                    'oldest_title' => optional($oldest->payment)->title,
                    'oldest_since' => $oldest->created_at,
                ];
            })
            ->filter()
            ->sortByDesc('total_due')
            ->values();
    }

    /**
     * One student's outstanding balance for the session, by the same
     * rule as rows(): 0 when the student owes nothing (or is no
     * longer an active student), the amount due otherwise.
     */
    public static function outstandingFor($student_user_id, $year): int
    {
        $active = StudentRecord::where('user_id', $student_user_id)
            ->where('grad', 0)
            ->exists();

        if (!$active) {
            return 0;
        }

        $records = PaymentRecord::with('payment')
            ->where('year', $year)
            ->where('paid', 0)
            ->where('student_id', $student_user_id)
            ->get();

        return (int) $records->sum(function ($r) {
            return $r->balance ?: $r->payment->amount;
        });
    }

    /** True while the student owes money for the session. */
    public static function isDefaulter($student_user_id, $year): bool
    {
        return self::outstandingFor($student_user_id, $year) > 0;
    }
}
