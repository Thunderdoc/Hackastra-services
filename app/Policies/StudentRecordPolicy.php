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
        // Workstream AB (owner's role model, 2026-10-21): the school Admin
        // runs the school alone, so student deletion sits with teamSA like
        // create/update above. Every deletion is still audit-logged by the
        // controller (Audit::log('student.deleted', ...)).
        return in_array($user->user_type, ['super_admin', 'admin'], true);
    }
}
