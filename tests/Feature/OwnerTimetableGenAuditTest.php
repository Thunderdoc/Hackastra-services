<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SchoolLevel;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 2 — deep generator audit, failing-first.
 * Every test pins a behaviour the audit questioned; fixes land
 * only where a test proves the old behaviour wrong.
 */
class OwnerTimetableGenAuditTest extends TestCase
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
            'name' => 'Audit Teacher ' . $code,
            'email' => 'audit-' . strtolower($code) . '@test.local',
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
            'name' => 'Audit Record ' . $classId . '-' . ($sectionId ?? 'all'),
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

    /** A1: a manually added period must carry its weekday number. */
    public function test_manual_cell_gets_day_num_via_manage_store()
    {
        $class = MyClass::create(['name' => 'Audit Daynum Class']);
        $t = $this->teacher('DAYNUM');
        $s = $this->subject('Daynum Subject', 'daynum-sub', $class->id, $t->id);
        $rec = $this->record($class->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');

        $response = $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $rec->id,
            'ts_id' => $slot->id,
            'subject_id' => $s->id,
            'day' => 'Monday',
        ]);
        $response->assertOk();

        $cell = TimeTable::where('ttr_id', $rec->id)->firstOrFail();
        $this->assertSame(1, (int) $cell->day_num);
    }

    /** A2: legacy NULL-day_num cells must not false-clash across days,
        and must still clash on the same day. */
    public function test_validator_day_num_fallback()
    {
        $c1 = MyClass::create(['name' => 'Audit Fallback One']);
        $c2 = MyClass::create(['name' => 'Audit Fallback Two']);
        $t = $this->teacher('FALLBACK');
        $s1 = $this->subject('Fallback A', 'fb-a', $c1->id, $t->id);
        $s2 = $this->subject('Fallback B', 'fb-b', $c2->id, $t->id);
        $r1 = $this->record($c1->id);
        $r2 = $this->record($c2->id);
        $slot1 = $this->slot($r1->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($r2->id, '9:00 AM', '9:40 AM');

        // Monday (NULL day_num) vs Tuesday (NULL day_num): no clash.
        $this->cell($r1->id, $slot1->id, $s1->id, 'Monday', null, '9:00 AM', '9:40 AM');
        $this->cell($r2->id, $slot2->id, $s2->id, 'Tuesday', null, '9:00 AM', '9:40 AM');
        $clashes = TimetableValidator::clashes($this->year());
        $mine = array_filter($clashes, fn ($c) => in_array($r1->id, $c['records'], true) && in_array($r2->id, $c['records'], true));
        $this->assertEmpty($mine, 'NULL-day_num cells on different days must not clash');

        // Same day, same time, different records: a real clash.
        $this->cell($r2->id, $slot2->id, $s2->id, 'Monday', null, '9:00 AM', '9:40 AM');
        $clashes = TimetableValidator::clashes($this->year());
        $mine = array_filter($clashes, fn ($c) => in_array($r1->id, $c['records'], true) && in_array($r2->id, $c['records'], true));
        $this->assertNotEmpty($mine, 'same-day same-time cells must clash even with NULL day_num');
    }

    /** B1: overlapping intervals with different starts are clashes. */
    public function test_validator_catches_overlapping_intervals()
    {
        $c1 = MyClass::create(['name' => 'Audit Overlap One']);
        $c2 = MyClass::create(['name' => 'Audit Overlap Two']);
        $t = $this->teacher('OVERLAP');
        $s1 = $this->subject('Overlap A', 'ov-a', $c1->id, $t->id);
        $s2 = $this->subject('Overlap B', 'ov-b', $c2->id, $t->id);
        $r1 = $this->record($c1->id);
        $r2 = $this->record($c2->id);
        $slot1 = $this->slot($r1->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($r2->id, '9:20 AM', '10:00 AM');

        $this->cell($r1->id, $slot1->id, $s1->id, 'Monday', 1, '9:00 AM', '9:40 AM');
        $this->cell($r2->id, $slot2->id, $s2->id, 'Monday', 1, '9:20 AM', '10:00 AM');

        $clashes = TimetableValidator::clashes($this->year());
        $mine = array_filter($clashes, fn ($c) => in_array($r1->id, $c['records'], true) && in_array($r2->id, $c['records'], true));
        $this->assertNotEmpty($mine, '9:00-9:40 vs 9:20-10:00 for one teacher must clash');
    }

    /** B3: two overlapping lessons inside one record are class errors. */
    public function test_validator_flags_same_record_overlap()
    {
        $c = MyClass::create(['name' => 'Audit SameRec']);
        $t1 = $this->teacher('SAMEREC1');
        $t2 = $this->teacher('SAMEREC2');
        $s1 = $this->subject('SameRec A', 'sr-a', $c->id, $t1->id);
        $s2 = $this->subject('SameRec B', 'sr-b', $c->id, $t2->id);
        $r = $this->record($c->id);
        $slot1 = $this->slot($r->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($r->id, '9:20 AM', '10:00 AM');

        $this->cell($r->id, $slot1->id, $s1->id, 'Monday', 1, '9:00 AM', '9:40 AM');
        $this->cell($r->id, $slot2->id, $s2->id, 'Monday', 1, '9:20 AM', '10:00 AM');

        $report = TimetableValidator::report($r);
        $overlap = array_filter($report['errors'], fn ($e) => stripos($e, 'overlap') !== false);
        $this->assertNotEmpty($overlap, 'same-record overlapping cells must be class errors');
    }

    /** C: a streamed section grid must not warn about other streams. */
    public function test_section_grid_coverage_ignores_other_streams()
    {
        $c = MyClass::create(['name' => 'Audit Stream Class']);
        $sec = Section::create(['name' => 'A', 'my_class_id' => $c->id, 'stream' => 'pcm']);
        $eng = $this->subject('Stream English', 'st-eng', $c->id, $this->teacher('STREAM1')->id);
        $phy = $this->subject('Stream Physics', 'st-phy', $c->id, $this->teacher('STREAM2')->id, 6, ['stream' => 'pcm']);
        $acc = $this->subject('Stream Accountancy', 'st-acc', $c->id, $this->teacher('STREAM3')->id, 6, ['stream' => 'commerce']);
        $r = $this->record($c->id, $sec->id);
        $slot1 = $this->slot($r->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($r->id, '9:40 AM', '10:20 AM');
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        foreach ($days as $i => $day) {
            $this->cell($r->id, $slot1->id, $eng->id, $day, $i + 1, '9:00 AM', '9:40 AM');
            $this->cell($r->id, $slot2->id, $phy->id, $day, $i + 1, '9:40 AM', '10:20 AM');
        }

        $report = TimetableValidator::report($r);
        foreach ($report['warnings'] as $w) {
            $this->assertStringNotContainsString('Accountancy', $w, 'commerce subject must not warn on a PCM grid');
        }
        $this->assertEmpty(array_filter($report['warnings'], fn ($w) => stripos($w, 'English') !== false || stripos($w, 'Physics') !== false));
    }

    /** D: teacher-less subjects are skipped, not placed, and honestly reported. */
    public function test_no_teacher_subjects_stay_empty_and_are_reported()
    {
        $c = MyClass::create(['name' => 'Audit NoTeacher Class']);
        $this->subject('NoTeacher Subject', 'nt-sub', $c->id, null);
        $this->subject('Taught Subject', 'nt-taught', $c->id, $this->teacher('NOTEACH')->id);

        $response = $this->generatePost(['class_id' => $c->id]);
        $response->assertRedirect(route('tt.index'));
        $flash = session('flash_success');
        $this->assertStringContainsString('No teacher assigned', $flash);
        $this->assertStringContainsString('left empty (no teacher assigned', $flash);

        $record = TimeTableRecord::where('my_class_id', $c->id)->whereNull('exam_id')->firstOrFail();
        $cells = TimeTable::with('subject')->where('ttr_id', $record->id)->get();
        $this->assertSame(0, $cells->where('subject.name', 'NoTeacher Subject')->count());
        $this->assertSame(6, $cells->where('subject.name', 'Taught Subject')->count());
    }

    /** E: lunch / break after the last period is invisible — refuse it. */
    public function test_lunch_and_break_must_fall_inside_the_day()
    {
        foreach ([
            ['lunch_after' => 8],
            ['lunch_after' => 6],
            ['break_after' => 6],
        ] as $bad) {
            $c = MyClass::create(['name' => 'Audit Bounds ' . json_encode($bad)]);
            $this->subject('Bounds Subject', 'bd-' . $c->id, $c->id, $this->teacher('BOUND' . $c->id)->id);
            $response = $this->generatePost(array_merge(['class_id' => $c->id], $bad));
            $this->assertNotNull(session('flash_danger'), 'bounds violation must be refused: ' . json_encode($bad));
            $this->assertNull(TimeTableRecord::where('my_class_id', $c->id)->whereNull('exam_id')->first(),
                'no grid may be created on a bounds violation');
        }
    }

    /** F: a fully-streamed class without a section must not get a mixed grid. */
    public function test_fully_streamed_class_without_section_is_refused()
    {
        $full = MyClass::create(['name' => 'Audit Full Stream']);
        Section::create(['name' => 'A', 'my_class_id' => $full->id, 'stream' => 'pcm']);
        Section::create(['name' => 'B', 'my_class_id' => $full->id, 'stream' => 'commerce']);
        $this->subject('Full English', 'full-eng', $full->id, $this->teacher('FULL1')->id);
        $this->subject('Full Physics', 'full-phy', $full->id, $this->teacher('FULL2')->id, 6, ['stream' => 'pcm']);
        $this->subject('Full Accountancy', 'full-acc', $full->id, $this->teacher('FULL3')->id, 6, ['stream' => 'commerce']);

        $response = $this->generatePost(['class_id' => $full->id]);
        $this->assertNotNull(session('flash_danger'));
        $this->assertNull(TimeTableRecord::where('my_class_id', $full->id)->whereNull('exam_id')->first());

        // Mixed class (one untagged section) keeps its whole-class grid.
        $mixed = MyClass::create(['name' => 'Audit Mixed Stream']);
        Section::create(['name' => 'A', 'my_class_id' => $mixed->id, 'stream' => 'pcm']);
        Section::create(['name' => 'B', 'my_class_id' => $mixed->id]);
        $this->subject('Mixed English', 'mix-eng', $mixed->id, $this->teacher('MIX1')->id);
        $this->subject('Mixed Physics', 'mix-phy', $mixed->id, $this->teacher('MIX2')->id, 6, ['stream' => 'pcm']);
        $response = $this->generatePost(['class_id' => $mixed->id]);
        $response->assertRedirect(route('tt.index'));
        $this->assertNotNull(TimeTableRecord::where('my_class_id', $mixed->id)->whereNull('exam_id')->whereNull('section_id')->first());
    }

    /** G: needs_double with fewer than 2 weekly periods can never pair. */
    public function test_needs_double_with_single_period_warns()
    {
        $c = MyClass::create(['name' => 'Audit Double Class']);
        $this->subject('Double Subject', 'dbl-sub', $c->id, $this->teacher('DOUBLE')->id, 1, ['needs_double' => 1]);
        $this->subject('Normal Subject', 'dbl-norm', $c->id, $this->teacher('DOUBLE2')->id);

        $response = $this->generatePost(['class_id' => $c->id]);
        $response->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('Double period', session('flash_success'));
    }

    /** H (already fixed 2026-10-28): early-years names stay Primary. */
    public function test_early_years_name_is_primary_regardless_of_id()
    {
        $ukg = MyClass::create(['name' => 'UKG']);
        $nursery = MyClass::create(['name' => 'Nursery']);
        $this->assertSame('primary', SchoolLevel::levelForClassId($ukg->id));
        $this->assertSame('primary', SchoolLevel::levelForClassId($nursery->id));
        $this->assertSame('secondary', SchoolLevel::levelForClassId(13));
    }

    /** I: a typo'd weekday must fail validation, not create a bogus cell. */
    public function test_manage_store_rejects_typo_day()
    {
        $c = MyClass::create(['name' => 'Audit Typo Class']);
        $s = $this->subject('Typo Subject', 'typo-sub', $c->id, $this->teacher('TYPO')->id);
        $rec = $this->record($c->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');

        $response = $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.store'), [
                'ttr_id' => $rec->id,
                'ts_id' => $slot->id,
                'subject_id' => $s->id,
                'day' => 'Mondyy',
            ]);
        $response->assertSessionHasErrors('day');
        $this->assertSame(0, TimeTable::where('ttr_id', $rec->id)->count());
    }

    /** J: replace refuses attendance-locked records; skip keeps cells. */
    public function test_replace_lock_and_skip_keep()
    {
        $c = MyClass::create(['name' => 'Audit Lock Class']);
        $t = $this->teacher('LOCKED');
        $s = $this->subject('Lock Subject', 'lock-sub', $c->id, $t->id);
        $rec = $this->record($c->id);
        $slot = $this->slot($rec->id, '9:00 AM', '9:40 AM');
        $cell = $this->cell($rec->id, $slot->id, $s->id, 'Monday', 1, '9:00 AM', '9:40 AM');
        AttendanceSession::create([
            'date' => '2026-10-26',
            'time_table_id' => $cell->id,
            'my_class_id' => $c->id,
            'section_id' => null,
            'subject_id' => $s->id,
            'teacher_id' => $t->id,
            'topic' => 'Audit lock',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $response = $this->generatePost(['class_id' => $c->id, 'mode' => 'replace']);
        $response->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('locked', session('flash_success'));
        $this->assertSame(1, TimeTable::where('ttr_id', $rec->id)->count());

        $response = $this->generatePost(['class_id' => $c->id, 'mode' => 'skip']);
        $this->assertStringContainsString('already has one', session('flash_success'));
        $this->assertSame(1, TimeTable::where('ttr_id', $rec->id)->count());
    }

    /** K (regression guard): the daily cap counts kept records' real load. */
    public function test_daily_cap_counts_kept_records()
    {
        $t = $this->teacher('KEPTCAP');
        $kept = MyClass::create(['name' => 'Audit Kept Class']);
        $sKept = $this->subject('Kept Subject', 'kept-sub', $kept->id, $t->id);
        $recKept = $this->record($kept->id);
        // Six Monday periods in the AFTERNOON: they never overlap the
        // new grid's morning slots, so only the cap can block Monday.
        foreach (['2:00 PM', '2:40 PM', '3:20 PM', '4:00 PM', '4:40 PM', '5:20 PM'] as $i => $from) {
            $to = date('g:i A', strtotime($from) + 40 * 60);
            $slot = $this->slot($recKept->id, $from, $to);
            $this->cell($recKept->id, $slot->id, $sKept->id, 'Monday', 1, $from, $to);
        }

        $fresh = MyClass::create(['name' => 'Audit Fresh Cap Class']);
        $this->subject('Fresh Subject', 'fresh-sub', $fresh->id, $t->id);
        $response = $this->generatePost(['class_id' => $fresh->id, 'max_teacher_periods_per_day' => 6]);
        $response->assertRedirect(route('tt.index'));

        $record = TimeTableRecord::where('my_class_id', $fresh->id)->whereNull('exam_id')->firstOrFail();
        $cells = TimeTable::where('ttr_id', $record->id)->get();
        $this->assertSame(6, $cells->count());
        $this->assertSame(0, $cells->where('day_num', 1)->count(), 'kept Monday load of 6 must use the whole cap');
    }

    /** L: scope=all section targets supersede the class grid, like scope=one. */
    public function test_scope_all_section_targets_supersede_class_grid()
    {
        $c = MyClass::create(['name' => 'Audit Supersede Class']);
        $secStreamed = Section::create(['name' => 'A', 'my_class_id' => $c->id, 'stream' => 'pcm']);
        Section::create(['name' => 'B', 'my_class_id' => $c->id]);
        $t = $this->teacher('SUPER');
        $this->subject('Supersede English', 'sup-eng', $c->id, $t->id);

        // Existing class grid books the teacher Monday 9:00.
        $classRec = $this->record($c->id);
        $classSlot = $this->slot($classRec->id, '9:00 AM', '9:40 AM');
        $this->cell($classRec->id, $classSlot->id, Subject::where('slug', 'sup-eng')->firstOrFail()->id, 'Monday', 1, '9:00 AM', '9:40 AM');

        $response = $this->generatePost(['scope' => 'all', 'mode' => 'skip', 'class_id' => null]);
        $response->assertRedirect(route('tt.index'));

        $secRec = TimeTableRecord::where('my_class_id', $c->id)->where('section_id', $secStreamed->id)->whereNull('exam_id')->first();
        $this->assertNotNull($secRec, 'section grid must be generated');
        // The generated slot carries time_from '9:00 AM' (cell
        // timestamps use the generator's 2026-01-05 base, so compare
        // via the slot, not the raw timestamp).
        $mondayNine = TimeTable::with('time_slot')->where('ttr_id', $secRec->id)->where('day_num', 1)->get()
            ->contains(fn ($cell) => optional($cell->time_slot)->time_from === '9:00 AM'
                && (int) $cell->subject_id === (int) Subject::where('slug', 'sup-eng')->firstOrFail()->id);
        $this->assertTrue($mondayNine, 'section grid must place the teacher Monday 9:00 despite the class grid');
    }

    /** B2: different period lengths across levels must not double-book. */
    public function test_generator_does_not_place_overlapping_periods_across_levels()
    {
        $t = $this->teacher('INTERVAL');
        $cx = MyClass::create(['name' => 'Audit Interval X']);
        $this->subject('Interval X', 'int-x', $cx->id, $t->id);
        $cy = MyClass::create(['name' => 'Audit Interval Y']);
        $this->subject('Interval Y', 'int-y', $cy->id, $t->id);

        $this->generatePost(['class_id' => $cx->id, 'period_minutes' => 40]);
        $this->generatePost(['class_id' => $cy->id, 'period_minutes' => 30]);
        // 30-minute day: P5/P6 fall in a free window, so Y still places.
        $recY = TimeTableRecord::where('my_class_id', $cy->id)->whereNull('exam_id')->firstOrFail();
        $this->assertGreaterThan(0, TimeTable::where('ttr_id', $recY->id)->count());

        $recX = TimeTableRecord::where('my_class_id', $cx->id)->whereNull('exam_id')->firstOrFail();
        $cellsX = TimeTable::where('ttr_id', $recX->id)->get();
        $cellsY = TimeTable::where('ttr_id', $recY->id)->get();
        foreach ($cellsX as $a) {
            foreach ($cellsY as $b) {
                if ((int) $a->day_num !== (int) $b->day_num) {
                    continue;
                }
                $overlap = (int) $a->timestamp_from < (int) $b->timestamp_to
                    && (int) $b->timestamp_from < (int) $a->timestamp_to;
                $this->assertFalse($overlap, "teacher double-booked day {$a->day_num}: "
                    . date('H:i', (int) $a->timestamp_from) . '-' . date('H:i', (int) $a->timestamp_to)
                    . ' vs ' . date('H:i', (int) $b->timestamp_from) . '-' . date('H:i', (int) $b->timestamp_to));
            }
        }
    }

    /** M: posted subjects must belong to their class (tamper guard). */
    public function test_posted_subjects_must_belong_to_their_class()
    {
        $a = MyClass::create(['name' => 'Audit Tamper A']);
        $b = MyClass::create(['name' => 'Audit Tamper B']);
        $this->subject('Tamper Own', 'tmp-own', $a->id, $this->teacher('TAMPER1')->id);
        $foreign = $this->subject('Tamper Foreign', 'tmp-foreign', $b->id, $this->teacher('TAMPER2')->id);

        $response = $this->generatePost([
            'class_id' => $a->id,
            'subjects_present' => [$a->id => 1],
            'subjects' => [$a->id => [$foreign->id]],
        ]);
        $this->assertNotNull(session('flash_danger'));
        $this->assertNull(TimeTableRecord::where('my_class_id', $a->id)->whereNull('exam_id')->first());
    }
}
