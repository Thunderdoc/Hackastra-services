<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 timetable auto-generation
 * upgrade: Primary (6 periods, ~2 PM) + Secondary (8 periods,
 * 3:20 PM) presets in one scope=all run, per-class subject
 * choice, teacherless-subject pre-flight warnings, and remembered
 * last-used configs.
 */
class OwnerWave21Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function basePayload(array $over = [])
    {
        return array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 8,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 5,
            'scope' => 'one',
            'mode' => 'skip',
        ], $over);
    }

    protected function newClassWithSubjects(array $subjects)
    {
        $class = MyClass::create(['name' => 'Wave21 Class']);
        foreach ($subjects as $s) {
            Subject::create(array_merge([
                'my_class_id' => $class->id,
                'slug' => 'w21-' . $class->id . '-' . count(Subject::where('my_class_id', $class->id)->get()),
            ], $s));
        }
        return $class;
    }

    protected function deleteRecordForClass(int $classId)
    {
        $record = TimeTableRecord::where('my_class_id', $classId)->whereNull('exam_id')->whereNull('section_id')->first();
        if ($record) {
            TimeTable::where('ttr_id', $record->id)->delete();
            TimeSlot::where('ttr_id', $record->id)->delete();
            $record->delete();
        }
    }

    public function test_generate_form_shows_level_presets_and_subject_picker()
    {
        $this->actingAs($this->admin());
        $this->get(route('tt.generate.form'))->assertOk()
            ->assertSee('Level presets', false)
            ->assertSee('Subjects to include', false)
            ->assertSee('Primary (Prep', false)
            ->assertSee('Secondary (Class 6', false);
    }

    public function test_scope_all_builds_primary_and_secondary_day_lengths()
    {
        // Class 1 (Prep, primary) loses its grid in-transaction so
        // scope=all regenerates it; the brand-new class lands in
        // the secondary bucket by position (after the first 6).
        $this->deleteRecordForClass(1);
        $new = $this->newClassWithSubjects([
            ['name' => 'W21 English', 'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6],
        ]);

        $this->actingAs($this->admin());
        // The new form posts per-level blocks; the single fields
        // alone would (by design) apply to both levels.
        $resp = $this->post(route('tt.generate'), $this->basePayload([
            'scope' => 'all',
            'primary_periods_per_day' => 6,
            'secondary_periods_per_day' => 8,
        ]));
        $resp->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('Timetables generated for 2 class(es)', (string) $resp->getSession()->get('flash_success'));

        $primary = TimeTableRecord::where('my_class_id', 1)->whereNull('exam_id')->whereNull('section_id')->first();
        $secondary = TimeTableRecord::where('my_class_id', $new->id)->whereNull('exam_id')->whereNull('section_id')->first();
        $this->assertNotNull($primary);
        $this->assertNotNull($secondary);
        $this->assertSame(6, TimeSlot::where('ttr_id', $primary->id)->count());
        $this->assertSame(8, TimeSlot::where('ttr_id', $secondary->id)->count());
        $this->assertSame('2:00 PM', TimeSlot::where('ttr_id', $primary->id)->orderBy('id')->get()->last()->time_to);
        $this->assertSame('3:20 PM', TimeSlot::where('ttr_id', $secondary->id)->orderBy('id')->get()->last()->time_to);
    }

    public function test_subject_choice_limits_placement_to_selected()
    {
        $class = $this->newClassWithSubjects([
            ['name' => 'W21 Chosen', 'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6],
            ['name' => 'W21 Dropped', 'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6],
        ]);
        $subjects = Subject::where('my_class_id', $class->id)->orderBy('id')->get();

        $this->actingAs($this->admin());
        $this->post(route('tt.generate'), $this->basePayload([
            'class_id' => $class->id,
            'subjects_present' => [$class->id => 1],
            'subjects' => [$class->id => [$subjects[0]->id]],
        ]))->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->whereNull('section_id')->first();
        $this->assertNotNull($record);
        $placed = TimeTable::where('ttr_id', $record->id)->pluck('subject_id')->unique()->all();
        $this->assertSame([$subjects[0]->id], $placed);
    }

    public function test_teacherless_subject_warns_but_does_not_block()
    {
        $class = $this->newClassWithSubjects([
            ['name' => 'W21 Orphan', 'teacher_id' => null, 'periods_per_week' => 6],
        ]);

        $this->actingAs($this->admin());
        $resp = $this->post(route('tt.generate'), $this->basePayload(['class_id' => $class->id]));
        $resp->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('No teacher assigned', (string) $resp->getSession()->get('flash_success'));
        $this->assertStringContainsString('W21 Orphan', (string) $resp->getSession()->get('flash_success'));
        $this->assertNotNull(TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->first());
    }

    public function test_used_config_is_remembered_for_next_form()
    {
        $class = $this->newClassWithSubjects([
            ['name' => 'W21 English', 'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6],
        ]);

        $this->actingAs($this->admin());
        $this->post(route('tt.generate'), $this->basePayload([
            'class_id' => $class->id, 'period_minutes' => 41,
        ]))->assertRedirect(route('tt.index'));

        $saved = json_decode(Setting::where('type', 'tt_gen_secondary')->first()->description, true);
        $this->assertSame(41, $saved['period_minutes']);
        $this->get(route('tt.generate.form'))->assertOk()
            ->assertSee('secondary_period_minutes', false)
            ->assertSee('value="41"', false);
    }
}
