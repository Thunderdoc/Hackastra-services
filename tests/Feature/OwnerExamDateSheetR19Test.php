<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 19) — auto-assign
 * FEWEST-DUTIES-FIRST ordering. Round 18 proved the clash guard
 * (a live hasClash query inside the loop, so a teacher assigned
 * to sitting A is invisible to sitting B at the same slot), but
 * the ordering claim itself — "fewest existing duties first" —
 * was never proven. These tests prove it, including the two
 * properties that shape it:
 *
 *  - The count is per-EXAM (autoAssign counts duties whose
 *    exam_date belongs to this exam only), so load duties must
 *    sit on this exam's own sittings to count.
 *  - Among candidates the sort is by count ascending, ties keep
 *    pool order (pool() orders by name — stable sort), and the
 *    non-own-subject preference picks the first NON-own-subject
 *    candidate in that order. The fixtures below make no probe
 *    the own-subject teacher of any target sitting, so the
 *    winner is purely the least-loaded candidate.
 *
 * Isolation trick: every SEEDED pool teacher is put on approved
 * leave covering the target dates, so the candidate set is
 * exactly the fresh probes. Leave rows are direct creates (no
 * controller, no bells), and everything rolls back.
 *
 * Dates are relative to the real today (2026-10-28): targets
 * 2026-11-10..12 and load sittings 2026-11-20..22 are all future,
 * so the past-skip branch (round 2) cannot interfere. Exam 1 is
 * the global Term 1 exam; on a fresh snapshot it holds no
 * sittings except the fixtures below (agent B round 8's probe
 * rows are exam 2, and auto-assign filters by exam_id).
 * Distinct (class, subject) pairs per exam are UNIQUE: load
 * pairs (1,1), (2,7), (3,13) never collide with target pairs
 * (4,19), (5,25), (6,31) — class m carries subjects
 * 6(m-1)+1..6m.
 */
