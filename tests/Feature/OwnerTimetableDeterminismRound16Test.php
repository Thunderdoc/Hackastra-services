<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 16 — generate determinism RE-PROOF.
 *
 * Round 1 (OwnerTimetableRotationTest) proved determinism before
 * the stream guard, the cap work, and all sibling edits: same
 * class regenerated -> same grid; neighbour classes -> different
 * rotations. This round re-proves both properties WITH a daily
 * cap set, because the cap interacts with the placement sort
 * (capped candidates are skipped mid-loop) and could in
 * principle disturb either property:
 *
 *  (a) SAME CLASS + CAP: generate twice (mode=replace) with
 *      cap 1 -> the two grids are cell-identical (same subject
 *      per day/slot). Full 36-slot grid: 6 subjects x 6/week
 *      over 6 days x 6 periods, cap 1 forces exactly 1/day per
 *      subject, so any drift would show.
 *  (b) NEIGHBOUR CLASSES + CAP: two sequentially created
 *      classes with the same subject names (distinct teachers)
 *      generated with cap 1 -> the grids DIFFER in rotation.
 *      The rotation seed is the class id, so adjacent ids give
 *      adjacent rotBase values mod 6 -> different grids. (Only
 *      N rotations exist for N subjects, so classes N apart
 *      share one by pigeonhole — neighbours must differ.)
 *  (c) CAP + KEPT LOAD: one teacher holds a kept Monday cell
 *      in another grid (stamped outside every generated slot,
 *      round-15 shape) and the class is generated twice with
 *      cap 1 -> both grids identical AND the kept teacher's
 *      subject places 0 Monday in both (the kept load actually
 *      engages, and determinism survives it).
 *
 * All fixtures are fully fresh and roll back.
 */
class OwnerTimetableDeterminismRound16Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R16 Teacher ' . $tag,
            'email' => 'r16-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R16' . $tag . uniqid(),
        ]);
    }

    /** Six subjects, same names as the rotation test, distinct teachers. */
    protected function makeClassWithSubjects(string $prefix): MyClass
    {
        $class = MyClass::create(['name' => 'R16 ' . $prefix . ' Class ' . uniqid()]);
        $names = ['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'];
        foreach ($names as $i => $name) {
            Subject::create([
                'name' => $name,
                'slug' => 'r16-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher($prefix . $i)->id,
                'periods_per_week' => 6,
            ]);
        }

        return $class;
    }

    protected function generateFor(MyClass $class, ?int $cap): void
    {
        $response = $this->actingAs($this->admin())
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
                'max_teacher_periods_per_day' => $cap,
            ]);
        $response->assertRedirect(route('tt.index'));
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    /** Cell tuples ordered by day then slot time: (day_num, subject_id). */
    protected function gridTuples(MyClass $class): array
    {
        $record = $this->recordFor($class->id);

        return TimeTable::where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => [$c->day_num, $c->subject_id])->all();
    }

    /** Subject-name sequence ordered by day then slot time (cross-class). */
    protected function gridNames(MyClass $class): array
    {
        $record = $this->recordFor($class->id);

        return TimeTable::with('subject')->where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => $c->subject->name)->all();
    }

    public function test_same_class_regeneration_with_cap_is_identical()
    {
        $class = $this->makeClassWithSubjects('CAPA');
        $this->generateFor($class, 1);
        $first = $this->gridTuples($class);
        $this->assertCount(36, $first, 'capped full grid must place all 36 cells');

        $this->generateFor($class, 1);
        $second = $this->gridTuples($class);

        $this->assertSame($first, $second);
    }

    public function test_neighbour_classes_with_cap_get_different_rotations()
    {
        // Created sequentially: adjacent class ids -> adjacent
        // rotBase values mod 6 -> different rotations guaranteed.
        $classA = $this->makeClassWithSubjects('CAPB');
        $classB = $this->makeClassWithSubjects('CAPC');
        $this->generateFor($classA, 1);
        $this->generateFor($classB, 1);

        $seqA = $this->gridNames($classA);
        $seqB = $this->gridNames($classB);

        $this->assertCount(36, $seqA);
        $this->assertCount(36, $seqB);
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $name) {
            $this->assertSame(6, count(array_filter($seqA, fn ($n) => $n === $name)), "A: $name");
            $this->assertSame(6, count(array_filter($seqB, fn ($n) => $n === $name)), "B: $name");
        }
        $this->assertNotSame($seqA, $seqB);
    }

    public function test_cap_plus_kept_load_regeneration_is_identical()
    {
        $t = $this->teacher('KEPT');
        $year = Qs::getCurrentSession();

        // Kept grid holding exactly one Monday cell for T,
        // stamped 4:00-4:40 PM — outside every slot the new grid
        // generates (last slot ends 3:20 PM), so busy overlap
        // cannot explain a Monday refusal; only the cap can.
        $classX = MyClass::create(['name' => 'R16 Kept Class ' . uniqid()]);
        $subX = Subject::create([
            'name' => 'R16 Kept Subject ' . uniqid(),
            'slug' => 'r16-kept-' . uniqid(),
            'my_class_id' => $classX->id,
            'teacher_id' => $t->id,
            'periods_per_week' => 6,
        ]);
        $recordX = TimeTableRecord::create([
            'name' => 'R16 Kept ' . uniqid(),
            'my_class_id' => $classX->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => $year,
        ]);
        $slotX = TimeSlot::create([
            'ttr_id' => $recordX->id,
            'hour_from' => 4, 'min_from' => '00', 'meridian_from' => 'PM',
            'hour_to' => 4, 'min_to' => '40', 'meridian_to' => 'PM',
            'time_from' => '4:00 PM', 'time_to' => '4:40 PM',
            'timestamp_from' => strtotime('2026-11-02 16:00:00'),
            'timestamp_to' => strtotime('2026-11-02 16:40:00'),
            'full' => '4:00 PM - 4:40 PM',
        ]);
        TimeTable::create([
            'ttr_id' => $recordX->id, 'ts_id' => $slotX->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $subX->id,
            'timestamp_from' => strtotime('2026-11-02 16:00:00'),
            'timestamp_to' => strtotime('2026-11-02 16:40:00'),
        ]);

        // New class: T teaches one subject; the other five have
        // fresh teachers. Generated twice with cap 1.
        $classY = MyClass::create(['name' => 'R16 New Class ' . uniqid()]);
        $names = ['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'];
        $keptSubjectId = null;
        foreach ($names as $i => $name) {
            $teacherId = $i === 0 ? $t->id : $this->teacher('NEW' . $i)->id;
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r16-new-' . $i . '-' . uniqid(),
                'my_class_id' => $classY->id,
                'teacher_id' => $teacherId,
                'periods_per_week' => 6,
            ]);
            if ($i === 0) {
                $keptSubjectId = $sub->id;
            }
        }

        $this->generateFor($classY, 1);
        $first = $this->gridTuples($classY);
        $recordY = $this->recordFor($classY->id);
        $mondayFirst = TimeTable::where('ttr_id', $recordY->id)
            ->where('day_num', 1)->where('subject_id', $keptSubjectId)->count();
        $this->assertSame(0, $mondayFirst, 'kept Monday load must block Monday placement');

        $this->generateFor($classY, 1);
        $second = $this->gridTuples($classY);
        $mondaySecond = TimeTable::where('ttr_id', $recordY->id)
            ->where('day_num', 1)->where('subject_id', $keptSubjectId)->count();
        $this->assertSame(0, $mondaySecond, 'kept Monday load must block Monday placement on regenerate');

        $this->assertSame($first, $second);
    }
}
