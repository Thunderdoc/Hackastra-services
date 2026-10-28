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

    public function getDaysAttribute(): int
    {
        return (int) ((strtotime($this->end_date) - strtotime($this->start_date)) / 86400) + 1;
    }
}
