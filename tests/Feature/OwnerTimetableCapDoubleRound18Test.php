<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 18 — cap × double tension.
 *
 * Two proven features pull in opposite directions and were never
 * tested together: a needs_double pair needs TWO cells of one
 * subject in ONE day, but max_teacher_periods_per_day = 1 forbids
 * any teacher teaching twice in a day. The pair's teacher teaches
 * only that subject, so cap 1 makes the pair IMPOSSIBLE by
 * construction. The questions:
 *
 *  (a) Does the cap win (pair skipped, target met via singles),
 *      or does the pair still form (cap violated — REAL bug)?
 *  (b) Does the run flash honestly report the degraded double,
 *      or does it silently degrade to singles?
 *  (c) Does the validator still certify the capped grid clean?
 *
 * Fixture mirrors round 17: six subjects x 6/week, Science marked
 * needs_double, one distinct teacher per subject, 6 days x 6
 * periods (break after P2, lunch after P4). All fixtures fresh
 * and rolled back.
 */
class OwnerTimetableCapDoubleRound18Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R18 Teacher ' . $tag,
            'email' => 'r18-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R18' . $tag . uniqid(),
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

    /** Six subjects x 6/week, Science marked needs_double. */
    protected function makeSixSubjectClass(string $prefix): array
    {
        $class = MyClass::create(['name' => 'R18 ' . $prefix . ' ' . uniqid()]);
        $doubleId = null;
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r18-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
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

    /**
     * Adjacent pairs for one subject: per day, order ALL cells by
     * time; a pair = two of the subject's cells at consecutive
     * slot positions in that day's list (round-17 method).
     */
    protected function pairsFor(int $recordId, int $subjectId): array
    {
        $pairs = [];
        for ($day = 1; $day <= 6; $day++) {
            $cells = TimeTable::where('ttr_id', $recordId)
                ->where('day_num', $day)->orderBy('timestamp_from')->get();
            $positions = [];
            foreach ($cells as $pos => $cell) {
                if ((int) $cell->subject_id === $subjectId) {
                    $positions[] = $pos;
                }
            }
            for ($i = 0; $i + 1 < count($positions); $i++) {
                if ($positions[$i + 1] === $positions[$i] + 1) {
                    $pairs[] = $day;
                }
            }
        }

        return $pairs;
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

    public function test_cap_one_blocks_pair_but_target_met_via_singles_and_flash_warns()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('CAP1');
        $this->generateFor($class, 1);
        $record = $this->recordFor($class->id);

        // Target met: 6 cells, exactly 1 per day (cap respected).
        $cells = TimeTable::where('ttr_id', $record->id)->where('subject_id', $doubleId)->count();
        $this->assertSame(6, $cells, 'double subject must still meet its 6/week target');
        $perDay = $this->perDayCounts($record->id, $doubleId);
        $this->assertSame(1, max($perDay), 'cap 1 forbids any day with 2 cells of the double subject; per-day: ' . json_encode($perDay));

        // No pair anywhere: the cap wins, the pair is skipped.
        $pairs = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(0, $pairs, 'cap 1 makes a pair impossible — none may form');

        // Honest flash: the degraded double must be named, not silent.
        $flash = session('flash_success') ?? '';
        $this->assertStringContainsString('Double period not possible', $flash,
            "flash must warn that the double could not be formed; got: $flash");
        $this->assertStringContainsString('Science', $flash,
            "flash must name the degraded subject; got: $flash");
    }

    public function test_uncapped_double_forms_pair_and_no_warning()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('NOCAP');
        $this->generateFor($class, null);
        $record = $this->recordFor($class->id);

        $pairs = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairs, 'uncapped run must form exactly one pair (round-17 control)');

        $flash = session('flash_success') ?? '';
        $this->assertStringNotContainsString('Double period not possible', $flash,
            'a formed pair must not raise a double warning');
    }

    public function test_cap_two_allows_pair_and_no_warning()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('CAP2');
        $this->generateFor($class, 2);
        $record = $this->recordFor($class->id);

        $pairs = $this->pairsFor($record->id, $doubleId);
        $this->assertCount(1, $pairs, 'cap 2 permits 2 cells in a day, so the pair must form');

        $flash = session('flash_success') ?? '';
        $this->assertStringNotContainsString('Double period not possible', $flash,
            'a formed pair must not raise a double warning');
    }

    public function test_capped_grid_validates_clean()
    {
        [$class, $doubleId] = $this->makeSixSubjectClass('CLEAN');
        $this->generateFor($class, 1);
        $record = $this->recordFor($class->id);

        $this->assertContains($record->id, TimetableValidator::cleanRecordIds($record->year),
            'the capped grid (singles only, no pair) must still validate clean — a missing pair is not a clash, break, or stream error');
    }
}
