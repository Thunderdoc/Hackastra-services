<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave cover visibility (roles agent, owner question 2026-10-28).
 *
 * The leave queue used to show only name + user_type, so an approver
 * could not see what a teacher's absence actually costs: which
 * classes/sections they own, and how many of their periods inside
 * the leave dates still have no substitute. This helper computes
 * both from the same resolution the rest of the app uses —
 * sections.teacher_id (class teacher), subjects.teacher_id
 * (class subject teacher), section_subject_teachers (per-section
 * overrides) and SectionTeacher::teacherIdFor for periods.
 *
 * It deliberately does NOT create substitutions: auto-cover is a
 * product decision for the owner. The queue only surfaces the gap
 * and links to the substitutions board where cover is arranged.
 *
 * Live-teacher standard (same as the allotment surfaces, F round 3):
 * a teacher counts only while user_type is 'teacher' AND status is
 * null (legacy row) or 'active'. A demoted, suspended, terminated
 * or deleted teacher's periods are an allotment problem (flagged
 * on sections/staffing), not a leave-cover problem — so the queue
 * shows a dash for them, never a count and never a "Covered"
 * badge. teacherIdFor itself is deliberately untouched (it feeds
 * validator/generation); the gate lives here, at display level.
 */
class LeaveCover
{
    /**
     * The allotment surfaces' live standard (F round 3): the row
     * must still be a teacher AND must not be suspended or
     * terminated. A null status is a legacy row and counts live.
     */
    public static function isLiveTeacher(User $user): bool
    {
        return $user->user_type === 'teacher'
            && (!$user->getAttribute('status') || $user->getAttribute('status') === 'active');
    }

    /**
     * Compact display lines for an applicant's allocation.
     * Teachers: class-teacher sections grouped by class, subjects
     * grouped by subject name, section overrides listed. Students:
     * their current class — section. Anyone else: empty.
     *
     * @return string[]
     */
    public static function allocationLines(User $user): array
    {
        if ($user->user_type === 'student') {
            $record = \App\Models\StudentRecord::where('user_id', $user->id)
                ->where('grad', 0)->orderByDesc('id')->first();
            if ($record) {
                $class = optional($record->my_class)->name ?: '—';
                $section = optional($record->section)->name ?: '—';

                return [$class . ' — ' . $section];
            }

            return [];
        }

        if (!static::isLiveTeacher($user)) {
            return [];
        }

        $lines = [];

        $sections = Section::where('teacher_id', $user->id)->with('my_class')->get();
        $byClass = [];
        foreach ($sections as $section) {
            $byClass[optional($section->my_class)->name ?: '—'][] = $section->name;
        }
        if ($byClass) {
            $parts = [];
            foreach ($byClass as $class => $names) {
                $parts[] = $class . ' — ' . implode(', ', $names);
            }
            $lines[] = 'Class teacher: ' . implode(' · ', $parts);
        }

        $subjects = Subject::where('teacher_id', $user->id)->with('my_class')->get();
        $bySubject = [];
        foreach ($subjects as $subject) {
            $bySubject[$subject->name][] = optional($subject->my_class)->name ?: '—';
        }
        if ($bySubject) {
            $parts = [];
            foreach ($bySubject as $name => $classes) {
                $parts[] = $name . ' (' . implode(', ', $classes) . ')';
            }
            $lines[] = 'Subjects: ' . implode(' · ', $parts);
        }

        $overrides = DB::table('section_subject_teachers')
            ->join('subjects', 'subjects.id', '=', 'section_subject_teachers.subject_id')
            ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
            ->join('my_classes', 'my_classes.id', '=', 'sections.my_class_id')
            ->where('section_subject_teachers.teacher_id', $user->id)
            ->select('subjects.name as subject', 'my_classes.name as class', 'sections.name as section')
            ->orderBy('my_classes.name')->orderBy('sections.name')->get();
        if ($overrides->isNotEmpty()) {
            $shown = $overrides->take(4)->map(function ($row) {
                return $row->subject . ' (' . $row->class . ' — ' . $row->section . ')';
            })->all();
            $extra = $overrides->count() - count($shown);
            $lines[] = 'Section overrides: ' . implode(', ', $shown) . ($extra > 0 ? ' +' . $extra . ' more' : '');
        }

        return $lines;
    }

    /**
     * Periods of a teacher falling inside [start, end] that have no
     * substitution with a substitute assigned yet. A period counts
     * once per date it falls on (weekday match against the leave
     * range); a substitution row for that (time_table_id, date)
     * with a substitute set covers it.
     *
     * Returns null (not 0) for a teacher who is not live — the
     * queue renders a dash for null, while 0 would render a green
     * "Covered" badge, which would be a lie for someone who no
     * longer teaches. A live teacher with no periods in range
     * still returns 0 ("Covered": nothing needs cover).
     */
    public static function uncoveredCount(int $teacherId, $startDate, $endDate): ?int
    {
        $teacher = User::find($teacherId);
        if (!$teacher || !static::isLiveTeacher($teacher)) {
            return null;
        }

        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse($startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse($endDate)->startOfDay();
        if ($end->lt($start)) {
            return 0;
        }

        $query = TimeTable::query()->select('id', 'day');
        SectionTeacher::applyTeacherFilter($query, $teacherId);
        $byDay = [];
        foreach ($query->get() as $cell) {
            $byDay[$cell->day][] = (int) $cell->id;
        }
        if (!$byDay) {
            return 0;
        }

        // NOTE: substitutions.date is a DATE column, but Eloquent
        // stores its date-cast Carbon as 'Y-m-d H:i:s', so a bare
        // whereBetween on 'Y-m-d' strings misses every row on the
        // end date. whereDate is the codebase convention here
        // (SubstitutionController) and matches both forms.
        $covered = [];
        $subs = Substitution::where('absent_teacher_id', $teacherId)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->whereNotNull('substitute_teacher_id')
            ->get(['time_table_id', 'date']);
        foreach ($subs as $sub) {
            $date = $sub->date instanceof Carbon ? $sub->date->toDateString() : (string) $sub->date;
            $covered[$date . '|' . (int) $sub->time_table_id] = true;
        }

        $uncovered = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $dayName = $date->format('l');
            $periods = $byDay[$dayName] ?? [];
            foreach ($periods as $ttId) {
                if (!isset($covered[$date->toDateString() . '|' . $ttId])) {
                    $uncovered++;
                }
            }
        }

        return $uncovered;
    }
}
