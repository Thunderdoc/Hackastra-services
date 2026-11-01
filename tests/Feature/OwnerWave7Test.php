<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 7 (green
 * validation): a clean validation turns the timetable green —
 * green banner on the Validate page, green Validated pill on the
 * index for the Admin — and any blocking error turns it red
 * instead. The pill is computed live by the same validator the
 * Validate page uses, never stored.
 */
class OwnerWave7Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::find(4);
    }

    protected function cleanRecord()
    {
        // Section segment (2026-10-30): whole-class records no
        // longer exist, so "the clean seeded record" is found by
        // running the product's own validator over the seeded
        // (now per-section) records and taking the first with
        // zero errors — the property (a clash-free record turns
        // green) is unchanged.
        foreach (TimeTableRecord::where('year', Qs::getCurrentSession())->orderBy('id')->get() as $record) {
            $report = \App\Support\TimetableValidator::report($record);
            if (empty($report['errors'])) {
                return $record;
            }
        }

        return null;
    }

    public function test_clean_validation_shows_green_banner()
    {
        $record = $this->cleanRecord();
        $this->assertNotNull($record, 'seed must hold a class timetable record');

        $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertStatus(200)
            ->assertSee('clash-free')
            ->assertDontSee('Not validated');
    }

    public function test_index_shows_validated_pill_for_admin_only()
    {
        $this->actingAs($this->admin())
            ->get(route('tt.index'))
            ->assertStatus(200)
            ->assertSee('Validated');

        $this->actingAs($this->teacher())
            ->get(route('tt.index'))
            ->assertStatus(200)
            ->assertDontSee('Validated');
    }

    public function test_broken_timetable_shows_red_banner_not_green()
    {
        $record = $this->cleanRecord();
        $cell = TimeTable::where('ttr_id', $record->id)->first();
        $this->assertNotNull($cell, 'seed record must hold cells');

        // Forge the "no teacher assigned" error: vacancy on the
        // cell's subject (teacher_id is nullable since 2026-10-23).
        // A section cell's teacher can also come from the section
        // override, so the forge clears BOTH sources — the vacancy
        // must be real for the validator to flag it.
        $subject = Subject::find($cell->subject_id);
        $subject->teacher_id = null;
        $subject->save();
        if ($record->section_id) {
            \Illuminate\Support\Facades\DB::table('section_subject_teachers')
                ->where('section_id', $record->section_id)
                ->where('subject_id', $subject->id)
                ->delete();
        }

        $this->actingAs($this->admin())
            ->get(route('ttr.validate', $record->id))
            ->assertStatus(200)
            ->assertSee('Not validated')
            ->assertDontSee('clash-free');
    }
}
