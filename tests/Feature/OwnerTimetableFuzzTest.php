<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimeSlot;
use App\Support\SectionTeacher;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent D round 2 — fuzz + validator gaps + Manage-page duplicates.
 *
 * (1) FUZZ: seeded-random generator matrix (subject/teacher/cap/
 * override combinations) asserting no clashes, targets met, and
 * determinism; 3 levels with mixed period lengths sharing one
 * teacher; override + cap + rotation combined.
 * (2) BROKEN CELLS: a cell with to <= from must be flagged as a
 * "broken cell" by TimetableValidator (the generator never creates
 * one — slot minutes are validated >= 20 — and broken kept cells
 * cannot block placement, but the validator was silent about them).
 * (3) MANAGE DUPLICATES: posting the same slot twice on the same
 * day used to hit the DB unique index and die with a 500; the
 * store/update paths now refuse gracefully instead.
 * (4) DELETED TEACHER (report-only, no deletion-behavior change):
 * the destroy route refuses attached teachers, but teacherIdFor
 * trusts raw ids — a directly-deleted (legacy) teacher id stays
 * truthy and the validator stays silent about it.
 */
class OwnerTimetableFuzzTest extends TestCase
{
    use DatabaseTransactions;

    private $n = 0;

    private function tag(): string
    {
        $this->n++;
        return 'Fz' . $this->n;
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

    private function generateFor(MyClass $class, array $opts = [], $sectionId = null): void
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
        $response = $this->actingAs($this->admin())->post(route('tt.generate'), $payload);
        $response->assertRedirect(route('tt.index'));
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

    /** Subject ids ordered by day then slot time. */
    private function sequenceFor(TimeTableRecord $record): array
    {
        return TimeTable::where('ttr_id', $record->id)
            ->orderBy('day_num')->orderBy('timestamp_from')
            ->get()->map(fn ($c) => (int) $c->subject_id)->all();
    }

    private function clashesFor(array $recordIds): array
    {
        return array_values(array_filter(
            TimetableValidator::clashes(\App\Helpers\Qs::getCurrentSession()),
            fn ($c) => !empty(array_intersect($c['records'], $recordIds))
        ));
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

    public function test_fuzz_matrix_no_clashes_targets_met_deterministic()
    {
        for ($case = 0; $case < 12; $case++) {
            mt_srand(4242 + $case);
            $tag = $this->tag();
            $class = MyClass::create(['name' => $tag . ' Class']);
            $nSub = mt_rand(3, 8);
            $sharedPool = mt_rand(0, 1) === 1;
            $pool = [];
            if ($sharedPool) {
                $poolSize = mt_rand(2, 3);
                for ($i = 0; $i < $poolSize; $i++) {
                    $pool[] = $this->mkTeacher($tag . 'P' . $i);
                }
            }
            $subjects = [];
            $totalTarget = 0;
            $hasUntargeted = false;
            for ($i = 0; $i < $nSub; $i++) {
                $teacher = $sharedPool ? $pool[mt_rand(0, count($pool) - 1)] : $this->mkTeacher($tag . 'S' . $i);
                $ppw = mt_rand(0, 1) === 1 ? mt_rand(2, 6) : null;
                $subjects[] = Subject::create([
                    'name' => $tag . ' Sub ' . $i,
                    'slug' => strtolower($tag) . '-sub-' . $i,
                    'my_class_id' => $class->id,
                    'teacher_id' => $teacher->id,
                    'periods_per_week' => $ppw,
                    'needs_double' => mt_rand(0, 4) === 0,
                ]);
                if ($ppw !== null) {
                    $totalTarget += $ppw;
                } else {
                    $hasUntargeted = true;
                }
            }
            $days = mt_rand(5, 6);
            $ppd = mt_rand(5, 8);
            $cap = [null, 1, 2, 3][mt_rand(0, 3)];
            $opts = [
                'period_minutes' => [30, 40][mt_rand(0, 1)],
                'periods_per_day' => $ppd,
                'lunch_after' => mt_rand(2, $ppd - 1),
                'lunch_minutes' => 45,
                'break_after' => mt_rand(0, $ppd - 1),
                'break_minutes' => 15,
                'days' => $days,
            ];
            if ($cap !== null) {
                $opts['max_teacher_periods_per_day'] = $cap;
            }
            $this->generateFor($class, $opts);
            $record = $this->recordFor($class);
            $cells = TimeTable::where('ttr_id', $record->id)->get();
            // No clashes involving the new grid.
            $this->assertSame([], $this->clashesFor([$record->id]), "case $case: clashes");
            // No subject placed beyond its weekly target.
            foreach ($subjects as $s) {
                if ($s->periods_per_week !== null) {
                    $placed = $cells->where('subject_id', $s->id)->count();
                    $this->assertLessThanOrEqual((int) $s->periods_per_week, $placed, "case $case: {$s->name} over target");
                }
            }
            // Cap respected per teacher per day (across this record).
            if ($cap !== null) {
                foreach ($this->teacherDayCounts([$record]) as $tid => $byDay) {
                    foreach ($byDay as $day => $count) {
                        $this->assertLessThanOrEqual($cap, $count, "case $case: teacher $tid over cap on $day");
                    }
                }
            }
            // Uncapped + fresh teachers: every slot fills. With
            // only targeted subjects, placed == min(target, slots);
            // an untargeted (null ppw) subject legacy-fills the
            // leftover slots, so placed == slots.
            if ($cap === null) {
                $slots = $days * $ppd;
                $expected = $hasUntargeted ? $slots : min($totalTarget, $slots);
                $this->assertSame($expected, $cells->count(), "case $case: placed vs budget");
            }
            // Determinism: regenerate and compare the full sequence.
            $first = $this->sequenceFor($record);
            $this->generateFor($class, $opts);
            $record2 = $this->recordFor($class);
            $this->assertSame($record->id, $record2->id, "case $case: record replaced, not duplicated");
            $this->assertSame($first, $this->sequenceFor($record2), "case $case: regeneration not deterministic");
        }
    }

    public function test_three_levels_mixed_period_lengths_shared_teacher_no_overlap()
    {
        $tag = $this->tag();
        $shared = $this->mkTeacher($tag . 'Shared');
        $classes = [];
        $configs = [
            ['start' => '09:00', 'mins' => 40],
            ['start' => '09:20', 'mins' => 30],
            ['start' => '09:10', 'mins' => 45],
        ];
        foreach ($configs as $ci => $cfg) {
            $class = MyClass::create(['name' => $tag . ' Lv' . $ci]);
            Subject::create(['name' => 'Common', 'slug' => strtolower($tag) . '-common-' . $ci,
                'my_class_id' => $class->id, 'teacher_id' => $shared->id, 'periods_per_week' => 5]);
            for ($i = 1; $i <= 3; $i++) {
                Subject::create(['name' => $tag . ' S' . $ci . $i, 'slug' => strtolower($tag) . "-s{$ci}{$i}",
                    'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . "L{$ci}T{$i}")->id,
                    'periods_per_week' => 5]);
            }
            $this->generateFor($class, [
                'start_time' => $cfg['start'], 'period_minutes' => $cfg['mins'],
                'periods_per_day' => 6, 'lunch_after' => 3, 'lunch_minutes' => 30,
                'break_after' => 0, 'days' => 5,
            ]);
            $classes[] = $class;
        }
        $records = array_map(fn ($c) => $this->recordFor($c), $classes);
        $ids = array_map(fn ($r) => $r->id, $records);
        $this->assertSame([], $this->clashesFor($ids), 'mixed-length levels clash');
        // Direct interval proof for the shared teacher: no two of
        // his cells overlap on the same day, across all 3 records.
        $intervals = [];
        foreach ($records as $record) {
            foreach (TimeTable::with('subject')->where('ttr_id', $record->id)->get() as $cell) {
                if (SectionTeacher::teacherIdFor(null, $cell->subject) === $shared->id) {
                    $intervals[$cell->day][] = [(int) $cell->timestamp_from % 86400, (int) $cell->timestamp_to % 86400];
                }
            }
        }
        foreach ($intervals as $day => $list) {
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
            $prevEnd = null;
            foreach ($list as [$f, $t]) {
                if ($prevEnd !== null) {
                    $this->assertGreaterThanOrEqual($prevEnd, $f, "shared teacher overlaps on $day");
                }
                $prevEnd = $prevEnd === null ? $t : max($prevEnd, $t);
            }
        }
    }

    public function test_override_cap_rotation_matrix()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $tEng = $this->mkTeacher($tag . 'Eng');
        $tMath = $this->mkTeacher($tag . 'Math');
        $tSci = $this->mkTeacher($tag . 'Sci');
        $ox = $this->mkTeacher($tag . 'Ox');
        $oy = $this->mkTeacher($tag . 'Oy');
        $eng = Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-eng',
            'my_class_id' => $class->id, 'teacher_id' => $tEng->id, 'periods_per_week' => 6]);
        $math = Subject::create(['name' => 'Mathematics', 'slug' => strtolower($tag) . '-math',
            'my_class_id' => $class->id, 'teacher_id' => $tMath->id, 'periods_per_week' => 6]);
        $sci = Subject::create(['name' => 'Science', 'slug' => strtolower($tag) . '-sci',
            'my_class_id' => $class->id, 'teacher_id' => $tSci->id, 'periods_per_week' => 6]);
        $secA = Section::create(['name' => 'A', 'my_class_id' => $class->id, 'teacher_id' => $tEng->id]);
        $secB = Section::create(['name' => 'B', 'my_class_id' => $class->id, 'teacher_id' => $tMath->id]);
        DB::table('section_subject_teachers')->insert([
            ['section_id' => $secA->id, 'subject_id' => $eng->id, 'teacher_id' => $ox->id],
            ['section_id' => $secB->id, 'subject_id' => $eng->id, 'teacher_id' => $oy->id],
        ]);
        $opts = ['periods_per_day' => 6, 'lunch_after' => 3, 'lunch_minutes' => 30,
            'break_after' => 0, 'days' => 5, 'max_teacher_periods_per_day' => 2];
        $this->generateFor($class, $opts, $secA->id);
        $this->generateFor($class, $opts, $secB->id);
        $recA = $this->recordFor($class, $secA->id);
        $recB = $this->recordFor($class, $secB->id);
        $this->assertSame([], $this->clashesFor([$recA->id, $recB->id]), 'override+cap grids clash');
        // Cap counts KEPT periods: the shared Math teacher never
        // exceeds 2/day across BOTH section grids combined.
        foreach ($this->teacherDayCounts([$recA, $recB]) as $tid => $byDay) {
            foreach ($byDay as $day => $count) {
                $this->assertLessThanOrEqual(2, $count, "teacher $tid over cap on $day");
            }
        }
        // Override teachers actually teach their sections' English.
        $this->assertSame($ox->id, SectionTeacher::teacherIdFor($secA->id, $eng));
        $this->assertSame($oy->id, SectionTeacher::teacherIdFor($secB->id, $eng));
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $recA->id)->where('subject_id', $eng->id)->count());
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $recB->id)->where('subject_id', $eng->id)->count());
        // Regenerating A is deterministic.
        $seqA = $this->sequenceFor($recA);
        $this->generateFor($class, $opts, $secA->id);
        $this->assertSame($seqA, $this->sequenceFor($this->recordFor($class, $secA->id)));
    }

    public function test_neighbouring_sections_rotate_differently()
    {
        // Isolated rotation proof: every subject is overridden per
        // section with FRESH teachers, so no kept load can explain
        // a difference — the only difference between the runs is
        // the rotation seed (section id). First pick of day 1 is
        // the argmin rotation, so it MUST differ, and with it the
        // whole sequence.
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $subjects = [];
        for ($i = 0; $i < 6; $i++) {
            $subjects[] = Subject::create(['name' => $tag . ' Sub ' . $i, 'slug' => strtolower($tag) . '-sub-' . $i,
                'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . 'C' . $i)->id,
                'periods_per_week' => 6]);
        }
        $secA = Section::create(['name' => 'A', 'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . 'LA')->id]);
        $secB = Section::create(['name' => 'B', 'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . 'LB')->id]);
        foreach ([$secA, $secB] as $si => $sec) {
            $rows = [];
            foreach ($subjects as $s) {
                $rows[] = ['section_id' => $sec->id, 'subject_id' => $s->id,
                    'teacher_id' => $this->mkTeacher($tag . "O{$si}S{$s->id}")->id];
            }
            DB::table('section_subject_teachers')->insert($rows);
        }
        $opts = ['periods_per_day' => 6, 'lunch_after' => 3, 'lunch_minutes' => 30,
            'break_after' => 0, 'days' => 5];
        $this->generateFor($class, $opts, $secA->id);
        $this->generateFor($class, $opts, $secB->id);
        $seqA = $this->sequenceFor($this->recordFor($class, $secA->id));
        $seqB = $this->sequenceFor($this->recordFor($class, $secB->id));
        $this->assertNotSame($seqA[0], $seqB[0], 'day-1 first pick identical despite different rotation seeds');
        $this->assertNotSame($seqA, $seqB, 'neighbouring section grids share one rotation');
    }

    public function test_streamed_section_grid_places_only_own_stream()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $mk = fn ($name, $stream) => Subject::create(['name' => $name, 'slug' => strtolower($tag) . '-' . strtolower($name),
            'my_class_id' => $class->id, 'teacher_id' => $this->mkTeacher($tag . $name)->id,
            'periods_per_week' => 6, 'stream' => $stream]);
        $eng = $mk('English', null);
        $phy = $mk('Physics', 'pcm,pcb');
        $che = $mk('Chemistry', 'pcm,pcb');
        $acc = $mk('Accountancy', 'commerce');
        $bus = $mk('Business', 'commerce');
        $his = $mk('History', 'arts');
        $secS = Section::create(['name' => 'S', 'my_class_id' => $class->id,
            'teacher_id' => $this->mkTeacher($tag . 'LS')->id, 'stream' => 'pcm']);
        $secC = Section::create(['name' => 'C', 'my_class_id' => $class->id,
            'teacher_id' => $this->mkTeacher($tag . 'LC')->id, 'stream' => 'commerce']);
        $opts = ['periods_per_day' => 6, 'lunch_after' => 3, 'lunch_minutes' => 30,
            'break_after' => 0, 'days' => 5];
        $this->generateFor($class, $opts, $secS->id);
        $this->generateFor($class, $opts, $secC->id);
        $recS = $this->recordFor($class, $secS->id);
        $recC = $this->recordFor($class, $secC->id);
        $this->assertSame([], $this->clashesFor([$recS->id, $recC->id]), 'streamed grids clash');
        $placedS = TimeTable::where('ttr_id', $recS->id)->get()->groupBy('subject_id')->map->count();
        $placedC = TimeTable::where('ttr_id', $recC->id)->get()->groupBy('subject_id')->map->count();
        // PCM section: common + pcm/pcb only — never commerce/arts.
        foreach ([$eng, $phy, $che] as $s) {
            $this->assertGreaterThan(0, $placedS[$s->id] ?? 0, "PCM grid missing {$s->name}");
        }
        foreach ([$acc, $bus, $his] as $s) {
            $this->assertSame(0, $placedS[$s->id] ?? 0, "PCM grid placed {$s->name}");
        }
        // Commerce section: common + commerce only.
        foreach ([$eng, $acc, $bus] as $s) {
            $this->assertGreaterThan(0, $placedC[$s->id] ?? 0, "Commerce grid missing {$s->name}");
        }
        foreach ([$phy, $che, $his] as $s) {
            $this->assertSame(0, $placedC[$s->id] ?? 0, "Commerce grid placed {$s->name}");
        }
        // Fully-streamed class without a section is refused (the
        // whole-class grid would mix every stream's subjects).
        $refused = $this->actingAs($this->admin())->from(route('tt.generate.form'))
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 3, 'lunch_minutes' => 30, 'break_after' => 0, 'days' => 5,
                'scope' => 'one', 'class_id' => $class->id, 'mode' => 'skip',
            ]);
        $refused->assertRedirect(route('tt.generate.form'));
        $refused->assertSessionHas('flash_danger');
        $this->assertStringContainsString('fully streamed', (string) $refused->getSession()->get('flash_danger'));
        // Regenerating the PCM section twice in a row is
        // deterministic. (Regenerating it right after C was built
        // legitimately differs from the first build: C's kept
        // cells now preload the shared English teacher's load —
        // the busy map MUST respect kept grids. Determinism
        // means same surrounding state → same grid.)
        $this->generateFor($class, $opts, $secS->id);
        $seqS = $this->sequenceFor($this->recordFor($class, $secS->id));
        $this->generateFor($class, $opts, $secS->id);
        $this->assertSame($seqS, $this->sequenceFor($this->recordFor($class, $secS->id)));
    }

    public function test_manage_duplicate_slot_refused_gracefully()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $teacher = $this->mkTeacher($tag);
        $subject = Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-eng',
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $subject2 = Subject::create(['name' => 'Maths', 'slug' => strtolower($tag) . '-math',
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $record = TimeTableRecord::create(['name' => $tag . ' Timetable', 'my_class_id' => $class->id,
            'section_id' => null, 'exam_id' => null, 'year' => \App\Helpers\Qs::getCurrentSession()]);
        $slot = TimeSlot::create(['ttr_id' => $record->id, 'hour_from' => '9', 'min_from' => '00',
            'meridian_from' => 'AM', 'hour_to' => '9', 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('2026-01-05 09:00'), 'timestamp_to' => strtotime('2026-01-05 09:40'),
            'full' => '9:00 AM - 9:40 AM']);
        $slot2 = TimeSlot::create(['ttr_id' => $record->id, 'hour_from' => '9', 'min_from' => '40',
            'meridian_from' => 'AM', 'hour_to' => '10', 'min_to' => '20', 'meridian_to' => 'AM',
            'time_from' => '9:40 AM', 'time_to' => '10:20 AM',
            'timestamp_from' => strtotime('2026-01-05 09:40'), 'timestamp_to' => strtotime('2026-01-05 10:20'),
            'full' => '9:40 AM - 10:20 AM']);
        $payload = ['ttr_id' => $record->id, 'ts_id' => $slot->id, 'day' => 'Monday', 'subject_id' => $subject->id];
        $first = $this->actingAs($this->admin())->post(route('tt.store'), $payload);
        $first->assertOk()->assertJson(['ok' => true]);
        // Same day + same slot again: graceful refusal, NOT a 500,
        // and no second row.
        $dup = $this->actingAs($this->admin())->post(route('tt.store'), $payload);
        $dup->assertOk()->assertJson(['ok' => false]);
        $this->assertSame(1, TimeTable::where('ttr_id', $record->id)->count());
        // A different slot the same day is fine.
        $ok = $this->actingAs($this->admin())->post(route('tt.store'),
            ['ttr_id' => $record->id, 'ts_id' => $slot2->id, 'day' => 'Monday', 'subject_id' => $subject2->id]);
        $ok->assertOk()->assertJson(['ok' => true]);
        // Moving the second cell INTO the occupied slot via update
        // is refused with a flash, and the row is unchanged.
        $cell2 = TimeTable::where('ttr_id', $record->id)->where('ts_id', $slot2->id)->firstOrFail();
        $upd = $this->actingAs($this->admin())->from(route('ttr.manage', $record->id))
            ->put(route('tt.update', $cell2->id),
                ['ttr_id' => $record->id, 'ts_id' => $slot->id, 'day' => 'Monday', 'subject_id' => $subject2->id]);
        $upd->assertRedirect(route('ttr.manage', $record->id));
        $upd->assertSessionHas('flash_danger');
        $this->assertSame($slot2->id, (int) TimeTable::find($cell2->id)->ts_id);
    }

    public function test_broken_cells_flagged_by_validator()
    {
        $tag = $this->tag();
        $class = MyClass::create(['name' => $tag . ' Class']);
        $teacher = $this->mkTeacher($tag);
        $subject = Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-eng',
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $record = TimeTableRecord::create(['name' => $tag . ' Timetable', 'my_class_id' => $class->id,
            'section_id' => null, 'exam_id' => null, 'year' => \App\Helpers\Qs::getCurrentSession()]);
        $base = strtotime('2026-01-05 09:00');
        // Lone zero-length cell: nothing else that day to overlap.
        TimeTable::create(['ttr_id' => $record->id, 'ts_id' => 1, 'subject_id' => $subject->id,
            'day' => 'Monday', 'day_num' => 1, 'timestamp_from' => $base, 'timestamp_to' => $base]);
        // Negative-length cell on Tuesday.
        TimeTable::create(['ttr_id' => $record->id, 'ts_id' => 1, 'subject_id' => $subject->id,
            'day' => 'Tuesday', 'day_num' => 2, 'timestamp_from' => $base + 3600, 'timestamp_to' => $base]);
        // A valid cell + a zero-length cell inside it on Wednesday:
        // the broken cell must be reported once, as broken — not
        // also as a class overlap or a teacher clash.
        TimeTable::create(['ttr_id' => $record->id, 'ts_id' => 1, 'subject_id' => $subject->id,
            'day' => 'Wednesday', 'day_num' => 3, 'timestamp_from' => $base, 'timestamp_to' => $base + 2400]);
        TimeTable::create(['ttr_id' => $record->id, 'ts_id' => 2, 'subject_id' => $subject->id,
            'day' => 'Wednesday', 'day_num' => 3, 'timestamp_from' => $base + 600, 'timestamp_to' => $base + 600]);
        $report = TimetableValidator::report($record);
        $joined = implode(' | ', $report['errors']);
        $this->assertStringContainsString('Broken cell on Monday', $joined);
        $this->assertStringContainsString('Broken cell on Tuesday', $joined);
        $this->assertStringContainsString('Broken cell on Wednesday', $joined);
        $this->assertStringNotContainsString('overlap', strtolower($joined), 'broken cell double-reported as overlap');
        $this->assertSame([], array_filter(TimetableValidator::clashes($record->year),
            fn ($c) => in_array($record->id, $c['records'], true)), 'broken cell double-reported as clash');
    }

    public function test_broken_kept_cell_does_not_block_generation()
    {
        $tag = $this->tag();
        $teacher = $this->mkTeacher($tag);
        $classA = MyClass::create(['name' => $tag . ' A']);
        $subjectA = Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-a',
            'my_class_id' => $classA->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $recordA = TimeTableRecord::create(['name' => $tag . ' A Timetable', 'my_class_id' => $classA->id,
            'section_id' => null, 'exam_id' => null, 'year' => \App\Helpers\Qs::getCurrentSession()]);
        $base = strtotime('2026-01-05 09:00');
        // Broken kept cell: zero-length, Monday 9:00, same teacher.
        TimeTable::create(['ttr_id' => $recordA->id, 'ts_id' => 1, 'subject_id' => $subjectA->id,
            'day' => 'Monday', 'day_num' => 1, 'timestamp_from' => $base, 'timestamp_to' => $base]);
        // A new class whose teacher is the same person, generating
        // Monday 9:00 must still place (the broken interval is
        // empty and can never satisfy the overlap predicate).
        $classB = MyClass::create(['name' => $tag . ' B']);
        Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-b',
            'my_class_id' => $classB->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $this->generateFor($classB, ['periods_per_day' => 6, 'lunch_after' => 3, 'lunch_minutes' => 30,
            'break_after' => 0, 'days' => 5]);
        $recB = $this->recordFor($classB);
        $monday = TimeTable::where('ttr_id', $recB->id)->where('day', 'Monday')->get();
        $this->assertGreaterThan(0, $monday->count(), 'broken kept cell blocked Monday placement');
    }

    public function test_deleted_teacher_id_still_trusted_by_resolver()
    {
        // The destroy ROUTE refuses attached teachers (guard holds).
        $tag = $this->tag();
        $teacher = $this->mkTeacher($tag);
        $class = MyClass::create(['name' => $tag . ' Class']);
        $subject = Subject::create(['name' => 'English', 'slug' => strtolower($tag) . '-eng',
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id, 'periods_per_week' => 6]);
        $record = TimeTableRecord::create(['name' => $tag . ' Timetable', 'my_class_id' => $class->id,
            'section_id' => null, 'exam_id' => null, 'year' => \App\Helpers\Qs::getCurrentSession()]);
        $base = strtotime('2026-01-05 09:00');
        TimeTable::create(['ttr_id' => $record->id, 'ts_id' => 1, 'subject_id' => $subject->id,
            'day' => 'Monday', 'day_num' => 1, 'timestamp_from' => $base, 'timestamp_to' => $base + 2400]);
        $refused = $this->actingAs($this->admin())->from(route('users.index'))
            ->delete(route('users.destroy', \App\Helpers\Qs::hash($teacher->id)));
        $refused->assertRedirect(route('users.index'));
        $this->assertNotNull(User::find($teacher->id), 'guard let an attached teacher be deleted via route');
        // Legacy / direct-DB deletion bypasses the guard: the
        // resolver still returns the dead id (truthy), so the
        // validator reports NO missing-teacher error for it.
        $deadId = $teacher->id;
        User::where('id', $deadId)->delete();
        $this->assertNull(User::find($deadId));
        $this->assertSame($deadId, SectionTeacher::teacherIdFor(null, $subject->fresh()));
        $this->assertNull(SectionTeacher::teacherFor(null, $subject->fresh()), 'id path and model path disagree');
        // The validator reports NO error at all for the dead
        // teacher's periods — that silence IS the documented gap.
        $this->assertSame([], TimetableValidator::report($record)['errors']);
        // NOTE (report-only, finding CORRECTED by snapshot probe):
        // sqlite rowid reuse does NOT happen here — users.id is
        // `integer primary key autoincrement` (sqlite_sequence
        // 3101), and the probe's next user took 3103, not the
        // deleted 3102. So a new user can never silently inherit
        // a dead teacher's timetable. The remaining gap is
        // bounded: the dead id stays truthy in teacherIdFor, the
        // validator reports no error for it, and displays fall
        // back to null/'A teacher'. App-path deletion is fully
        // guarded (subject/section/override), so dead ids can
        // only come from legacy pre-guard data or direct-DB
        // deletion. No deletion-behavior change (parent's call).
    }
}
