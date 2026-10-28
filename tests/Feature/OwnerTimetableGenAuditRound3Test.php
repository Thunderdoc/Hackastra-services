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
 * Agent D round 3 — second re-audit: half-open intervals, section
 * rotations, degenerate inputs, and the Manage update path.
 */
class OwnerTimetableGenAuditRound3Test extends TestCase
{
    use DatabaseTransactions;

    private function year(): string
    {
        return Qs::getCurrentSession();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'Round3 Teacher ' . $code,
            'email' => 'r3-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function subject(string $name, string $slug, int $classId, $teacherId, int $ppw = 6, array $extra = []): Subject
    {
        return Subject::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'my_class_id' => $classId,
            'teacher_id' => $teacherId,
            'periods_per_week' => $ppw,
        ], $extra));
    }

    private function record(int $classId, $sectionId = null): TimeTableRecord
    {
        return TimeTableRecord::create([
            'name' => 'R3 Record ' . $classId . '-' . ($sectionId ?? 'all'),
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'exam_id' => null,
            'year' => $this->year(),
        ]);
    }

    private function slot(int $recordId, string $from, string $to): TimeSlot
    {
        return TimeSlot::create([
            'ttr_id' => $recordId,
            'hour_from' => (int) date('g', strtotime($from)),
            'min_from' => date('i', strtotime($from)),
            'meridian_from' => date('A', strtotime($from)),
            'hour_to' => (int) date('g', strtotime($to)),
            'min_to' => date('i', strtotime($to)),
            'meridian_to' => date('A', strtotime($to)),
            'time_from' => $from,
            'time_to' => $to,
            'timestamp_from' => strtotime($from),
            'timestamp_to' => strtotime($to),
            'full' => $from . ' - ' . $to,
        ]);
    }

    private function cell(int $recordId, int $slotId, int $subjectId, string $day, $dayNum, string $from, string $to): TimeTable
    {
        return TimeTable::create([
            'ttr_id' => $recordId,
            'ts_id' => $slotId,
            'subject_id' => $subjectId,
            'day' => $day,
            'day_num' => $dayNum,
            'timestamp_from' => strtotime($day . ' ' . $from),
            'timestamp_to' => strtotime($day . ' ' . $to),
        ]);
    }

    private function generatePost(array $overrides = [])
    {
        return $this->actingAs($this->admin())->post(route('tt.generate'), array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'one',
            'mode' => 'replace',
        ], $overrides));
    }

    /** Half-open intervals: 9:00-9:40 + 9:40-10:20 is NOT a clash. */
    public function test_back_to_back_periods_do_not_clash()
    {
        $c1 = MyClass::create(['name' => 'R3 BackToBack One']);
        $c2 = MyClass::create(['name' => 'R3 BackToBack Two']);
        $t = $this->teacher('BTB');
        $s1 = $this->subject('BTB A', 'btb-a', $c1->id, $t->id);
        $s2 = $this->subject('BTB B', 'btb-b', $c2->id, $t->id);
        $r1 = $this->record($c1->id);
        $r2 = $this->record($c2->id);
        $slot1 = $this->slot($r1->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($r2->id, '9:40 AM', '10:20 AM');

        $this->cell($r1->id, $slot1->id, $s1->id, 'Monday', 1, '9:00 AM', '9:40 AM');
        $this->cell($r2->id, $slot2->id, $s2->id, 'Monday', 1, '9:40 AM', '10:20 AM');

        $clashes = TimetableValidator::clashes($this->year());
        $mine = array_filter($clashes, fn ($c) => in_array($r1->id, $c['records'], true) && in_array($r2->id, $c['records'], true));
        $this->assertEmpty($mine, 'back-to-back periods share only an endpoint, not time');
        $errors = TimetableValidator::report($r1)['errors'];
        $this->assertEmpty(array_filter($errors, fn ($e) => stripos($e, 'overlap') !== false || stripos($e, 'double-booked') !== false));
    }

    /** Generator half-open: a kept 9:00-9:40 cell leaves 9:40 placeable. */
    public function test_generator_allows_back_to_back_after_kept_cell()
    {
        $t = $this->teacher('BTBGEN');
        $kept = MyClass::create(['name' => 'R3 Kept BTB']);
        $sKept = $this->subject('Kept BTB Subject', 'kept-btb', $kept->id, $t->id);
        $recKept = $this->record($kept->id);
        $slotKept = $this->slot($recKept->id, '9:00 AM', '9:40 AM');
        $this->cell($recKept->id, $slotKept->id, $sKept->id, 'Monday', 1, '9:00 AM', '9:40 AM');

        $fresh = MyClass::create(['name' => 'R3 Fresh BTB']);
        $sFresh = $this->subject('Fresh BTB Subject', 'fresh-btb', $fresh->id, $t->id);
        $response = $this->generatePost(['class_id' => $fresh->id]);
        $response->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $fresh->id)->whereNull('exam_id')->firstOrFail();
        $nineForty = TimeTable::with('time_slot')->where('ttr_id', $record->id)->where('day_num', 1)->get()
            ->contains(fn ($cell) => optional($cell->time_slot)->time_from === '9:40 AM'
                && (int) $cell->subject_id === (int) $sFresh->id);
        $this->assertTrue($nineForty, '9:40 slot must be placeable after a kept 9:00-9:40 cell');
    }

    /** Two same-stream sections of one class get different rotations. */
    public function test_two_sections_same_stream_get_different_rotations()
    {
        $c = MyClass::create(['name' => 'R3 Section Rotation']);
        $secA = Section::create(['name' => 'A', 'my_class_id' => $c->id, 'stream' => 'pcm']);
        $secB = Section::create(['name' => 'B', 'my_class_id' => $c->id, 'stream' => 'pcm']);
        $eng = $this->subject('SecRot English', 'secrot-eng', $c->id, $this->teacher('SECROT0')->id, 6);
        $phy = $this->subject('SecRot Physics', 'secrot-phy', $c->id, $this->teacher('SECROT1')->id, 6, ['stream' => 'pcm']);
        $che = $this->subject('SecRot Chemistry', 'secrot-che', $c->id, $this->teacher('SECROT2')->id, 6, ['stream' => 'pcm']);
        // Per-section override teachers, so the two grids never block
        // each other — any sequence difference is rotation alone.
        foreach ([$secA, $secB] as $i => $sec) {
            foreach ([$eng, $phy, $che] as $j => $sub) {
                DB::table('section_subject_teachers')->insert([
                    'section_id' => $sec->id,
                    'subject_id' => $sub->id,
                    'teacher_id' => $this->teacher('SECROT' . $i . $j)->id,
                ]);
            }
        }

        $this->generatePost(['class_id' => $c->id, 'section_id' => $secA->id]);
        $this->generatePost(['class_id' => $c->id, 'section_id' => $secB->id]);

        $seq = function ($secId) use ($c) {
            $rec = TimeTableRecord::where('my_class_id', $c->id)->where('section_id', $secId)->whereNull('exam_id')->firstOrFail();
            return TimeTable::with('subject')->where('ttr_id', $rec->id)
                ->orderBy('day_num')->orderBy('timestamp_from')
                ->get()->map(fn ($cell) => $cell->subject->name)->all();
        };
        $seqA = $seq($secA->id);
        $seqB = $seq($secB->id);
        $this->assertCount(18, $seqA);
        $this->assertCount(18, $seqB);
        foreach (['SecRot English', 'SecRot Physics', 'SecRot Chemistry'] as $name) {
            $this->assertSame(6, count(array_filter($seqA, fn ($n) => $n === $name)));
            $this->assertSame(6, count(array_filter($seqB, fn ($n) => $n === $name)));
        }
        $this->assertNotSame($seqA, $seqB);
    }

    /** A class with no subjects reports cleanly, never crashes. */
    public function test_class_without_subjects_reports_cleanly()
    {
        $c = MyClass::create(['name' => 'R3 Empty Class']);
        $response = $this->generatePost(['class_id' => $c->id]);
        $response->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('No subjects yet', session('flash_success'));
    }

    /** A teacher with no subjects anywhere must not break generation. */
    public function test_teacher_without_subjects_does_not_break_generation()
    {
        $this->teacher('IDLE');
        $c = MyClass::create(['name' => 'R3 Idle Teacher Class']);
        $this->subject('Idle Class Subject', 'idle-sub', $c->id, $this->teacher('IDLE2')->id);
        $response = $this->generatePost(['class_id' => $c->id]);
        $response->assertRedirect(route('tt.index'));
        $this->assertNotNull(TimeTableRecord::where('my_class_id', $c->id)->whereNull('exam_id')->first());
    }

    /** needs_double still forms a back-to-back pair after the sort change. */
    public function test_needs_double_pair_forms()
    {
        $c = MyClass::create(['name' => 'R3 Double Pair']);
        $d = $this->subject('Double Pair Subject', 'dbl-pair', $c->id, $this->teacher('DBLPAIR')->id, 4, ['needs_double' => 1]);
        $response = $this->generatePost(['class_id' => $c->id]);
        $response->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $c->id)->whereNull('exam_id')->firstOrFail();
        $cells = TimeTable::where('ttr_id', $record->id)->orderBy('day_num')->orderBy('timestamp_from')->get();
        $this->assertSame(4, $cells->count());
        $pair = false;
        $byDay = $cells->groupBy('day_num');
        foreach ($byDay as $dayCells) {
            $ordered = $dayCells->values();
            for ($i = 0; $i + 1 < $ordered->count(); $i++) {
                if ((int) $ordered[$i]->subject_id === (int) $d->id && (int) $ordered[$i + 1]->subject_id === (int) $d->id) {
                    $pair = true;
                }
            }
        }
        $this->assertTrue($pair, 'a needs_double subject must sit in one back-to-back pair');
    }

    /** Manage update moving a cell to another day updates its day_num. */
    public function test_manage_update_changes_day_num()
    {
        $c = MyClass::create(['name' => 'R3 Update Day']);
        $s = $this->subject('Update Day Subject', 'upd-day', $c->id, $this->teacher('UPDDAY')->id);
        $rec = $this->record($c->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $slot->id, $s->id, 'Monday', 1, '9:00 AM', '9:40 AM');

        $response = $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->put(route('tt.update', $cell->id), [
                'ttr_id' => $rec->id,
                'ts_id' => $slot->id,
                'subject_id' => $s->id,
                'day' => 'Tuesday',
            ]);
        $response->assertRedirect();
        $cell->refresh();
        $this->assertSame('Tuesday', $cell->day);
        $this->assertSame(2, (int) $cell->day_num);
    }
}
