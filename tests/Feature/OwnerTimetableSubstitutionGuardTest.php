<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner audit 2026-10-29 — two timetable-generation seams:
 *
 * (a) SUBSTITUTION ORPHANS. substitutions.time_table_id is a plain
 *     id with NO foreign key. Replacing a grid (generate mode=
 *     replace), deleting a whole record, or deleting a single cell
 *     used to strand every arranged substitution on a period that
 *     no longer exists: the board would claim coverage that is not
 *     there and the substitute's access window would silently die.
 *     All three paths now refuse/keep, mirroring the submitted-
 *     attendance guards (TT-01 doctrine: plans and history are
 *     never silently destroyed).
 *
 * (b) SETTINGS WEEK CAP. teacher_max_periods_week (Settings,
 *     default 40) was read by the staffing surfaces and the
 *     validator, but the generator ignored it and produced grids
 *     its own validator then warned about. The placer now counts
 *     kept + placed load and never takes a teacher over the cap.
 */
class OwnerTimetableSubstitutionGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'SG Teacher ' . $tag,
            'email' => 'sg-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'SG' . $tag . uniqid(),
        ]);
    }

    protected function generatePayload(array $overrides = []): array
    {
        return array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'one',
            'mode' => 'replace',
            'max_teacher_periods_per_day' => null,
        ], $overrides);
    }

    /** Fresh class, six subjects x 6/week, one teacher each. Returns [class, record, teachersBySubject]. */
    protected function generateGrid(string $prefix): array
    {
        $class = MyClass::create(['name' => 'SG ' . $prefix . ' ' . uniqid()]);
        $teachers = [];
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $t = $this->teacher($prefix . $i);
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'sg-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $t->id,
                'periods_per_week' => 6,
            ]);
            $teachers[$sub->id] = $t;
        }
        $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id]))
            ->assertRedirect(route('tt.index'));
        $record = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
        $this->assertSame(36, TimeTable::where('ttr_id', $record->id)->count(),
            'fixture grid must be complete before the probe runs');

        return [$class, $record, $teachers];
    }

    protected function gridTuples(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => [$c->day_num, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
    }

    /** Arrange a substitution on the record's first cell, dated on that cell's weekday. */
    protected function arrangeSubstitution(TimeTableRecord $record, array $teachers): Substitution
    {
        $cell = TimeTable::where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')->firstOrFail();
        $date = null;
        $cursor = \Carbon\Carbon::create(2026, 11, 2);
        for ($i = 0; $i < 28 && !$date; $i++) {
            $candidate = $cursor->copy()->addDays($i);
            if ($candidate->format('l') === $cell->day) {
                $date = $candidate->toDateString();
            }
        }
        $absent = $teachers[$cell->subject_id];
        $substitute = collect($teachers)->first(fn ($t) => $t->id !== $absent->id);

        return Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $date,
            'absent_teacher_id' => $absent->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Guard probe',
        ]);
    }

    public function test_replace_generate_keeps_grid_that_carries_substitutions()
    {
        [$class, $record, $teachers] = $this->generateGrid('SUBKEEP');
        $sub = $this->arrangeSubstitution($record, $teachers);
        $before = $this->gridTuples($record->id);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id]));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        $this->assertStringContainsString('Kept (substitutions are arranged on their periods', $flash);
        $this->assertStringContainsString($class->name, $flash);
        $this->assertSame($before, $this->gridTuples($record->id), 'grid carrying substitutions must survive a replace attempt untouched');
        $this->assertSame($sub->time_table_id, (int) $sub->fresh()->time_table_id, 'substitution must still point at the same live cell');
    }

    public function test_delete_record_refused_when_substitutions_are_arranged()
    {
        [, $record, $teachers] = $this->generateGrid('SUBREC');
        $this->arrangeSubstitution($record, $teachers);

        $response = $this->actingAs($this->admin())->delete(route('ttr.destroy', $record->id));
        $response->assertRedirect();
        $this->assertSame(
            'This timetable cannot be deleted — substitutions are arranged on its periods. Remove those arrangements on the Substitutions board first.',
            session('flash_danger')
        );
        $this->assertNotNull(TimeTableRecord::find($record->id), 'record must survive the refused delete');
    }

    public function test_cell_delete_refused_when_a_substitution_is_arranged()
    {
        [, $record, $teachers] = $this->generateGrid('SUBCELL');
        $sub = $this->arrangeSubstitution($record, $teachers);

        $response = $this->actingAs($this->admin())->delete(route('tt.delete', $sub->time_table_id));
        $response->assertRedirect();
        $this->assertSame(
            'This period cannot be deleted — a substitution is arranged on it. Remove that arrangement on the Substitutions board first.',
            session('flash_danger')
        );
        $this->assertNotNull(TimeTable::find($sub->time_table_id), 'cell must survive the refused delete');
        $this->assertNotNull(Substitution::find($sub->id), 'substitution itself must be untouched');
    }

    public function test_generator_respects_the_settings_week_cap()
    {
        Setting::updateOrCreate(['type' => 'teacher_max_periods_week'], ['description' => '3']);

        $class = MyClass::create(['name' => 'SG CAP ' . uniqid()]);
        $capped = $this->teacher('CAPPED');
        $cappedSubjects = [];
        foreach (['English', 'Mathematics'] as $i => $name) {
            $cappedSubjects[] = Subject::create([
                'name' => $name,
                'slug' => 'sg-cap-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $capped->id,
                'periods_per_week' => 6,
            ]);
        }
        foreach (['Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            Subject::create([
                'name' => $name,
                'slug' => 'sg-cap-x-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher('CAPX' . $i)->id,
                'periods_per_week' => 6,
            ]);
        }

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->generatePayload(['class_id' => $class->id]));
        $response->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
        $cappedCells = TimeTable::where('ttr_id', $record->id)
            ->whereIn('subject_id', collect($cappedSubjects)->pluck('id'))->count();
        $this->assertLessThanOrEqual(3, $cappedCells,
            'the capped teacher must never be placed over the Settings week cap of 3');
        $this->assertSame(3, $cappedCells,
            'the capped teacher should still be used up to the cap, not excluded entirely');
        $flash = session('flash_success') ?? '';
        $this->assertStringContainsString('REQUIREMENTS NOT FULLY MET', $flash,
            'the unmet remainder of the capped subjects must be reported, not silently dropped');
    }
}
