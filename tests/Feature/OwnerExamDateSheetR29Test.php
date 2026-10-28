<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 29) — two generate
 * seams never test-locked:
 *
 * (a) SKIP MODE KEEPS EXISTING ROWS — WITH A BRIEF-PREMISE
 * CORRECTION. The brief expected mode=skip to leave the 2
 * existing rows byte-identical AND add exactly 1 row for the
 * subject with no row. A failing-first probe (temporary test,
 * deleted after its run) proved the second half false: skip
 * left exactly 2 rows, adding nothing. That is NOT a rewrite,
 * drop, or duplicate — it is the code's deliberate CLASS-LEVEL
 * skip: ExamDateController::generate() skips a class wholesale
 * whenever it already has ANY row for the exam ("if ($existing
 * > 0 && $data['mode'] === 'skip') { $skipped[] = ...;
 * continue; }"), and the flash says so in words ("Skipped
 * (sheet already exists): <class>."). So the byte-identity
 * half HOLDS and the new-row half FAILS BY DESIGN. This test
 * locks the true behaviour (byte-identical rows + skipped
 * flash + zero new rows) and the seam is FLAGGED as an open
 * product question, not silently "fixed": the only generate
 * path that adds the missing subject is mode=replace, and the
 * replace control below proves replace REWRITES the hand-kept
 * rows (dates, times, notes all become generated values). A
 * school that hand-adjusted dates therefore cannot add one
 * new subject via generate without losing its manual edits;
 * row-level skip (keep existing rows, fill only missing
 * subjects) would be a semantics change needing an owner
 * call, because the slot walk would also have to step over
 * the kept rows' slots. Until that call, the Manage page is
 * the manual completion path.
 *
 * (b) GAP + SKIP-DATE SEQUENCE EXACT. Round 28's generate
 * params asserted slot-group SHARING, never the date sequence
 * itself. This round asserts the exact generated date list
 * for a fresh 10-subject class through the real scope=one
 * generate. Parameter names are the controller's own
 * (validate() list): exam_id, scope, class_id, start_date,
 * exam_days, papers_per_day, session1_time, session2_time,
 * venue, skip_sunday, gap_every, gap_days, skip_dates, mode.
 * Implemented rule, stated precisely: gap_every counts EXAM
 * DAYS, not papers — with papers_per_day=2, a gap of
 * gap_days lands after every 2 exam days (every 4 papers),
 * not after every 2 papers. The brief's "after every 2
 * papers" phrasing would mean a gap after every day, which
 * is gap_every=1, a different parameter choice — not a bug.
 * Subjects are placed in NAME order (findSubjectByClass
 * orders by name), so the R29-GAP-A..J names pin the order.
 */
