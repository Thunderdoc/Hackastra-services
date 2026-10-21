<?php

namespace App\Support;

use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;

/**
 * Teacher assignment scope (Batch S — owner's role rule, 2026-10-21:
 * "a teacher sees only their assigned students — details, marks,
 * attendance — never every class").
 *
 * This is the single canonical source every enforcement point uses,
 * so the rule cannot drift between controllers the way the old
 * team-membership checks did.
 *
 * Derivation — a class is "assigned" to a teacher when EITHER real
 * assignment source in the schema names them for that class:
 *
 *   1. subjects.teacher_id — the teacher teaches a subject in the
 *      class (subjects.my_class_id). This is the same source the
 *      teacher dashboard derives its numbers from.
 *   2. sections.teacher_id — the teacher is class-teacher of a
 *      section (sections.my_class_id); leading a section of a
 *      class assigns the whole class, mirroring how the dashboard
 *      counts "sections led".
 *
 * Timetable rows carry no teacher_id of their own: a TimeTable row
 * points at a Subject, so a teacher's scheduled periods are covered
 * transitively by source 1 (exactly how Period Attendance finds a
 * teacher's periods: whereHas('subject', teacher_id)).
 *
 * The scope applies ONLY to user_type = 'teacher'. Super admins,
 * admins, accountants and librarians have different data domains
 * and are never restricted by this class; every method returns the
 * empty/false answer for a non-teacher, and enforcement points ask
 * "is this user a teacher AND outside their scope?" before denying.
 */
class TeacherScope
{
    /** @var array<int, int[]> per-request memo of class ids, keyed by user id */
    protected static $classIdsByUser = [];

    public static function appliesTo($user): bool
    {
        return $user && ($user->user_type ?? null) === 'teacher';
    }

    /**
     * Ids of every class assigned to the teacher ([] for anyone else).
     *
     * @return int[]
     */
    public static function classIds(User $user): array
    {
        if (!self::appliesTo($user)) {
            return [];
        }

        if (isset(self::$classIdsByUser[$user->id])) {
            return self::$classIdsByUser[$user->id];
        }

        $fromSubjects = Subject::where('teacher_id', $user->id)->pluck('my_class_id');
        $fromSections = Section::where('teacher_id', $user->id)->pluck('my_class_id');

        $ids = $fromSubjects->merge($fromSections)
            ->map(function ($id) { return (int) $id; })
            ->unique()
            ->values()
            ->all();

        return self::$classIdsByUser[$user->id] = $ids;
    }

    /** May this teacher work with the given class at all? */
    public static function canTeachClass(User $user, $classId): bool
    {
        if (!self::appliesTo($user) || !$classId) {
            return false;
        }

        return in_array((int) $classId, self::classIds($user), true);
    }

    /** May this teacher see/work with the student identified by user id? */
    public static function canTeachStudent(User $user, $studentUserId): bool
    {
        if (!self::appliesTo($user) || !$studentUserId) {
            return false;
        }

        $classId = StudentRecord::where('user_id', $studentUserId)->value('my_class_id');

        return $classId && self::canTeachClass($user, $classId);
    }

    /**
     * User ids of every student in the teacher's assigned classes.
     *
     * @return int[]
     */
    public static function studentUserIds(User $user): array
    {
        if (!self::appliesTo($user)) {
            return [];
        }

        return StudentRecord::whereIn('my_class_id', self::classIds($user))
            ->pluck('user_id')
            ->map(function ($id) { return (int) $id; })
            ->all();
    }
}
