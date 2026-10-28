<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\ExamRecord;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 41) — END-TO-END EXAM
 * LIFECYCLE WORKFLOW. Every link of this chain was previously
 * proven only in isolation (E31 write guards, E32 read guards,
 * E33 venue, E36 auto-assign determinism, publish switch); this
 * round runs ONE continuous workflow through the REAL routes
 * with a fresh class-scoped exam, so a scope leak at any
 * handoff between links would surface here (pattern P2: scope
 * gap across a whole chain; P7: flash honesty at each step).
 *
 * Chain (single test flow, admin + X/Y students + X/Y parents):
 *  (a) Admin CREATES the scoped exam for class X via exams.store
 *      (term 2 — term 1 is blocked by the seeded global exam's
 *      partition rule); scope is FIXED at creation (update
 *      ignores a posted my_class_id).
 *  (b) Generate X's sheet with venue + 2 papers/day -> 2 rows,
 *      venue in note; generate Y under the same exam -> refused
 *      with the scope message, zero Y rows, X rows untouched.
 *  (c) Auto-assign X sittings -> 2 duties, deterministic
 *      name-order mapping (R36); duties index for the exam
 *      shows exactly the 2 X sittings, zero Y sittings, no error.
 *  (d) Admit cards: X student myCard renders papers + dates
 *      (venue absent from the card = the R33 known gap, noted
 *      not re-litigated); Y student myCard -> scope refusal;
 *      bulk print X renders, bulk print Y -> scope refusal.
 *  (e) Publish: X student show is not_published before publish
 *      and lists the exam after; X parent likewise; Y student
 *      (records under a published GLOBAL exam G) lists G but
 *      NEVER the X exam; Y parent likewise; X print renders,
 *      Y print for the X exam -> dashboard (no record).
 *  (f) Date-sheet print X renders venue; print Y -> scope
 *      refusal. Show X renders venue too.
 *
 * Fixture rules: fresh classes/subjects/sections/students/
 * parents (all in-transaction, rolled back); fixture subjects
 * point at user 1 (super_admin, never in the invigilator pool)
 * so auto-assign has no own-subject preference and the mapping
 * is pure name order (R36 trick); fixture students carry NO
 * payment records, so the fee block never masks a step.
 */