class OwnerExamDateSheetR19Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function seededPool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get();
    }

    protected function makeTeacher(string $name, string $code, string $email)
    {
        return User::create([
            'name' => $name,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function sitting(int $classId, int $subjectId, string $date, string $time = '09:00')
    {
        return ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => $date,
            'start_time' => $time,
        ]);
    }

    protected function duty(int $sittingId, int $teacherId)
    {
        return ExamDuty::create([
            'exam_date_id' => $sittingId,
            'teacher_id' => $teacherId,
            'assigned_by' => $this->admin()->id,
        ]);
    }

    // NOTE: $seeded must be captured BEFORE probes are created —
    // re-querying here would leave the probes out too and the run
    // would go unfilled (this bit test 1 and 2 on the first run).
    protected function leaveOutSeeded($seeded, string $from, string $to)
    {
        foreach ($seeded as $teacher) {
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'start_date' => $from,
                'end_date' => $to,
                'reason' => 'probe',
                'leave_type' => 'normal',
                'status' => 'approved',
            ]);
        }
    }

    protected function auto()
    {
        return $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
    }

    public function test_auto_assign_picks_zero_duty_teacher_over_loaded_teacher()
    {
        $seeded = $this->seededPool();
        $this->assertGreaterThan(0, $seeded->count(), 'Seeded pool must exist to leave out');

        $loaded = $this->makeTeacher('ZZZ R19 Loaded', 'R19LOADED', 'r19loaded@probe.test');
        $fresh = $this->makeTeacher('AAA R19 Fresh', 'R19FRESH', 'r19fresh@probe.test');

        // Load duties on THIS exam's own sittings (counts are
        // per-exam): the loaded teacher holds 2, the fresh one 0.
        $this->duty($this->sitting(1, 1, '2026-11-20')->id, $loaded->id);
        $this->duty($this->sitting(2, 7, '2026-11-21')->id, $loaded->id);

        $this->leaveOutSeeded($seeded, '2026-11-10', '2026-11-10');

        $target = $this->sitting(4, 19, '2026-11-10');

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        // filledDespiteLeave is 1: every seeded teacher was
        // leave-excluded for the target date, so the flash names
        // leave exactly once alongside the assigned count.
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 1 sitting(s) got an invigilator. 1 sitting(s) filled while other teachers were on approved leave.'
        );

        // DB proof: the target went to the 0-duty teacher, not
        // the 2-duty teacher; flash count (1) matches reality.
        $duty = ExamDuty::where('exam_date_id', $target->id)->firstOrFail();
        $this->assertEquals($fresh->id, $duty->teacher_id);
        $this->assertEquals(2, ExamDuty::where('teacher_id', $loaded->id)->count());
        $this->assertEquals(1, ExamDuty::where('teacher_id', $fresh->id)->count());
    }

    public function test_auto_assign_balances_load_fewest_first_with_name_tiebreak()
    {
        $seeded = $this->seededPool();
        $this->assertGreaterThan(0, $seeded->count(), 'Seeded pool must exist to leave out');

        // Names sort AAA < BBB < CCC in pool order (pool() orders
        // by name); counts start 0 / 1 / 2 via load duties on
        // this exam's own sittings.
        $zero = $this->makeTeacher('AAA R19 Zero', 'R19ZERO', 'r19zero@probe.test');
        $one = $this->makeTeacher('BBB R19 One', 'R19ONE', 'r19one@probe.test');
        $two = $this->makeTeacher('CCC R19 Two', 'R19TWO', 'r19two@probe.test');

        $this->duty($this->sitting(1, 1, '2026-11-20')->id, $one->id);
        $this->duty($this->sitting(2, 7, '2026-11-21')->id, $two->id);
        $this->duty($this->sitting(3, 13, '2026-11-22')->id, $two->id);

        $this->leaveOutSeeded($seeded, '2026-11-10', '2026-11-12');

        // Three target sittings at distinct slots (no clash can
        // interfere with the ordering question).
        $s1 = $this->sitting(4, 19, '2026-11-10');
        $s2 = $this->sitting(5, 25, '2026-11-11');
        $s3 = $this->sitting(6, 31, '2026-11-12');

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 3 sitting(s) got an invigilator. 3 sitting(s) filled while other teachers were on approved leave.'
        );

        // Exact per-sitting winners, walking the sort:
        // S1 counts 0/1/2 -> Zero. S2 counts 1/1/2, tie Zero/One
        // keeps pool (name) order -> Zero again. S3 counts
        // 2/1/2 -> One. Final totals balance at 2/2/2.
        $this->assertEquals(
            $zero->id,
            ExamDuty::where('exam_date_id', $s1->id)->firstOrFail()->teacher_id
        );
        $this->assertEquals(
            $zero->id,
            ExamDuty::where('exam_date_id', $s2->id)->firstOrFail()->teacher_id
        );
        $this->assertEquals(
            $one->id,
            ExamDuty::where('exam_date_id', $s3->id)->firstOrFail()->teacher_id
        );
        $this->assertEquals(2, ExamDuty::where('teacher_id', $zero->id)->count());
        $this->assertEquals(2, ExamDuty::where('teacher_id', $one->id)->count());
        $this->assertEquals(2, ExamDuty::where('teacher_id', $two->id)->count());
        $this->assertEquals(3, ExamDuty::whereIn('exam_date_id', [$s1->id, $s2->id, $s3->id])->count());
    }

    public function test_auto_assign_skips_own_subject_teacher_for_least_loaded()
    {
        // The second shaping rule: among candidates, the first
        // NON-own-subject teacher in count order wins. If the
        // least-loaded candidate teaches the sitting's subject,
        // the next-least-loaded non-own-subject teacher is picked.
        $seeded = $this->seededPool();
        $this->assertGreaterThan(0, $seeded->count(), 'Seeded pool must exist to leave out');

        // Subject 19 (class 4) is taught by a seeded teacher; use
        // that teacher as the least-loaded candidate by leaving
        // everyone else out EXCEPT that teacher and one fresh
        // teacher with a load duty.
        $subjectTeacher = User::where('id', function ($q) {
            $q->select('teacher_id')->from('subjects')->where('id', 19);
        })->first();
        $this->assertNotNull($subjectTeacher, 'Subject 19 must have a teacher');
        $this->assertEquals('teacher', $subjectTeacher->user_type);
        // Guard against vacuity: the subject teacher must actually
        // be a pool candidate (active), or the test would pass by
        // having only one candidate at all.
        $this->assertTrue(
            $seeded->contains('id', $subjectTeacher->id),
            'Subject 19 teacher must be in the active pool'
        );

        $loaded = $this->makeTeacher('BBB R19 LoadedTwo', 'R19LOAD2', 'r19load2@probe.test');
        $this->duty($this->sitting(1, 1, '2026-11-20')->id, $loaded->id);

        // Leave out every seeded teacher EXCEPT the subject
        // teacher, so candidates are exactly: subject teacher
        // (0 duties, but own-subject) and the fresh teacher
        // (1 duty, non-own-subject).
        foreach ($seeded as $teacher) {
            if ($teacher->id === $subjectTeacher->id) {
                continue;
            }
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'start_date' => '2026-11-10',
                'end_date' => '2026-11-10',
                'reason' => 'probe',
                'leave_type' => 'normal',
                'status' => 'approved',
            ]);
        }

        $target = $this->sitting(4, 19, '2026-11-10');

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 1 sitting(s) got an invigilator. 1 sitting(s) filled while other teachers were on approved leave.'
        );

        // The 0-duty own-subject teacher is SKIPPED in favour of
        // the 1-duty non-own-subject teacher — fewest-first is
        // subordinate to the own-subject preference.
        $duty = ExamDuty::where('exam_date_id', $target->id)->firstOrFail();
        $this->assertEquals($loaded->id, $duty->teacher_id);
        $this->assertEquals(0, ExamDuty::where('teacher_id', $subjectTeacher->id)->count());
    }
}
