<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 post-push batch, quick fixes:
 * timetable headers show the section, the student picker cascade
 * rebuilds (no dead disabled options), the eligibility form scrolls
 * into view, and promotion gains a Graduate mode (no To Class
 * needed for Class 12).
 */
class OwnerWave17Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function makeSectionRecord()
    {
        $ttr = TimeTableRecord::create([
            'name' => 'Class 1 A Timetable',
            'my_class_id' => 2,
            'section_id' => 4,
            'exam_id' => null,
            'year' => '2026-2027',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $ttr->id,
            'timestamp_from' => strtotime('9:00 AM'),
            'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
            'time_from' => '9:00 AM',
            'time_to' => '9:40 AM',
            'hour_from' => '9', 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => '9', 'min_to' => '40', 'meridian_to' => 'AM',
        ]);
        TimeTable::create([
            'ttr_id' => $ttr->id,
            'ts_id' => $slot->id,
            'day' => 'Monday',
            'subject_id' => 7,
            'timestamp_from' => (string) strtotime('9:00 AM'),
            'timestamp_to' => (string) strtotime('9:40 AM'),
        ]);
        return $ttr;
    }

    protected function classGridRecord()
    {
        return TimeTableRecord::whereNull('section_id')->where('my_class_id', 2)->first();
    }

    public function test_show_print_validate_show_section()
    {
        $ttr = $this->makeSectionRecord();
        $this->actingAs($this->admin());
        $this->get(route('ttr.show', $ttr->id))->assertOk()
            ->assertSee('Section</small><strong>A</strong>', false);
        $this->get(route('ttr.print', $ttr->id))->assertOk()
            ->assertSee('Timetable for CLASS 1 - SECTION A (2026-2027)', false);
        $this->get(route('ttr.validate', $ttr->id))->assertOk()
            ->assertSee('Section A', false);
    }

    public function test_show_print_show_all_sections_for_class_grid()
    {
        $ttr = $this->classGridRecord();
        $this->assertNotNull($ttr);
        $this->actingAs($this->admin());
        $this->get(route('ttr.show', $ttr->id))->assertOk()
            ->assertSee('All Sections', false);
        $this->get(route('ttr.print', $ttr->id))->assertOk()
            ->assertSee('ALL SECTIONS', false);
    }

    public function test_picker_cascade_uses_rebuild_not_disabled_options()
    {
        $this->actingAs($this->admin());
        $this->get(route('students.list'))->assertOk()
            ->assertSee('from a cached clone', false)
            ->assertDontSee('options.forEach(function (opt)', false);
    }

    public function test_eligibility_form_has_scroll_hook()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->first();
        $this->actingAs($this->admin());
        $this->get(route('planning.eligibility', ['teacher' => Qs::hash($teacher->id)]))
            ->assertOk()
            ->assertSee('eligibility-form', false)
            ->assertSee('scrollIntoView', false);
    }

    public function test_graduation_mode_lists_without_promote_option()
    {
        $this->actingAs($this->admin());
        $this->get(route('students.promotion', [13, 64, 'G']))->assertOk()
            ->assertSee('Graduated', false)
            ->assertSee('Save Graduation', false)
            ->assertDontSee('<option value="P">', false);
    }

    public function test_graduation_mode_graduates_and_skips_promote()
    {
        $students = StudentRecord::where('my_class_id', 13)->where('section_id', 64)
            ->where('session', '2026-2027')->where('grad', 0)->orderBy('id')->get();
        $this->assertTrue($students->count() >= 2);
        $payload = [];
        foreach ($students as $sr) {
            $payload['p-' . $sr->id] = 'G';
        }
        $skipped = $students->last();
        $payload['p-' . $skipped->id] = 'P';
        $graduated = $students->first();
        $before = DB::table('promotions')->where('student_id', $skipped->user_id)->count();

        $this->actingAs($this->admin());
        $this->post(route('students.promote', [13, 64, 'G', 64]), $payload)->assertRedirect();

        $this->assertSame(1, (int) $graduated->fresh()->grad);
        $this->assertSame(1, (int) DB::table('promotions')
            ->where('student_id', $graduated->user_id)->where('grad', 1)->count());
        $this->assertSame(0, (int) $skipped->fresh()->grad);
        $this->assertSame('2026-2027', $skipped->fresh()->session);
        $this->assertSame($before, DB::table('promotions')->where('student_id', $skipped->user_id)->count());
    }
}
