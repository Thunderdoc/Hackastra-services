<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 27 — cross-class teacher double-booking in
 * scope=all generation (never probed before).
 *
 * CODE READ FIRST. TimeTableController::generate() builds ONE
 * $busyIntervals map before the target loop (line ~465), passes
 * it BY REFERENCE into every target's transaction closure (line
 * ~608), and every placed cell appends its teacher's interval
 * (line ~812); the busy check is interval overlap per teacher
 * per day (lines ~755-762). So a teacher placed by an earlier
 * target in the SAME scope=all run must block later targets —
 * the brief's "only within the current class's grid" fear would
 * be true only if the map were rebuilt per target. This suite
 * proves the shared map empirically, with a SENSITIVE fixture:
 * one subject per class, target 20/week, a 4-slot x 5-day grid
 * (20 slots exactly). Without cross-class busyness the second
 * class would fill all 20 slots at IDENTICAL day+slot tuples
 * (same slot config) — 20 double-bookings. With it, the second
 * class starves completely.
 *
 * VALIDATOR SCOPE (also read first, correcting the brief's
 * premise): TimetableValidator is NOT blind to cross-record
 * clashes. clashes() sweeps ALL of the year's records per
 * teacher+day, and recordErrors() folds every clash involving
 * the record into report()['errors']. Test 2 proves that with
 * a hand-crafted double-booking.
 *
 * TEST-CRAFT CORRECTIONS (first run 2 failed, 2 passed — both
 * mine, not app bugs): (1) distinct-teacher grids DO overlap in
 * time — that is normal school operation, so the control asserts
 * identical tuples instead; (2) scope=one runs are NOT
 * independent — the kept-record preload blocks the second class
 * too, so the solo control is a lone class and the second-class
 * starvation is locked as its own preload-path test.
 *
 * SCOPE=ALL RESTRICTION. A scope=all run targets every class,
 * so the run is restricted via subjects_present: every seeded
 * class posts an EMPTY selection (no_subjects branch, record
 * untouched) and only the two fixture classes post their
 * subject. The flash must still name seeded classes under
 * 'No subjects yet:' — that clause is the proof the
 * restriction actually held; if empty selections were ever
 * stripped in transit, seeded classes would regenerate and
 * the clause would vanish, failing the test honestly.
 *
 * All fixtures fresh and rolled back.
 */
