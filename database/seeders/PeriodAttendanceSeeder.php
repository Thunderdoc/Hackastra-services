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
 * Submits realistic period registers for the demo teacher's own
 * timetable: today plus the previous five school days, every period
 * with a plausible topic for its subject and a full class roster at
 * roughly 92% present. Fully guarded and idempotent — a pair
 * (date, period) that already has a session is never touched, and
 * any surprise is swallowed so demo seeding can never hard-fail.
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

    protected function seed(): void
    {
        if (!Schema::hasTable('attendance_sessions')
            || !Schema::hasTable('attendance_session_entries')
            || !Schema::hasTable('time_tables')) {
            return;
        }

        $teacherId = DB::table('users')->where('email', 'teacher@teacher.com')->value('id')
            ?: DB::table('users')->where('user_type', 'teacher')->value('id');
        if (!$teacherId) {
            return;
        }

        foreach ($this->schoolDays() as $date) {
            $periods = TimeTable::with(['subject', 'time_slot', 'tt_record'])
                ->where('day', $date->format('l'))
                ->whereNotNull('subject_id')
                ->whereHas('subject', function ($q) use ($teacherId) {
                    $q->where('teacher_id', $teacherId);
                })
                ->get();

            foreach ($periods as $period) {
                $this->seedSession($period, $date);
            }
        }
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

        $students = DB::table('student_records')
            ->where('my_class_id', $classId)
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
            'section_id' => null,
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
