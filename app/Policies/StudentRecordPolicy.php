<?php

namespace App\Policies;

use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;

/**
 * Record-level authorization for students (audit §6).
 * The core rule this encodes: changing an ID in the URL must never be
 * enough to see or edit another student.
 *
 * Batch S reconciliation (2026-10-21): this policy previously granted
 * ANY record to teachers AND accountants, while the student-profile
 * controller bounced accountants — the layers disagreed. The
 * controller's behaviour is the product-correct one (accountants get
 * fee surfaces, not academic profiles), so the policy now matches it:
 *
 *   - super_admin / admin: any record.
 *   - teacher: only records in their assigned classes (TeacherScope —
 *     subjects.teacher_id + sections.teacher_id), never the whole
 *     school.
 *   - student: only their own record.
 *   - parent: only their own children's records.
 *   - accountant / librarian: no academic-profile access here. Fee
 *     work goes through viewFees below / PaymentRecordPolicy.
 */
class StudentRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->user_type, ['super_admin', 'admin', 'teacher'], true);
    }

    public function view(User $user, StudentRecord $record): bool
    {
        if (in_array($user->user_type, ['super_admin', 'admin'], true)) {
            return true;
        }

        if ($user->user_type === 'teacher') {
            return TeacherScope::canTeachClass($user, $record->my_class_id);
        }

        if ($user->user_type === 'student') {
            return (int) $record->user_id === (int) $user->id;
        }

        if ($user->user_type === 'parent') {
            return (int) $record->my_parent_id === (int) $user->id;
        }

        return false;
    }

    /**
     * Fee-domain access to a student's record (Batch S). An invoice
     * page is a fee surface that happens to resolve student identity;
     * it is not an academic profile. PaymentController@invoice
     * authorizes THIS ability so that tightening view() above does
     * not break the accountant's legitimate fee work, while the
     * accountant still cannot open the academic profile itself.
     */
    public function viewFees(User $user, StudentRecord $record): bool
    {
        if (in_array($user->user_type, ['super_admin', 'admin', 'accountant'], true)) {
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

    /*
     * Writes (Workstream CM — SA view-only boundary): student records
     * are school records; creating, editing and deleting them belongs
     * to the school's Admin alone (staff flows). The Super Admin keeps
     * the reads above but writes nothing. Every deletion is still
     * audit-logged by the controller (Audit::log('student.deleted')).
     */
    public function create(User $user): bool
    {
        return $user->user_type === 'admin';
    }

    public function update(User $user, StudentRecord $record): bool
    {
        return $user->user_type === 'admin';
    }

    public function delete(User $user, StudentRecord $record): bool
    {
        return $user->user_type === 'admin';
    }
}
