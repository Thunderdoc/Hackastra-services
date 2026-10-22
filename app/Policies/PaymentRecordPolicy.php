<?php

namespace App\Policies;

use App\Models\PaymentRecord;
use App\User;

/**
 * Record-level authorization for payments/receipts (audit §6/§8).
 */
class PaymentRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, ['super_admin', 'admin', 'accountant', 'parent', 'student'], true);
    }

    public function view(User $user, PaymentRecord $record): bool
    {
        if (in_array($user->user_type, ['super_admin', 'admin', 'accountant'], true)) {
            return true;
        }

        if ($user->user_type === 'student') {
            return (int) $record->student_id === (int) $user->id;
        }

        if ($user->user_type === 'parent') {
            return \App\Models\StudentRecord::where('my_parent_id', $user->id)
                ->where('user_id', $record->student_id)
                ->exists();
        }

        return false;
    }

    /*
     * Writes (Workstream CM — SA view-only boundary): recording,
     * correcting and deleting payments are school finance jobs, so
     * they belong to the school's Admin + Accountant. The Super Admin
     * keeps every read above but writes nothing. delete() previously
     * sat with super_admin alone — the one school-operational right on
     * the wrong side of the boundary; it moves to Admin + Accountant.
     */
    public function create(User $user): bool
    {
        return in_array($user->user_type, ['admin', 'accountant'], true);
    }

    public function update(User $user, PaymentRecord $record): bool
    {
        return in_array($user->user_type, ['admin', 'accountant'], true);
    }

    public function delete(User $user, PaymentRecord $record): bool
    {
        return in_array($user->user_type, ['admin', 'accountant'], true);
    }
}
