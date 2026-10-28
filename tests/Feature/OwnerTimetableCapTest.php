<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-28: teachers need free slots. The generator
 * takes an optional max-periods-per-teacher-per-day cap: capped
 * periods stay empty (and are reported) instead of packing the
 * teacher, and the placer prefers the teacher with fewer periods
 * that day so days stop being solid walls.
 */
class OwnerTimetableCapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_daily_cap_binds_and_is_reported()
    {
        $class = MyClass::create(['name' => 'Cap Class']);
        // Fresh teachers: the seeded English teachers already carry
        // kept periods every day, so a daily cap would (correctly)
        // leave them zero new periods. Fresh teachers prove the cap
        // itself binds at exactly 1 new period per day.
        $t1 = User::create(['name' => 'Cap Teacher One', 'email' => 'cap-t1@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'CAPT1']);
        $t2 = User::create(['name' => 'Cap Teacher Two', 'email' => 'cap-t2@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'CAPT2']);
        $s1 = Subject::create(['name' => 'Cap Subject A', 'slug' => 'cap-a', 'my_class_id' => $class->id, 'teacher_id' => $t1->id, 'periods_per_week' => 6]);
        $s2 = Subject::create(['name' => 'Cap Subject B', 'slug' => 'cap-b', 'my_class_id' => $class->id, 'teacher_id' => $t2->id, 'periods_per_week' => 6]);

        $response = $this->actingAs(User::where('email', 'admin@hackstra.app')->firstOrFail())
            ->post(route('tt.generate'), [
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 8,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 5,
                'scope' => 'one',
                'class_id' => $class->id,
                'mode' => 'replace',
                'max_teacher_periods_per_day' => 1,
            ]);
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success');
        $this->assertStringContainsString('daily cap', $flash);

        $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->firstOrFail();
        $cells = TimeTable::with('subject')->where('ttr_id', $record->id)->get();
        // Cap 1/day × 5 days = 5 placements per subject, not 6.
        $this->assertSame(10, $cells->count());

        $perTeacherDay = [];
        foreach ($cells as $cell) {
            $teacherId = SectionTeacher::teacherIdFor(null, $cell->subject);
            $key = $teacherId . ':' . $cell->day_num;
            $perTeacherDay[$key] = ($perTeacherDay[$key] ?? 0) + 1;
        }
        $this->assertNotEmpty($perTeacherDay);
        foreach ($perTeacherDay as $count) {
            $this->assertLessThanOrEqual(1, $count);
        }
    }
}
