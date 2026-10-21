<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * One-line audit logging for sensitive actions (audit §6).
 *
 * Deliberately failure-proof: a broken audit write must never break the
 * action being audited, so every failure is swallowed silently.
 *
 * Usage: Audit::log('payment.recorded', $record, 'Paid 500; balance 0');
 */
class Audit
{
    public static function log(string $action, $subject = null, ?string $description = null): void
    {
        try {
            $user = auth()->user();

            AuditLog::create([
                'user_id' => $user ? $user->id : null,
                'action' => $action,
                'subject_type' => $subject instanceof Model ? get_class($subject) : (is_string($subject) ? $subject : null),
                'subject_id' => $subject instanceof Model ? $subject->getKey() : null,
                'description' => $description,
                'ip_address' => request() ? request()->ip() : null,
            ]);
        } catch (\Throwable $e) {
            // Never let auditing break the real work.
        }
    }
}
