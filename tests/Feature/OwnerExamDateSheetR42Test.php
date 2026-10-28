<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 42) — end-to-end invigilation
 * duties WORKFLOW (audit pattern P15: chain handoff). The duty links
 * were each proven separately — bell replacement (round 8), duty
 * chains (round 9), leave warning (round 6), clash refusal (rounds
 * 18/28), past visibility (round 27), determinism (round 36) — but
 * never as ONE chain. This suite runs one continuous workflow through
 * the REAL routes and asserts scope/counts at every handoff.
 *
 * Code under test (quoted, not assumed):
 * - ExamDutyController::assign: pool membership first ('That teacher
 *   is not in the invigilator pool (must be an active teacher).'),
 *   then Invigilation::assign() (clash rule + updateOrCreate), then
 *   flash 'Invigilator saved.' + own-subject / on-leave notes.
 * - Invigilation::assign(): re-saving the SAME teacher is not a
 *   clash and rings NO new bell; a replacement DELETES exactly the
 *   old teacher's bell whose body matches this sitting
 *   (dutyBellBody: class — subject on date at HH:MM) and rings the
 *   new teacher; bells for sittings the old teacher still holds are
 *   untouched because the body names this sitting.
 * - Invigilation::autoAssign(): skips assigned + past sittings,
 *   excludes approved-leave teachers, fewest-duties-first with a
 *   stable sortBy over the name-ordered pool (round 36: the tie-break
 *   IS name order); hasClash() is date + start_time only, so it is
 *   cross-class by construction.
 * - ExamDutyController::myDuties(): teachers only (others redirect
 *   to dashboard), own duties sorted by date + time, NO date filter.
 * - Routes (web.php): duties have index / assign / auto / my ONLY —
 *   there is NO delete route, so re-assignment is the only removal.
 *
 * Fixtures: one fresh global exam, two fresh classes (K, K2), five
 * fresh subjects all pointing at the admin id (so NO pool teacher is
 * own-subject for any sitting — the own-subject preference leg is
 * deliberately out of this chain; it is proven in round 36). Seeded
 * live teachers A (Ananya, id 4), B (Priya, id 12), L (Arun, id 16);
 * L carries an approved leave covering ONLY the S2 date. Dates are
 * computed from Carbon::today() so the future property never rots.
 * Each test runs inside its own transaction.
 */
class OwnerExamDateSheetR42Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function priya()
    {
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function arun()
    {
        return User::where('email', 'teacher3@teacher.com')->firstOrFail();
    }

    protected function suspendedTeacher()
    {
        return User::create([
            'name' => 'R42 Suspended',
            'email' => 'r42suspended@teacher.com',
            'password' => bcrypt('teacher'),
            'code' => 'R42SUSP',
            'user_type' => 'teacher',
            'status' => 'suspended',
        ]);
    }

    protected function assignPost($sittingId, $teacherId, $examId)
    {
        $this->actingAs($this->admin());
        return $this->from(route('exam_duties.index', ['exam_id' => $examId]))
            ->post(route('exam_duties.assign'), [
                'exam_date_id' => $sittingId,
                'teacher_id' => $teacherId,
            ]);
    }

    protected function bellCount($teacherId, ExamDate $sitting)
    {
        return UserNotification::where('user_id', $teacherId)
            ->where('type', 'duty_assigned')
            ->where('body', Invigilation::dutyBellBody($sitting->fresh(['my_class', 'subject'])))
            ->count();
    }

    /** First pool teacher eligible for a sitting under the auto rule, computed from the rule (not by calling autoAssign). */
    protected function expectedWinner(ExamDate $sitting, $examId, array $excludeIds = [])
    {
        $counts = ExamDuty::whereIn('exam_date_id',
            ExamDate::where('exam_id', $examId)->pluck('id'))
            ->select('teacher_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'))
            ->groupBy('teacher_id')->pluck('c', 'teacher_id')->all();
        return Invigilation::pool()
            ->filter(function ($t) use ($sitting, $counts, $excludeIds) {
                return !in_array($t->id, $excludeIds, true)
                    && !Invigilation::hasClash($t->id, $sitting)
                    && !Invigilation::isOnApprovedLeave($t->id, $sitting->exam_date)
                    && !Invigilation::isOwnSubject($t->id, $sitting);
            })
            ->sortBy(function ($t) use ($counts) {
                return $counts[$t->id] ?? 0;
            })
            ->values()
            ->first();
    }

    public function test_full_duties_chain_manual_replace_leave_auto_my_duties_no_delete()
    {
        $admin = $this->admin();
        $a = $this->ananya();
        $b = $this->priya();
        $l = $this->arun();
        $suspended = $this->suspendedTeacher();

        $d0 = Carbon::today()->addDays(29)->toDateString();
        $d1 = Carbon::today()->addDays(30)->toDateString();
        $d2 = Carbon::today()->addDays(31)->toDateString();
        $d3 = Carbon::today()->addDays(32)->toDateString();

        $exam = Exam::create([
            'name' => 'R42 Chain Exam',
            'term' => 97,
            'year' => '2026-2027',
            'my_class_id' => 0,
        ]);
        $classK = MyClass::create(['name' => 'R42 K', 'class_type_id' => 4]);
        $classK2 = MyClass::create(['name' => 'R42 K2', 'class_type_id' => 4]);
        $subjects = [];
        foreach (['R42 Sub E' => $classK->id, 'R42 Sub A' => $classK->id,
                  'R42 Sub B' => $classK->id, 'R42 Sub C' => $classK->id,
                  'R42 Sub D' => $classK2->id] as $name => $classId) {
            $subjects[$name] = Subject::create([
                'name' => $name,
                'my_class_id' => $classId,
                'teacher_id' => $admin->id,
                'slug' => 'r42-' . \Illuminate\Support\Str::slug($name),
                'periods_per_week' => 6,
            ]);
        }
        // S0: A's standing duty (proves replacement bell cleanup is surgical).
        $s0 = ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $classK->id,
            'subject_id' => $subjects['R42 Sub E']->id, 'exam_date' => $d0, 'start_time' => '09:00:00']);
        $s1 = ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $classK->id,
            'subject_id' => $subjects['R42 Sub A']->id, 'exam_date' => $d1, 'start_time' => '09:00:00']);
        $s2 = ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $classK->id,
            'subject_id' => $subjects['R42 Sub B']->id, 'exam_date' => $d2, 'start_time' => '09:00:00']);
        $s3 = ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $classK->id,
            'subject_id' => $subjects['R42 Sub C']->id, 'exam_date' => $d3, 'start_time' => '09:00:00']);
        // S4: SAME slot as S1 but the other class — the cross-class clash probe.
        $s4 = ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $classK2->id,
            'subject_id' => $subjects['R42 Sub D']->id, 'exam_date' => $d1, 'start_time' => '09:00:00']);

        LeaveRequest::create([
            'user_id' => $l->id, 'start_date' => $d2, 'end_date' => $d2,
            'reason' => 'R42 leave', 'leave_type' => 'normal', 'status' => 'approved',
        ]);

        // (pre) A holds S0 — the bell that must SURVIVE the S1 replacement.
        $this->assignPost($s0->id, $a->id, $exam->id)->assertRedirect();
        $this->assertSame(1, $this->bellCount($a->id, $s0));

        // (a) MANUAL ASSIGN S1 -> A: row + exact bell body.
        $response = $this->assignPost($s1->id, $a->id, $exam->id);
        $response->assertRedirect();
        $this->assertSame('Invigilator saved.', $response->getSession()->get('flash_success'));
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $s1->id, 'teacher_id' => $a->id]);
        $this->assertSame(1, $this->bellCount($a->id, $s1));
        $this->assertStringContainsString('R42 K — R42 Sub A on ' . $d1 . ' at 09:00',
            Invigilation::dutyBellBody($s1->fresh(['my_class', 'subject'])));

        // (b) REPLACE S1 A -> B: exactly 1 row, old bell deleted, other bell untouched.
        $response = $this->assignPost($s1->id, $b->id, $exam->id);
        $response->assertRedirect();
        $this->assertSame('Invigilator saved.', $response->getSession()->get('flash_success'));
        $this->assertSame(1, ExamDuty::where('exam_date_id', $s1->id)->count());
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $s1->id, 'teacher_id' => $b->id]);
        $this->assertSame(0, $this->bellCount($a->id, $s1), 'Replaced teacher\'s S1 bell must be deleted.');
        $this->assertSame(1, $this->bellCount($b->id, $s1));
        $this->assertSame(1, $this->bellCount($a->id, $s0), 'A\'s S0 bell must survive the S1 replacement.');
        $this->assertSame(1, ExamDuty::where('exam_date_id', $s0->id)->count());

        // (b2) No-op re-save S1 -> B: no new bell, still 1 row.
        $this->assignPost($s1->id, $b->id, $exam->id)->assertRedirect();
        $this->assertSame(1, $this->bellCount($b->id, $s1));
        $this->assertSame(1, ExamDuty::where('exam_date_id', $s1->id)->count());

        // (negatives, before auto so they cannot disturb chain state)
        // B already holds S1 at d1 09:00 — S4 is the same slot, other class: clash.
        $this->assertTrue(Invigilation::hasClash($b->id, $s4), 'Cross-class same-slot clash must be detected.');
        $response = $this->assignPost($s4->id, $b->id, $exam->id);
        $response->assertRedirect();
        $this->assertSame('That teacher already has a duty at this date and time.',
            $response->getSession()->get('flash_danger'));
        $this->assertSame(0, ExamDuty::where('exam_date_id', $s4->id)->count());
        // Suspended teacher is not in the pool at all.
        $response = $this->assignPost($s3->id, $suspended->id, $exam->id);
        $response->assertRedirect();
        $this->assertSame('That teacher is not in the invigilator pool (must be an active teacher).',
            $response->getSession()->get('flash_danger'));
        $this->assertSame(0, ExamDuty::where('exam_date_id', $s3->id)->count());

        // (c) MANUAL ASSIGN S2 -> L (approved leave on d2): warning note + duty + bell.
        $response = $this->assignPost($s2->id, $l->id, $exam->id);
        $response->assertRedirect();
        $this->assertStringContainsString('Note: this teacher is on approved leave covering this date.',
            (string) $response->getSession()->get('flash_success'));
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $s2->id, 'teacher_id' => $l->id]);
        $this->assertSame(1, $this->bellCount($l->id, $s2));

        // (d) AUTO-ASSIGN: fills exactly S4 + S3 (S0/S1/S2 already assigned).
        $expectedW4 = $this->expectedWinner($s4, $exam->id);
        $this->assertNotNull($expectedW4, 'A winner must exist for S4.');
        $this->assertNotSame($b->id, $expectedW4->id, 'S4 winner must not be the clash-holder B.');
        $this->actingAs($admin);
        $response = $this->from(route('exam_duties.index', ['exam_id' => $exam->id]))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id]);
        $response->assertRedirect();
        $this->assertSame('Auto-assignment complete: 2 sitting(s) got an invigilator.',
            $response->getSession()->get('flash_success'));
        $w4 = ExamDuty::where('exam_date_id', $s4->id)->firstOrFail()->teacher_id;
        $w3 = ExamDuty::where('exam_date_id', $s3->id)->firstOrFail()->teacher_id;
        $this->assertSame($expectedW4->id, $w4, 'S4 auto winner must be the rule\'s first eligible teacher.');
        $this->assertNotSame($w4, $w3, 'S3 winner must differ: W4 now carries a duty for this exam.');
        $this->assertNotSame($b->id, $w4, 'Cross-class clash: S4 winner is not B.');
        $this->assertFalse(Invigilation::isOnApprovedLeave($w4, $d1));
        $this->assertFalse(Invigilation::isOnApprovedLeave($w3, $d3));
        $this->assertSame(1, $this->bellCount($w4, $s4));
        $this->assertSame(1, $this->bellCount($w3, $s3));

        // (d2) Determinism in-chain: delete the auto duties, re-run, same winners.
        // (Bells are append-only, so determinism is asserted on duties, not bells;
        // a duty delete has no UI route, so this re-run is reachable only in-test.)
        ExamDuty::whereIn('exam_date_id', [$s4->id, $s3->id])->delete();
        $this->actingAs($admin);
        $this->from(route('exam_duties.index', ['exam_id' => $exam->id]))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id])->assertRedirect();
        $this->assertSame($w4, ExamDuty::where('exam_date_id', $s4->id)->firstOrFail()->teacher_id);
        $this->assertSame($w3, ExamDuty::where('exam_date_id', $s3->id)->firstOrFail()->teacher_id);

        // (d3) Second auto run with everything assigned fills nothing.
        $response = $this->actingAs($admin)->from(route('exam_duties.index', ['exam_id' => $exam->id]))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id]);
        $response->assertRedirect();
        $this->assertSame('Auto-assignment complete: 0 sitting(s) got an invigilator.',
            $response->getSession()->get('flash_success'));

        // (e) MY DUTIES (P10): each teacher's page lists exactly her stored rows.
        $expected = [
            $a->id => [$s0->id],
            $b->id => [$s1->id],
            $l->id => [$s2->id],
            $w4 => [$s4->id],
            $w3 => [$s3->id],
        ];
        foreach ($expected as $teacherId => $sittingIds) {
            $this->actingAs(User::findOrFail($teacherId));
            $duties = $this->get(route('exam_duties.my'))->assertOk()->viewData('duties');
            $this->assertSame($sittingIds, $duties->pluck('exam_date_id')->all(),
                "My Duties for teacher {$teacherId} must equal stored rows.");
        }
        // Non-teacher guard: admin is bounced to the dashboard.
        $this->actingAs($admin);
        $this->get(route('exam_duties.my'))->assertRedirect(route('dashboard'));

        // (f) NO delete route: re-assignment (step b) is the only removal.
        $this->assertFalse(Route::has('exam_duties.destroy'));
        $this->assertFalse(Route::has('exam_duties.delete'));
        $this->actingAs($admin);
        $this->delete('/exams/duties/' . $s1->id)->assertNotFound();
    }
}
