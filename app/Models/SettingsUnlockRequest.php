<?php

namespace App\Models;

use App\User;
use Illuminate\Database\Eloquent\Model;

/**
 * One written request to unlock the school settings
 * (owner order 2026-10-29). Stages: pending_principal ->
 * pending_super_admin -> approved | rejected (a rejection at
 * either stage ends the request).
 */
class SettingsUnlockRequest extends Model
{
    protected $fillable = [
        'requested_by', 'changes', 'status',
        'principal_decided_by', 'principal_decided_at',
        'super_decided_by', 'super_decided_at',
    ];

    protected $casts = [
        'principal_decided_at' => 'datetime',
        'super_decided_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending_principal', 'pending_super_admin'], true);
    }

    public function statusLabel(): string
    {
        return [
            'pending_principal' => 'Waiting for the Principal',
            'pending_super_admin' => 'Waiting for the Super Admin',
            'approved' => 'Approved — settings unlocked',
            'rejected' => 'Rejected',
        ][$this->status] ?? $this->status;
    }
}
