<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 7 — scope=all summary aggregation audit.
 *
 * A scope=all run builds MANY targets, but the admin sees ONE flash
 * line. Every number in that line must equal the per-target reality
 * summed across targets: placed + empty + capped + no_teacher +
 * free must equal the total slots of the generated targets, and
 * each bucket must come from the targets that actually produced
 * it (no double-counting, no dropped target, no cross-target
 * misattribution). Seeded targets are all skipped in skip mode, so
 * the flash buckets come only from the fresh targets below, whose
 * per-target reality is read back from the DB.
 *
 * Causes are isolated per run because the daily cap is global: a
 * capped run cannot also prove the pure-busy bucket (a kept busy
 * day also counts toward the cap), so busy gets its own cap-free
 * run.
 */
class OwnerTimetableSummaryRound7Test extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'R7 Teacher ' . $code,
            'email' => 'r7-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function subject(string $name, string $slug, int $classId, ?int $teacherId, ?int $ppw, ?string $stream = null): Subject
    {
        return Subject::create([
            'name' => $name, 'slug' => $slug, 'my_class_id' => $classId,
            'teacher_id' => $teacherId, 'periods_per_week' => $ppw, 'stream' => $stream,
        ]);
    }

    private function generateAll(array $extra = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), array_merge([
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 6,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 5,
                'scope' => 'all',
                'mode' => 'skip',
            ], $extra));
    }

    private function cellsForClass(int $classId, ?int $sectionId = null): int
    {
        $q = TimeTableRecord::where('my_class_id', $classId)->whereNull('exam_id')
            ->where('year', Qs::getCurrentSession());
        $q = $sectionId ? $q->where('section_id', $sectionId) : $q->whereNull('section_id');

        return TimeTable::whereIn('ttr_id', $q->pluck('id'))->count();
    }

    private function flashNumber(string $flash, string $pattern): ?int
    {
        if (preg_match($pattern, $flash, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Capped + teacher-less + free in ONE scope=all run.
     * 3 fresh targets x 30 slots = 90 slots.
     * A (ppw 1, fresh teacher): 1 placed, 29 free (target met, so
     * later slots leave the candidate pool and count as free, NOT
     * capped — the cap only binds while a candidate exists).
     * B (ppw 6, NO teacher): 0 placed, 30 no-teacher, shortfall 0/6.
     * C (2 subjects ppw 30, 2 teachers, cap 1/day): 10 placed
     * (2/day), 20 capped, shortfalls 5/30 each.
     * Identity: 1+0+10 placed, 29 free, 20 capped, 30 no-teacher,
     * 0 busy = 90.
     */
    public function test_scope_all_aggregates_capped_teacherless_free()
    {
        $a = MyClass::create(['name' => 'R7 Free']);
        $b = MyClass::create(['name' => 'R7 NoTeacher']);
        $c = MyClass::create(['name' => 'R7 Capped']);
        $this->subject('R7 Free Subject', 'r7-free', $a->id, $this->teacher('R7FA')->id, 1);
        $this->subject('R7 Orphan Subject', 'r7-orphan', $b->id, null, 6);
        $this->subject('R7 Cap Subject One', 'r7-cap-1', $c->id, $this->teacher('R7C1')->id, 30);
        $this->subject('R7 Cap Subject Two', 'r7-cap-2', $c->id, $this->teacher('R7C2')->id, 30);

        $response = $this->generateAll(['max_teacher_periods_per_day' => 1]);
        $response->assertRedirect(route('tt.index'));
        $flash = (string) $response->getSession()->get('flash_success');

        // Per-target DB reality first.
        $this->assertSame(1, $this->cellsForClass($a->id), 'free target places its 1 required period');
        $this->assertSame(0, $this->cellsForClass($b->id), 'teacher-less target places nothing');
        $this->assertSame(10, $this->cellsForClass($c->id), 'capped target places 2/day x 5 days');

        // Flash buckets must match that reality exactly.
        $this->assertSame(11, $this->flashNumber($flash, '/(\d+) periods placed/'), 'flash placed: ' . $flash);
        $this->assertSame(20, $this->flashNumber($flash, '/(\d+) period\(s\) left empty \(teacher daily cap/'), 'flash capped: ' . $flash);
        $this->assertSame(30, $this->flashNumber($flash, '/(\d+) period\(s\) left empty \(no teacher assigned/'), 'flash no-teacher: ' . $flash);
        $this->assertSame(29, $this->flashNumber($flash, '/(\d+) free period\(s\)/'), 'flash free: ' . $flash);
        $this->assertStringNotContainsString('every teacher busy', $flash, 'no busy bucket when nothing was busy');
        $this->assertStringContainsString('Timetables generated for 3 class(es)', $flash);
        // Shortfalls: orphan 0/6, capped subjects 5/30 each — none dropped.
        $this->assertStringContainsString('0 of 6', $flash);
        $this->assertStringContainsString('5 of 30', $flash);
        $this->assertStringContainsString('No teacher assigned', $flash);
        // Slot identity: buckets sum to the generated targets' slots.
        $this->assertSame(90, 11 + 29 + 20 + 30 + 0);
    }

    /**
     * Pure-busy + free in a CAP-FREE scope=all run.
     * Blocker class (skipped: it already has a record) holds the
     * busy teacher on Monday, all 6 slots. D (ppw 30, same teacher):
     * Monday 6 busy-empty, Tue-Fri 24 placed, shortfall 24/30.
     * E (ppw 1, fresh teacher): 1 placed, 29 free.
     * Identity: 25 placed + 6 busy + 29 free = 60 slots.
     */
    public function test_scope_all_aggregates_busy_and_free_without_cap()
    {
        $blocker = MyClass::create(['name' => 'R7 Blocker']);
        $d = MyClass::create(['name' => 'R7 Busy']);
        $e = MyClass::create(['name' => 'R7 FreeTwo']);
        $td = $this->teacher('R7BD');
        $this->subject('R7 Blocker Subject', 'r7-blocker', $blocker->id, $td->id, 6);
        $this->subject('R7 Busy Subject', 'r7-busy', $d->id, $td->id, 30);
        $this->subject('R7 FreeTwo Subject', 'r7-freetwo', $e->id, $this->teacher('R7F2')->id, 1);

        // Kept Monday grid for the busy teacher (6 slots, same clock
        // times the run will use, so every Monday interval overlaps).
        $record = TimeTableRecord::create([
            'name' => 'R7 Blocker Timetable', 'my_class_id' => $blocker->id,
            'section_id' => null, 'exam_id' => null, 'year' => Qs::getCurrentSession(),
        ]);
        $slotTimes = [['09:00', '09:40'], ['09:40', '10:20'], ['10:35', '11:15'], ['11:15', '11:55'], ['12:40', '13:20'], ['13:20', '14:00']];
        $blockerSubject = Subject::where('slug', 'r7-blocker')->firstOrFail();
        foreach ($slotTimes as [$from, $to]) {
            $slot = TimeSlot::create([
                'ttr_id' => $record->id,
                'hour_from' => (int) date('g', strtotime($from)), 'min_from' => date('i', strtotime($from)),
                'meridian_from' => date('A', strtotime($from)),
                'hour_to' => (int) date('g', strtotime($to)), 'min_to' => date('i', strtotime($to)),
                'meridian_to' => date('A', strtotime($to)),
                'time_from' => date('g:i A', strtotime($from)), 'time_to' => date('g:i A', strtotime($to)),
                'timestamp_from' => strtotime('2026-01-05 ' . $from), 'timestamp_to' => strtotime('2026-01-05 ' . $to),
                'full' => date('g:i A', strtotime($from)) . ' - ' . date('g:i A', strtotime($to)),
            ]);
            TimeTable::create([
                'ttr_id' => $record->id, 'ts_id' => $slot->id, 'subject_id' => $blockerSubject->id,
                'day' => 'Monday', 'day_num' => 1,
                'timestamp_from' => strtotime('2026-01-05 ' . $from),
                'timestamp_to' => strtotime('2026-01-05 ' . $to),
            ]);
        }

        $response = $this->generateAll();
        $response->assertRedirect(route('tt.index'));
        $flash = (string) $response->getSession()->get('flash_success');

        $this->assertSame(24, $this->cellsForClass($d->id), 'busy target places Tue-Fri only');
        $this->assertSame(1, $this->cellsForClass($e->id), 'free target places its 1 required period');
        $this->assertSame(25, $this->flashNumber($flash, '/(\d+) periods placed/'), 'flash placed: ' . $flash);
        $this->assertSame(6, $this->flashNumber($flash, '/(\d+) period\(s\) left empty \(every teacher busy/'), 'flash busy: ' . $flash);
        $this->assertSame(29, $this->flashNumber($flash, '/(\d+) free period\(s\)/'), 'flash free: ' . $flash);
        $this->assertStringNotContainsString('daily cap', $flash, 'no cap bucket without a cap');
        $this->assertStringNotContainsString('no teacher assigned', $flash);
        $this->assertStringContainsString('Timetables generated for 2 class(es)', $flash, 'blocker skipped, not counted as generated');
        $this->assertStringContainsString('24 of 30', $flash);
        $this->assertSame(60, 25 + 6 + 29 + 0 + 0);
    }

    /**
     * Section targets must be counted as sections, not classes.
     * A fully-streamed fresh class expands (scope=all) into 2
     * section targets only — no class target. The flash must say
     * "2 section(s)", not "2 class(es)": the old wording reported
     * section grids as classes, mis-stating what was built.
     *
     * Bucket reality (proven by grid dump, not assumed): each
     * section places 12, but the shared Common teacher + the load
     * tiebreaker push the second section's stream subject ahead,
     * so it meets its target while Common is still short — and the
     * one slot where Common's teacher is busy with section A is
     * honestly busy (1 busy, 35 free, 24 placed = 60 slots).
     * That busy cell is correct placement behaviour, not an
     * aggregation error: the counts sum exactly.
     */
    public function test_scope_all_counts_section_targets_as_sections()
    {
        $s = MyClass::create(['name' => 'R7 Streamed']);
        $secPcm = Section::create(['name' => 'A', 'my_class_id' => $s->id, 'teacher_id' => $this->teacher('R7S1')->id, 'stream' => 'pcm']);
        $secPcb = Section::create(['name' => 'B', 'my_class_id' => $s->id, 'teacher_id' => $this->teacher('R7S2')->id, 'stream' => 'pcb']);
        $this->subject('R7 Common', 'r7-common', $s->id, $this->teacher('R7SC')->id, 6, null);
        $this->subject('R7 PCM Only', 'r7-pcm', $s->id, $this->teacher('R7SP')->id, 6, 'pcm');
        $this->subject('R7 PCB Only', 'r7-pcb', $s->id, $this->teacher('R7SB')->id, 6, 'pcb');

        $response = $this->generateAll();
        $response->assertRedirect(route('tt.index'));
        $flash = (string) $response->getSession()->get('flash_success');

        $this->assertSame(12, $this->cellsForClass($s->id, $secPcm->id), 'PCM section: common 6 + PCM 6');
        $this->assertSame(12, $this->cellsForClass($s->id, $secPcb->id), 'PCB section: common 6 + PCB 6');
        $this->assertSame(24, $this->flashNumber($flash, '/(\d+) periods placed/'), 'flash placed: ' . $flash);
        $this->assertSame(1, $this->flashNumber($flash, '/(\d+) period\(s\) left empty \(every teacher busy/'), 'flash busy: ' . $flash);
        $this->assertSame(35, $this->flashNumber($flash, '/(\d+) free period\(s\)/'), 'flash free: ' . $flash);
        $this->assertStringContainsString('Timetables generated for 2 section(s)', $flash, 'section targets reported as sections: ' . $flash);
        $this->assertStringNotContainsString('2 class(es)', $flash);
        $this->assertSame(60, 24 + 1 + 35 + 0 + 0);
    }

    /**
     * Mixed scope=all run: 1 class target + 2 section targets.
     * Headline must split the units: "1 class(es) and 2
     * section(s)", with buckets summed across all three targets
     * (plain class 6 placed + 24 free; streamed pair 24 placed +
     * 1 busy + 35 free; totals 30 placed, 1 busy, 59 free = 90).
     */
    public function test_scope_all_mixed_headline_splits_classes_and_sections()
    {
        $plain = MyClass::create(['name' => 'R7 Plain']);
        $this->subject('R7 Plain Subject', 'r7-plain', $plain->id, $this->teacher('R7PL')->id, 6);
        $s = MyClass::create(['name' => 'R7 StreamedTwo']);
        $secPcm = Section::create(['name' => 'A', 'my_class_id' => $s->id, 'teacher_id' => $this->teacher('R7T1')->id, 'stream' => 'pcm']);
        $secPcb = Section::create(['name' => 'B', 'my_class_id' => $s->id, 'teacher_id' => $this->teacher('R7T2')->id, 'stream' => 'pcb']);
        $this->subject('R7 CommonTwo', 'r7-common2', $s->id, $this->teacher('R7TC')->id, 6, null);
        $this->subject('R7 PCMTwo', 'r7-pcm2', $s->id, $this->teacher('R7TP')->id, 6, 'pcm');
        $this->subject('R7 PCBTw', 'r7-pcb2', $s->id, $this->teacher('R7TB')->id, 6, 'pcb');

        $response = $this->generateAll();
        $response->assertRedirect(route('tt.index'));
        $flash = (string) $response->getSession()->get('flash_success');

        $this->assertSame(6, $this->cellsForClass($plain->id));
        $this->assertSame(12, $this->cellsForClass($s->id, $secPcm->id));
        $this->assertSame(12, $this->cellsForClass($s->id, $secPcb->id));
        $this->assertSame(30, $this->flashNumber($flash, '/(\d+) periods placed/'), 'flash placed: ' . $flash);
        $this->assertSame(1, $this->flashNumber($flash, '/(\d+) period\(s\) left empty \(every teacher busy/'), 'flash busy: ' . $flash);
        $this->assertSame(59, $this->flashNumber($flash, '/(\d+) free period\(s\)/'), 'flash free: ' . $flash);
        $this->assertStringContainsString('Timetables generated for 1 class(es) and 2 section(s)', $flash, 'mixed headline: ' . $flash);
        $this->assertSame(90, 30 + 1 + 59 + 0 + 0);
    }

    /**
     * Skip-all message must not drop the no-subjects targets.
     * A fresh class with zero subjects, in a skip run where every
     * seeded target is skipped and nothing generates, used to
     * vanish from the flash entirely (the skip-all branch only
     * listed skipped names). It must be named as having no
     * subjects — otherwise the admin never learns why that class
     * got no timetable.
     */
    public function test_skip_all_message_names_no_subject_targets()
    {
        $empty = MyClass::create(['name' => 'R7 Empty']);

        $response = $this->generateAll();
        $response->assertRedirect(route('tt.index'));
        $flash = (string) $response->getSession()->get('flash_success');

        $this->assertStringContainsString('No new timetables', $flash);
        $this->assertStringContainsString('No subjects yet: R7 Empty', $flash, 'empty class named: ' . $flash);
        $this->assertSame(0, $this->cellsForClass($empty->id));
    }
}
