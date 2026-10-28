<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 20) — auto-assign
 * OWN-SUBJECT-ONLY pool edge. This round CORRECTS its own
 * brief's premise, stated openly: the brief expected the
 * sitting to stay UNFILLED when only the sitting subject's
 * own teacher remains eligible. The code says otherwise, and
 * the code is right:
 *
 *   $best = $candidates->first(fn ($t) =>
 *       !self::isOwnSubject($t->id, $sitting))
 *       ?: $candidates->first();
 *
 * The own-subject rule in auto-assign is a PREFERENCE with a
 * fallback, not a hard exclusion. If no non-own-subject
 * candidate exists, the sitting IS filled by the own-subject
 * teacher rather than left without an invigilator. This does
 * NOT contradict round 19 test 3 (there a non-own-subject
 * alternative existed, and the preference picked it). It also
 * cannot produce a misleading "every teacher already has a
 * duty" reason, because no unfilled reason is produced at all
 * — the sitting is filled.
 *
 * Note that isOwnSubject() keys off subjects.teacher_id only,
 * so at most one pool member is own-subject for any sitting.
 * Design observation, NOT a defect: unlike manual assign
 * (round 14: the own-subject warning renders on the duties
 * page), auto-assign gives no signal that the filler was the
 * own-subject teacher — the flash only notes leave-shaped
 * pools. Whether that deserves a flash note is an owner call;
 * a hall with an own-subject invigilator beats an empty hall,
 * and the docblock's rule says "preferably not".
 *
 * Isolation trick (round 19): every SEEDED pool teacher except
 * the named subject teacher(s) is put on approved leave
 * covering the target dates, so the candidate set is exactly
 * the subject teacher(s). Leave rows are direct creates (no
 * controller, no bells), and everything rolls back. The
 * seeded pool MUST be captured before any probe is created.
 */
class OwnerExamDateSheetR20Test extends TestCase
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

    protected function subjectTeacher(int $subjectId)
    {
        $teacher = User::where('id', function ($q) use ($subjectId) {
            $q->select('teacher_id')->from('subjects')->where('id', $subjectId);
        })->first();
        $this->assertNotNull($teacher, "Subject {$subjectId} must have a teacher");
        $this->assertEquals('teacher', $teacher->user_type);
        return $teacher;
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

    protected function leaveOutExcept($seeded, array $keepIds, string $date)
    {
        foreach ($seeded as $teacher) {
            if (in_array($teacher->id, $keepIds, true)) {
                continue;
            }
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'start_date' => $date,
                'end_date' => $date,
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

    public function test_auto_assign_fills_with_own_subject_teacher_when_no_alternative_exists()
    {
        $seeded = $this->seededPool();
        $this->assertGreaterThan(0, $seeded->count(), 'Seeded pool must exist to leave out');

        // Subject 19 (class 4) is taught by a seeded teacher; keep
        // ONLY that teacher eligible for the target date.
        $subjectTeacher = $this->subjectTeacher(19);
        $this->assertTrue(
            $seeded->contains('id', $subjectTeacher->id),
            'Subject 19 teacher must be in the active pool, or the test would pass vacuously'
        );

        $this->leaveOutExcept($seeded, [$subjectTeacher->id], '2026-11-10');

        $target = $this->sitting(4, 19, '2026-11-10');

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        // The sitting is FILLED (fallback), not unfilled: the
        // leave note appears because leave shaped the pool, and
        // no "Could not fill" clause appears at all.
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 1 sitting(s) got an invigilator. 1 sitting(s) filled while other teachers were on approved leave.'
        );

        // DB proof: exactly one duty, held by the own-subject
        // teacher — the fallback, not a leak and not an empty hall.
        $duty = ExamDuty::where('exam_date_id', $target->id)->firstOrFail();
        $this->assertEquals($subjectTeacher->id, $duty->teacher_id);
        $this->assertEquals(1, ExamDuty::where('exam_date_id', $target->id)->count());
        // The filler is belled exactly like any other assignee.
        $this->assertEquals(1, UserNotification::where('user_id', $subjectTeacher->id)
            ->where('type', 'duty_assigned')->count());
    }

    public function test_auto_assign_fills_each_sitting_with_its_own_subject_teacher_when_pool_is_only_them()
    {
        $seeded = $this->seededPool();
        $this->assertGreaterThan(0, $seeded->count(), 'Seeded pool must exist to leave out');

        // Two sittings on DIFFERENT dates, different subjects,
        // different subject teachers. The leave-out is
        // date-scoped, so each sitting's candidate set contains
        // ONLY its own subject teacher: putting both sittings
        // on one date would leave both teachers eligible for
        // both sittings, and the non-own-subject preference
        // would swap them (each teacher is non-own for the
        // other's sitting) — that would test the preference,
        // not the fallback.
        $teacher19 = $this->subjectTeacher(19);
        $teacher25 = $this->subjectTeacher(25);
        $this->assertNotEquals($teacher19->id, $teacher25->id, 'The two sittings need distinct subject teachers');
        $this->assertTrue($seeded->contains('id', $teacher19->id));
        $this->assertTrue($seeded->contains('id', $teacher25->id));

        $this->leaveOutExcept($seeded, [$teacher19->id], '2026-11-10');
        $this->leaveOutExcept($seeded, [$teacher25->id], '2026-11-11');

        $s1 = $this->sitting(4, 19, '2026-11-10');
        $s2 = $this->sitting(5, 25, '2026-11-11');

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 2 sitting(s) got an invigilator. 2 sitting(s) filled while other teachers were on approved leave.'
        );

        // Each sitting went to ITS own subject teacher — the
        // fallback is per-sitting, not a single-teacher sweep.
        $this->assertEquals(
            $teacher19->id,
            ExamDuty::where('exam_date_id', $s1->id)->firstOrFail()->teacher_id
        );
        $this->assertEquals(
            $teacher25->id,
            ExamDuty::where('exam_date_id', $s2->id)->firstOrFail()->teacher_id
        );
        $this->assertEquals(2, ExamDuty::whereIn('exam_date_id', [$s1->id, $s2->id])->count());
    }
}
