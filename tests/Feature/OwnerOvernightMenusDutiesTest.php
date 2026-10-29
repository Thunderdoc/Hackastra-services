<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Overnight menus stream (S6-MENUS), item 21: invigilation Save All.
 *
 * Duties table gains a # column (row numbers 1..N); a Save All
 * button posts ONLY the changed rows in one request. Each row
 * keeps the single-save pool check, clash rule and audit entry;
 * clashing rows are reported while the rest still save. Rows
 * cleared to empty are skipped (no unassign invented).
 *
 * Fixtures: exam 1; sitting A = class 1 English, 2027-02-01
 * 09:00; sitting B = class 1 Mathematics, 2027-02-02 09:00;
 * sitting C = class 4 Computer Science, 2027-02-01 09:00 (the
 * same slot as A, so it clashes with A's teacher).
 */
class OwnerOvernightMenusDutiesTest extends TestCase
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

    protected function seedSittings()
    {
        ExamDuty::whereIn('exam_date_id', ExamDate::where('exam_id', 1)->pluck('id'))->delete();
        ExamDate::where('exam_id', 1)->delete();

        $english = Subject::where('my_class_id', 1)->where('name', 'English Language')->firstOrFail();
        $maths = Subject::where('my_class_id', 1)->where('name', 'Mathematics')->firstOrFail();
        $cs = Subject::where('my_class_id', 4)->where('name', 'Computer Science')->firstOrFail();

        $a = ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => $english->id, 'exam_date' => '2027-02-01', 'start_time' => '09:00:00']);
        $b = ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => $maths->id, 'exam_date' => '2027-02-02', 'start_time' => '09:00:00']);
        $c = ExamDate::create(['exam_id' => 1, 'my_class_id' => 4, 'subject_id' => $cs->id, 'exam_date' => '2027-02-01', 'start_time' => '09:00:00']);

        return [$a, $b, $c];
    }

    public function test_duties_page_has_row_numbers_and_save_all()
    {
        [$a] = $this->seedSittings();

        $response = $this->actingAs($this->admin())->get(route('exam_duties.index', ['exam_id' => 1]));
        $response->assertOk();
        $response->assertSee('<th>#</th>', false);
        $response->assertSee('Save All');
        // The JS posts only rows whose select changed from its
        // initial value; unchanged and empty rows are excluded.
        $response->assertSee('js-duty-select', false);
        $response->assertSee('data-current', false);
        $response->assertSee('saveAllDuties', false);
    }

    public function test_save_all_saves_changed_rows_in_one_request()
    {
        [$a, $b] = $this->seedSittings();

        $response = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [
                $a->id => Qs::hash($this->teacher()->id),
                $b->id => Qs::hash($this->otherTeacher()->id),
            ],
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_success');
        $this->assertSame($this->teacher()->id, (int) ExamDuty::where('exam_date_id', $a->id)->value('teacher_id'));
        $this->assertSame($this->otherTeacher()->id, (int) ExamDuty::where('exam_date_id', $b->id)->value('teacher_id'));
    }

    public function test_save_all_keeps_good_rows_and_reports_clashing_row()
    {
        [$a, $b, $c] = $this->seedSittings();
        ExamDuty::create(['exam_date_id' => $a->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);

        // C sits in A's exact slot, so A's teacher clashes on C;
        // B is free and must still save.
        $response = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [
                $c->id => Qs::hash($this->teacher()->id),
                $b->id => Qs::hash($this->otherTeacher()->id),
            ],
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_success');
        $response->assertSessionHas('flash_danger');
        $this->assertSame($this->otherTeacher()->id, (int) ExamDuty::where('exam_date_id', $b->id)->value('teacher_id'));
        $this->assertNull(ExamDuty::where('exam_date_id', $c->id)->first());
    }

    public function test_save_all_skips_rows_cleared_to_empty()
    {
        [$a, $b] = $this->seedSittings();

        $response = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [$b->id => ''],
        ]);
        $response->assertRedirect();
        $response->assertSessionMissing('flash_danger');
        $this->assertNull(ExamDuty::where('exam_date_id', $b->id)->first());
    }

    public function test_save_all_rejects_teachers_outside_the_pool()
    {
        [$a, $b] = $this->seedSittings();

        $response = $this->actingAs($this->admin())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [$b->id => Qs::hash($this->admin()->id)],
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_danger');
        $this->assertNull(ExamDuty::where('exam_date_id', $b->id)->first());
    }

    public function test_teacher_cannot_bulk_save()
    {
        [$a] = $this->seedSittings();

        // teamSA middleware (not a 403 abort): non-team users are
        // redirected to login, exactly like the single-save route.
        $this->actingAs($this->teacher())->post(route('exam_duties.assign_bulk'), [
            'assignments' => [$a->id => Qs::hash($this->teacher()->id)],
        ])->assertRedirect(route('login'));
        $this->assertNull(ExamDuty::where('exam_date_id', $a->id)->first());
    }
}
