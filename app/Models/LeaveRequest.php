<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Leave requests (Wave 13, HR-lite): a staff member applies, the
 * Admin approves or rejects. No numeric leave balance is computed
 * — no allowance is configured anywhere in the app, so inventing
 * one would be a false number; the teacher home shows the status
 * of each request instead.
 */
class LeaveRequest extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    /** Apply-time types (owner order 2026-10-28). Emergency is
     *  auto-approved at submit (within seconds, synchronously);
     *  normal goes through the usual approver. */
    public const TYPES = ['normal', 'emergency'];

    protected $fillable = [
        'user_id', 'start_date', 'end_date', 'reason', 'leave_type', 'status',
        'decided_by', 'decided_at', 'decision_note',
        'day_part', 'period_from', 'period_to',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(\App\User::class, 'user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(\App\User::class, 'decided_by');
    }

    /** Partial-leave window (owner order 2026-10-29): the period
     *  numbers of the school day this leave touches, or null for a
     *  full-day leave. The school day runs 8 periods; halves split
     *  1-4 / 5-8. A 'periods' leave carries its explicit range. */
    public function periodWindow(): ?array
    {
        if ($this->day_part === 'first_half') {
            return [1, 4];
        }
        if ($this->day_part === 'second_half') {
            return [5, 8];
        }
        if ($this->day_part === 'periods' && $this->period_from && $this->period_to) {
            return [(int) $this->period_from, (int) $this->period_to];
        }

        return null;
    }

    /** Short label for the queue / my-requests lists; null when the
     *  leave is a plain full-day leave (callers show the day count). */
    public function partialLabel(): ?string
    {
        if ($this->day_part === 'first_half') {
            return 'Half day (periods 1-4)';
        }
        if ($this->day_part === 'second_half') {
            return 'Half day (periods 5-8)';
        }
        if ($this->day_part === 'periods' && $this->period_from && $this->period_to) {
            return 'Periods ' . (int) $this->period_from . '-' . (int) $this->period_to . ' only';
        }

        return null;
    }

    public function getDaysAttribute(): int
    {
        return (int) ((strtotime($this->end_date) - strtotime($this->start_date)) / 86400) + 1;
    }

    /** Display dates in the Indian format the owner ordered
     *  (2026-10-29: DD/MM/YYYY everywhere). Deliberately
     *  crash-proof: a corrupt stored date falls back to the raw
     *  value instead of 500ing the page — the queue must survive
     *  one bad row (overnight hardening contract). */
    public function getStartDateDisplayAttribute(): string
    {
        try {
            return \Carbon\Carbon::parse($this->start_date)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $this->start_date;
        }
    }

    public function getEndDateDisplayAttribute(): string
    {
        try {
            return \Carbon\Carbon::parse($this->end_date)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $this->end_date;
        }
    }
}
