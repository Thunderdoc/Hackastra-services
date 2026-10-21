<?php

namespace App\Support;

use App\Models\AttendanceSession;
use App\Models\Circular;
use App\Models\TimeTable;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Loop 31 (Fix 10): the teacher's derived reminders — ONE source
 * feeding both the dashboard "Upcoming Tasks / Important
 * Reminders" card (via HomeController::teacherHomeData) and the
 * topbar bell dropdown (the delimited hook in partials/top_menu).
 *
 * Composed at render from REAL records only; nothing is ever
 * written to the database for these. Priority order:
 *   1. TODAY's period registers not yet submitted (actionable,
 *      one row per period, linked to that period's take page);
 *   2. tomorrow's periods summary;
 *   3. urgent / important circulars addressed to teachers;
 *   4. upcoming exam sittings (exam dates live on exam timetable
 *      rows — the exams table itself has no date column).
 *
 * Item shape = the dashboard card's task shape (icon, tone, title,
 * meta, badge, badge_tone, date_label, link) plus reminder
 * metadata: kind, actionable (counts toward the bell badge),
 * tag ('Reminder' — how the bell renders these distinctly).
 */
class TeacherReminders
{
    /** Per-request memo, keyed by teacher + date. */
    protected static $cache = [];

    public static function forTeacher(User $user, $now = null): array
    {
        $now = $now ? Carbon::parse($now) : now();
        $key = $user->id . '|' . $now->toDateString();
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $teacher_id = $user->id;
        $register_items = [];
        $tomorrow_items = [];
        $circular_items = [];
        $exam_items = [];

        if (Schema::hasTable('time_tables')) {
            // (1) Today's unsubmitted period registers. A period
            // counts as submitted when an attendance session exists
            // for it today — the same rule the dashboard timetable
            // uses (HomeController::teacherHomeData).
            $submitted = collect();
            if (Schema::hasTable('attendance_sessions')) {
                $submitted = AttendanceSession::whereDate('date', $now->toDateString())
                    ->pluck('time_table_id');
            }

            $periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class'])
                ->where('day', $now->format('l'))
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->get()
                ->reject(function ($period) use ($submitted) {
                    return $submitted->contains($period->id);
                })
                ->sortBy(function ($period) {
                    return optional($period->time_slot)->timestamp_from ?? '99:99';
                })
                ->values();

            foreach ($periods as $period) {
                $subject_name = optional($period->subject)->name ?: '—';
                $class = optional($period->tt_record)->my_class ?: optional($period->subject)->my_class;
                $meta_parts = array_filter([
                    optional($class)->name,
                    optional($period->time_slot)->full,
                ]);
                $register_items[] = [
                    'icon' => 'icon-calendar52', 'tone' => 'red',
                    'title' => $subject_name . ' register not submitted',
                    'meta' => $meta_parts ? implode(' · ', $meta_parts) : 'Period register awaiting submission',
                    'badge' => 'Pending', 'badge_tone' => 'red',
                    'date_label' => $now->format('d M Y'),
                    'link' => route('attendance.periods.take', $period->id),
                    'kind' => 'register', 'actionable' => true, 'tag' => 'Reminder',
                ];
            }

            // (2) Tomorrow's periods summary.
            $tomorrow = $now->copy()->addDay();
            $tomorrow_count = TimeTable::where('day', $tomorrow->format('l'))
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->count();
            if ($tomorrow_count > 0) {
                $tomorrow_items[] = [
                    'icon' => 'icon-calendar3', 'tone' => 'blue',
                    'title' => $tomorrow_count . ' ' . ($tomorrow_count === 1 ? 'period' : 'periods') . ' scheduled for tomorrow',
                    'meta' => 'Check your timetable',
                    'badge' => 'Upcoming', 'badge_tone' => 'blue',
                    'date_label' => $tomorrow->format('d M Y'),
                    'link' => route('tt.index'),
                    'kind' => 'tomorrow', 'actionable' => false, 'tag' => 'Reminder',
                ];
            }

            // (4) Upcoming exam sittings (the pre-existing
            // derivation: exam dates live only on exam timetable
            // rows, linked to an Exam via the timetable record).
            $exam_rows = TimeTable::with(['subject.my_class', 'tt_record.exam'])
                ->whereNotNull('exam_date')
                ->where('exam_date', '>=', $now->toDateString())
                ->whereHas('subject', function ($q) use ($teacher_id) {
                    $q->where('teacher_id', $teacher_id);
                })
                ->orderBy('exam_date')
                ->take(2)
                ->get();
            foreach ($exam_rows as $exam_row) {
                $exam_name = optional(optional($exam_row->tt_record)->exam)->name;
                $subject_name = optional($exam_row->subject)->name ?: '—';
                $exam_class = optional(optional($exam_row->subject)->my_class)->name;
                $exam_items[] = [
                    'icon' => 'icon-file-text2', 'tone' => 'orange',
                    'title' => 'Exam: ' . ($exam_name ?: $subject_name),
                    'meta' => $subject_name . ($exam_class ? ' · ' . $exam_class : ''),
                    'badge' => 'Exam', 'badge_tone' => 'orange',
                    'date_label' => Carbon::parse($exam_row->exam_date)->format('d M Y'),
                    'link' => route('marks.index'),
                    'kind' => 'exam', 'actionable' => false, 'tag' => 'Reminder',
                ];
            }
        }

        // (3) Urgent / important circulars addressed to teachers —
        // the same published + audience scopes the feed itself uses.
        if (class_exists(Circular::class) && Schema::hasTable('circulars')) {
            $circulars = Circular::published()
                ->forAudience('teacher')
                ->whereIn('priority', ['urgent', 'important'])
                ->latest()
                ->take(2)
                ->get();
            foreach ($circulars as $circular) {
                $urgent = $circular->priority === 'urgent';
                $circular_items[] = [
                    'icon' => 'icon-megaphone', 'tone' => $urgent ? 'red' : 'amber',
                    'title' => $circular->title,
                    'meta' => 'Circular · by ' . (optional($circular->publisher)->name ?: 'School Office'),
                    'badge' => $urgent ? 'Urgent' : 'Important', 'badge_tone' => $urgent ? 'red' : 'amber',
                    'date_label' => optional($circular->starts_at)->format('d M Y') ?: $circular->created_at->format('d M Y'),
                    'link' => route('circulars.show', $circular->id),
                    'kind' => 'circular', 'actionable' => false, 'tag' => 'Reminder',
                ];
            }
        }

        $items = array_merge($register_items, $tomorrow_items, $circular_items, $exam_items);

        return self::$cache[$key] = $items;
    }

    /**
     * How many reminders currently demand action (today's
     * unsubmitted registers). Drives the bell badge: it clears by
     * itself once those registers are submitted.
     */
    public static function actionableCount(User $user, $now = null): int
    {
        return collect(self::forTeacher($user, $now))->where('actionable', true)->count();
    }
}