class OwnerExamDateSheetR41Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClass($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name, 'class_type_id' => 4]);
        $section = Section::create(['name' => 'R41-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName, 'my_class_id' => $class->id,
                'teacher_id' => 1, 'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $section, $created];
    }

    protected function makeParent($name)
    {
        return User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'parent', 'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('parent'), 'username' => 'TEST/R41/P' . strtoupper(Str::random(6)),
        ]);
    }

    protected function makeStudent($name, $classId, $sectionId, $parentId = null)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'), 'username' => 'TEST/R41/' . strtoupper(Str::random(6)),
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'adm_no' => 'TEST/R41/' . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0, 'my_parent_id' => $parentId,
        ]);
        return $user;
    }

    protected function giveRecords($student, $exam, $classId, $sectionId, $subjects)
    {
        ExamRecord::create([
            'exam_id' => $exam->id, 'my_class_id' => $classId,
            'student_id' => $student->id, 'section_id' => $sectionId,
            'year' => '2026-2027',
        ]);
        foreach ($subjects as $subject) {
            Mark::create([
                'student_id' => $student->id, 'exam_id' => $exam->id,
                'subject_id' => $subject->id, 'my_class_id' => $classId,
                'section_id' => $sectionId, 'year' => '2026-2027',
                't1' => 15, 't2' => 16, 'exm' => 40,
            ]);
        }
    }

    protected function generateParams($examId, $classId)
    {
        return [
            'exam_id' => $examId, 'scope' => 'one', 'class_id' => $classId,
            'start_date' => '2026-12-01', 'exam_days' => 2, 'papers_per_day' => 2,
            'session1_time' => '09:00', 'session2_time' => '13:00',
            'venue' => 'R41 Main Hall', 'skip_sunday' => 0,
            'gap_every' => 0, 'gap_days' => 0, 'skip_dates' => '',
            'mode' => 'replace',
        ];
    }

    public function test_full_exam_lifecycle_chain_holds_scope_at_every_handoff()
    {
        [$classX, $sectionX, $subjectsX] = $this->makeClass('R41 Chain X Class', ['R41-XA', 'R41-XB'], 'r41-x');
        [$classY, $sectionY, $subjectsY] = $this->makeClass('R41 Chain Y Class', ['R41-YA', 'R41-YB'], 'r41-y');
        $parentX = $this->makeParent('R41 X Parent');
        $parentY = $this->makeParent('R41 Y Parent');
        $studentX = $this->makeStudent('R41 X Pupil', $classX->id, $sectionX->id, $parentX->id);
        $studentY = $this->makeStudent('R41 Y Pupil', $classY->id, $sectionY->id, $parentY->id);
        $scopeMsg = 'This exam is scoped to R41 Chain X Class — it cannot carry dates for R41 Chain Y Class.';

        // (a) CREATE the scoped exam through the real route. Term 2:
        // term 1 is taken by the seeded global exam, and the
        // partition rule refuses a class exam under a global term.
        $this->actingAs($this->admin());
        $resp = $this->post(route('exams.store'), [
            'name' => 'R41 Chain Exam', 'term' => 2, 'my_class_id' => $classX->id,
        ]);
        $resp->assertRedirect(route('exams.index'));
        $exam = Exam::where('name', 'R41 Chain Exam')->firstOrFail();
        $this->assertSame((int) $classX->id, (int) $exam->my_class_id, 'Exam created scoped to X');
        $this->assertFalse((bool) $exam->results_published, 'Fresh exam starts unpublished');

        // Scope is FIXED at creation: update accepts name/term only,
        // a posted my_class_id for Y is ignored, not applied.
        $resp = $this->put(route('exams.update', ['exam' => $exam->id]), [
            'name' => 'R41 Chain Exam', 'term' => 2, 'my_class_id' => $classY->id,
        ]);
        $resp->assertRedirect(route('exams.index'));
        $this->assertSame((int) $classX->id, (int) $exam->fresh()->my_class_id,
            'Scope survives an update carrying a foreign my_class_id');

        // Global exam G (term 3) for the Y student's mark records.
        $resp = $this->post(route('exams.store'), [
            'name' => 'R41 Global Exam', 'term' => 3, 'my_class_id' => 0,
        ]);
        $resp->assertRedirect(route('exams.index'));
        $global = Exam::where('name', 'R41 Global Exam')->firstOrFail();
        $this->assertSame(0, (int) $global->my_class_id);

        $this->giveRecords($studentX, $exam, $classX->id, $sectionX->id, $subjectsX);
        $this->giveRecords($studentY, $global, $classY->id, $sectionY->id, $subjectsY);

        // (b) GENERATE X's sheet with venue; Y under the same exam
        // is refused with zero rows and X untouched (E31 in-chain).
        $resp = $this->post(route('exam_dates.generate'), $this->generateParams($exam->id, $classX->id));
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id]));
        $resp->assertSessionHas('flash_success');
        $rowsX = ExamDate::where('exam_id', $exam->id)->where('my_class_id', $classX->id)->orderBy('exam_date')->orderBy('start_time')->get();
        $this->assertSame(2, $rowsX->count(), 'X gets exactly its 2 papers');
        foreach ($rowsX as $row) {
            $this->assertSame('R41 Main Hall', $row->note, 'Venue stored in note on every X row');
            $this->assertSame('2026-12-01', $row->exam_date, 'Both papers land on day 1 (2 papers/day)');
        }
        $this->assertSame(['09:00', '13:00'], $rowsX->pluck('start_time')->map(fn ($t) => substr((string) $t, 0, 5))->values()->all());

        $resp = $this->post(route('exam_dates.generate'), $this->generateParams($exam->id, $classY->id));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame($scopeMsg, session('flash_danger'));
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->where('my_class_id', $classY->id)->count(),
            'Refused Y generate writes nothing');
        $this->assertSame(2, ExamDate::where('exam_id', $exam->id)->where('my_class_id', $classX->id)->count(),
            'Refused Y generate touches nothing for X');

        // (c) AUTO-ASSIGN X sittings: deterministic name-order
        // mapping (R36), 2 duties, distinct teachers.
        $pool = Invigilation::pool();
        $this->assertTrue($pool->count() >= 2, 'Pool must hold at least 2 teachers for the mapping check');
        $resp = $this->post(route('exam_duties.auto'), ['exam_id' => $exam->id]);
        $resp->assertSessionHas('flash_success');
        $this->assertStringContainsString('2 sitting(s) got an invigilator', session('flash_success'));
        $duties = ExamDuty::whereIn('exam_date_id', $rowsX->pluck('id'))->orderBy('exam_date_id')->get();
        $this->assertSame(2, $duties->count(), 'Both X sittings get an invigilator');
        $this->assertSame(2, $duties->pluck('teacher_id')->unique()->count(), 'No teacher double-booked across the 2 sittings');
        $bySitting = [];
        foreach ($rowsX as $row) {
            $bySitting[$row->id] = $duties->firstWhere('exam_date_id', $row->id)->teacher_id;
        }
        $this->assertSame((int) $pool[0]->id, (int) $bySitting[$rowsX[0]->id],
            'First sitting (09:00) goes to the name-first pool teacher');
        $this->assertSame((int) $pool[1]->id, (int) $bySitting[$rowsX[1]->id],
            'Second sitting (13:00) goes to the next pool teacher (fewest-first)');

        // Duties surface for the exam: exactly the 2 X sittings,
        // zero Y sittings, no error (P5: index agrees with rows).
        $resp = $this->get(route('exam_duties.index', ['exam_id' => $exam->id]));
        $resp->assertOk();
        $sittings = $resp->viewData('sittings');
        $this->assertSame(2, $sittings->count(), 'Duties index shows exactly the X sittings');
        $this->assertTrue($sittings->every(fn ($s) => (int) $s->my_class_id === (int) $classX->id),
            'No Y sitting appears on the duties surface');
        $resp->assertDontSee('R41 Chain Y Class');

        // (d) ADMIT CARDS. X student: papers + dates render.
        $resp = $this->actingAs($studentX)->get(route('admit_cards.my_card', ['exam' => $exam->id, 'student_id' => $studentX->id]));
        $resp->assertOk();
        $resp->assertSee('R41 X Pupil');
        $resp->assertSee('R41-XA');
        $resp->assertSee('R41-XB');
        $resp->assertSee('Tue, 01 Dec 2026');
        $resp->assertSee('9:00 AM');
        // R33 known gap, noted not re-litigated: the card carries
        // no venue even though the sheet does.
        $resp->assertDontSee('R41 Main Hall');

        // Y student myCard for the X exam: scope refusal (E32).
        $resp = $this->actingAs($studentY)->get(route('admit_cards.my_card', ['exam' => $exam->id, 'student_id' => $studentY->id]));
        $resp->assertRedirect(route('admit_cards.my'));
        $this->assertSame($scopeMsg, session('flash_danger'));

        // Bulk print: X renders, Y refuses.
        $resp = $this->actingAs($this->admin())->get(
            route('admit_cards.print', ['exam' => $exam->id, 'class' => $classX->id]) . '?section_id=' . $sectionX->id);
        $resp->assertOk();
        $resp->assertSee('R41 X Pupil');
        $resp->assertSee('R41-XA');
        $resp = $this->get(
            route('admit_cards.print', ['exam' => $exam->id, 'class' => $classY->id]) . '?section_id=' . $sectionY->id);
        $resp->assertRedirect(route('admit_cards.index'));
        $this->assertSame($scopeMsg, session('flash_danger'));

        // (e) PUBLISH. X student show is not_published first.
        $resp = $this->actingAs($studentX)->get(route('marks.show', [Qs::hash($studentX->id), '2026-2027']));
        $resp->assertViewIs('pages.support_team.marks.not_published');
        $resp->assertDontSee('R41 Chain Exam');

        $this->actingAs($this->admin());
        $this->from(route('exams.index'));
        $resp = $this->post(route('exams.publish_results', ['exam' => $exam->id]));
        $resp->assertRedirect(route('exams.index'));
        $this->assertStringContainsString('Results published', session('flash_success'));
        $this->assertTrue((bool) $exam->fresh()->results_published, 'Publish flips the switch');

        $resp = $this->actingAs($studentX)->get(route('marks.show', [Qs::hash($studentX->id), '2026-2027']));
        $resp->assertOk();
        $resp->assertSee('R41 Chain Exam');
        $resp = $this->actingAs($parentX)->get(route('marks.show', [Qs::hash($studentX->id), '2026-2027']));
        $resp->assertOk();
        $resp->assertSee('R41 Chain Exam');

        // Y's sheet renders (via published global G) but NEVER
        // lists the X exam — the scope partition through publish.
        $this->actingAs($this->admin());
        $this->from(route('exams.index'));
        $resp = $this->post(route('exams.publish_results', ['exam' => $global->id]));
        $resp->assertRedirect(route('exams.index'));
        $this->assertTrue((bool) $global->fresh()->results_published);

        $resp = $this->actingAs($studentY)->get(route('marks.show', [Qs::hash($studentY->id), '2026-2027']));
        $resp->assertOk();
        $resp->assertSee('R41 Global Exam');
        $resp->assertDontSee('R41 Chain Exam');
        $resp = $this->actingAs($parentY)->get(route('marks.show', [Qs::hash($studentY->id), '2026-2027']));
        $resp->assertOk();
        $resp->assertSee('R41 Global Exam');
        $resp->assertDontSee('R41 Chain Exam');

        // Print: X renders; Y printing the X exam has no record
        // for it and lands on the dashboard, never on a card.
        $resp = $this->actingAs($studentX)->get(route('marks.print', [Qs::hash($studentX->id), $exam->id, '2026-2027']));
        $resp->assertOk();
        $resp->assertSee('R41 Chain Exam');
        $resp = $this->actingAs($studentY)->get(route('marks.print', [Qs::hash($studentY->id), $exam->id, '2026-2027']));
        $resp->assertRedirect(route('dashboard'));
        $resp->assertSessionHas('flash_danger');

        // (f) DATE-SHEET PRINT. X renders venue; Y refuses (E32).
        $resp = $this->actingAs($this->admin())->get(
            route('exam_dates.print') . '?exam_id=' . $exam->id . '&class_id=' . $classX->id);
        $resp->assertOk();
        $resp->assertSee('R41 Main Hall');
        $resp->assertSee('R41-XA');
        $resp = $this->get(
            route('exam_dates.print') . '?exam_id=' . $exam->id . '&class_id=' . $classY->id);
        $resp->assertRedirect(route('exam_dates.index'));
        $this->assertSame($scopeMsg, session('flash_danger'));

        // Screen show for X renders the venue too.
        $resp = $this->get(
            route('exam_dates.index') . '?exam_id=' . $exam->id . '&class_id=' . $classX->id);
        $resp->assertOk();
        $resp->assertSee('R41 Main Hall');
    }
}
