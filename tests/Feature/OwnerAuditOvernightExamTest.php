<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AUDIT stream AUD-EXAM — Audit Round 1 (overnight batch 2026-10-28/29).
 * Verification ONLY: this file probes, it does not fix anything.
 *
 * Items in scope:
 *   (21) invigilation "Save All" button + row numbering/indexing —
 *        Save All posts only changed rows, keeps per-row clash-check
 *        + audit, reports clashing rows without losing others.
 *   (22) date-sheet approval flow end to end — admin generate/edit
 *        -> submit for principal approval -> principal approves =
 *        final publish -> students/parents see only then (state
 *        machine + read gating + principal approval surface).
 *   Standing rules the flow must not break: scope guard, clash
 *   guard, Manage-edit -> duties follow, clear-paper duty cascade.
 *
 * Probe kinds (read the names, not just pass/fail):
 *   - test_item*_desired_*  asserts the SPECIFIED behaviour. A FAIL
 *     here is a finding: the feature is absent, not a regression.
 *   - test_item*_current_*  asserts CURRENT behaviour. A PASS here
 *     documents the pre-fix state (e.g. sheets are currently
 *     ungated); after S6 lands, these probes must be INVERTED or
 *     replaced by the desired-behaviour probes.
 *   - test_standing_*       asserts rules that already exist. A FAIL
 *     here is a REGRESSION and must be routed immediately.
 *
 * Fixtures mirror OwnerExamDateSheetR31/R45: fresh classes/subjects
 * in-transaction, global exam 1, admin@hackstra.app, teacher@
 * (Ananya), principal@hackstra.app. Dates are in 2027 so the
 * past-date refusal never masks a probe.
 */
class OwnerAuditOvernightExamTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacherA()
    {
        // teacher1@teacher.com (NOT teacher@): fixture subjects carry
        // teacher_id 4 (= teacher@), so using teacher@ would append
        // the own-subject note to the assign flash and mask the probe.
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name]);
        foreach ($subjects as $i => $subjectName) {
            Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return $class;
    }

    protected function makeStudent($name, $classId, $sectionId, $tag)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper($tag . $classId),
            'password' => bcrypt('student'), 'username' => 'TEST/OVN-A/' . $tag . $classId,
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'adm_no' => 'TEST/OVN-A/' . $tag . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0,
        ]);
        return $user;
    }

    protected function generateFor($classId, $examId = 1)
    {
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), [
            'exam_id' => $examId,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => '2027-03-01',
            'exam_days' => 2,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'replace',
            'venue' => 'OVN-A Hall',
        ]);
    }

    protected function assign($sittingId, $teacherId)
    {
        return $this->actingAs($this->admin())
            ->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.assign'), ['exam_date_id' => $sittingId, 'teacher_id' => $teacherId]);
    }

    /* ---------------- Item 21: Save All + numbering ---------------- */

    public function test_item21_desired_duties_index_has_save_all_button()
    {
        $html = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()->getContent();
        $this->assertTrue(
            stripos($html, 'Save All') !== false || stripos($html, 'Submit All') !== false,
            'Duties index must offer a Save All / Submit All bulk action (item 21).'
        );
    }

    public function test_item21_desired_duties_index_has_row_numbering()
    {
        $class = $this->makeClassWithSubjects('OVN-A Num Class', ['OVN-A Num Alpha', 'OVN-A Num Beta'], 'ovn-a-num');
        $this->generateFor($class->id)->assertRedirect();
        $html = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<th[^>]*>\s*(#|No\.?|S\.?\s?No\.?)\s*<\/th>/i',
            $html,
            'Duties table must carry a row-numbering column (item 21 indexing).'
        );
    }

    public function test_item21_desired_save_all_route_exists()
    {
        // UPDATED (FINAL-FOLLOWUP-A, S6 landed): the Save All route
        // is the landed exam_duties.assign_bulk — POST
        // exams/duties/assign-bulk — not the pre-S6 candidate names.
        // Intent unchanged: a bulk Save All route for duties exists.
        $this->assertTrue(Route::has('exam_duties.assign_bulk'),
            'No bulk Save All route for duties exists (expected exam_duties.assign_bulk).');
        $route = Route::getRoutes()->getByName('exam_duties.assign_bulk');
        $this->assertContains('POST', $route->methods());
        $this->assertSame('exams/duties/assign-bulk', $route->uri());
    }

    public function test_item21_current_per_row_assign_still_clash_checked_with_audit()
    {
        $class1 = $this->makeClassWithSubjects('OVN-A Clash1 Class', ['OVN-A Clash1 Subj'], 'ovn-a-c1');
        $class2 = $this->makeClassWithSubjects('OVN-A Clash2 Class', ['OVN-A Clash2 Subj'], 'ovn-a-c2');
        $s1 = ExamDate::create(['exam_id' => 1, 'my_class_id' => $class1->id,
            'subject_id' => Subject::where('my_class_id', $class1->id)->firstOrFail()->id,
            'exam_date' => '2027-04-01', 'start_time' => '09:00:00']);
        $s2 = ExamDate::create(['exam_id' => 1, 'my_class_id' => $class2->id,
            'subject_id' => Subject::where('my_class_id', $class2->id)->firstOrFail()->id,
            'exam_date' => '2027-04-01', 'start_time' => '09:00:00']);
        $teacher = $this->teacherA();

        $this->assign($s1->id, $teacher->id)->assertRedirect()
            ->assertSessionHas('flash_success', 'Invigilator saved.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.duty_assigned']);

        // Same teacher, same date+time, second sitting: clash-refused, first duty untouched.
        $this->assign($s2->id, $teacher->id)->assertRedirect()
            ->assertSessionHas('flash_danger', 'That teacher already has a duty at this date and time.');
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $s2->id, 'teacher_id' => $teacher->id]);
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $s1->id, 'teacher_id' => $teacher->id]);
    }

    public function test_item21_current_reassign_same_teacher_is_noop_single_row()
    {
        // Changed-rows-only analogue at the level that exists today:
        // re-saving the SAME teacher must not duplicate the row or re-ring the bell.
        $class = $this->makeClassWithSubjects('OVN-A Noop Class', ['OVN-A Noop Subj'], 'ovn-a-noop');
        $sitting = ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id,
            'subject_id' => Subject::where('my_class_id', $class->id)->firstOrFail()->id,
            'exam_date' => '2027-04-02', 'start_time' => '09:00:00']);
        $teacher = $this->teacherA();
        [$ok1] = Invigilation::assign($sitting, (int) $teacher->id, (int) $this->admin()->id);
        $this->assertTrue($ok1);
        [$ok2] = Invigilation::assign($sitting, (int) $teacher->id, (int) $this->admin()->id);
        $this->assertTrue($ok2);
        $this->assertSame(1, ExamDuty::where('exam_date_id', $sitting->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $teacher->id)
            ->where('type', 'duty_assigned')->where('body', Invigilation::dutyBellBody($sitting->fresh(['my_class', 'subject'])))->count());
    }

    /* ---------------- Item 22: approval flow ---------------- */

    public function test_item22_desired_datesheet_approval_route_exists()
    {
        $candidates = ['exam_dates.submit', 'exam_dates.submit_for_approval', 'exam_dates.approve',
            'exam_dates.publish', 'exam_dates.approval', 'exam_dates.submit_approval'];
        $found = array_filter($candidates, function ($name) { return Route::has($name); });
        $this->assertNotEmpty($found, 'No date-sheet submit/approve/publish route exists (tried: ' . implode(', ', $candidates) . ').');
    }

    public function test_item22_desired_approval_state_machine_exists()
    {
        // INVERTED (FINAL-VERIFY-A, S6 landed): the dedicated
        // approval state machine now exists — one row per
        // (exam, class) in exam_date_sheet_states, draft ->
        // submitted -> published; no row means draft. It is NOT
        // exams.results_published (the marks switch).
        $this->assertTrue(Schema::hasTable('exam_date_sheet_states'),
            'exam_date_sheet_states table is missing');
        $this->assertTrue(Schema::hasColumn('exam_date_sheet_states', 'status'),
            'exam_date_sheet_states.status is missing');
        $this->assertTrue(Schema::hasColumn('exam_date_sheet_states', 'exam_id'),
            'exam_date_sheet_states.exam_id is missing');
        $this->assertTrue(Schema::hasColumn('exam_date_sheet_states', 'my_class_id'),
            'exam_date_sheet_states.my_class_id is missing');
        $this->assertSame('draft', \App\Models\ExamDateSheetState::DRAFT);
        $this->assertSame('submitted', \App\Models\ExamDateSheetState::SUBMITTED);
        $this->assertSame('published', \App\Models\ExamDateSheetState::PUBLISHED);
        $class = $this->makeClassWithSubjects('OVN-A State Class', ['OVN-A State Subj'], 'ovn-a-state');
        $this->assertSame('draft', \App\Models\ExamDateSheetState::statusFor(1, $class->id),
            'No state row must mean draft.');
    }

    public function test_item22_desired_unpublished_sheet_hidden_from_student_read()
    {
        $class = $this->makeClassWithSubjects('OVN-A Gate Class', ['OVN-A Gate Alpha', 'OVN-A Gate Beta'], 'ovn-a-gate');
        $section = Section::create(['name' => 'OVN-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('OVN-A Gate Pupil', $class->id, $section->id, 'GATE');
        $this->generateFor($class->id)->assertRedirect();
        $this->assertSame(2, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());

        // Admin saved it, nobody approved it: the student must NOT see it yet (item 22).
        $resp = $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk();
        $this->assertSame(0, $resp->viewData('announced'),
            'Unapproved sheet leaks announced rows to the student read.');
        $resp->assertSee('Dates not announced yet');
        $resp->assertDontSee('Mon, 01 Mar 2027');
    }

    public function test_item22_current_student_sees_saved_sheet_only_after_publish()
    {
        // INVERTED (FINAL-VERIFY-A, S6 landed): a saved but
        // unpublished sheet is now GATED — the student sees the
        // honest "Dates not announced yet" state, not the dates.
        // Visibility only after principal publish is proven in
        // OwnerFinalApprovalTest.
        $class = $this->makeClassWithSubjects('OVN-A Ungated Class', ['OVN-A Ungated Alpha', 'OVN-A Ungated Beta'], 'ovn-a-ung');
        $section = Section::create(['name' => 'OVN-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('OVN-A Ungated Pupil', $class->id, $section->id, 'UNG');
        $this->generateFor($class->id)->assertRedirect();
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet')
            ->assertDontSee('OVN-A Ungated Alpha')
            ->assertDontSee('Mon, 01 Mar 2027');
    }

    public function test_item22_current_principal_has_no_datesheet_approval_surface()
    {
        // INVERTED (FINAL-FOLLOWUP-A, S6 landed): the principal HAS
        // an approval surface now — exam_dates.approve (principal-
        // guarded Approve & Publish, "Date Sheet Approvals" queue).
        // KEPT UNCHANGED: the principal Manage page itself stays
        // blocked (manage/save/generate are teamSA/teamSAWrite);
        // only the approval-route expectation is flipped.
        $resp = $this->actingAs($this->principal())->get(route('exam_dates.manage'));
        $this->assertTrue(in_array($resp->status(), [302, 403], true),
            'Principal unexpectedly reaches the Manage page (status ' . $resp->status() . ').');
        $this->assertTrue(Route::has('exam_dates.approve'), 'exam_dates.approve route is missing (S6 approval surface).');
    }

    /* ---------------- Standing rules (must not break) ---------------- */

    public function test_standing_scope_guard_save_foreign_class_refused()
    {
        $classX = $this->makeClassWithSubjects('OVN-A Scope X Class', ['OVN-A Scope XA'], 'ovn-a-sx');
        $classY = $this->makeClassWithSubjects('OVN-A Scope Y Class', ['OVN-A Scope YA'], 'ovn-a-sy');
        $exam = Exam::create(['name' => 'OVN-A Scoped Exam', 'term' => 'OVN-A Term',
            'year' => '2026-2027', 'my_class_id' => $classX->id]);
        $subjectY = Subject::where('my_class_id', $classY->id)->firstOrFail();
        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => $exam->id, 'class_id' => $classY->id,
            'dates' => [$subjectY->id => ['exam_date' => '2027-05-03', 'start_time' => '09:00', 'note' => '']],
        ])->assertRedirect()->assertSessionHas('flash_danger',
            'This exam is scoped to OVN-A Scope X Class — it cannot carry dates for OVN-A Scope Y Class.');
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->where('my_class_id', $classY->id)->count());
    }

    public function test_standing_manage_clash_guard_two_papers_one_session_refused()
    {
        $class = $this->makeClassWithSubjects('OVN-A Sess Class', ['OVN-A Sess Alpha', 'OVN-A Sess Beta'], 'ovn-a-sess');
        $subjects = Subject::where('my_class_id', $class->id)->orderBy('id')->get();
        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => $class->id,
            'dates' => [
                $subjects[0]->id => ['exam_date' => '2027-05-04', 'start_time' => '09:00', 'note' => ''],
                $subjects[1]->id => ['exam_date' => '2027-05-04', 'start_time' => '09:00', 'note' => ''],
            ],
        ])->assertRedirect()->assertSessionHas('flash_danger',
            'Two papers cannot share one session: OVN-A Sess Alpha and OVN-A Sess Beta are both on 2027-05-04 at 09:00. Move one to another session.');
        $this->assertSame(0, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());
    }

    public function test_standing_manage_edit_moves_duty_with_sitting()
    {
        $class = $this->makeClassWithSubjects('OVN-A Move Class', ['OVN-A Move Alpha'], 'ovn-a-move');
        $subject = Subject::where('my_class_id', $class->id)->firstOrFail();
        $sitting = ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id,
            'subject_id' => $subject->id, 'exam_date' => '2027-05-05', 'start_time' => '09:00:00']);
        [$ok] = Invigilation::assign($sitting, (int) $this->teacherA()->id, (int) $this->admin()->id);
        $this->assertTrue($ok);
        $dutyId = ExamDuty::where('exam_date_id', $sitting->id)->firstOrFail()->id;

        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => $class->id,
            'dates' => [$subject->id => ['exam_date' => '2027-05-07', 'start_time' => '09:00', 'note' => 'OVN-A Hall']],
        ])->assertRedirect();
        $this->assertSame('2027-05-07', ExamDate::findOrFail($sitting->id)->exam_date);
        $this->assertSame($dutyId, ExamDuty::where('exam_date_id', $sitting->id)->firstOrFail()->id);
    }

    public function test_standing_clear_paper_deletes_duty_and_bell()
    {
        $class = $this->makeClassWithSubjects('OVN-A Clear Class', ['OVN-A Clear Alpha'], 'ovn-a-clear');
        $subject = Subject::where('my_class_id', $class->id)->firstOrFail();
        $sitting = ExamDate::with(['my_class', 'subject'])->findOrFail(ExamDate::create([
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subject->id,
            'exam_date' => '2027-05-06', 'start_time' => '09:00:00'])->id);
        [$ok] = Invigilation::assign($sitting, (int) $this->teacherA()->id, (int) $this->admin()->id);
        $this->assertTrue($ok);
        $bellBody = Invigilation::dutyBellBody($sitting);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->teacherA()->id, 'type' => 'duty_assigned', 'body' => $bellBody]);

        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => $class->id,
            'dates' => [$subject->id => ['exam_date' => '', 'start_time' => '', 'note' => '']],
        ])->assertRedirect();
        $this->assertDatabaseMissing('exam_dates', ['id' => $sitting->id]);
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $sitting->id]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->teacherA()->id, 'type' => 'duty_assigned', 'body' => $bellBody]);
    }
}
