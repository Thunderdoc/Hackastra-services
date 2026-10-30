<?php

namespace App\Support;

use App\Helpers\Qs;
use App\Models\LeaveRequest;
use Carbon\Carbon;

/**
 * Paid-leave quota accounting (owner orders 2026-10-30).
 *
 * The government allots every staff member a fixed number of
 * PAID leave days per year; the school sets that number in
 * Settings (`paid_leave_quota_days`). Approved leave beyond the
 * quota is unpaid: payroll deducts for each extra day a
 * percentage of one day's salary (Settings:
 * `leave_deduction_percent`, default 100; one day's salary =
 * the month's gross — basic + allowances — divided by 30).
 *
 * Counting rules (kept deliberately plain, and printed on the
 * payslip so a teacher can check them):
 *  - Only APPROVED leaves count.
 *  - Every calendar date inside an approved range counts; a
 *    partial leave counts its fraction of the day (first/second
 *    half = 0.5; an explicit period window = periods / 8).
 *  - The quota is consumed chronologically through the calendar
 *    year: the first `quota` days are paid, everything after is
 *    extra. A month's extra days are therefore the part of that
 *    month's leave that falls after the quota ran out.
 *  - Until the school sets a quota (> 0), nothing is deducted —
 *    leave is still counted and shown.
 */
class LeaveBalance
{
    public static function quota(): float
    {
        $v = Qs::getSetting('paid_leave_quota_days');
        return $v === null || $v === '' ? 0.0 : max(0.0, (float) $v);
    }

    public static function deductionPercent(): float
    {
        $v = Qs::getSetting('leave_deduction_percent');
        if ($v === null || $v === '') {
            return 100.0;
        }
        return min(100.0, max(0.0, (float) $v));
    }

    /** The fraction of one day a leave consumes on a given date. */
    public static function dayValue(LeaveRequest $leave, Carbon $date): float
    {
        $window = $leave->periodWindow();
        if ($window === null) {
            return 1.0;
        }
        return max(0.0, min(1.0, ($window[1] - $window[0] + 1) / 8));
    }

    /**
     * Every approved leave day of a user inside one calendar
     * year, as an ordered map 'Y-m-d' => day value. Multiple
     * leaves touching one date are capped at one full day.
     */
    public static function daysInYear(int $userId, int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        $leaves = LeaveRequest::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $yearEnd->toDateString())
            ->whereDate('end_date', '>=', $yearStart->toDateString())
            ->orderBy('start_date')
            ->get();

        $days = [];
        foreach ($leaves as $leave) {
            $from = Carbon::parse($leave->start_date)->startOfDay()->max($yearStart);
            $to = Carbon::parse($leave->end_date)->startOfDay()->min($yearEnd);
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $key = $d->toDateString();
                $days[$key] = min(1.0, ($days[$key] ?? 0.0) + self::dayValue($leave, $d));
            }
        }
        ksort($days);
        return $days;
    }

    public static function usedInYear(int $userId, int $year): float
    {
        return round(array_sum(self::daysInYear($userId, $year)), 2);
    }

    public static function usedInMonth(int $userId, int $year, int $month): float
    {
        $sum = 0.0;
        foreach (self::daysInYear($userId, $year) as $key => $value) {
            if ((int) substr($key, 5, 2) === $month) {
                $sum += $value;
            }
        }
        return round($sum, 2);
    }

    /**
     * The part of one month's leave that falls beyond the paid
     * quota — the quota is consumed chronologically from 1 Jan,
     * so a month early in the year may have none and a later
     * month all of it.
     */
    public static function extraInMonth(int $userId, int $year, int $month): float
    {
        $quota = self::quota();
        if ($quota <= 0) {
            return 0.0;
        }
        $consumed = 0.0;
        $extra = 0.0;
        foreach (self::daysInYear($userId, $year) as $key => $value) {
            $paidPart = max(0.0, min($value, $quota - $consumed));
            $consumed += $value;
            if ((int) substr($key, 5, 2) === $month) {
                $extra += $value - $paidPart;
            }
        }
        return round($extra, 2);
    }

    /** Extra (unpaid) days in the whole year at the current quota. */
    public static function extraInYear(int $userId, int $year): float
    {
        $quota = self::quota();
        if ($quota <= 0) {
            return 0.0;
        }
        return round(max(0.0, self::usedInYear($userId, $year) - $quota), 2);
    }

    /** The deduction for one payroll month at a given gross. */
    public static function deductionFor(int $userId, int $year, int $month, float $gross): float
    {
        $extra = self::extraInMonth($userId, $year, $month);
        if ($extra <= 0 || $gross <= 0) {
            return 0.0;
        }
        return round($extra * ($gross / 30) * (self::deductionPercent() / 100), 2);
    }

    /** Portal/payslip summary for one user and calendar year. */
    public static function summary(int $userId, int $year): array
    {
        $quota = self::quota();
        $used = self::usedInYear($userId, $year);
        return [
            'quota' => $quota,
            'used' => $used,
            'remaining' => $quota > 0 ? round(max(0.0, $quota - $used), 2) : null,
            'extra' => self::extraInYear($userId, $year),
            'deduction_percent' => self::deductionPercent(),
            'year' => $year,
        ];
    }
}
