<?php

namespace Database\Seeders;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\TimeTable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Period Attendance demo data (Workstream AC).
 *
 * Submits realistic period registers for the seeded teachers'
 * timetables: today plus the previous five school days, about six
 * submitted sessions per day (~36 in total), each with a plausible
 * topic for its subject and a full class roster at roughly 92%
 * present. Every session is taught by its timetable row's ACTUAL
 * subject teacher (Workstream AT — since the seed rebalance the
 * demo teacher teaches three classes, not all ten, so the sessions
 * are picked round-robin across every teacher who has periods that
 * day, the demo teacher included). Fully guarded and idempotent —
 * a pair (date, period) that already has a session is never
 * touched, and any surprise is swallowed so demo seeding can never
 * hard-fail.
 *
 * Runs after DemoDataSeeder (which builds the demo timetables).
 */
class PeriodAttendanceSeeder extends Seeder
{
    public function run()
    {
        try {
            $this->seed();
        } catch (\Throwable $e) {
            // Demo data must never break a seed run.
            if (isset($this->command) && $this->command) {
                $this->command->warn('PeriodAttendanceSeeder skipped: ' . $e->getMessage());
            }
        }
    }

    /** Sessions seeded per school day — six days at six ≈ the demo volume. */
    protected const SESSIONS_PER_DAY = 6;

    protected function seed(): void
    {
        if (!Schema::hasTable('attendance_sessions')
            || !Schema::hasTable('attendance_session_entries')
            || !Schema::hasTable('time_tables')) {
            return;
        }

        foreach ($this->schoolDays() as $dayIndex => $date) {
            $periods = TimeTable::with(['subject', 'time_slot', 'tt_record'])
                ->where('day', $date->format('l'))
                ->whereNotNull('subject_id')
                ->whereHas('subject', function ($q) {
                    $q->whereNotNull('teacher_id');
                })
                ->get();

            foreach ($this->pickDailySessions($periods, (int) $dayIndex) as $period) {
                $this->seedSession($period, $date);
            }
        }
    }

    /**
     * Choose the day's sessions deterministically: group the day's
     * periods by their subject's real teacher, then take them
     * round-robin across the teachers so every teacher with periods
     * that day — the demo teacher included — gets sessions over the
     * six days. Both the teacher order and each teacher's period
     * list are rotated by the day index, so different days feature
     * different teachers and different classes instead of the same
     * six periods repeating.
     *
     * @param \Illuminate\Support\Collection $periods
     * @return TimeTable[]
     */
    protected function pickDailySessions($periods, int $dayIndex): array
    {
        $byTeacher = [];
        foreach ($periods as $period) {
            if ($period->subject && $period->subject->teacher_id) {
                $byTeacher[(int) $period->subject->teacher_id][] = $period;
            }
        }
        if (!$byTeacher) {
            return [];
        }

        ksort($byTeacher);
        foreach ($byTeacher as $teacherId => $list) {
            usort($list, function ($a, $b) {
                return $a->id <=> $b->id;
            });
            $byTeacher[$teacherId] = $this->rotated($list, $dayIndex);
        }

        $teacherIds = $this->rotated(array_keys($byTeacher), $dayIndex);
        $picked = [];
        $progress = true;
        while (count($picked) < self::SESSIONS_PER_DAY && $progress) {
            $progress = false;
            foreach ($teacherIds as $teacherId) {
                if (count($picked) >= self::SESSIONS_PER_DAY) {
                    break;
                }
                if (!empty($byTeacher[$teacherId])) {
                    $picked[] = array_shift($byTeacher[$teacherId]);
                    $progress = true;
                }
            }
        }

        return $picked;
    }

    /** Rotate a list left by $offset positions (deterministic). */
    protected function rotated(array $list, int $offset): array
    {
        $count = count($list);
        if ($count === 0) {
            return $list;
        }
        $offset %= $count;
        if ($offset === 0) {
            return $list;
        }

        return array_merge(array_slice($list, $offset), array_slice($list, 0, $offset));
    }

    /** Today plus the previous five school days (Mon–Fri), newest first. */
    protected function schoolDays(): array
    {
        $days = [];
        $cursor = Carbon::today();
        $guard = 0;
        while (count($days) < 6 && $guard++ < 21) {
            if ($cursor->isWeekday()) {
                $days[] = $cursor->copy();
            }
            $cursor->subDay();
        }

        return $days;
    }

