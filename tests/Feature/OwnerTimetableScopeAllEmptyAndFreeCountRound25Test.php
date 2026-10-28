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
 * Agent D round 25 — two never-locked arithmetic/reporting seams.
 *
 * (a) SCOPE=ALL WITH AN EMPTY CLASS. Round 7 locked the SKIP-ALL
 * branch (every class already has a timetable) naming its
 * no-subject targets, but the MIXED branch — some classes
 * generate, some skip, one has zero subjects — was never
 * locked: the else-branch flash appends '. No subjects yet: …'
 * (TimeTableController::generate, line ~918) and the empty
 * target must create zero rows. A silent skip (no naming) or a
 * phantom grid would both be REAL findings.
 *
 * (b) MANAGE-CARD FREE-COUNT ARITHMETIC. The tt.index card
 * renders record_stats['free'], computed in
 * TimeTableController::index as
 *     free = max(0, slots × distinct cell days − placed cells)
 * The DEFINITION (empty days invisible — a record's days come
 * only from its cells) stays an open product call; this suite
 * locks only the ARITHMETIC of the stated formula, with known
 * slots, known distinct cell days, and known placed cells, and
 * pins the number to the record's own card segment (the record
 * name is unique, so the segment cannot be another record's).
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableScopeAllEmptyAndFreeCountRound25Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R25 Teacher ' . $tag,
            'email' => 'r25-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R25' . $tag . uniqid(),
        ]);
    }

    /** Fresh class with six subjects x 6/week, one distinct teacher each. */
    protected function makeSixSubjectClass(string $prefix): MyClass
    {
        $class = MyClass::create(['name' => 'R25 ' . $prefix . ' ' . uniqid()]);
        foreach (['English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $i => $name) {
            Subject::create([
                'name' => $name,
                'slug' => 'r25-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher($prefix . $i)->id,
                'periods_per_week' => 6,
            ]);
        }

        return $class;
    }

    protected function scopeAllPayload(): array
    {
        return [
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'all',
            'mode' => 'skip',
        ];
    }

    public function test_scope_all_names_empty_class_and_generates_normal_class()
    {
        $normal = $this->makeSixSubjectClass('NORMAL');
        $empty = MyClass::create(['name' => 'R25 Empty Class ' . uniqid()]);
        $this->assertSame(0, Subject::where('my_class_id', $empty->id)->count(),
            'the empty fixture class must genuinely have zero subjects');

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeAllPayload());
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        // The mixed-branch flash must NAME the empty class.
        $this->assertStringContainsString('No subjects yet: ' . $empty->name, $flash,
            "scope=all must name the empty class in its 'No subjects yet' clause; flash: $flash");
        $this->assertStringNotContainsString('generation failed', $flash,
            "the run must not fail because of the empty class; flash: $flash");

        // The normal class must actually generate: full 36-cell grid.
        $record = TimeTableRecord::where('my_class_id', $normal->id)
            ->whereNull('exam_id')->whereNull('section_id')->first();
        $this->assertNotNull($record, 'the normal fresh class must gain a timetable record');
        $this->assertSame(36, TimeTable::where('ttr_id', $record->id)->count(),
            'the normal class grid must be complete (6 subjects x 6/week = 36 cells)');

        // Zero rows of any kind for the empty class: no phantom grid.
        $this->assertSame(0, TimeTableRecord::where('my_class_id', $empty->id)->count(),
            'the empty class must gain no timetable record');
        $this->assertSame(0, TimeSlot::whereIn('ttr_id',
            TimeTableRecord::where('my_class_id', $empty->id)->pluck('id'))->count(),
            'the empty class must gain no time slots');
    }

    /**
     * Fixture record: $slotCount slots, cells spread over
     * $cellsByDay (day name => placed count). Returns the record.
     */
    protected function makeFreeCountRecord(string $prefix, int $slotCount, array $cellsByDay): TimeTableRecord
    {
        $class = MyClass::create(['name' => 'R25 Free ' . $prefix . ' ' . uniqid()]);
        $subjects = [];
        foreach (['Alpha', 'Beta'] as $i => $name) {
            $subjects[] = Subject::create([
                'name' => $name,
                'slug' => 'r25-free-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $this->teacher($prefix . $i)->id,
                'periods_per_week' => 6,
            ]);
        }
        $record = TimeTableRecord::create([
            'name' => 'R25 FreeCount ' . $prefix . ' ' . uniqid(),
            'my_class_id' => $class->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        $slotStarts = ['9:00 AM', '9:40 AM', '10:35 AM', '11:15 AM', '12:40 PM', '1:20 PM', '2:00 PM', '2:40 PM'];
        $slots = [];
        for ($i = 0; $i < $slotCount; $i++) {
            $from = strtotime('2026-11-02 ' . $slotStarts[$i]);
            $slots[] = TimeSlot::create([
                'ttr_id' => $record->id,
                'hour_from' => date('g', $from), 'min_from' => date('i', $from), 'meridian_from' => date('A', $from),
                'hour_to' => date('g', $from + 2400), 'min_to' => date('i', $from + 2400), 'meridian_to' => date('A', $from + 2400),
                'time_from' => date('g:i A', $from), 'time_to' => date('g:i A', $from + 2400),
                'timestamp_from' => $from, 'timestamp_to' => $from + 2400,
                'full' => date('g:i A', $from) . ' - ' . date('g:i A', $from + 2400),
            ]);
        }
        $dayDates = ['Monday' => '2026-11-02', 'Tuesday' => '2026-11-03', 'Wednesday' => '2026-11-04'];
        $dayNums = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3];
        $k = 0;
        foreach ($cellsByDay as $day => $count) {
            for ($j = 0; $j < $count; $j++) {
                $slot = $slots[$j % $slotCount];
                $from = strtotime($dayDates[$day] . ' ' . $slotStarts[$j % $slotCount]);
                TimeTable::create([
                    'ttr_id' => $record->id,
                    'ts_id' => $slot->id,
                    'day' => $day,
                    'day_num' => $dayNums[$day],
                    'subject_id' => $subjects[$k % 2]->id,
                    'timestamp_from' => $from,
                    'timestamp_to' => $from + 2400,
                ]);
                $k++;
            }
        }

        return $record;
    }

    /** Card segment for a record: from its unique name to the next record card. */
    protected function cardSegment(string $html, string $recordName): string
    {
        $start = strpos($html, $recordName);
        $this->assertNotFalse($start, "the index page must render the fixture record '$recordName'");
        $rest = substr($html, $start);
        // Cut at the NEXT card div only: the meta span class
        // 'ttc-record-meta' itself contains 'ttc-record', so a bare
        // 'ttc-record' cut would stop before the counts (test-craft
        // trap, caught on the first run — not an app bug).
        $next = strpos($rest, '<div class="ttc-record">', 10);
        return $next === false ? $rest : substr($rest, 0, $next);
    }

    public function test_manage_card_free_count_matches_formula()
    {
        // Inputs: 6 slots × 3 distinct cell days − 10 placed = 8 free.
        $record = $this->makeFreeCountRecord('PART', 6, ['Monday' => 4, 'Tuesday' => 3, 'Wednesday' => 3]);

        $slots = TimeSlot::where('ttr_id', $record->id)->count();
        $days = TimeTable::where('ttr_id', $record->id)->pluck('day')->unique()->count();
        $placed = TimeTable::where('ttr_id', $record->id)->count();
        $this->assertSame(6, $slots, 'fixture must hold exactly 6 slots');
        $this->assertSame(3, $days, 'fixture cells must span exactly 3 distinct days');
        $this->assertSame(10, $placed, 'fixture must hold exactly 10 placed cells');
        $this->assertSame(8, $slots * $days - $placed, 'stated formula must yield 8');

        $response = $this->actingAs($this->admin())->get(route('tt.index'));
        $response->assertOk();
        $segment = $this->cardSegment($response->getContent(), $record->name);
        $this->assertStringContainsString('10 periods', $segment,
            "the card must show the placed count; segment: $segment");
        $this->assertStringContainsString('8 free', $segment,
            "the card must show exactly slots × days − placed = 8 free; segment: $segment");
    }

    public function test_manage_card_free_count_zero_when_grid_full()
    {
        // Inputs: 6 slots × 2 distinct cell days − 12 placed = 0 free.
        $record = $this->makeFreeCountRecord('FULL', 6, ['Monday' => 6, 'Tuesday' => 6]);

        $slots = TimeSlot::where('ttr_id', $record->id)->count();
        $days = TimeTable::where('ttr_id', $record->id)->pluck('day')->unique()->count();
        $placed = TimeTable::where('ttr_id', $record->id)->count();
        $this->assertSame(6, $slots);
        $this->assertSame(2, $days);
        $this->assertSame(12, $placed);
        $this->assertSame(0, $slots * $days - $placed);

        $response = $this->actingAs($this->admin())->get(route('tt.index'));
        $response->assertOk();
        $segment = $this->cardSegment($response->getContent(), $record->name);
        $this->assertStringContainsString('12 periods', $segment,
            "the card must show the placed count; segment: $segment");
        $this->assertStringContainsString('0 free', $segment,
            "a full grid must show 0 free, not a negative or stale count; segment: $segment");
    }
}
