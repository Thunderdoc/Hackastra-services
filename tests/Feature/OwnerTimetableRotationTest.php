<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 2 — rotation question (roles agent C finding):
 * Prep = Class 1 = Class 2 seeded grids share one identical subject
 * rotation (teachers differ, so no clash — but every class's day
 * looks copy-pasted). The generator had the same property: subjects
 * arrive in id order and the placement sort is stable, so identical
 * subject structures produced identical grids for every class.
 *
 * Decision (evidence-backed): VARY, deterministically. The rotation
 * is a pure final tiebreaker in the placement sort — it never
 * overrides the weekly target, the day spread, the daily cap, or
 * the busy map — so balance, cap, load spreading, overrides and
 * clash-freedom are unchanged, and re-generating the same class
 * gives the same grid (determinism kept).
 *
 * The SEEDER's own round-robin is deliberately NOT changed: seeded
 * cell anchors (tt 1 = Prep Monday English, session 11, …) are load-
 * bearing for dozens of tests and other agents' fixtures. Seeded
 * demo grids stay as demo fixtures; regenerated grids vary.
 */
class OwnerTimetableRotationTest extends TestCase
{
    use DatabaseTransactions;

    private function makeClassWithSubjects(string $prefix): MyClass
    {
        $class = MyClass::create(['name' => $prefix . ' Class']);
        $names = ['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'];
        foreach ($names as $i => $name) {
            $teacher = User::create([
                'name' => $prefix . ' Teacher ' . $i,
                'email' => strtolower($prefix) . '-t' . $i . '@test.local',
                'password' => bcrypt('teacher'),
                'user_type' => 'teacher',
                'code' => strtoupper($prefix) . 'T' . $i,
            ]);
            Subject::create([
                'name' => $name,
                'slug' => strtolower($prefix) . '-' . $i,
                'my_class_id' => $class->id,
                'teacher_id' => $teacher->id,
                'periods_per_week' => 6,
            ]);
        }
        return $class;
    }

    private function generateFor(MyClass $class): void
    {
        $response = $this->actingAs(User::where('email', 'admin@hackstra.app')->firstOrFail())
            ->post(route('tt.generate'), [
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
                'mode' => 'replace',
            ]);
        $response->assertRedirect(route('tt.index'));
    }

    /** @return string[] subject names ordered by day then slot time */
    private function sequenceFor(MyClass $class): array
    {
        $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->firstOrFail();
        return TimeTable::with('subject')->where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => $c->subject->name)->all();
    }

    public function test_identical_structures_get_different_rotations()
    {
        $classA = $this->makeClassWithSubjects('RotA');
        $classB = $this->makeClassWithSubjects('RotB');
        $this->generateFor($classA);
        $this->generateFor($classB);

        $seqA = $this->sequenceFor($classA);
        $seqB = $this->sequenceFor($classB);

        $this->assertCount(36, $seqA);
        $this->assertCount(36, $seqB);
        // Balance kept: every subject exactly its weekly target.
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $name) {
            $this->assertSame(6, count(array_filter($seqA, fn ($n) => $n === $name)), "A: $name");
            $this->assertSame(6, count(array_filter($seqB, fn ($n) => $n === $name)), "B: $name");
        }
        // But the grids are no longer the same rotation.
        $this->assertNotSame($seqA, $seqB);
    }

    public function test_regeneration_is_deterministic_for_the_same_class()
    {
        $class = $this->makeClassWithSubjects('RotC');
        $this->generateFor($class);
        $first = $this->sequenceFor($class);
        $this->generateFor($class);
        $second = $this->sequenceFor($class);
        $this->assertSame($first, $second);
    }

    public function test_rotated_grids_have_no_teacher_double_booking()
    {
        $classA = $this->makeClassWithSubjects('RotD');
        $classB = $this->makeClassWithSubjects('RotE');
        $this->generateFor($classA);
        $this->generateFor($classB);

        foreach ([$classA, $classB] as $class) {
            $record = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->firstOrFail();
            $cells = TimeTable::with('subject')->where('ttr_id', $record->id)->get();
            $seen = [];
            foreach ($cells as $cell) {
                $teacherId = $cell->subject->teacher_id;
                $minute = (int) date('H', (int) $cell->timestamp_from) * 60 + (int) date('i', (int) $cell->timestamp_from);
                $key = $teacherId . ':' . $cell->day_num . ':' . $minute;
                $this->assertArrayNotHasKey($key, $seen, "teacher $teacherId double-booked");
                $seen[$key] = true;
            }
        }
    }
}
