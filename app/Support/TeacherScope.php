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
 * Derivation — a class is "assigned" to a teacher when ANY real
 * assignment source in the schema names them for that class:
 *
 *   1. subjects.teacher_id — the teacher teaches a subject in the
 *      class (subjects.my_class_id). This is the same source the
 *      teacher dashboard derives its numbers from.
 *   2. sections.teacher_id — the teacher is class-teacher of a
 *      section (sections.my_class_id); leading a section of a
 *      class assigns the whole class, mirroring how the dashboard
 *      counts "sections led".
 *   3. section_subject_teachers — the teacher is the section's
 *      override teacher for a subject (section → sections.
 *      my_class_id). Added 2026-10-28 (allotment audit): 13 of 33
 *      seeded teachers teach ONLY through overrides (29–36 real
 *      periods each) and previously had NO scope at all — they
 *      could take attendance for their periods (period-level
 *      surfaces resolve the effective teacher) but were refused
 *      marks entry, the students list and every other
 *      class-scoped surface. Like source 2, an override assigns
 *      the whole class: the scope is class-granular everywhere.
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
    public static function appliesTo($user): bool
    {
        return $user && ($user->user_type ?? null) === 'teacher';
    }

    /**
     * Ids of every class assigned to the teacher ([] for anyone else).
     *
     * Deliberately NOT memoized (round-8 allotment fix): the old
     * static $classIdsByUser memo was documented as "per-request",
     * but production runs Apache prefork + mod_php, whose child
     * processes persist across requests — so a memo could serve a
     * STALE scope indefinitely after an admin changed allotments
     * (and, in-process, it made combined PHPUnit runs fail: a
     * rolled-back teacher's id got reused with a stale [] memo
     * behind it, 403ing a legitimate leader). The three lookups
     * below are tiny indexed queries on small tables, and call
     * sites are per-request gates, never per-row loops — so fresh
     * reads every time cost nothing measurable and can never go
     * stale.
     *
     * @return int[]
     */
    public static function classIds(User $user): array
    {
        if (!self::appliesTo($user)) {
            return [];
        }

        $fromSubjects = Subject::where('teacher_id', $user->id)->pluck('my_class_id');
        $fromSections = Section::where('teacher_id', $user->id)->pluck('my_class_id');
        // Source 3 (allotment audit 2026-10-28): section-subject
        // overrides. A teacher who teaches only through overrides
        // previously had an empty scope despite a full timetable.
        $fromOverrides = Section::whereIn('id', function ($q) use ($user) {
            $q->select('section_id')->from('section_subject_teachers')
                ->where('teacher_id', $user->id);
        })->pluck('my_class_id');

        return $fromSubjects->merge($fromSections)->merge($fromOverrides)
            ->map(function ($id) { return (int) $id; })
            ->unique()
            ->values()
            ->all();
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
