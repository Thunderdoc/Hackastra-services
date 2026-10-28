<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent D round 24 — cap × double × kept load, the one three-way
 * interaction never probed together.
 *
 * Round 18 proved cap × double (cap 1 blocks the pair, cap 2
 * allows it). Rounds 15/19 proved cap × kept load (a kept cell
 * counts toward the day's cap, even when stamped outside the
 * generated slots, and even when it reaches the teacher only
 * through a section override). Round 17 proved double placement
 * (exactly one TRUE pair, zero wall-clock gap). Never all three:
 *
 *  Fixture: fresh class U, six subjects x 6/week, Science marked
 *  needs_double and taught by T (one distinct teacher per
 *  subject). T ALSO carries exactly ONE kept Monday cell from a
 *  section record that this scope=one run never rebuilds: the
 *  kept subject lives in a host class X (taught there by a
 *  distinct teacher) and reaches T only through a section
 *  override on section K (round-19 design). The kept cell sits
 *  at 4:00–4:40 PM, outside every generated slot, so busy
 *  overlap cannot explain any refusal — only the cap can.
 *
 *  With max_teacher_periods_per_day = 2:
 *   (a) Monday headroom for T is exactly 1 (1 kept + 1 placed
 *       = cap 2), so the pair CANNOT form Monday — it must
 *       form on a non-kept day, exactly once, as a TRUE pair
 *       (consecutive teaching slots, zero wall-clock gap),
 *       the 6/week target must still be met, and the flash
 *       must carry NO double warning.
 *   (b) Regenerating (mode=replace) must reproduce the grid
 *       cell-identical AND pair-identical.
 *   (c) The capped grid must still validate clean (a missing
 *       Monday pair slot is not a clash, break, or stream
 *       error — round-18 control, kept here).
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableCapDoubleKeptRound24Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R24 Teacher ' . $tag,
            'email' => 'r24-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R24' . $tag . uniqid(),
        ]);
    }

    /** Six subjects x 6/week, Science marked needs_double. Returns [class, doubleId, doubleTeacher]. */
    protected function makeSixSubjectClass(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R24 ' . $prefix . ' ' . uniqid()]);
        $doubleId = null;
        $doubleTeacher = null;
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $t = $this->teacher($prefix . $i);
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r24-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $t->id,
                'periods_per_week' => 6,
                'needs_double' => $name === 'Science',
            ]);
            if ($name === 'Science') {
                $doubleId = $sub->id;
                $doubleTeacher = $t;
            }
        }

        return [$class, $doubleId, $doubleTeacher];
    }

    /**
     * One kept Monday cell for $teacherT, via the round-19
     * design: the kept subject lives in host class X (taught
     * there by a distinct teacher) and reaches T only through
     * a section override on section K of class K. The kept
     * cell is stamped Monday 2026-11-02 4:00–4:40 PM — outside
     * every generated slot (last generated slot ends 3:20 PM).
     */
    protected function makeKeptMondayLoad(User $teacherT): void
    {
        $classX = MyClass::create(['name' => 'R24 KeptHost ' . uniqid()]);
        $keptSubject = Subject::create([
            'name' => 'R24 Kept Subject', 'slug' => 'r24-keptsubj-' . uniqid(),
            'my_class_id' => $classX->id, 'teacher_id' => $this->teacher('KEPTHOST')->id,
            'periods_per_week' => 6,
        ]);

        $classK = MyClass::create(['name' => 'R24 Kept ' . uniqid()]);
        Subject::create([
            'name' => 'Kept Other', 'slug' => 'r24-keptk-' . uniqid(),
            'my_class_id' => $classK->id, 'teacher_id' => $this->teacher('KEPTK')->id,
            'periods_per_week' => 6,
        ]);
        $sectionK = Section::create(['name' => 'R24 Sec', 'my_class_id' => $classK->id, 'active' => 1]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $sectionK->id, 'subject_id' => $keptSubject->id, 'teacher_id' => $teacherT->id,
        ]);

        $recordK = TimeTableRecord::create([
            'name' => 'R24 Kept ' . uniqid(), 'my_class_id' => $classK->id,
            'section_id' => $sectionK->id, 'exam_id' => null, 'year' => Qs::getCurrentSession(),
        ]);
        // 2026-11-02 is a Monday (round-15/19 shape).
        $ts = strtotime('2026-11-02 16:00:00');
        $slot = TimeSlot::create([
            'ttr_id' => $recordK->id,
            'hour_from' => 4, 'min_from' => '00', 'meridian_from' => 'PM',
            'hour_to' => 4, 'min_to' => '40', 'meridian_to' => 'PM',
            'time_from' => '4:00 PM', 'time_to' => '4:40 PM',
            'timestamp_from' => $ts, 'timestamp_to' => $ts + 2400,
            'full' => '4:00 PM - 4:40 PM',
        ]);
        TimeTable::create([
            'ttr_id' => $recordK->id, 'ts_id' => $slot->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $keptSubject->id,
            'timestamp_from' => $ts, 'timestamp_to' => $ts + 2400,
        ]);
    }

    protected function generateFor(MyClass $class, $cap): \Illuminate\Testing\TestResponse
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

        return $response;
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    /**
     * Adjacent pairs for one subject: per day, order ALL cells by
     * time; a pair = two of the subject's cells at consecutive
     * slot positions in that day's list (round-17 method).
     * Returns [pairs, layout] where each pair is [day_num,
     * first_from, first_to, second_from, second_to, gap_minutes].
     */
    protected function pairsFor(int $recordId, int $subjectId): array
    {
        $pairs = [];
        $layout = [];
        for ($day = 1; $day <= 6; $day++) {
            $cells = TimeTable::where('ttr_id', $recordId)
                ->where('day_num', $day)->orderBy('timestamp_from')->get();
            $positions = [];
            foreach ($cells as $pos => $cell) {
                if ((int) $cell->subject_id === $subjectId) {
                    $positions[] = [$pos, $cell];
                }
            }
            $dayBits = [];
            foreach ($positions as [$pos, $cell]) {
                $dayBits[] = 'P' . ($pos + 1) . ' ' . date('H:i', $cell->timestamp_from)
                    . '-' . date('H:i', $cell->timestamp_to);
            }
            $layout[] = "Day $day: " . ($dayBits ? implode(', ', $dayBits) : '—');
            for ($i = 0; $i + 1 < count($positions); $i++) {
                [$posA, $cellA] = $positions[$i];
                [$posB, $cellB] = $positions[$i + 1];
                if ($posB === $posA + 1) {
                    $pairs[] = [
                        $day,
                        date('H:i', $cellA->timestamp_from), date('H:i', $cellA->timestamp_to),
                        date('H:i', $cellB->timestamp_from), date('H:i', $cellB->timestamp_to),
                        (int) (($cellB->timestamp_from - $cellA->timestamp_to) / 60),
                    ];
                }
            }
        }

        return [$pairs, implode(' | ', $layout)];
    }

    protected function perDayCounts(int $recordId, int $subjectId): array
    {
        $counts = [];
        for ($day = 1; $day <= 6; $day++) {
            $counts[$day] = TimeTable::where('ttr_id', $recordId)
                ->where('subject_id', $subjectId)->where('day_num', $day)->count();
        }

        return $counts;
    }

    protected function gridTuples(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => [$c->day_num, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
    }

    public function test_cap_two_with_kept_monday_pairs_off_monday_and_meets_target()
    {
        [$class, $doubleId, $teacherT] = $this->makeSixSubjectClass('KEPT2');
        $this->makeKeptMondayLoad($teacherT);
        $this->generateFor($class, 2);
        $record = $this->recordFor($class->id);
        $flash = session('flash_success') ?? '';

        // Target met: 6 cells for the double subject.
        $this->assertSame(6, TimeTable::where('ttr_id', $record->id)
            ->where('subject_id', $doubleId)->count(), 'double subject must meet its 6/week target');

        // Monday headroom is exactly 1 (1 kept + 1 placed = cap 2):
        // the kept Monday cell must count, so Monday places once.
        $perDay = $this->perDayCounts($record->id, $doubleId);
        $this->assertSame(1, $perDay[1],
            'Monday must place exactly once (1 kept + 1 placed = cap 2); per-day: ' . json_encode($perDay));
        $this->assertLessThanOrEqual(2, max($perDay),
            'no day may exceed cap 2 for the double subject; per-day: ' . json_encode($perDay));

        // Exactly one pair, and NOT on the kept day.
        [$pairs, $layout] = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairs, "exactly one adjacent pair in the week; layout: $layout");
        [$day, $fromA, $toA, $fromB, $toB, $gap] = $pairs[0];
        $this->assertNotSame(1, $day,
            "the pair must form on a NON-kept day, not Monday (kept load leaves only 1 headroom there); layout: $layout");
        $this->assertSame(0, $gap,
            "pair must be a TRUE double (zero wall-clock gap); got day $day $fromA-$toA + $fromB-$toB with {$gap}min gap; layout: $layout");
        $this->assertSame($toA, $fromB, "second cell must start exactly when the first ends; layout: $layout");

        // No degraded-double warning: the pair formed.
        $this->assertStringNotContainsString('Double period not possible', $flash,
            "a formed pair must not raise a double warning; flash: $flash");
    }

    public function test_cap_double_kept_grid_is_deterministic_on_regenerate()
    {
        [$class, $doubleId, $teacherT] = $this->makeSixSubjectClass('DET2');
        $this->makeKeptMondayLoad($teacherT);
        $this->generateFor($class, 2);
        $record = $this->recordFor($class->id);
        $first = $this->gridTuples($record->id);
        [$pairsFirst] = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairsFirst, 'first grid must hold exactly one pair');

        $this->generateFor($class, 2);
        $record = $this->recordFor($class->id);
        $second = $this->gridTuples($record->id);
        [$pairsSecond] = $this->pairsFor($record->id, $doubleId);

        $this->assertSame($first, $second, 'regenerated grid must be cell-identical (day + times + subject)');
        $this->assertSame($pairsFirst, $pairsSecond, 'pair position must not move on regenerate');
    }

    public function test_cap_double_kept_grid_validates_clean()
    {
        [$class, $doubleId, $teacherT] = $this->makeSixSubjectClass('CLEAN2');
        $this->makeKeptMondayLoad($teacherT);
        $this->generateFor($class, 2);
        $record = $this->recordFor($class->id);

        $report = TimetableValidator::report($record->fresh());
        $this->assertSame([], $report['errors'],
            'the capped grid must validate clean — a kept-load-constrained Monday is not a clash, break, or stream error');
        $this->assertContains($record->id, TimetableValidator::cleanRecordIds($record->year),
            'the capped grid must keep its green Validated pill');
    }
}
