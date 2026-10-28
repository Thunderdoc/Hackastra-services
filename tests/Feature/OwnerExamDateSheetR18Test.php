<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 18) — auto-assign
 * DOUBLE-BOOKING guard. Manual assign refuses clashes (round 16:
 * exact flash_danger 'That teacher already has a duty at this
 * date and time.'), but auto-assign's own clash handling was
 * never proven: the candidate filter reads Invigilation::hasClash
 * (a live DB query), and duties are created inside the loop, so
 * a teacher assigned to sitting A must be invisible to sitting B
 * at the same date + start time — but that in-loop visibility was
 * assumed, never tested. These tests prove it, plus the
 * fully-clashed unfilled branch and respect for a pre-existing
 * manual duty.
 *
 * Dates are relative to the real today (2026-10-28): every
 * sitting below is future (2026-11-10), so the past-skip branch
 * (round 2) cannot interfere. Exam 1 is the global Term 1 exam.
 * The snapshot DB holds agent B round 8's exam-2 probe rows, but
 * auto-assign filters by exam_id, so the exam-1 universe here is
 * exactly the fixtures below. Subject/class pairs: my_class_id
 * 1..11 carry subjects 6(m-1)+1..6m, class 12 (Class 11) carries
 * 67..78, class 13 (Class 12) carries 79..90.
 */
class OwnerExamDateSheetR18Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function pool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get();
    }

    protected function sitting(int $classId, int $subjectId, string $date = '2026-11-10', string $time = '09:00')
    {
        return ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => $date,
            'start_time' => $time,
        ]);
    }

    /** Every distinct (class, subject) pair the seed defines. */
    protected function classSubjectPairs(): array
    {
        $pairs = [];
        for ($m = 1; $m <= 11; $m++) {
            for ($s = 6 * ($m - 1) + 1; $s <= 6 * $m; $s++) {
                $pairs[] = [$m, $s];
            }
        }
        for ($s = 67; $s <= 78; $s++) {
            $pairs[] = [12, $s];
        }
        for ($s = 79; $s <= 90; $s++) {
            $pairs[] = [13, $s];
        }
        return $pairs;
    }

    protected function auto()
    {
        return $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
    }

    public function test_auto_assign_same_slot_gets_different_teachers()
    {
        $a = $this->sitting(1, 1);
        $b = $this->sitting(2, 7);

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 2 sitting(s) got an invigilator.'
        );

        // DB proof: both sittings filled, by DIFFERENT teachers —
        // the hard rule holds inside the automatic path, and the
        // flash count (2) matches reality exactly.
        $this->assertEquals(2, ExamDuty::count());
        $dutyA = ExamDuty::where('exam_date_id', $a->id)->firstOrFail();
        $dutyB = ExamDuty::where('exam_date_id', $b->id)->firstOrFail();
        $this->assertNotEquals($dutyA->teacher_id, $dutyB->teacher_id);
        $this->assertEquals(1, ExamDuty::where('teacher_id', $dutyA->teacher_id)->count());
        $this->assertEquals(1, ExamDuty::where('teacher_id', $dutyB->teacher_id)->count());
    }

    public function test_auto_assign_unfilled_when_whole_pool_clashes()
    {
        $pool = $this->pool();
        $pairs = $this->classSubjectPairs();
        $this->assertGreaterThanOrEqual(
            $pool->count(), count($pairs),
            'Not enough distinct (class, subject) pairs to clash the whole pool'
        );

        // Give EVERY pool teacher a duty at the target slot, each
        // on a distinct sitting (distinct pair, same date + time).
        foreach ($pool as $i => $teacher) {
            [$classId, $subjectId] = $pairs[$i];
            $sitting = $this->sitting($classId, $subjectId);
            ExamDuty::create([
                'exam_date_id' => $sitting->id,
                'teacher_id' => $teacher->id,
                'assigned_by' => $this->admin()->id,
            ]);
        }
        $preFilled = ExamDuty::count();
        $this->assertEquals($pool->count(), $preFilled);

        // Target sitting: same slot, a pair no pool teacher holds.
        [$tClass, $tSubject] = $pairs[$pool->count()];
        $target = $this->sitting($tClass, $tSubject);

        $expected = 'Auto-assignment complete: 0 sitting(s) got an invigilator.'
            . ' Could not fill: 2026-11-10 09:00 — every teacher already has a duty at this time (or no teachers exist).';

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_danger', $expected);

        // Rendered outcome: the duties page carries the exact toast.
        $this->get(route('exam_duties.index'))
            ->assertOk()
            ->assertSee("flash({msg : '{$expected}', type : 'danger'})", false);

        // DB proof: target unfilled, pre-filled duties untouched.
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $target->id]);
        $this->assertEquals($preFilled, ExamDuty::count());
    }

    public function test_auto_assign_respects_pre_existing_manual_duty()
    {
        $pool = $this->pool();
        $this->assertNotEmpty($pool, 'No live teachers in pool — fixture broken');

        $a = $this->sitting(1, 1);
        $b = $this->sitting(2, 7);

        // Manual duty first (via assign(), which enforces the
        // clash rule itself): teacher X holds sitting A.
        [$ok] = Invigilation::assign($a, $pool->first()->id, $this->admin()->id);
        $this->assertTrue($ok);
        $manualTeacher = $pool->first()->id;

        $response = $this->auto();
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas(
            'flash_success',
            'Auto-assignment complete: 1 sitting(s) got an invigilator.'
        );

        // Sitting A is skipped (already assigned); sitting B is
        // filled by someone OTHER than the manual holder.
        $this->assertEquals(2, ExamDuty::count());
        $dutyB = ExamDuty::where('exam_date_id', $b->id)->firstOrFail();
        $this->assertNotEquals($manualTeacher, $dutyB->teacher_id);
        $this->assertEquals(1, ExamDuty::where('teacher_id', $manualTeacher)->count());
    }
}
