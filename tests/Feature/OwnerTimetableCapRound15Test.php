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
 * Agent D round 15 — teacher daily cap, end to end.
 *
 * The original cap test (OwnerTimetableCapTest) proved the cap
 * BINDS (cap 1 -> 5 placements per subject, never 2 in a day)
 * and that the flash mentions the cap. It never proved the
 * three things that make the cap honest:
 *
 *  (a) CAUSE ORDER: when one slot's candidates mix a capped
 *      teacher with a teacher-less subject, the empty slot must
 *      bucket as CAP, not as no-teacher (round-4 rule: cap >
 *      no-teacher > free > busy). A reversed order would hide
 *      the working lever (raise the cap) behind a misleading
 *      one (assign a teacher — which cannot fix a capped slot).
 *  (b) UNCAPPED CONTROL: the same run with no cap must place
 *      without the constraint (the capped subject piles into
 *      day 1), proving the cap — not the spread logic — was
 *      the binding constraint in (a).
 *  (c) KEPT LOAD COUNTS: a teacher's already-kept Monday cell
 *      in another class's grid must count toward Monday's cap
 *      in a new grid (the preload comment promises "a cap is
 *      real load, not just this run's load"). The kept cell is
 *      stamped OUTSIDE the new grid's slot times, so busy
 *      overlap cannot explain a Monday refusal — only the cap
 *      can.
 *
 * All fixtures are fully fresh and roll back.
 */
class OwnerTimetableCapRound15Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R15 Teacher ' . $tag,
            'email' => 'r15-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R15' . $tag . uniqid(),
        ]);
    }

    protected function generate(int $classId, ?int $cap): string
    {
        $payload = [
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 8,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 5,
            'scope' => 'one',
            'class_id' => $classId,
            'mode' => 'skip',
            'max_teacher_periods_per_day' => $cap,
        ];
        $this->actingAs($this->admin())->post(route('tt.generate'), $payload)
            ->assertRedirect(route('tt.index'));

        return session('flash_success');
    }

    /**
     * Fresh class with one capped-teacher subject (6/week) and
     * one teacher-less subject (6/week). Both targets stay
     * unmet all week, so every empty slot mixes both causes.
     */
    protected function mixedFixture(): array
    {
        $class = MyClass::create(['name' => 'R15 Cap Class ' . uniqid()]);
        $t = $this->teacher('CAP');
        $a = Subject::create([
            'name' => 'R15 Cap Subject ' . uniqid(),
            'slug' => 'r15-cap-' . uniqid(),
            'my_class_id' => $class->id,
            'teacher_id' => $t->id,
            'periods_per_week' => 6,
        ]);
        $b = Subject::create([
            'name' => 'R15 Unstaffed ' . uniqid(),
            'slug' => 'r15-unstaffed-' . uniqid(),
            'my_class_id' => $class->id,
            'teacher_id' => null,
            'periods_per_week' => 6,
        ]);

        return [$class, $t, $a, $b];
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    protected function perDayCounts(TimeTableRecord $record, int $subjectId): array
    {
        $counts = [];
        foreach (TimeTable::where('ttr_id', $record->id)->where('subject_id', $subjectId)->get() as $cell) {
            $counts[$cell->day_num] = ($counts[$cell->day_num] ?? 0) + 1;
        }

        return $counts;
    }

    public function test_capped_slot_buckets_as_cap_not_no_teacher()
    {
        [$class, $t, $a, $b] = $this->mixedFixture();
        $flash = $this->generate($class->id, 1);

        $record = $this->recordFor($class->id);
        $slotsPerDay = TimeSlot::where('ttr_id', $record->id)->count();
        $this->assertGreaterThan(0, $slotsPerDay);

        // 1 placement per day for the capped teacher (5 days),
        // nothing for the teacher-less subject.
        $cells = TimeTable::where('ttr_id', $record->id)->get();
        $this->assertSame(5, $cells->count());
        $this->assertSame(5, TimeTable::where('ttr_id', $record->id)->where('subject_id', $a->id)->count());
        $this->assertSame(0, TimeTable::where('ttr_id', $record->id)->where('subject_id', $b->id)->count());
        foreach ($this->perDayCounts($record, $a->id) as $day => $count) {
            $this->assertSame(1, $count, "Day $day exceeds the cap");
        }

        // Every empty slot mixed both causes, so all of them must
        // bucket as cap (cap > no-teacher) and none as no-teacher.
        $expectedCapped = $slotsPerDay * 5 - 5;
        $this->assertStringContainsString(
            $expectedCapped . ' period(s) left empty (teacher daily cap reached', $flash);
        $this->assertStringNotContainsString(
            'period(s) left empty (no teacher assigned', $flash);
        // The teacher-less subject still shows up honestly in the
        // pre-flight warning (different clause, not the bucket).
        $this->assertStringContainsString('No teacher assigned (those periods may stay empty)', $flash);
    }

    public function test_uncapped_control_places_without_constraint()
    {
        [$class, $t, $a, $b] = $this->mixedFixture();
        $flash = $this->generate($class->id, null);

        $record = $this->recordFor($class->id);
        $slotsPerDay = TimeSlot::where('ttr_id', $record->id)->count();
        $this->assertGreaterThan(0, $slotsPerDay);

        // No cap: the capped subject meets its full 6 target,
        // piled into day 1 (spread logic alone never held it to
        // 1/day), and every remaining slot buckets no-teacher.
        $cells = TimeTable::where('ttr_id', $record->id)->get();
        $this->assertSame(6, $cells->count());
        $perDay = $this->perDayCounts($record, $a->id);
        $this->assertSame(6, $perDay[1]);
        $this->assertGreaterThan(1, max($perDay));

        $expectedNoTeacher = $slotsPerDay * 5 - 6;
        $this->assertStringContainsString(
            $expectedNoTeacher . ' period(s) left empty (no teacher assigned', $flash);
        $this->assertStringNotContainsString('teacher daily cap reached', $flash);
    }

    public function test_kept_load_counts_toward_cap()
    {
        $t = $this->teacher('KEPT');
        $year = Qs::getCurrentSession();

        // Class X: a kept grid holding exactly one Monday cell
        // for T, stamped 4:00–4:40 PM — outside every slot the
        // new grid will generate (last slot ends 3:20 PM), so
        // busy overlap cannot explain any Monday refusal.
        $classX = MyClass::create(['name' => 'R15 Kept Class ' . uniqid()]);
        $subX = Subject::create([
            'name' => 'R15 Kept Subject ' . uniqid(),
            'slug' => 'r15-kept-' . uniqid(),
            'my_class_id' => $classX->id,
            'teacher_id' => $t->id,
            'periods_per_week' => 6,
        ]);
        $recordX = TimeTableRecord::create([
            'name' => 'R15 Kept ' . uniqid(),
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

        // Class Y: T's fresh subject, generated with cap 1.
        $classY = MyClass::create(['name' => 'R15 New Class ' . uniqid()]);
        $subY = Subject::create([
            'name' => 'R15 New Subject ' . uniqid(),
            'slug' => 'r15-new-' . uniqid(),
            'my_class_id' => $classY->id,
            'teacher_id' => $t->id,
            'periods_per_week' => 6,
        ]);
        $flash = $this->generate($classY->id, 1);

        $recordY = $this->recordFor($classY->id);
        $perDay = $this->perDayCounts($recordY, $subY->id);
        // Monday is already at the cap from the kept cell, so Y
        // places nothing Monday and 1/day Tue–Fri.
        $this->assertSame(0, $perDay[1] ?? 0);
        $this->assertSame(4, TimeTable::where('ttr_id', $recordY->id)->count());
        foreach ([2, 3, 4, 5] as $day) {
            $this->assertSame(1, $perDay[$day] ?? 0, "Day $day should place exactly once");
        }
        $this->assertStringContainsString('teacher daily cap reached', $flash);
    }
}
