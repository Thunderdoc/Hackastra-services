<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent D round 3 — deeper generator fuzz.
 *
 * Round 2 covered the 12-case random matrix (5/6 days, caps
 * null/1/2/3). This file attacks the edges round 2 did not:
 * out-of-range inputs, the tightest cap across classes, cap
 * reporting, needs_double pairs under cap + override, and the
 * documented sibling-regeneration determinism subtlety.
 */
class OwnerTimetableFuzzRound3Test extends TestCase
{
    use DatabaseTransactions;

    private $n = 0;

    private function tag(): string
    {
        $this->n++;
        return 'F3' . $this->n;
    }

    private function mkTeacher(string $tag): User
    {
        return User::create([
            'name' => $tag . ' Teacher',
            'email' => strtolower($tag) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => strtoupper($tag),
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function generate(MyClass $class, array $opts = [], $sectionId = null)
    {
        $payload = array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 5,
            'scope' => 'one',
            'class_id' => $class->id,
            'mode' => 'replace',
        ], $opts);
        if ($sectionId) {
            $payload['section_id'] = $sectionId;
        }
        return $this->actingAs($this->admin())->post(route('tt.generate'), $payload);
    }

    private function recordFor(MyClass $class, $sectionId = null): TimeTableRecord
    {
        $q = TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id');
        if ($sectionId) {
            $q->where('section_id', $sectionId);
        } else {
            $q->whereNull('section_id');
        }
        return $q->firstOrFail();
    }

    private function sequenceFor(TimeTableRecord $record): array
    {
        return TimeTable::where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => (int) $c->subject_id)->all();
    }

