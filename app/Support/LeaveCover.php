<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     * The one-line version of allocationLines() for the queue
     * row itself (owner order 2026-10-30): the row must never
     * carry the paragraph — "Class teacher · 9 sections" — the
     * full list lives behind the row's expander. Students get
     * their single short line ("Prep — A") as-is.
     */
    public static function allocationSummary(User $user): ?string
    {
        if ($user->user_type === 'student') {
            $lines = static::allocationLines($user);
            return $lines[0] ?? null;
        }
        if (!static::isLiveTeacher($user)) {
            return null;
        }
        $parts = [];
        $sectionCount = Section::where('teacher_id', $user->id)->count();
        if ($sectionCount > 0) {
            $parts[] = 'Class teacher · ' . $sectionCount . ' section' . ($sectionCount === 1 ? '' : 's');
        }
        $subjectCount = Subject::where('teacher_id', $user->id)->count();
        if ($subjectCount > 0) {
            $parts[] = 'Teaches ' . $subjectCount . ' subject' . ($subjectCount === 1 ? '' : 's');
        }
        if (Schema::hasTable('section_subject_teachers')) {
            $overrideCount = DB::table('section_subject_teachers')
                ->where('teacher_id', $user->id)->count();
            if ($overrideCount > 0) {
                $parts[] = $overrideCount . ' section override' . ($overrideCount === 1 ? '' : 's');
            }
        }
        return $parts ? implode(' · ', $parts) : null;
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
    /**
     * Period number of every cell of the given timetable records on
     * a weekday: within one record's day grid, cells ordered by
     * their slot start (then id) are periods 1, 2, 3... — the same
     * numbering the school day uses. Partial leave windows are
     * expressed in these numbers, so cover counting and arranging
     * both resolve cells through this map.
     *
     * @param int[] $ttrIds
     * @return array<int,int> time_table_id => period number
     */
    public static function periodIndexMap(array $ttrIds, string $dayName): array
    {
        if (!$ttrIds) {
            return [];
        }
        $cells = TimeTable::with('time_slot')
            ->whereIn('ttr_id', $ttrIds)
            ->where('day', $dayName)
            ->whereNotNull('subject_id')
            ->get();
        $byRecord = [];
        foreach ($cells as $cell) {
            $byRecord[(int) $cell->ttr_id][] = $cell;
        }
        $map = [];
        foreach ($byRecord as $recordCells) {
            usort($recordCells, function ($a, $b) {
                $ta = optional($a->time_slot)->time_from ?: '99:99';
                $tb = optional($b->time_slot)->time_from ?: '99:99';
                if ($ta !== $tb) {
                    return strcmp((string) $ta, (string) $tb);
                }
                return (int) $a->id <=> (int) $b->id;
            });
            foreach (array_values($recordCells) as $i => $cell) {
                $map[(int) $cell->id] = $i + 1;
            }
        }

        return $map;
    }

    public static function uncoveredCount(int $teacherId, $startDate, $endDate, ?array $window = null): ?int
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
            // Partial leave: only the window's periods of the day
            // can be uncovered — the teacher is present for the rest.
            if ($window !== null && $periods) {
                $ttrIds = TimeTable::whereIn('id', $periods)->pluck('ttr_id')
                    ->map(function ($id) {
                        return (int) $id;
                    })->unique()->all();
                $indexMap = static::periodIndexMap($ttrIds, $dayName);
                $periods = array_values(array_filter($periods, function ($ttId) use ($indexMap, $window) {
                    $n = $indexMap[$ttId] ?? null;
                    return $n !== null && $n >= $window[0] && $n <= $window[1];
                }));
            }
            foreach ($periods as $ttId) {
                if (!isset($covered[$date->toDateString() . '|' . $ttId])) {
                    $uncovered++;
                }
            }
        }

        return $uncovered;
    }

    /**
     * Leave intelligence (owner brief 2026-10-30): the full impact
     * of one teacher's leave, from the SAME walk uncoveredCount()
     * uses, so the queue's numbers can never disagree — affected
     * periods in the (partial-leave aware) window, how many already
     * carry a substitute, which classes / sections / subjects are
     * hit, how many are lab sessions, and on how many real working
     * days. Non-teaching day types (holiday / closure / event-only)
     * contribute nothing. Null for non-teachers.
     */
    public static function impactSummary(int $teacherId, $startDate, $endDate, ?array $window = null): ?array
    {
        $teacher = User::find($teacherId);
        if (!$teacher || !static::isLiveTeacher($teacher)) {
            return null;
        }
        $start = $startDate instanceof Carbon ? $startDate->copy()->startOfDay() : Carbon::parse($startDate)->startOfDay();
        $end = $endDate instanceof Carbon ? $endDate->copy()->startOfDay() : Carbon::parse($endDate)->startOfDay();
        $empty = ['affected' => 0, 'covered' => 0, 'uncovered' => 0, 'classes' => 0, 'sections' => 0, 'subjects' => [], 'labs' => 0, 'working_days' => 0];
        if ($end->lt($start)) {
            return $empty;
        }

        $query = TimeTable::query()->select('id', 'day', 'subject_id', 'ttr_id')
            ->with(['tt_record:id,my_class_id,section_id', 'subject:id,name,needs_lab']);
        SectionTeacher::applyTeacherFilter($query, $teacherId);
        $byDay = [];
        foreach ($query->get() as $cell) {
            $byDay[$cell->day][] = $cell;
        }
        if (!$byDay) {
            return $empty;
        }

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

        $affected = 0;
        $coveredCount = 0;
        $classes = [];
        $sections = [];
        $subjects = [];
        $labs = 0;
        $workDates = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if (!DayType::isTeachingDay($date)) {
                continue;
            }
            $dayName = $date->format('l');
            $cells = $byDay[$dayName] ?? [];
            if ($window !== null && $cells) {
                $ttrIds = collect($cells)->pluck('ttr_id')->map(function ($id) {
                    return (int) $id;
                })->unique()->all();
                $indexMap = static::periodIndexMap($ttrIds, $dayName);
                $cells = array_values(array_filter($cells, function ($cell) use ($indexMap, $window) {
                    $n = $indexMap[(int) $cell->id] ?? null;
                    return $n !== null && $n >= $window[0] && $n <= $window[1];
                }));
            }
            foreach ($cells as $cell) {
                $affected++;
                if (isset($covered[$date->toDateString() . '|' . (int) $cell->id])) {
                    $coveredCount++;
                }
                $record = $cell->tt_record;
                if ($record) {
                    if ($record->my_class_id) {
                        $classes[(int) $record->my_class_id] = true;
                    }
                    if ($record->section_id) {
                        $sections[(int) $record->section_id] = true;
                    }
                }
                if ($cell->subject) {
                    $subjects[$cell->subject->name] = true;
                    if ($cell->subject->needs_lab) {
                        $labs++;
                    }
                }
            }
            if ($cells) {
                $workDates[$date->toDateString()] = true;
            }
        }

        return [
            'affected' => $affected,
            'covered' => $coveredCount,
            'uncovered' => $affected - $coveredCount,
            'classes' => count($classes),
            'sections' => count($sections),
            'subjects' => array_keys($subjects),
            'labs' => $labs,
            'working_days' => count($workDates),
        ];
    }

    /**
     * The teacher's full weekly allocation as grid rows (owner
     * brief 2026-10-30: the queue's "View full allocation" table) —
     * Day | Period | Class | Section | Subject | Room, straight
     * from the real timetable cells.
     */
    public static function weekGrid(User $user): array
    {
        if (!static::isLiveTeacher($user)) {
            return [];
        }
        $query = TimeTable::query()
            ->with(['tt_record.my_class', 'tt_record.section', 'subject', 'time_slot', 'room']);
        SectionTeacher::applyTeacherFilter($query, $user->id);
        $cells = $query->get();
        if ($cells->isEmpty()) {
            return [];
        }
        $maps = [];
        foreach ($cells->pluck('day')->unique() as $day) {
            $maps[$day] = static::periodIndexMap($cells->pluck('ttr_id')->map(function ($id) {
                return (int) $id;
            })->unique()->all(), $day);
        }
        $dayOrder = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
        $rows = [];
        foreach ($cells as $cell) {
            $record = $cell->tt_record;
            $rows[] = [
                'day' => $cell->day,
                'period' => $maps[$cell->day][(int) $cell->id] ?? null,
                'class' => $record ? (optional($record->my_class)->name ?: '—') : '—',
                'section' => $record && $record->section ? $record->section->name : '—',
                'subject' => $cell->subject ? $cell->subject->name : '—',
                'room' => $cell->room ? $cell->room->name : '—',
            ];
        }
        usort($rows, function ($a, $b) use ($dayOrder) {
            $d = ($dayOrder[$a['day']] ?? 9) <=> ($dayOrder[$b['day']] ?? 9);
            if ($d !== 0) {
                return $d;
            }
            return ($a['period'] ?? 99) <=> ($b['period'] ?? 99);
        });

        return $rows;
    }

    /** Total weekly teaching periods on the teacher's real grid. */
    public static function weeklyPeriods(User $user): int
    {
        if (!static::isLiveTeacher($user)) {
            return 0;
        }
        $query = TimeTable::query();
        SectionTeacher::applyTeacherFilter($query, $user->id);

        return $query->count();
    }
}
