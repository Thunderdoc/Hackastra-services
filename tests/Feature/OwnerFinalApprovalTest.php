<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FINAL-VERIFY-A (overnight batch 2026-10-29): independent
 * verification of S6's date-sheet approval flow (item 22) and
 * invigilation Save All (item 21) against the finished tree.
 *
 * Approval design verified here (not redesigned): dedicated
 * exam_date_sheet_states table, one row per (exam, class),
 * draft -> submitted -> published; no row = draft. This is NOT
 * exams.results_published (the marks switch). Read gates cover
 * show + print + PDF for students, parents AND teachers;
 * unpublished sheets render "Dates not announced yet";
 * print/PDF redirect ONLY when dates actually exist to leak
 * (an undated sheet still prints its honest empty note).
 * Admit cards are deliberately NOT gated (not probed here).
 *
 * Fixtures are fresh classes/subjects per test (2027 dates so
 * the past-date refusal never masks a probe); exam 1 is the
 * global exam. Parent gating is probed with a freshly linked
 * parent/child pair (not inferred from the shared resolveScope
 * path alone); teacher gating with a teacher scoped to the
 * fresh class via its subjects.
 */
class OwnerFinalApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function makeClass($name, array $subjects, $slug, $teacherId = 4)
    {
        $class = MyClass::create(['name' => $name]);
        foreach ($subjects as $i => $subjectName) {
            Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => $teacherId,
                'slug' => $slug . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return $class;
    }

    protected function makeStudent($name, $classId, $sectionId, $tag, $parentId = null)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper($tag . $classId),
            'password' => bcrypt('student'), 'username' => 'TEST/FIN-A/' . $tag . $classId,
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'my_parent_id' => $parentId,
            'adm_no' => 'TEST/FIN-A/' . $tag . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0,
        ]);
        return $user;
    }

    protected function makeParent($classId, $tag)
    {
        return User::create([
            'name' => 'FIN-A Parent ' . $tag, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'parent', 'code' => strtoupper('FINP' . $tag . $classId),
            'password' => bcrypt('parent'), 'username' => 'TEST/FIN-P/' . $tag . $classId,
        ]);
    }

    protected function generateFor($classId, $mode = 'replace', $examId = 1)
    {
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), [
            'exam_id' => $examId,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => '2027-03-01',
            'exam_days' => 2,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => $mode,
            'venue' => 'FIN Hall',
        ]);
    }

    protected function submitFor($classId, $examId = 1)
    {
        return $this->actingAs($this->admin())->post(route('exam_dates.submit'), [
            'exam_id' => $examId, 'class_id' => $classId,
        ]);
    }

    protected function approveFor($classId, $examId = 1)
    {
        return $this->actingAs($this->principal())->post(route('exam_dates.approve'), [
            'exam_id' => $examId, 'class_id' => $classId,
        ]);
    }

    protected function publish($classId)
    {
        $this->generateFor($classId)->assertRedirect();
        $this->submitFor($classId)->assertRedirect();
        $this->approveFor($classId)->assertRedirect();
        $this->assertSame('published', $this->stateFor($classId)->status);
    }

    protected function stateFor($classId, $examId = 1)
    {
        return ExamDateSheetState::where('exam_id', $examId)->where('my_class_id', $classId)->first();
    }

    protected function storedDates($classId, $examId = 1)
    {
        $dates = [];
        foreach (ExamDate::where('exam_id', $examId)->where('my_class_id', $classId)->get() as $row) {
            $dates[$row->subject_id] = [
                'exam_date' => $row->exam_date,
                'start_time' => substr((string) $row->start_time, 0, 5),
                'note' => $row->note,
            ];
        }
        return $dates;
    }

    /* ---------------- Item 22: approval flow ---------------- */

    public function test_full_flow_draft_submitted_published_student_sees_only_after_publish()
    {
        $class = $this->makeClass('FIN Flow Class', ['FIN Flow Alpha', 'FIN Flow Beta'], 'fin-flow');
        $section = Section::create(['name' => 'FIN', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('FIN Flow Pupil', $class->id, $section->id, 'FLOW');

        // Draft: no row = draft, student gated.
        $this->generateFor($class->id)->assertRedirect();
        $this->assertNull($this->stateFor($class->id));
        $this->assertSame('draft', ExamDateSheetState::statusFor(1, $class->id));
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet')->assertDontSee('FIN Flow Alpha');
        $manage = $this->actingAs($this->admin())->get(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => $class->id]));
        $manage->assertOk()->assertSee('Draft — not yet published')->assertSee('Submit for Principal Approval');

        // Submitted: still gated; principal queue lists the sheet.
        $this->submitFor($class->id)->assertRedirect();
        $this->assertSame('submitted', $this->stateFor($class->id)->status);
        $this->assertSame($this->admin()->id, (int) $this->stateFor($class->id)->submitted_by);
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet')->assertDontSee('Mon, 01 Mar 2027');
        $this->actingAs($this->principal())->get(route('exam_dates.approvals'))->assertOk()
            ->assertSee('FIN Flow Class')->assertSee('Approve & Publish');

        // Published: student sees dates; manage badge flips.
        $this->approveFor($class->id)->assertRedirect();
        $this->assertSame('published', $this->stateFor($class->id)->status);
        $this->assertSame($this->principal()->id, (int) $this->stateFor($class->id)->approved_by);
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('FIN Flow Alpha')->assertSee('Mon, 01 Mar 2027')->assertDontSee('Dates not announced yet');
        $this->actingAs($this->admin())->get(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertOk()->assertSee('Published by Principal');
    }

    public function test_admin_approve_attempt_bounced_to_dashboard_stays_submitted()
    {
        $class = $this->makeClass('FIN Bounce Class', ['FIN Bounce Alpha'], 'fin-bounce');
        $this->generateFor($class->id)->assertRedirect();
        $this->submitFor($class->id)->assertRedirect();
        $this->assertSame('submitted', $this->stateFor($class->id)->status);

        $this->actingAs($this->admin())->post(route('exam_dates.approve'), [
            'exam_id' => 1, 'class_id' => $class->id,
        ])->assertRedirect(route('dashboard'));
        $this->assertSame('submitted', $this->stateFor($class->id)->status);
        // Non-principal queue access is likewise bounced.
        $this->actingAs($this->admin())->get(route('exam_dates.approvals'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_parent_read_gated_until_published_then_visible()
    {
        $class = $this->makeClass('FIN Parent Class', ['FIN Parent Alpha', 'FIN Parent Beta'], 'fin-parent');
        $section = Section::create(['name' => 'FIN', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $parent = $this->makeParent($class->id, 'PAR');
        $this->makeStudent('FIN Parent Pupil', $class->id, $section->id, 'PAR', $parent->id);

        $this->generateFor($class->id)->assertRedirect();
        // Own-probe: the parent's own linked child resolves to this
        // fresh class, and the unpublished sheet is gated on show
        // AND print (dates exist to leak, so print redirects).
        $this->actingAs($parent)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet')->assertDontSee('FIN Parent Alpha');
        $this->actingAs($parent)->get(route('exam_dates.print', ['exam_id' => 1]))
            ->assertRedirect(route('exam_dates.index'));

        $this->submitFor($class->id)->assertRedirect();
        $this->approveFor($class->id)->assertRedirect();
        $this->actingAs($parent)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('FIN Parent Alpha')->assertSee('Mon, 01 Mar 2027');
        $this->actingAs($parent)->get(route('exam_dates.print', ['exam_id' => 1]))->assertOk();
    }

    public function test_teacher_read_gated_show_print_pdf()
    {
        // Subjects carry teacher@'s id, so the fresh class is in
        // teacher@'s TeacherScope; the teacher page's furniture
        // names subjects, so date strings (not subject names)
        // are the gating assertions.
        $class = $this->makeClass('FIN Teacher Class', ['FIN Teacher Alpha', 'FIN Teacher Beta'], 'fin-teacher', $this->teacher()->id);
        $this->generateFor($class->id)->assertRedirect();

        // Date assertion uses the full "Mon, 01 Mar 2027" sheet
        // label: the generate flash banner itself carries the
        // bare "01 Mar 2027 – 02 Mar 2027" range, which would
        // false-fail a bare-date absence check on this first GET.
        $this->actingAs($this->teacher())->get(route('exam_dates.show', ['exam_id' => 1, 'class_id' => $class->id]))->assertOk()
            ->assertSee('Dates not announced yet')->assertDontSee('Mon, 01 Mar 2027');
        $this->actingAs($this->teacher())->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->teacher())->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertRedirect(route('exam_dates.index'));

        $this->submitFor($class->id)->assertRedirect();
        $this->approveFor($class->id)->assertRedirect();
        $this->actingAs($this->teacher())->get(route('exam_dates.show', ['exam_id' => 1, 'class_id' => $class->id]))->assertOk()
            ->assertSee('Mon, 01 Mar 2027');
        $this->actingAs($this->teacher())->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]))->assertOk();
        $examName = Exam::findOrFail(1)->name;
        $this->actingAs($this->teacher())->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertDownload('exam-date-sheet-' . Str::slug($examName) . '-' . Str::slug($class->name) . '.pdf');
    }

    public function test_edit_real_change_reverts_published_to_draft()
    {
        $class = $this->makeClass('FIN Edit Class', ['FIN Edit Alpha', 'FIN Edit Beta'], 'fin-edit');
        $section = Section::create(['name' => 'FIN', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('FIN Edit Pupil', $class->id, $section->id, 'EDIT');
        $this->publish($class->id);

        $firstSubject = Subject::where('my_class_id', $class->id)->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => $class->id,
            'dates' => [$firstSubject->id => ['exam_date' => '2027-03-10', 'start_time' => '09:00', 'note' => 'FIN Hall']],
        ])->assertRedirect();
        $this->assertSame('draft', $this->stateFor($class->id)->status);
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet');
    }

    public function test_no_change_save_keeps_published()
    {
        $class = $this->makeClass('FIN NoChange Class', ['FIN NoChange Alpha', 'FIN NoChange Beta'], 'fin-nochg');
        $section = Section::create(['name' => 'FIN', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('FIN NoChange Pupil', $class->id, $section->id, 'NOCHG');
        $this->publish($class->id);

        $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => $class->id,
            'dates' => $this->storedDates($class->id),
        ])->assertRedirect();
        $this->assertSame('published', $this->stateFor($class->id)->status);
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('FIN NoChange Alpha');
    }

    public function test_generate_reverts_published_to_draft()
    {
        $class = $this->makeClass('FIN Regen Class', ['FIN Regen Alpha', 'FIN Regen Beta'], 'fin-regen');
        $section = Section::create(['name' => 'FIN', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('FIN Regen Pupil', $class->id, $section->id, 'REGEN');
        $this->publish($class->id);

        $this->generateFor($class->id, 'replace')->assertRedirect();
        $this->assertSame('draft', $this->stateFor($class->id)->status);
        $this->actingAs($student)->get(route('exam_dates.show', ['exam_id' => 1]))->assertOk()
            ->assertSee('Dates not announced yet');
    }

    public function test_skipped_class_keeps_published_state()
    {
        $class = $this->makeClass('FIN Skip Class', ['FIN Skip Alpha', 'FIN Skip Beta'], 'fin-skip');
        $this->publish($class->id);
        $rowsBefore = ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count();

        // Mode skip: the class already has a sheet, so generate
        // writes nothing for it and must not touch its state.
        $this->generateFor($class->id, 'skip')->assertRedirect();
        $this->assertSame('published', $this->stateFor($class->id)->status);
        $this->assertSame($rowsBefore, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());
    }

    public function test_undated_sheet_prints_honest_empty_note_not_redirect()
    {
        $class = $this->makeClass('FIN Undated Class', ['FIN Undated Alpha', 'FIN Undated Beta'], 'fin-undated', $this->teacher()->id);
        $this->assertSame(0, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());

        $this->actingAs($this->teacher())->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertOk()->assertSee('have not been announced yet')->assertSee('FIN Undated Alpha')->assertSee('Not announced');
        $examName = Exam::findOrFail(1)->name;
        $this->actingAs($this->teacher())->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertDownload('exam-date-sheet-' . Str::slug($examName) . '-' . Str::slug($class->name) . '.pdf');
    }

    /* ---------------- Item 21: invigilation Save All ---------------- */

    protected function seedSittings()
    {
        $classA = $this->makeClass('FIN Duty A Class', ['FIN Duty A Subj'], 'fin-duty-a');
        // Class B needs TWO subjects: B and C are different papers
        // of the same class (one sitting per (exam, class, subject)).
        $classB = $this->makeClass('FIN Duty B Class', ['FIN Duty B Subj', 'FIN Duty C Subj'], 'fin-duty-b');
        $subjectsB = Subject::where('my_class_id', $classB->id)->orderBy('id')->get();
        $a = ExamDate::create(['exam_id' => 1, 'my_class_id' => $classA->id,
            'subject_id' => Subject::where('my_class_id', $classA->id)->firstOrFail()->id,
            'exam_date' => '2027-04-01', 'start_time' => '09:00:00']);
        $b = ExamDate::create(['exam_id' => 1, 'my_class_id' => $classB->id,
            'subject_id' => $subjectsB[0]->id,
            'exam_date' => '2027-04-02', 'start_time' => '09:00:00']);
        // C shares A's exact slot, so A's teacher clashes on C.
        $c = ExamDate::create(['exam_id' => 1, 'my_class_id' => $classB->id,
            'subject_id' => $subjectsB[1]->id,
            'exam_date' => '2027-04-01', 'start_time' => '09:00:00']);
        return [$a, $b, $c];
    }

    public function test_duties_index_has_number_column_and_save_all()
    {
        [$a] = $this->seedSittings();
        $html = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('<th>#</th>', $html);
        $this->assertStringContainsString('Save All', $html);
        $this->assertStringContainsString('saveAllDuties', $html);
        // $loop->iteration renders 1-based numbers in order.
        $this->assertMatchesRegularExpression('/<td>1<\/td>/', $html);
    }

    public function test_save_all_saves_rows_with_pool_check_and_audit()
    {
        [$a, $b] = $this->seedSittings();
        $resp = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [
                $a->id => Qs::hash($this->teacher()->id),
                $b->id => Qs::hash($this->otherTeacher()->id),
            ],
        ]);
        $resp->assertRedirect()->assertSessionHas('flash_success', '2 invigilator(s) saved in one go.');
        $this->assertSame($this->teacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));
        $this->assertSame($this->otherTeacher()->id, (int) ExamDuty::where('exam_date_id', $b->id)->value('teacher_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.duty_assigned']);
    }

    public function test_save_all_reports_clashing_row_while_rest_save()
    {
        [$a, $b, $c] = $this->seedSittings();
        ExamDuty::create(['exam_date_id' => $a->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);

        $resp = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [
                $c->id => Qs::hash($this->teacher()->id),
                $b->id => Qs::hash($this->otherTeacher()->id),
            ],
        ]);
        $resp->assertRedirect()->assertSessionHas('flash_success')->assertSessionHas('flash_danger');
        $this->assertSame($this->otherTeacher()->id, (int) ExamDuty::where('exam_date_id', $b->id)->value('teacher_id'));
        $this->assertNull(ExamDuty::where('exam_date_id', $c->id)->first());
        $this->assertSame($this->teacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));
    }

    public function test_save_all_skips_cleared_rows_without_unassigning()
    {
        [$a, $b] = $this->seedSittings();
        ExamDuty::create(['exam_date_id' => $a->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);

        $resp = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [$a->id => '', $b->id => ''],
        ]);
        $resp->assertRedirect()->assertSessionMissing('flash_danger');
        // Cleared rows are skipped: the existing duty survives —
        // bulk save invents no unassign, exactly like per-row save.
        $this->assertSame($this->teacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));
        $this->assertNull(ExamDuty::where('exam_date_id', $b->id)->first());
    }

    public function test_save_all_rejects_non_pool_teacher_per_row()
    {
        [$a, $b] = $this->seedSittings();
        $resp = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [
                $a->id => Qs::hash($this->teacher()->id),
                $b->id => Qs::hash($this->admin()->id),
            ],
        ]);
        $resp->assertRedirect()->assertSessionHas('flash_success')->assertSessionHas('flash_danger');
        $this->assertSame($this->teacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));
        $this->assertNull(ExamDuty::where('exam_date_id', $b->id)->first());
    }

    public function test_per_row_save_and_auto_assign_all_empty_unchanged()
    {
        [$a, $b] = $this->seedSittings();
        // Per-row Save still works with the same clash rule + audit.
        // (otherTeacher, not teacher@: sitting A's subject belongs
        // to teacher@, so a teacher@ save would append the
        // own-subject note to the flash and mask the exact text.)
        $this->actingAs($this->admin())
            ->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.assign'), ['exam_date_id' => $a->id, 'teacher_id' => Qs::hash($this->otherTeacher()->id)])
            ->assertRedirect()->assertSessionHas('flash_success', 'Invigilator saved.');
        $this->assertSame($this->otherTeacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));

        // Auto-Assign All Empty fills the remaining empty sitting.
        $this->actingAs($this->admin())
            ->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.auto'), ['exam_id' => 1])
            ->assertRedirect()->assertSessionHas('flash_success');
        $this->assertNotNull(ExamDuty::where('exam_date_id', $b->id)->first());
    }
}