    protected function seedSession(TimeTable $period, Carbon $date): void
    {
        $subject = $period->subject;
        if (!$subject) {
            return;
        }

        $classId = optional($period->tt_record)->my_class_id ?: $subject->my_class_id;
        if (!$classId) {
            return;
        }

        $dateStr = $date->toDateString();
        $exists = AttendanceSession::whereDate('date', $dateStr)
            ->where('time_table_id', $period->id)
            ->exists();
        if ($exists) {
            return;
        }

        $sectionId = optional($period->tt_record)->section_id;
        $students = DB::table('student_records')
            ->where('my_class_id', $classId)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId);
            })
            ->where('grad', 0)
            ->orderBy('id')
            ->get();
        if ($students->isEmpty()) {
            return;
        }

        $submittedAt = $date->copy()->setTime(15, 0);
        $slot = $period->time_slot;
        if ($slot && $slot->time_to) {
            $parsed = strtotime($dateStr . ' ' . $slot->time_to);
            if ($parsed !== false) {
                $submittedAt = Carbon::createFromTimestamp($parsed);
            }
        }

        $session = AttendanceSession::create([
            'date' => $dateStr,
            'time_table_id' => $period->id,
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'subject_id' => $subject->id,
            'teacher_id' => $subject->teacher_id,
            'topic' => $this->topicFor($subject->name, $subject->id, $period->id, $dateStr),
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => $submittedAt,
        ]);

        $now = now();
        $entries = [];
        foreach ($students as $student) {
            $roll = $this->hashOf('pa|' . $period->id . '|' . $dateStr . '|' . $student->id) % 100;
            $entries[] = [
                'session_id' => $session->id,
                'student_record_id' => $student->id,
                'status' => $roll < 92 ? 'present' : ($roll < 97 ? 'late' : 'absent'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        AttendanceSessionEntry::insert($entries);
    }

    /** A plausible topic for the subject, picked deterministically. */
    protected function topicFor(string $subjectName, int $subjectId, int $periodId, string $dateStr): string
    {
        $name = strtolower($subjectName);

        if (strpos($name, 'math') !== false) {
            $pool = [
                'Quadratic Equations — solving by factorisation',
                'Linear Equations in Two Variables',
                'Introduction to Trigonometry',
                'Circles — tangents and secants',
                'Arithmetic Progressions — nth term',
                'Probability — basic concepts',
                'Mensuration — surface areas and volumes',
                'Real Numbers — HCF and LCM',
            ];
        } elseif (strpos($name, 'english') !== false || strpos($name, 'language') !== false) {
            $pool = [
                'Comprehension — unseen passage practice',
                'Notice Writing — format and drafting',
                'Grammar — reported speech',
                'Letter to the Editor — drafting',
                'Poetry — figure of speech and appreciation',
                'Prose — chapter discussion and questions',
                'Revision — extracts and short answers',
                'Vocabulary building and synonyms',
            ];
        } elseif (strpos($name, 'science') !== false || strpos($name, 'physics') !== false
            || strpos($name, 'chemistry') !== false || strpos($name, 'biology') !== false) {
            $pool = [
                'Light — reflection and refraction',
                'Acids, Bases and Salts — indicators',
                'Life Processes — nutrition in plants',
                'Electricity — Ohm\'s law and circuits',
                'Chemical Reactions — types and balancing',
                'Heredity — Mendel\'s experiments',
                'Control and Coordination — nervous system',
                'Revision — diagram practice',
            ];
        } elseif (strpos($name, 'social') !== false || strpos($name, 'history') !== false
            || strpos($name, 'geography') !== false || strpos($name, 'civics') !== false) {
            $pool = [
                'The Rise of Nationalism in Europe',
                'Resources and Development — types of resources',
                'The Indian Constitution — fundamental rights',
                'Industrialisation — the factory system',
                'Agriculture — cropping patterns in India',
                'Map work — major ports and power plants',
                'Print Culture and the Modern World',
                'Revision — source-based questions',
            ];
        } else {
            $pool = [
                'Revision and class test',
                'Introduction to a new chapter',
                'Solved examples and practice questions',
                'Chapter summary and key points',
                'Group activity and discussion',
                'Doubt-clearing session',
            ];
        }

        return $pool[$this->hashOf('topic|' . $subjectId . '|' . $periodId . '|' . $dateStr) % count($pool)];
    }

    /** Unsigned crc32 so hash-derived values are identical on any platform. */
    protected function hashOf(string $key): int
    {
        return (int) sprintf('%u', crc32($key));
    }
}