    /** [teacherId][dayName] => cell count across the given records. */
    private function teacherDayCounts(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            foreach (TimeTable::with('subject')->where('ttr_id', $record->id)->get() as $cell) {
                $tid = SectionTeacher::teacherIdFor($record->section_id ?? null, $cell->subject);
                if ($tid) {
                    $out[$tid][$cell->day] = ($out[$tid][$cell->day] ?? 0) + 1;
                }
            }
        }
        return $out;
    }

    private function clashesFor(array $recordIds): array
    {
        return array_values(array_filter(
            TimetableValidator::clashes(\App\Helpers\Qs::getCurrentSession()),
            fn ($c) => !empty(array_intersect($c['records'], $recordIds))
        ));
    }

    /** A 7-day week is refused by validation, not built, not crashed. */
    public function test_days_seven_refused_by_validation()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $t = $this->mkTeacher($tag . 'T');
        Subject::create([
            'name' => $tag . ' Sub', 'slug' => strtolower($tag) . '-sub',
            'my_class_id' => $class->id, 'teacher_id' => $t->id, 'periods_per_week' => 6,
        ]);

        $response = $this->generate($class, ['days' => 7]);
        $response->assertSessionHasErrors('days');
        $this->assertSame(0, TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->count());
    }

    /** Cap 0 is refused by validation (min:1), not treated as no-cap. */
    public function test_cap_zero_refused_by_validation()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $t = $this->mkTeacher($tag . 'T');
        Subject::create([
            'name' => $tag . ' Sub', 'slug' => strtolower($tag) . '-sub',
            'my_class_id' => $class->id, 'teacher_id' => $t->id, 'periods_per_week' => 6,
        ]);

        $response = $this->generate($class, ['max_teacher_periods_per_day' => 0]);
        $response->assertSessionHasErrors('max_teacher_periods_per_day');
        $this->assertSame(0, TimeTableRecord::where('my_class_id', $class->id)->whereNull('exam_id')->count());
    }

    /** Cap 12 (the max) is accepted and never binds at 6 periods/day. */
    public function test_cap_twelve_accepted_and_never_binds()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $subjects = [];
        for ($i = 0; $i < 2; $i++) {
            $subjects[] = Subject::create([
                'name' => $tag . ' Sub ' . $i, 'slug' => strtolower($tag) . '-sub-' . $i,
                'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . 'T' . $i)->id,
                'periods_per_week' => 6,
            ]);
        }

        $this->generate($class, ['max_teacher_periods_per_day' => 12])->assertRedirect(route('tt.index'));
        $record = $this->recordFor($class);
        $this->assertSame(12, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertSame([], $this->clashesFor([$record->id]));
    }

    /**
     * One teacher, one subject in each of three classes, cap 1/day.
     * Kept-period preload must count across records: class 1 takes
     * the teacher's whole daily budget, so classes 2 and 3 get none
     * of that teacher — and the per-day total never exceeds 1.
     */
    public function test_one_teacher_three_classes_cap_one()
    {
        $tag = $this->tag();
        $shared = $this->mkTeacher($tag . 'SH');
        $records = [];
        foreach (['A', 'B', 'C'] as $suffix) {
            $class = MyClass::create(['name' => $tag . ' Class ' . $suffix]);
            Subject::create([
                'name' => $tag . ' Sub ' . $suffix, 'slug' => strtolower($tag) . '-sub-' . strtolower($suffix),
                'my_class_id' => $class->id, 'teacher_id' => $shared->id, 'periods_per_week' => 6,
            ]);
            $this->generate($class, ['max_teacher_periods_per_day' => 1])->assertRedirect(route('tt.index'));
            $records[] = $this->recordFor($class);
        }

        $counts = $this->teacherDayCounts($records)[$shared->id] ?? [];
        foreach ($counts as $day => $count) {
            $this->assertLessThanOrEqual(1, $count, "shared teacher over cap on $day");
        }
        // Class 1 fills the budget (one period per day); the kept
        // cells then block the same teacher in classes 2 and 3.
        $this->assertSame(5, TimeTable::where('ttr_id', $records[0]->id)->count());
        $this->assertSame(0, TimeTable::where('ttr_id', $records[1]->id)->count());
        $this->assertSame(0, TimeTable::where('ttr_id', $records[2]->id)->count());
        $this->assertSame([], $this->clashesFor(array_map(fn ($r) => $r->id, $records)));
    }

    /**
     * Tightest cap, two subjects, one teacher: 5 periods total
     * (one per day), and the summary names the cap as the cause.
     */
    public function test_cap_one_two_subjects_same_teacher_reports_cap()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $t = $this->mkTeacher($tag . 'T');
        for ($i = 0; $i < 2; $i++) {
            Subject::create([
                'name' => $tag . ' Sub ' . $i, 'slug' => strtolower($tag) . '-sub-' . $i,
                'my_class_id' => $class->id, 'teacher_id' => $t->id, 'periods_per_week' => 6,
            ]);
        }

        $response = $this->generate($class, ['max_teacher_periods_per_day' => 1]);
        $response->assertRedirect(route('tt.index'));
        $msg = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('cap', strtolower((string) $msg));

        $record = $this->recordFor($class);
        $this->assertSame(5, TimeTable::where('ttr_id', $record->id)->count());
        foreach ($this->teacherDayCounts([$record])[$t->id] as $day => $count) {
            $this->assertLessThanOrEqual(1, $count, "teacher over cap on $day");
        }
        $this->assertSame([], $this->clashesFor([$record->id]));
    }

    /**
     * needs_double + cap + section override. Documented design
     * (Slice D comment, 2026-10-23): a double subject gets ONE
     * back-to-back pair during the week — the placer prefers
     * continuing the previous slot's subject until that pair
     * exists, and the remaining periods are singles. A first
     * version of this test demanded a pair every day; that
     * contradicts the documented design (Monday's pair forms,
     * Tuesday's two periods are singles by design) and was
     * corrected, not the generator. Open product question for
     * the owner: should ALL of a double subject's periods be
     * paired instead of one pair per week?
     */
    public function test_needs_double_with_cap_and_override_forms_pairs()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $tClass = $this->mkTeacher($tag . 'CL');
        $tOver = $this->mkTeacher($tag . 'OV');
        $tOther = $this->mkTeacher($tag . 'OT');
        $sec = Section::create(['name' => 'S', 'my_class_id' => $class->id, 'teacher_id' => $tClass->id]);
        $dbl = Subject::create([
            'name' => $tag . ' Double', 'slug' => strtolower($tag) . '-dbl',
            'my_class_id' => $class->id, 'teacher_id' => $tClass->id,
            'periods_per_week' => 4, 'needs_double' => true,
        ]);
        $other = Subject::create([
            'name' => $tag . ' Other', 'slug' => strtolower($tag) . '-oth',
            'my_class_id' => $class->id, 'teacher_id' => $tOther->id, 'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $sec->id, 'subject_id' => $dbl->id, 'teacher_id' => $tOver->id,
        ]);

        $this->generate($class, ['max_teacher_periods_per_day' => 2], $sec->id)->assertRedirect(route('tt.index'));
        $record = $this->recordFor($class, $sec->id);
        $cells = TimeTable::where('ttr_id', $record->id)->get();

        // The double subject meets its target exactly.
        $dblCells = $cells->where('subject_id', $dbl->id)->sortBy('timestamp_from')->values();
        $this->assertSame(4, $dblCells->count());
        // At least one back-to-back pair exists during the week:
        // same day, consecutive slots in the record's slot order.
        $slotOrder = \App\Models\TimeSlot::where('ttr_id', $record->id)
            ->orderBy('timestamp_from')->pluck('id')->all();
        $slotPos = array_flip($slotOrder);
        $pairs = 0;
        foreach ($dblCells->groupBy('day') as $dayCells) {
            $positions = $dayCells->map(fn ($c) => $slotPos[$c->ts_id])->sort()->values();
            for ($i = 1; $i < $positions->count(); $i++) {
                if ($positions[$i] - $positions[$i - 1] === 1) {
                    $pairs++;
                }
            }
        }
        $this->assertGreaterThanOrEqual(1, $pairs, 'double subject formed no back-to-back pair');
        // The override teacher teaches it, and caps hold for all.
        $this->assertSame($tOver->id, SectionTeacher::teacherIdFor($sec->id, $dbl));
        foreach ($this->teacherDayCounts([$record]) as $tid => $byDay) {
            foreach ($byDay as $day => $count) {
                $this->assertLessThanOrEqual(2, $count, "teacher $tid over cap on $day");
            }
        }
        $this->assertSame([], $this->clashesFor([$record->id]));
    }

    /**
     * Sibling-regeneration determinism (round-2 subtlety, pinned):
     * regenerating a section grid twice in a row — same surrounding
     * state — gives the identical grid, and every regeneration
     * stays valid (targets met, no clashes).
     */
    public function test_sibling_regeneration_is_deterministic_and_valid()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $tShared = $this->mkTeacher($tag . 'SH');
        $tA = $this->mkTeacher($tag . 'A');
        $tB = $this->mkTeacher($tag . 'B');
        $secS = Section::create(['name' => 'S', 'my_class_id' => $class->id, 'teacher_id' => $tA->id]);
        $secC = Section::create(['name' => 'C', 'my_class_id' => $class->id, 'teacher_id' => $tB->id]);
        $subX = Subject::create([
            'name' => $tag . ' X', 'slug' => strtolower($tag) . '-x',
            'my_class_id' => $class->id, 'teacher_id' => $tShared->id, 'periods_per_week' => 6,
        ]);
        $subY = Subject::create([
            'name' => $tag . ' Y', 'slug' => strtolower($tag) . '-y',
            'my_class_id' => $class->id, 'teacher_id' => $tA->id, 'periods_per_week' => 6,
        ]);

        $this->generate($class, [], $secS->id)->assertRedirect(route('tt.index'));
        $recS = $this->recordFor($class, $secS->id);
        $first = $this->sequenceFor($recS);
        $this->assertSame(12, count($first));

        // Build the sibling grid, then regenerate S twice.
        $this->generate($class, [], $secC->id)->assertRedirect(route('tt.index'));
        $this->generate($class, [], $secS->id)->assertRedirect(route('tt.index'));
        $second = $this->sequenceFor($recS);
        $this->generate($class, [], $secS->id)->assertRedirect(route('tt.index'));
        $third = $this->sequenceFor($recS);

        // Same surroundings -> same grid (the determinism contract).
        $this->assertSame($second, $third);
        // Every regeneration is valid, whatever the surroundings did.
        $this->assertSame(12, count($second));
        $this->assertSame([], $this->clashesFor([$recS->id]));
        $cells = TimeTable::where('ttr_id', $recS->id)->get();
        $this->assertSame(6, $cells->where('subject_id', $subX->id)->count());
        $this->assertSame(6, $cells->where('subject_id', $subY->id)->count());
    }
}
