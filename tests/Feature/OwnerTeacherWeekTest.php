<?php

namespace Tests\Feature;

use App\Support\SectionTeacher;
use App\Models\TimeTable;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner orders 2026-10-28: the teacher week grid hid lunch/break
 * (teaching slots back-to-back) and named no section ("Class 12"
 * hides which of 13 sections). Screen, print and PDF share
 * teacherWeekData, so one fix covers all three.
 */
class OwnerTeacherWeekTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** A section-grid period the teacher actually teaches. */
    protected function sectionedPeriodLabel(): ?string
    {
        $teacherId = $this->teacher()->id;
        $cell = TimeTable::with(['subject', 'tt_record.my_class', 'tt_record.section'])
            ->get()
            ->first(function ($c) use ($teacherId) {
                return $c->tt_record && $c->tt_record->section_id
                    && (int) SectionTeacher::teacherIdFor($c->tt_record->section_id, $c->subject) === (int) $teacherId;
            });
        if (!$cell) {
            return null;
        }

        return $cell->tt_record->my_class->name . ' — ' . $cell->tt_record->section->name;
    }

    public function test_week_grid_shows_lunch_break_and_section()
    {
        $label = $this->sectionedPeriodLabel();
        $this->assertNotNull($label, 'No section-grid period found for the teacher');

        $response = $this->actingAs($this->teacher())->get(route('tt.mine'));
        $response->assertOk();
        $response->assertSee('Lunch');
        $response->assertSee('Break');
        $response->assertSee($label);
    }

    public function test_week_print_shows_lunch_and_section()
    {
        $label = $this->sectionedPeriodLabel();
        $this->assertNotNull($label, 'No section-grid period found for the teacher');

        $response = $this->actingAs($this->teacher())->get(route('tt.mine_print'));
        $response->assertOk();
        $response->assertSee('Lunch');
        $response->assertSee($label);
    }
}
