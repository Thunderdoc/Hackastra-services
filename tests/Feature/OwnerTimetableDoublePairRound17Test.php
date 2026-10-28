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
 * Agent D round 17 — needs_double pairing PLACEMENT proof.
 *
 * Round 3 documented the design (needs_double = ONE back-to-back
 * pair per week, via the -1000 "continue the previous subject"
 * boost in the placement sort, $doubled set once per target)
 * but never proved WHERE the pair lands. This round proves
 * placement on the generated grid itself:
 *
 *  (a) EXACTLY ONE pair: the double subject (6/week) places
 *      6 cells: one adjacent pair + four singles + one zero
 *      day. No second pair anywhere in the week.
 *  (b) TRUE double, not a break-straddler: the pair's two
 *      cells are consecutive TEACHING slots (adjacent positions
 *      in the day's ordered cell list) with ZERO wall-clock gap
 *      (second.from == first.to). This matters because
 *      buildSlots() emits teaching slots only — lunch/break
 *      merely shift the clock — so a pair straddling a break
 *      would still be "adjacent slots" but NOT a real double
 *      period. The observed pair sits after lunch (P5+P6),
 *      gap 0, so it is a genuine double.
 *  (c) DETERMINISM: regenerating (mode=replace) reproduces
 *      the full grid AND the pair position exactly.
 *  (d) SMALL TARGET: a needs_double subject with target 2
 *      forms exactly one pair (the pair consumes the whole
 *      target) and raises no "Double period not possible"
 *      warning.
 *
 * All fixtures are fully fresh and roll back.
 */
class OwnerTimetableDoublePairRound17Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R17 Teacher ' . $tag,
            'email' => 'r17-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R17' . $tag . uniqid(),
        ]);
    }

    protected function generateFor(MyClass $class): void
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
                'max_teacher_periods_per_day' => null,
            ]);
        $response->assertRedirect(route('tt.index'));
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    /**
     * Adjacent pairs for one subject: per day, order ALL cells by
     * time; a pair = two of the subject's cells at consecutive
     * slot positions in that day's list. Returns pairs as
     * [day_num, first_from, first_to, second_from, second_to,
     * gap_minutes] plus a human layout string for messages.
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

    protected function gridTuples(MyClass $class): array
    {
        $record = $this->recordFor($class->id);

        return TimeTable::where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => [$c->day_num, $c->subject_id])->all();
    }

    /** Six subjects x 6/week, Science marked needs_double. */
    protected function makeSixSubjectClass(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R17 ' . $prefix . ' ' . uniqid()]);
        $doubleId = null;
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r17-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher($prefix . $i)->id,
                'periods_per_week' => 6,
                'needs_double' => $name === 'Science',
            ]);
            if ($name === 'Science') {
                $doubleId = $sub->id;
            }
        }

        return [$class, $doubleId];
    }

    public function test_double_subject_gets_exactly_one_true_pair()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('PAIR');
        $this->generateFor($class);
        $record = $this->recordFor($class->id);

        $cells = TimeTable::where('ttr_id', $record->id)->where('subject_id', $doubleId)->count();
        $this->assertSame(6, $cells, 'double subject must meet its 6/week target');

        [$pairs, $layout] = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairs, "exactly one adjacent pair in the week; layout: $layout");
        [$day, $fromA, $toA, $fromB, $toB, $gap] = $pairs[0];
        $this->assertSame(0, $gap,
            "pair must be a TRUE double (no break/lunch clock gap between cells); got day $day $fromA-$toA + $fromB-$toB with {$gap}min gap; layout: $layout");
        $this->assertSame($toA, $fromB, "second cell must start exactly when the first ends; layout: $layout");
    }

    public function test_pair_position_is_deterministic_on_regenerate()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('DET');
        $this->generateFor($class);
        $first = $this->gridTuples($class);
        $record = $this->recordFor($class->id);
        [$pairsFirst] = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairsFirst, 'first grid must hold exactly one pair');

        $this->generateFor($class);
        $second = $this->gridTuples($class);
        $record = $this->recordFor($class->id);
        [$pairsSecond] = $this->pairsFor($record->id, $doubleId);

        $this->assertSame($first, $second, 'regenerated grid must be cell-identical');
        $this->assertSame($pairsFirst, $pairsSecond, 'pair position must not move on regenerate');
    }

    public function test_double_with_target_two_forms_one_pair()
    {
        // Two-subject fixture: the double subject (target 2) must
        // pair on day 1 — with only two subjects it places by
        // slot 1 at the latest, so the -1000 boost guarantees
        // the second cell lands in the very next slot.
        $class = MyClass::create(['name' => 'R17 Small ' . uniqid()]);
        $double = Subject::create([
            'name' => 'Lab Science',
            'slug' => 'r17-small-double-' . uniqid(),
            'my_class_id' => $class->id,
            'teacher_id' => $this->teacher('SD')->id,
            'periods_per_week' => 2,
            'needs_double' => true,
        ]);
        Subject::create([
            'name' => 'Filler',
            'slug' => 'r17-small-filler-' . uniqid(),
            'my_class_id' => $class->id,
            'teacher_id' => $this->teacher('SF')->id,
            'periods_per_week' => 6,
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45,
                'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'one', 'class_id' => $class->id,
                'mode' => 'replace', 'max_teacher_periods_per_day' => null,
            ]);
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';
        $this->assertStringNotContainsString('Double period not possible', $flash,
            'target 2 can form a pair, so no double warning may fire');

        $record = $this->recordFor($class->id);
        $cells = TimeTable::where('ttr_id', $record->id)->where('subject_id', $double->id)->count();
        $this->assertSame(2, $cells, 'double subject must place its full target of 2');
        [$pairs, $layout] = $this->pairsFor($record->id, $double->id);
        $this->assertCount(1, $pairs, "the 2 cells must form exactly one pair; layout: $layout");
        $this->assertSame(0, $pairs[0][5], "pair must have zero clock gap; layout: $layout");
    }
}