class OwnerTimetableCrossClassTeacherRound27Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R27 Teacher ' . $tag,
            'email' => 'r27-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R27' . $tag . uniqid(),
        ]);
    }

    /** Fresh class with ONE subject (target 20/week) taught by $teacherId. */
    protected function makeSingleSubjectClass(string $prefix, int $teacherId): array
    {
        $class = MyClass::create(['name' => 'R27 ' . $prefix . ' ' . uniqid()]);
        $subject = Subject::create([
            'name' => 'R27 Shared',
            'slug' => 'r27-' . strtolower($prefix) . '-' . uniqid(),
            'my_class_id' => $class->id,
            'teacher_id' => $teacherId,
            'periods_per_week' => 20,
        ]);

        return [$class, $subject];
    }

    /** 4 teaching slots x 5 days = exactly 20 slots. */
    protected function singleFields(): array
    {
        return [
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 4,
            'lunch_after' => 3,
            'lunch_minutes' => 45,
            'break_after' => 1,
            'break_minutes' => 15,
            'days' => 5,
        ];
    }

    /**
     * scope=all payload restricted to $mine ([classId => [subjectIds]]):
     * every other class posts an empty selection.
     */
    protected function scopeAllPayload(array $mine): array
    {
        $present = [];
        $subjects = [];
        foreach (MyClass::pluck('id') as $id) {
            $present[$id] = [];
            $subjects[$id] = [];
        }
        foreach ($mine as $classId => $subjectIds) {
            $present[$classId] = $subjectIds;
            $subjects[$classId] = $subjectIds;
        }

        return array_merge($this->singleFields(), [
            'scope' => 'all',
            'mode' => 'replace',
            'subjects_present' => $present,
            'subjects' => $subjects,
        ]);
    }

    protected function scopeOnePayload(int $classId): array
    {
        return array_merge($this->singleFields(), [
            'scope' => 'one',
            'class_id' => $classId,
            'mode' => 'replace',
        ]);
    }

    protected function recordFor(int $classId): ?TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->first();
    }

    /** [day_num, fromMinuteOfDay, toMinuteOfDay] per cell of a record. */
    protected function cellIntervals(TimeTableRecord $record): array
    {
        $out = [];
        foreach (TimeTable::where('ttr_id', $record->id)->get() as $c) {
            $out[] = [
                (int) $c->day_num,
                (int) date('G', $c->timestamp_from) * 60 + (int) date('i', $c->timestamp_from),
                (int) date('G', $c->timestamp_to) * 60 + (int) date('i', $c->timestamp_to),
            ];
        }

        return $out;
    }

    /** Overlapping cross-record (day, interval) pairs, as readable strings. */
    protected function crossOverlaps(TimeTableRecord $a, TimeTableRecord $b): array
    {
        $found = [];
        foreach ($this->cellIntervals($a) as [$dayA, $fromA, $toA]) {
            foreach ($this->cellIntervals($b) as [$dayB, $fromB, $toB]) {
                if ($dayA === $dayB && $fromA < $toB && $fromB < $toA) {
                    $found[] = "day $dayA $fromA-$toA vs $fromB-$toB";
                }
            }
        }

        return $found;
    }

    public function test_scope_all_shared_teacher_never_double_booked()
    {
        $shared = $this->teacher('SHARED');
        [$classA, $subjectA] = $this->makeSingleSubjectClass('A', $shared->id);
        [$classB, $subjectB] = $this->makeSingleSubjectClass('B', $shared->id);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeAllPayload([
                $classA->id => [$subjectA->id],
                $classB->id => [$subjectB->id],
            ]));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';

        // The restriction held: seeded classes named as no-subject, untouched.
        $this->assertStringContainsString('No subjects yet:', $flash,
            "the restricted run must name seeded classes as no-subject; flash: $flash");
        $this->assertStringContainsString('Prep', $flash,
            "a seeded class name must appear in the no-subjects clause; flash: $flash");

        $recA = $this->recordFor($classA->id);
        $recB = $this->recordFor($classB->id);
        $this->assertNotNull($recA, 'the first class must gain a record');
        $this->assertNotNull($recB, 'the second class must gain a record');

        // First target fills its whole 20-slot grid with the shared teacher.
        $this->assertSame(20, TimeTable::where('ttr_id', $recA->id)->count(),
            'class A (generated first, id order) must fill all 20 slots');

        // Second target starves completely: the shared teacher is busy
        // at every slot, so every placement is refused as busy.
        $this->assertSame(0, TimeTable::where('ttr_id', $recB->id)->count(),
            'class B must place NOTHING — its only teacher is busy at every slot');
        $this->assertStringContainsString('0 of 20 placed', $flash,
            "class B must report its full shortfall; flash: $flash");
        $this->assertStringContainsString('20 period(s) left empty', $flash,
            "the 20 busy refusals must be reported as empties, not hidden; flash: $flash");

        // And the decisive check: ZERO overlapping day+interval pairs
        // across the two grids for the shared teacher.
        $this->assertSame([], $this->crossOverlaps($recA, $recB),
            'no day+slot may be double-booked across the two grids');

        // Both generated records validate clean (the starved grid has
        // a coverage WARNING, not an error — errors must be empty).
        $this->assertSame([], TimetableValidator::report($recA)['errors'],
            'the filled grid must validate clean');
        $this->assertSame([], TimetableValidator::report($recB)['errors'],
            'the starved grid must validate clean (shortfall is a warning, not an error)');
    }

    public function test_scope_one_solo_class_fills_alone()
    {
        // A lone class with the shared teacher fills completely —
        // the scope=all starvation in test 1 is caused by cross-class
        // busyness, not by the fixture.
        $shared = $this->teacher('SOLO');
        [$class, $subject] = $this->makeSingleSubjectClass('SOLO', $shared->id);

        $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeOnePayload($class->id))
            ->assertRedirect(route('tt.index'));
        $rec = $this->recordFor($class->id);
        $this->assertNotNull($rec);
        $this->assertSame(20, TimeTable::where('ttr_id', $rec->id)->count(),
            'a solo class must fill all 20 slots');
    }

    public function test_scope_one_second_class_blocked_by_kept_record()
    {
        // TEST-CRAFT CORRECTION (first draft assumed scope=one runs
        // were independent — they are NOT): the busy-map PRELOAD
        // (Slice D follow-up) makes kept records of OTHER classes
        // block a later run too. So the second class sharing the
        // teacher must ALSO starve via scope=one — this locks the
        // cross-run preload path, the complement of test 1's
        // in-run shared map.
        $shared = $this->teacher('KEPT');
        [$classA, $subjectA] = $this->makeSingleSubjectClass('A', $shared->id);
        [$classB, $subjectB] = $this->makeSingleSubjectClass('B', $shared->id);

        $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeOnePayload($classA->id))
            ->assertRedirect(route('tt.index'));
        $recA = $this->recordFor($classA->id);
        $this->assertSame(20, TimeTable::where('ttr_id', $recA->id)->count(),
            'class A must fill all 20 slots first');

        $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeOnePayload($classB->id))
            ->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';
        $recB = $this->recordFor($classB->id);
        $this->assertNotNull($recB, 'class B must still gain a record');
        $this->assertSame(0, TimeTable::where('ttr_id', $recB->id)->count(),
            'class B must place NOTHING — class A\'s kept record blocks its only teacher');
        $this->assertStringContainsString('0 of 20 placed', $flash,
            "class B must report its full shortfall; flash: $flash");
    }

    public function test_scope_all_distinct_teachers_both_fill()
    {
        [$classA, $subjectA] = $this->makeSingleSubjectClass('A', $this->teacher('ONE')->id);
        [$classB, $subjectB] = $this->makeSingleSubjectClass('B', $this->teacher('TWO')->id);

        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), $this->scopeAllPayload([
                $classA->id => [$subjectA->id],
                $classB->id => [$subjectB->id],
            ]));
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success') ?? '';
        $this->assertStringContainsString('40 periods placed', $flash,
            "both grids must fill completely with distinct teachers; flash: $flash");

        $recA = $this->recordFor($classA->id);
        $recB = $this->recordFor($classB->id);
        $this->assertSame(20, TimeTable::where('ttr_id', $recA->id)->count());
        $this->assertSame(20, TimeTable::where('ttr_id', $recB->id)->count());
        // TEST-CRAFT CORRECTION (first draft asserted no overlaps
        // here — wrong): DIFFERENT teachers may legitimately teach
        // at the same time, and with one subject + one slot config
        // both grids MUST occupy the identical 20 tuples. That
        // identity is itself the control's strength: the same
        // tuples that are harmless across teachers are exactly what
        // test 1 refuses to double-book for ONE teacher.
        $tuplesA = $this->cellIntervals($recA);
        $tuplesB = $this->cellIntervals($recB);
        sort($tuplesA);
        sort($tuplesB);
        $this->assertSame($tuplesA, $tuplesB,
            'distinct-teacher grids must occupy the identical 20 slot tuples');
    }

    public function test_validator_catches_handcrafted_cross_record_clash()
    {
        $shared = $this->teacher('CLASH');
        $year = Qs::getCurrentSession();

        // Two records, same teacher, same Monday 9:00-9:40 cell.
        $records = [];
        foreach (['A', 'B'] as $tag) {
            [$class, $subject] = $this->makeSingleSubjectClass($tag, $shared->id);
            $record = TimeTableRecord::create([
                'name' => 'R27 Clash ' . $tag . ' ' . uniqid(),
                'my_class_id' => $class->id,
                'section_id' => null,
                'exam_id' => null,
                'year' => $year,
            ]);
            $from = strtotime('2026-11-02 09:00');
            $slot = TimeSlot::create([
                'ttr_id' => $record->id,
                'hour_from' => '9', 'min_from' => '00', 'meridian_from' => 'AM',
                'hour_to' => '9', 'min_to' => '40', 'meridian_to' => 'AM',
                'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
                'timestamp_from' => $from, 'timestamp_to' => $from + 2400,
                'full' => '9:00 AM - 9:40 AM',
            ]);
            TimeTable::create([
                'ttr_id' => $record->id,
                'ts_id' => $slot->id,
                'day' => 'Monday',
                'day_num' => 1,
                'subject_id' => $subject->id,
                'timestamp_from' => $from,
                'timestamp_to' => $from + 2400,
            ]);
            $records[] = $record;
        }
        [$recA, $recB] = $records;

        $clashes = array_values(array_filter(
            TimetableValidator::clashes($year),
            fn ($c) => $c['records'] === [min($recA->id, $recB->id), max($recA->id, $recB->id)]
        ));
        $this->assertCount(1, $clashes,
            'clashes() must report exactly one cross-record clash for the hand-crafted pair');
        $this->assertStringContainsString('Teacher double-booked', $clashes[0]['message'],
            'the clash message must name the double-booking');
        $this->assertStringContainsString($shared->name, $clashes[0]['message'],
            'the clash message must name the shared teacher');

        // And both records' own reports carry it as an error — so the
        // green Validated pill (cleanRecordIds) can never certify
        // either grid while the clash stands.
        $this->assertContains($clashes[0]['message'], TimetableValidator::report($recA)['errors']);
        $this->assertContains($clashes[0]['message'], TimetableValidator::report($recB)['errors']);
        $clean = TimetableValidator::cleanRecordIds($year);
        $this->assertNotContains($recA->id, $clean, 'clashing record A must not be Validated-clean');
        $this->assertNotContains($recB->id, $clean, 'clashing record B must not be Validated-clean');
    }
}
