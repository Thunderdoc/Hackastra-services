<?php

namespace App\Policies;

use App\Models\StudentRecord;
use App\User;

/**
 * Record-level authorization for students (audit §6).
 * The core rule this encodes: changing an ID in the URL must never be
 * enough to see or edit another student.
 */
class StudentRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, ['super_admin', 'admin', 'teacher', 'accountant'], true);
    }

    public function view(User $user, StudentRecord $record): bool
    {
        if (in_array($user->user_type, ['super_admin', 'admin', 'teacher', 'accountant'], true)) {
            return true;
        }

        if ($user->user_type === 'student') {
            return (int) $record->user_id === (int) $user->id;
        }

        if ($user->user_type === 'parent') {
            return (int) $record->my_parent_id === (int) $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return in_array($user->user_type, ['super_admin', 'admin'], true);
    }

    public function update(User $user, StudentRecord $record): bool
    {
        return in_array($user->user_type, ['super_admin', 'admin'], true);
    }

    public function delete(User $user, StudentRecord $record): bool
    {
        // Destructive actions stay Super Admin-only (readme + audit §6).
        return $user->user_type === 'super_admin';
    }
}
