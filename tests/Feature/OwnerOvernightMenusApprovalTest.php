<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Overnight menus stream (S6-MENUS), item 22: date sheet approval.
 *
 * Flow: admin generate/edit -> "Submit for Principal Approval" ->
 * principal approves = final publish -> students, parents AND
 * teachers see the sheet ONLY then. Today the sheet is visible
 * on save. State machine: draft -> submitted -> published per
 * (exam, class); no state row means draft. Editing a published
 * sheet returns it to draft; a no-change save does not.
 *
 * Fixtures: exam 1 (Term 1 Examination, global scope), class 1
 * with 6 subjects; student@ is in class 1, teacher@ teaches
 * class 1, parent@ has children in class 1.
 */
class OwnerOvernightMenusApprovalTest extends TestCase
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

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    /** Seed one dated row per class-1 subject for exam 1. */
    protected function seedSheet()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 1)->delete();
        ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 1)->delete();
        $day = 1;
        foreach (Subject::where('my_class_id', 1)->orderBy('id')->get() as $subject) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 1,
                'subject_id' => $subject->id,
                'exam_date' => '2027-02-' . str_pad($day, 2, '0', STR_PAD_LEFT),
                'start_time' => '09:00:00',
            ]);
            $day++;
        }
    }

    protected function submitSheet()
    {
        return $this->actingAs($this->admin())->post(route('exam_dates.submit'), [
            'exam_id' => 1,
            'class_id' => 1,
        ]);
    }

    protected function approveSheet()
    {
        return $this->actingAs($this->principal())->post(route('exam_dates.approve'), [
            'exam_id' => 1,
            'class_id' => 1,
        ]);
    }

    protected function state()
    {
        return ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 1)->first();
    }

    public function test_students_parents_teachers_see_nothing_until_published()
    {
        $this->seedSheet();

        $this->actingAs($this->student())->get(route('exam_dates.index'))
            ->assertOk()->assertSee('Dates not announced yet')->assertDontSee('English Language');
        $this->actingAs($this->parent())->get(route('exam_dates.index'))
            ->assertOk()->assertSee('Dates not announced yet');
        // Date-based assertion for the teacher page: its furniture
        // (attendance reminders) names subjects, so a subject-name
        // absence would false-fail; the sheet's own dates must be
        // absent instead.
        $this->actingAs($this->teacher())->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()->assertSee('Dates not announced yet')->assertDontSee('01 Feb 2027');
    }

    public function test_admin_and_principal_see_live_rows_before_publish()
    {
        $this->seedSheet();

        $this->actingAs($this->admin())->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()->assertSee('English Language')->assertDontSee('Dates not announced yet');
        $this->actingAs($this->principal())->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()->assertSee('English Language');
    }

    public function test_submit_requires_rows_and_moves_to_submitted()
    {
        // Class 2 has no rows for exam 1: nothing to submit.
        $refused = $this->actingAs($this->admin())->post(route('exam_dates.submit'), [
            'exam_id' => 1,
            'class_id' => 2,
        ]);
        $refused->assertRedirect();
        $refused->assertSessionHas('flash_danger');
        $this->assertNull(ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 2)->first());

        $this->seedSheet();
        $this->submitSheet()->assertRedirect();
        $this->assertSame('submitted', $this->state()->status);
        $this->assertSame($this->admin()->id, (int) $this->state()->submitted_by);

        // A second submit is an honest no-op, not a duplicate.
        $this->submitSheet()->assertRedirect();
        $this->assertSame('submitted', $this->state()->status);
    }

    public function test_manage_page_shows_status_and_submit_button()
    {
        $this->seedSheet();

        $draft = $this->actingAs($this->admin())->get(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 1]));
        $draft->assertOk();
        $draft->assertSee('Draft — not yet published');
        $draft->assertSee('Submit for Principal Approval');

        $this->submitSheet();
        $submitted = $this->actingAs($this->admin())->get(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 1]));
        $submitted->assertOk();
        $submitted->assertSee('Awaiting Principal approval');
        $submitted->assertDontSee('Submit for Principal Approval');
    }

    public function test_principal_approval_publishes_and_families_see_it()
    {
        $this->seedSheet();
        $this->submitSheet();

        $queue = $this->actingAs($this->principal())->get(route('exam_dates.approvals'));
        $queue->assertOk();
        $queue->assertSee('Term 1 Examination');

        $this->approveSheet()->assertRedirect();
        $this->assertSame('published', $this->state()->status);
        $this->assertSame($this->principal()->id, (int) $this->state()->approved_by);

        $this->actingAs($this->student())->get(route('exam_dates.index'))
            ->assertOk()->assertSee('English Language')->assertDontSee('Dates not announced yet');
        $this->actingAs($this->teacher())->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()->assertSee('English Language')->assertSee('01 Feb 2027');
        $this->actingAs($this->student())->get(route('exam_dates.print'))
            ->assertOk();
    }

    public function test_non_principal_cannot_approve()
    {
        $this->seedSheet();
        $this->submitSheet();

        $this->actingAs($this->admin())->post(route('exam_dates.approve'), [
            'exam_id' => 1,
            'class_id' => 1,
        ])->assertRedirect(route('dashboard'));
        $this->assertSame('submitted', $this->state()->status);
    }

    public function test_print_and_pdf_gated_until_published()
    {
        $this->seedSheet();

        $this->actingAs($this->student())->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($this->student())->get(route('exam_dates.pdf'))
            ->assertRedirect(route('exam_dates.index'));
    }

    public function test_undated_sheet_still_prints_its_empty_note()
    {
        // Refinement of the print/PDF gate: an undated sheet has
        // nothing to leak, so it still prints its honest empty
        // note instead of redirecting (standing R24 behavior).
        ExamDate::where('exam_id', 1)->where('my_class_id', 1)->delete();
        ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 1)->delete();

        $this->actingAs($this->teacher())->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()->assertSee('have not been announced yet')->assertSee('English Language')->assertSee('Not announced');
    }

    public function test_editing_published_sheet_returns_it_to_draft()
    {
        $this->seedSheet();
        $this->submitSheet();
        $this->approveSheet();
        $this->assertSame('published', $this->state()->status);

        $firstSubject = Subject::where('my_class_id', 1)->orderBy('id')->first();
        $saved = $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => 1,
            'dates' => [$firstSubject->id => ['exam_date' => '2027-03-01', 'start_time' => '09:00']],
        ]);
        $saved->assertRedirect();
        $this->assertSame('draft', $this->state()->status);

        $this->actingAs($this->student())->get(route('exam_dates.index'))
            ->assertOk()->assertSee('Dates not announced yet');
    }

    public function test_no_change_save_keeps_published()
    {
        $this->seedSheet();
        $this->submitSheet();
        $this->approveSheet();

        $dates = [];
        foreach (ExamDate::where('exam_id', 1)->where('my_class_id', 1)->get() as $row) {
            $dates[$row->subject_id] = [
                'exam_date' => $row->exam_date,
                'start_time' => substr((string) $row->start_time, 0, 5),
            ];
        }
        $saved = $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => 1,
            'dates' => $dates,
        ]);
        $saved->assertRedirect();
        $this->assertSame('published', $this->state()->status);
    }
}
