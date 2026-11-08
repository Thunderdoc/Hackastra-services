<?php

namespace App\Support;

use App\Models\AttendanceSessionEntry;
use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Models\TimeTable;
use Illuminate\Support\Carbon;

/**
 * One student's period-attendance dataset (LMS build, 2026-11-08).
 *
 * Single source for the two surfaces that show a student their
 * attendance — My Attendance (month picker + drill-down) and the
 * My Week page's attendance section — so the figures can never
 * drift apart. The math is the settled house rule, unchanged from
 * AttendanceController@my: % = (present + late) / marked, counted
 * from period entries only (the retired day-wise table is never
 * read). On top of the totals, each subject row carries its real
 * date-wise register (the drill-down), and an absence that an
 * APPROVED student leave covers is flagged leave_covered — shown
 * with the dashed "Absent · Leave approved" tag. The flag is a
 * display treatment only: the absence still counts in the totals,
 * exactly as on every other attendance surface in the app.
 */
class StudentAttendance
{
    /** Attendance tier label for a percentage (settled tiers). */
    public static function tierLabel($percent): ?string
    {
        if ($percent === null) {
            return null;
        }
        if ($percent >= 90) {
            return 'Excellent';
        }
        if ($percent >= 75) {
            return 'Good';
        }
        if ($percent >= 50) {
            return 'At Risk';
        }

        return 'Critical';
    }

    public static function percentFor(int $present, int $late, int $marked): ?int
    {
        return $marked > 0 ? (int) round(($present + $late) / $marked * 100) : null;
    }

    /**
     * Build the dataset for one student record.
     *
     * @param  Carbon|null  $month  null = whole session; a month
     *                              restricts summary/subjects/register
     *                              to that month (the months strip
     *                              always describes the whole session).
     * @return array{summary: array, months: \Illuminate\Support\Collection,
     *               subjects: \Illuminate\Support\Collection,
     *               entries: \Illuminate\Support\Collection}
     */
    public static function build(StudentRecord $record, ?Carbon $month = null): array
    {
        $summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0,
            'attended' => 0, 'percent' => null, 'tier' => null];

        $allEntries = AttendanceSessionEntry::with(['session.subject', 'session.time_table.time_slot'])
            ->where('student_record_id', $record->id)
            ->get();

        // Per-month strip — always from the whole session.
        $byMonth = [];
        foreach ($allEntries as $e) {
            if (!$e->session) {
                continue;
            }
            $key = $e->session->date->format('Y-m');
            $byMonth[$key] = $byMonth[$key] ?? ['key' => $key, 'label' => $e->session->date->format('F Y'),
                'present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
            if (isset($byMonth[$key][$e->status])) {
                $byMonth[$key][$e->status]++;
            }
            $byMonth[$key]['marked']++;
        }
        ksort($byMonth);
        $months = collect($byMonth)->map(function ($m) {
            $m['percent'] = self::percentFor($m['present'], $m['late'], $m['marked']);
            $m['attended'] = $m['present'] + $m['late'];
            return $m;
        })->values();

        $entries = $allEntries;
        if ($month) {
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth();
            $entries = $allEntries->filter(function ($e) use ($from, $to) {
                return $e->session && $e->session->date->between($from, $to);
            })->values();
        }

        $entries = $entries
            ->sortByDesc(function ($entry) {
                return $entry->session->date->format('Y-m-d') . '-' . str_pad((string) $entry->session_id, 10, '0', STR_PAD_LEFT);
            })
            ->values();

        foreach ($entries as $entry) {
            if (isset($summary[$entry->status])) {
                $summary[$entry->status]++;
            }
            $summary['marked']++;
        }
        $summary['attended'] = $summary['present'] + $summary['late'];
        $summary['percent'] = self::percentFor($summary['present'], $summary['late'], $summary['marked']);
        $summary['tier'] = self::tierLabel($summary['percent']);

        // Period numbers + leave coverage for the drill-down rows.
        $periodNos = self::periodNumbers($entries);
        $leaves = self::approvedLeaves($record);

        $subjects = $entries
            ->groupBy(function ($entry) {
                return $entry->session->subject_id;
            })
            ->map(function ($subjectEntries) use ($periodNos, $leaves) {
                $present = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_PRESENT)->count();
                $absent = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_ABSENT)->count();
                $late = $subjectEntries->where('status', AttendanceSessionEntry::STATUS_LATE)->count();
                $marked = $present + $absent + $late;

                $register = $subjectEntries->map(function ($entry) use ($periodNos, $leaves) {
                    $ttId = (int) optional($entry->session)->time_table_id;
                    $covered = $entry->status === AttendanceSessionEntry::STATUS_ABSENT
                        && self::leaveCovers($leaves, $entry->session->date, $periodNos[$ttId] ?? null);

                    return [
                        'date' => $entry->session->date,
                        'period_no' => $periodNos[$ttId] ?? null,
                        'time' => optional(optional(optional($entry->session)->time_table)->time_slot)->full,
                        'topic' => $entry->session->topic,
                        'status' => $entry->status,
                        'leave_covered' => $covered,
                    ];
                })->values();

                return [
                    'subject' => $subjectEntries->first()->session->subject,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'marked' => $marked,
                    'conducted' => $marked,
                    'attended' => $present + $late,
                    'percent' => self::percentFor($present, $late, $marked),
                    'register' => $register,
                ];
            })
            ->sortBy(function ($row) {
                return strtolower(optional($row['subject'])->name ?? '');
            })
            ->values();

        return [
            'summary' => $summary,
            'months' => $months,
            'subjects' => $subjects,
            'entries' => $entries,
        ];
    }

    /**
     * Period number (1-based, by slot start) of every timetable
     * cell behind these entries, via the canonical
     * LeaveCover::periodIndexMap — [time_table_id => period no].
     */
    protected static function periodNumbers($entries): array
    {
        $ttIds = $entries->map(function ($e) {
            return (int) optional($e->session)->time_table_id;
        })->filter()->unique()->values()->all();
        if (!$ttIds) {
            return [];
        }

        $cells = TimeTable::whereIn('id', $ttIds)->get(['id', 'ttr_id', 'day']);
        $map = [];
        foreach ($cells->groupBy(function ($c) {
            return $c->ttr_id . '|' . $c->day;
        }) as $group) {
            $first = $group->first();
            $map += LeaveCover::periodIndexMap([(int) $first->ttr_id], (string) $first->day);
        }

        return $map;
    }

    /** The student's own APPROVED leave requests (display lookup). */
    protected static function approvedLeaves(StudentRecord $record)
    {
        if (!$record->user_id) {
            return collect();
        }

        return LeaveRequest::where('user_id', $record->user_id)
            ->where('status', 'approved')
            ->get();
    }

    /**
     * Does an approved leave cover this date (+ period, when the
     * leave is partial)? Mirrors PeriodAttendanceController's
     * approvedLeaveCovering semantics.
     */
    protected static function leaveCovers($leaves, $date, ?int $periodNo): bool
    {
        if (!$date) {
            return false;
        }
        $day = $date->format('Y-m-d');
        foreach ($leaves as $leave) {
            $start = $leave->start_date ? Carbon::parse($leave->start_date)->format('Y-m-d') : null;
            $end = $leave->end_date ? Carbon::parse($leave->end_date)->format('Y-m-d') : null;
            if (!$start || !$end || $day < $start || $day > $end) {
                continue;
            }
            $window = $leave->periodWindow();
            if ($window === null || $periodNo === null
                || ($periodNo >= $window[0] && $periodNo <= $window[1])) {
                return true;
            }
        }

        return false;
    }
}