class OwnerExamDateSheetR29Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    protected function generatePayload($classId, $mode)
    {
        return [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => '2026-12-01',
            'exam_days' => 10,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'venue' => 'Main Hall',
            'skip_sunday' => '1',
            'gap_every' => 2,
            'gap_days' => 1,
            'skip_dates' => '2026-12-05',
            'mode' => $mode,
        ];
    }

    protected function rowList($classId)
    {
        return ExamDate::where('exam_dates.exam_id', 1)->where('exam_dates.my_class_id', $classId)
            ->join('subjects', 'subjects.id', '=', 'exam_dates.subject_id')
            ->orderBy('subjects.name')
            ->get(['subjects.name as subject', 'exam_dates.exam_date', 'exam_dates.start_time', 'exam_dates.note'])
            ->map(function ($r) {
                return [$r->subject, $r->exam_date, substr((string) $r->start_time, 0, 5), $r->note];
            })->all();
    }

    public function test_skip_mode_keeps_existing_rows_byte_identical_and_adds_nothing()
    {
        // Isolate: no other exam-1 row may occupy a slot or a flash count.
        ExamDate::where('exam_id', 1)->delete();
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R29 Skip Class', ['R29-SKIP-A', 'R29-SKIP-B', 'R29-SKIP-C'], 'r29-skip');

        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[0]->id,
            'exam_date' => '2026-11-02', 'start_time' => '09:00:00', 'note' => 'R29-KEPT-A']);
        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[1]->id,
            'exam_date' => '2026-11-03', 'start_time' => '13:00:00', 'note' => 'R29-KEPT-B']);
        $before = $this->rowList($class->id);
        $this->assertSame([
            ['R29-SKIP-A', '2026-11-02', '09:00', 'R29-KEPT-A'],
            ['R29-SKIP-B', '2026-11-03', '13:00', 'R29-KEPT-B'],
        ], $before, 'Precondition: exactly the 2 hand-kept rows exist');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id, 'skip'));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $this->assertStringContainsString(
            'Skipped (sheet already exists): R29 Skip Class.',
            session('flash_success'),
            'Skip must say the class was skipped, not silently pretend it generated');

        // Byte-identity: same rows, same dates, same times, same notes.
        $this->assertSame($before, $this->rowList($class->id),
            'Skip must never rewrite, drop, or duplicate an existing row');
        // And the flagged seam, locked as current behaviour: the
        // subject with no row gets NO row from skip mode.
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[2]->id,
        ]);
    }

    public function test_replace_mode_is_the_only_generate_path_to_add_missing_subject_and_it_rewrites()
    {
        ExamDate::where('exam_id', 1)->delete();
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R29 Replace Class', ['R29-REPL-A', 'R29-REPL-B', 'R29-REPL-C'], 'r29-repl');
        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[0]->id,
            'exam_date' => '2026-11-02', 'start_time' => '09:00:00', 'note' => 'R29-KEPT-A']);
        ExamDate::create(['exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[1]->id,
            'exam_date' => '2026-11-03', 'start_time' => '13:00:00', 'note' => 'R29-KEPT-B']);

        $this->actingAs($this->admin());
        // Skip first: still 2 rows, C still missing (round-29 test 1).
        $this->post(route('exam_dates.generate'), $this->generatePayload($class->id, 'skip'))->assertRedirect();
        $this->assertCount(2, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->get());

        // Replace: all 3 subjects placed, in name order, from slot 0.
        $this->post(route('exam_dates.generate'), $this->generatePayload($class->id, 'replace'))->assertRedirect();
        $this->assertSame([
            ['R29-REPL-A', '2026-12-01', '09:00', 'Main Hall'],
            ['R29-REPL-B', '2026-12-01', '13:00', 'Main Hall'],
            ['R29-REPL-C', '2026-12-02', '09:00', 'Main Hall'],
        ], $this->rowList($class->id),
            'Replace regenerates the whole sheet: the hand-kept dates and notes are gone, replaced by generated values');
    }

    public function test_gap_skip_date_and_sunday_sequence_is_exact()
    {
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'J') as $letter) {
            $names[] = 'R29-GAP-' . $letter;
        }
        [$class] = $this->makeClassWithSubjects('R29 Gap Class', $names, 'r29-gap');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id, 'replace'));
        $resp->assertRedirect();

        $rows = $this->rowList($class->id);
        $this->assertCount(10, $rows, 'All 10 subjects must place within the 10 exam days');
        $dates = array_column($rows, 1);
        $times = array_column($rows, 2);
        // Exact sequence, in subject-name order. Day calendar
        // (2026-12-01 is a Tuesday): 01 Tue, 02 Wed, 03 Thu GAP,
        // 04 Fri, 05 Sat SKIP-DATE, 06 Sun SUNDAY, 07 Mon,
        // 08 Tue GAP, 09 Wed.
        $this->assertSame([
            '2026-12-01', '2026-12-01',
            '2026-12-02', '2026-12-02',
            '2026-12-04', '2026-12-04',
            '2026-12-07', '2026-12-07',
            '2026-12-09', '2026-12-09',
        ], $dates, 'Exact generated date list in order');
        $this->assertSame(
            ['09:00', '13:00', '09:00', '13:00', '09:00', '13:00', '09:00', '13:00', '09:00', '13:00'],
            $times, 'Two papers per day: session 1 then session 2, every day');

        // Per-date rule satisfaction, each asserted directly.
        $this->assertSame('Tuesday', Carbon::parse('2026-12-01')->format('l'));
        $this->assertSame([], array_intersect($dates, ['2026-12-03']),
            '2026-12-03 (Thu) is the gap day after the first 2 exam days (Dec 1 + Dec 2) and must never carry a paper');
        $this->assertSame([], array_intersect($dates, ['2026-12-05']),
            '2026-12-05 (Sat) is the explicit skip_dates entry and must never appear');
        $this->assertSame([], array_intersect($dates, ['2026-12-06']),
            '2026-12-06 is a Sunday and skip_sunday=1, so it must never appear');
        $this->assertSame([], array_intersect($dates, ['2026-12-08']),
            '2026-12-08 (Tue) is the gap day after the next 2 exam days (Dec 4 + Dec 7) and must never carry a paper');
        // The jump Dec 4 -> Dec 7 spans the skip date + the Sunday,
        // not a gap: Dec 4 is only the 3rd exam day (sinceGap=1).
        $this->assertContains('2026-12-07', $dates, 'Papers resume Mon Dec 7, immediately after the skip + Sunday pair');
    }
}
