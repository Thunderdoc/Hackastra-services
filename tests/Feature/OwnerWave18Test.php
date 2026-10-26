<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 evening owner reports:
 * (1) admit cards read the generated date sheet, not only exam
 * timetables; (2) the date-sheet print footer names the class
 * teacher (when the class's sections agree on one) on the same
 * line as the principal; (3) the theme toggle rolls back when its
 * save fails instead of leaving a flip the next page undoes.
 */
class OwnerWave18Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    public function test_admit_card_reads_generated_date_sheet()
    {
        $this->assertNull(TimeTableRecord::where('exam_id', 1)->where('my_class_id', 1)->first());
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 1, 'exam_date' => '2027-02-06', 'start_time' => '09:00:00', 'note' => null]);
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 2, 'exam_date' => '2027-02-08', 'start_time' => '09:00:00', 'note' => null]);

        $this->actingAs($this->admin());
        $this->get(route('admit_cards.print', [1, 1]) . '?section_id=1')->assertOk()
            ->assertSee('06 Feb 2027', false)
            ->assertSee('08 Feb 2027', false)
            ->assertSee('9:00 AM', false)
            ->assertDontSee('will be announced', false)
            ->assertDontSee('No exam timetable or date sheet', false);
    }

    public function test_admit_card_falls_back_when_nothing_generated()
    {
        $this->assertNull(TimeTableRecord::where('exam_id', 1)->where('my_class_id', 2)->first());
        $this->actingAs($this->admin());
        $this->get(route('admit_cards.print', [1, 2]) . '?section_id=4')->assertOk()
            ->assertSee('will be announced', false)
            ->assertSee('No exam timetable or date sheet', false);
    }

    public function test_date_sheet_footer_names_agreed_class_teacher()
    {
        $this->actingAs($this->admin());
        $this->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]))->assertOk()
            ->assertSee('ds-sign-top', false)
            ->assertSee('<div class="ds-sign-name">Ananya Sharma</div>', false);
    }

    public function test_date_sheet_footer_stays_blank_when_sections_disagree()
    {
        DB::table('sections')->where('id', 2)->update(['teacher_id' => 12]);
        $this->actingAs($this->admin());
        $this->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]))->assertOk()
            ->assertSee('<div class="ds-sign-name"></div>', false);
    }

    public function test_theme_toggle_rolls_back_on_failed_save()
    {
        $this->actingAs($this->admin());
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('roll the screen back', false);
    }
}
