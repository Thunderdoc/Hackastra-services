<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AuditLog;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Per-teacher caps (owner direction 2026-11-06, "teacher capacity
 * badhao"; audit options C2 + gaps G4/G5/G11):
 *
 * - Staffing::capsFor is the ONE resolver: a teacher's own
 *   override (users.max_periods_per_day/week) beats the school
 *   defaults everywhere a specific teacher is judged.
 * - The generator respects a LOWERED and a RAISED personal cap.
 * - Manual cell writes (TTRequest) refuse a cell that would take
 *   the effective teacher over their day or week cap.
 * - The validator flags day-cap breaches (warning severity).
 * - A generate run with the day-cap field cleared writes an
 *   audit row naming the admin and the scope (G5).
 * - The Admin sets caps on the teacher's Edit User form; the
 *   teacher's Workload page compares against HER caps.
 *
 * Fixtures resolve users by EMAIL (a nurse seed user shifted the
 * generated ids) and every test runs in a transaction, so cap
 * overrides and generated grids never leak into the shared seed.
 */
class OwnerPerTeacherCapsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function setCaps(User $teacher, $day, $week): void
    {
        $teacher->update(['max_periods_per_day' => $day, 'max_periods_per_week' => $week]);
    }

    /* ---------------- Workload page (deliverable 3) ---------------- */

    public function test_workload_page_uses_school_defaults_without_override()
    {
        $html = $this->actingAs($this->ananya())->get(route('tt.workload'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('caps: 8 periods a day, 40 a week (Settings)', $html);
        $this->assertStringContainsString('36 <small>/ 40 cap</small>', $html);
    }

    public function test_workload_page_override_beats_global()
    {
        $this->setCaps($this->ananya(), 6, 30);

        $html = $this->actingAs($this->ananya()->fresh())->get(route('tt.workload'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('caps: 6 periods a day, 30 a week (your personal caps)', $html);
        $this->assertStringContainsString('36 <small>/ 30 cap</small>', $html);
        $this->assertStringContainsString('against a weekly cap of 30', $html);
        $this->assertStringContainsString('above the daily cap of 6', $html);
    }

    /* ---------------- Staffing report ---------------- */

    public function test_staffing_report_judges_teacher_against_her_own_cap()
    {
        $rowOf = function () {
            foreach (Staffing::report()['teachers'] as $row) {
                if ((int) $row['teacher']->id === (int) $this->ananya()->id) {
                    return $row;
                }
            }
            return null;
        };

        $row = $rowOf();
        $this->assertNotNull($row);
        $this->assertSame(40, $row['cap']);
        $this->assertFalse($row['overloaded']);

        $this->setCaps($this->ananya(), null, 30);
        $row = $rowOf();
        $this->assertSame(30, $row['cap']);
        $this->assertTrue($row['overloaded']);
        $this->assertSame(120, $row['utilization']);
    }

    /* ---------------- Validator: week + day caps ---------------- */

    public function test_validator_flags_personal_week_and_day_cap_breaches()
    {
        $record = TimeTableRecord::whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())->firstOrFail();

        $warnings = TimetableValidator::report($record)['warnings'];
        $ananyaDay = array_filter($warnings, function ($w) {
            return strpos($w, 'Ananya Sharma') === 0 && strpos($w, 'day cap') !== false;
        });
        $this->assertCount(0, $ananyaDay, 'at the default day cap of 8 her 8-period days are within cap');

        $this->setCaps($this->ananya(), 5, 30);
        $warnings = TimetableValidator::report($record)['warnings'];
        $this->assertContains('Ananya Sharma carries 8 periods on Monday, over the day cap of 5.', $warnings);
        $this->assertContains('Ananya Sharma carries 36 periods in the week, over the cap of 30.', $warnings);
    }

    /* ---------------- Readiness wording follows the count ---------------- */

    public function test_readiness_sentence_uses_personal_caps_wording_when_an_override_binds()
    {
        $detailOf = function () {
            foreach (\App\Support\Readiness::checks()['checks'] as $check) {
                if ($check['label'] === 'Teacher load within caps') {
                    return $check['detail'];
                }
            }
            return null;
        };

        $this->assertStringContainsString('over the weekly cap of 40.', (string) $detailOf());

        // Ananya (36/week) becomes overloaded only through her
        // personal cap of 30 — the sentence must stop citing the
        // single school number.
        $this->setCaps($this->ananya(), null, 30);
        $detail = (string) $detailOf();
        $this->assertStringContainsString('1 over their weekly caps.', $detail);
    }

    /* ---------------- Manual cell writes (G4) ---------------- */

    /** A genuinely free Friday slot in some record, for a subject Ananya effectively teaches. */
    private function freeFridayCellForAnanya(): array
    {
        $ananya = $this->ananya();
        $records = TimeTableRecord::whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())->get();
        foreach ($records as $record) {
            $subjects = Subject::where('my_class_id', $record->my_class_id)->get()
                ->filter(function ($s) use ($record, $ananya) {
                    return SectionTeacher::teacherIdFor($record->section_id, $s) === (int) $ananya->id;
                });
            if ($subjects->isEmpty()) {
                continue;
            }
            foreach (TimeSlot::where('ttr_id', $record->id)->get() as $slot) {
                $taken = TimeTable::where('ttr_id', $record->id)
                    ->where('ts_id', $slot->id)->where('day', 'Friday')->exists();
                if (!$taken) {
                    return [$record, $subjects->first(), $slot];
                }
            }
        }
        $this->fail('fixture: no free Friday slot found for Ananya');
    }

    public function test_manual_cell_over_day_cap_is_refused()
    {
        // Ananya already carries 8 periods on Monday — the global
        // day cap. One more Monday cell for her subject must be a
        // validation error naming her and the cap.
        $response = $this->actingAs($this->admin())->post(route('tt.store'), [
            'subject_id' => 1,
            'ttr_id' => 1,
            'ts_id' => TimeSlot::where('ttr_id', 1)->firstOrFail()->id,
            'day' => 'Monday',
        ]);
        $response->assertSessionHasErrors('day');
        $errors = session('errors')->get('day');
        $this->assertStringContainsString('Ananya Sharma', $errors[0]);
        $this->assertStringContainsString('daily cap is 8', $errors[0]);
    }

    public function test_manual_cell_within_caps_is_accepted()
    {
        [$record, $subject, $slot] = $this->freeFridayCellForAnanya();

        $response = $this->actingAs($this->admin())->post(route('tt.store'), [
            'subject_id' => $subject->id,
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => 'Friday',
        ]);
        $response->assertOk();
        $response->assertSessionHasNoErrors();
        $this->assertTrue(TimeTable::where('ttr_id', $record->id)
            ->where('ts_id', $slot->id)->where('day', 'Friday')
            ->where('subject_id', $subject->id)->exists());
    }

    public function test_manual_cell_over_personal_week_cap_is_refused()
    {
        // Her week is exactly 36; a personal week cap of 36 leaves
        // no room — even on Friday, where her day has room.
        $this->setCaps($this->ananya(), null, 36);
        [$record, $subject, $slot] = $this->freeFridayCellForAnanya();

        $response = $this->actingAs($this->admin())->post(route('tt.store'), [
            'subject_id' => $subject->id,
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => 'Friday',
        ]);
        $response->assertSessionHasErrors('subject_id');
        $errors = session('errors')->get('subject_id');
        $this->assertStringContainsString('weekly cap is 36', $errors[0]);
    }

    public function test_manual_cell_day_override_raises_the_ceiling()
    {
        // Same Monday post that the day-cap test refuses at the
        // global cap of 8 — a personal day cap of 9 lets the cap
        // check pass (the controller's own duplicate-slot refusal
        // is a different layer and may still answer, but there
        // must be no validation error).
        $this->setCaps($this->ananya(), 9, null);

        $response = $this->actingAs($this->admin())->post(route('tt.store'), [
            'subject_id' => 1,
            'ttr_id' => 1,
            'ts_id' => TimeSlot::where('ttr_id', 1)->firstOrFail()->id,
            'day' => 'Monday',
        ]);
        $response->assertSessionHasNoErrors();
    }

    /* ---------------- Generator vs personal caps ---------------- */

    private function freshTeacher(string $code): User
    {
        return User::create([
            'name' => 'Caps Teacher ' . $code,
            'email' => 'caps-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function freshClassWithSubject(User $teacher, string $code, int $need): array
    {
        $class = MyClass::create(['name' => 'Caps Class ' . $code]);
        $subject = Subject::create([
            'name' => 'Caps Subject ' . $code, 'slug' => 'caps-sub-' . strtolower($code),
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => $need,
        ]);

        return [$class, $subject];
    }

    /**
     * 38 kept cells for the teacher in a second class's record —
     * busy times (15:00) sit outside the generated school day, so
     * only the CAP can bind, never the busy map. Per-day counts
     * stay under the run's day cap of 8.
     */
    private function preloadKeptCells(User $teacher, string $code): void
    {
        [$class, $subject] = $this->freshClassWithSubject($teacher, $code, 6);
        $record = TimeTableRecord::create([
            'name' => $class->name . ' ' . Qs::getCurrentSession(),
            'my_class_id' => $class->id,
            'year' => Qs::getCurrentSession(),
        ]);
        $proto = TimeSlot::firstOrFail();
        $slots = [];
        for ($i = 0; $i < 8; $i++) {
            $attrs = $proto->getAttributes();
            unset($attrs['id']);
            $attrs['ttr_id'] = $record->id;
            $attrs['timestamp_from'] = strtotime('2026-01-05 15:00') + $i * 2400;
            $attrs['timestamp_to'] = strtotime('2026-01-05 15:40') + $i * 2400;
            $slots[] = TimeSlot::create($attrs);
        }
        $perDay = [1 => 7, 2 => 7, 3 => 6, 4 => 6, 5 => 6, 6 => 6]; // 38
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
        foreach ($perDay as $dayNum => $count) {
            for ($i = 0; $i < $count; $i++) {
                TimeTable::create([
                    'ttr_id' => $record->id,
                    'ts_id' => $slots[$i]->id,
                    'subject_id' => $subject->id,
                    'day' => $dayNames[$dayNum],
                    'day_num' => $dayNum,
                    'timestamp_from' => strtotime('2026-01-0' . (4 + $dayNum) . ' 15:00') + $i * 2400,
                    'timestamp_to' => strtotime('2026-01-0' . (4 + $dayNum) . ' 15:40') + $i * 2400,
                ]);
            }
        }
    }

    private function generateForClass(MyClass $class, array $extra = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), array_merge([
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 6,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 6,
                'scope' => 'one',
                'class_id' => $class->id,
                'mode' => 'skip',
                'max_teacher_periods_per_day' => 8,
            ], $extra));
    }

    private function placedForClass(MyClass $class): int
    {
        $recordIds = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->pluck('id');

        return TimeTable::whereIn('ttr_id', $recordIds)->count();
    }

    public function test_generator_week_cap_default_blocks_at_global()
    {
        $teacher = $this->freshTeacher('GW1');
        $this->preloadKeptCells($teacher, 'GW1K');
        [$class] = $this->freshClassWithSubject($teacher, 'GW1', 6);

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        // 38 kept + cap 40 = room for exactly 2 of the 6 required.
        $this->assertSame(2, $this->placedForClass($class));
    }

    public function test_generator_week_cap_raised_by_override()
    {
        $teacher = $this->freshTeacher('GW2');
        $this->preloadKeptCells($teacher, 'GW2K');
        [$class] = $this->freshClassWithSubject($teacher, 'GW2', 6);
        $this->setCaps($teacher, null, 44);

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame(6, $this->placedForClass($class));
    }

    public function test_generator_week_cap_lowered_by_override()
    {
        $teacher = $this->freshTeacher('GW3');
        $this->preloadKeptCells($teacher, 'GW3K');
        [$class] = $this->freshClassWithSubject($teacher, 'GW3', 6);
        $this->setCaps($teacher, null, 39);

        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame(1, $this->placedForClass($class));
    }

    public function test_generator_day_cap_lowered_by_override()
    {
        $teacher = $this->freshTeacher('GD1');
        [$class] = $this->freshClassWithSubject($teacher, 'GD1', 6);
        $this->setCaps($teacher, 1, null);

        // Five school days, one personal period per day at most.
        $response = $this->generateForClass($class, ['days' => 5]);
        $response->assertSessionHas('flash_success');
        $this->assertSame(5, $this->placedForClass($class));
    }

    public function test_generator_day_cap_default_places_full_requirement()
    {
        $teacher = $this->freshTeacher('GD2');
        [$class] = $this->freshClassWithSubject($teacher, 'GD2', 6);

        $response = $this->generateForClass($class, ['days' => 5]);
        $response->assertSessionHas('flash_success');
        $this->assertSame(6, $this->placedForClass($class));
    }

    /* ---------------- G5: cleared day cap leaves an audit row ---------------- */

    public function test_generate_with_cleared_day_cap_writes_audit_row()
    {
        $teacher = $this->freshTeacher('G5A');
        [$class] = $this->freshClassWithSubject($teacher, 'G5A', 6);

        $before = AuditLog::where('action', 'timetable.generated_without_day_cap')->count();
        $response = $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'one', 'class_id' => $class->id, 'mode' => 'skip',
                'max_teacher_periods_per_day' => '',
            ]);
        $response->assertSessionHas('flash_success');

        $this->assertSame($before + 1, AuditLog::where('action', 'timetable.generated_without_day_cap')->count());
        $row = AuditLog::where('action', 'timetable.generated_without_day_cap')->latest('id')->first();
        $this->assertSame((int) $this->admin()->id, (int) $row->user_id);
        $this->assertStringContainsString($class->name, $row->description);
        $this->assertStringContainsString('no day cap', $row->description);
    }

    public function test_generate_with_day_cap_set_writes_no_audit_row()
    {
        $teacher = $this->freshTeacher('G5B');
        [$class] = $this->freshClassWithSubject($teacher, 'G5B', 6);

        $before = AuditLog::where('action', 'timetable.generated_without_day_cap')->count();
        $response = $this->generateForClass($class);
        $response->assertSessionHas('flash_success');
        $this->assertSame($before, AuditLog::where('action', 'timetable.generated_without_day_cap')->count());
    }

    /* ---------------- Admin surface: Edit User form ---------------- */

    private function userUpdatePayload(User $user, array $extra = []): array
    {
        // The seed leaves some profile fields NULL on generated
        // accounts; the update form requires them, so fall back to
        // a real value for any the account does not carry.
        return array_merge([
            'name' => $user->name,
            'gender' => $user->gender ?: 'Female',
            'address' => $user->address ?: '12 Demo Street, Madurai',
            'state_id' => $user->state_id ?: \DB::table('states')->value('id'),
            'nal_id' => $user->nal_id ?: \DB::table('nationalities')->value('id'),
            'email' => $user->email,
        ], $extra);
    }

    public function test_admin_sets_teacher_caps_from_edit_form()
    {
        $ananya = $this->ananya();

        $html = $this->actingAs($this->admin())->get(route('users.edit', Qs::hash($ananya->id)))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Max periods per day', $html);
        $this->assertStringContainsString('school default of 40 periods a week', $html);

        $response = $this->actingAs($this->admin())->put(
            route('users.update', Qs::hash($ananya->id)),
            $this->userUpdatePayload($ananya, ['max_periods_per_day' => 6, 'max_periods_per_week' => 30])
        );
        $response->assertRedirect(route('users.index'));

        $fresh = $ananya->fresh();
        $this->assertSame(6, (int) $fresh->max_periods_per_day);
        $this->assertSame(30, (int) $fresh->max_periods_per_week);
        $this->assertTrue(AuditLog::where('action', 'user.caps_updated')
            ->where('subject_id', $ananya->id)->exists());

        // Blank clears back to the school default (NULL).
        $this->actingAs($this->admin())->put(
            route('users.update', Qs::hash($ananya->id)),
            $this->userUpdatePayload($ananya->fresh(), ['max_periods_per_day' => '', 'max_periods_per_week' => ''])
        );
        $fresh = $ananya->fresh();
        $this->assertNull($fresh->max_periods_per_day);
        $this->assertNull($fresh->max_periods_per_week);
    }

    public function test_teacher_caps_validation_rejects_week_below_day()
    {
        $ananya = $this->ananya();

        $response = $this->actingAs($this->admin())->put(
            route('users.update', Qs::hash($ananya->id)),
            $this->userUpdatePayload($ananya, ['max_periods_per_day' => 10, 'max_periods_per_week' => 8])
        );
        $response->assertSessionHasErrors('max_periods_per_week');
        $this->assertNull($ananya->fresh()->max_periods_per_week);
    }

    public function test_caps_fields_do_not_exist_for_non_teachers()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();

        $html = $this->actingAs($this->admin())->get(route('users.edit', Qs::hash($student->id)))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Max periods per day', $html);

        $this->actingAs($this->admin())->put(
            route('users.update', Qs::hash($student->id)),
            $this->userUpdatePayload($student, ['max_periods_per_day' => 5, 'max_periods_per_week' => 20])
        );
        $fresh = $student->fresh();
        $this->assertNull($fresh->max_periods_per_day);
        $this->assertNull($fresh->max_periods_per_week);
    }
}
