<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 35) — generate
 * CAPACITY OVERFLOW: what happens when a class's subjects
 * exceed exam_days x papers_per_day?
 *
 * Code read first (ExamDateController::generate()): there
 * IS a capacity mechanism, not a silent truncation. The
 * slot walk runs over exactly $totalSlots = count($days) *
 * $ppd slots; a subject that finds no free slot lands in
 * $overflow[] as "<Class> — <Subject>", and the flash
 * appends "Could not fit within the days given: <list> —
 * extend the days or set those on the Manage page."
 * This suite proves that mechanism end to end through the
 * real generate POST — placed subjects, named overflow
 * subjects, exact flash text, and no date spilling past
 * the chosen exam days — so the overflow is HONEST, not a
 * silent success. No code change follows from this round:
 * the overflow is named in the flash and the Manage page
 * is the named completion path, exactly as the flash says.
 *
 * Fixture rules (from round 29): subjects place in NAME
 * order (findSubjectByClass orders by name); exam 1 is
 * global, so scope=one generates for any fresh class;
 * ExamDate::where('exam_id', 1)->delete() isolates slots
 * (all in-transaction, rolled back). Days: 2026-12-01 is
 * a Tuesday; with no skip_sunday/gap/skip_dates, 5 exam
 * days = Dec 1–5 (Tue–Sat), 2 papers/day = 10 slots.
 */
class OwnerExamDateSheetR35Test extends TestCase
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

    protected function generatePayload($classId)
    {
        return [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => '2026-12-01',
            'exam_days' => 5,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'mode' => 'replace',
        ];
    }

    protected function rowList($classId)
    {
        return ExamDate::where('exam_dates.exam_id', 1)->where('exam_dates.my_class_id', $classId)
            ->join('subjects', 'subjects.id', '=', 'exam_dates.subject_id')
            ->orderBy('subjects.name')
            ->get(['subjects.name as subject', 'exam_dates.exam_date', 'exam_dates.start_time'])
            ->map(function ($r) {
                return [$r->subject, $r->exam_date, substr((string) $r->start_time, 0, 5)];
            })->all();
    }

    protected function placedSequence()
    {
        return [
            ['2026-12-01', '09:00'], ['2026-12-01', '13:00'],
            ['2026-12-02', '09:00'], ['2026-12-02', '13:00'],
            ['2026-12-03', '09:00'], ['2026-12-03', '13:00'],
            ['2026-12-04', '09:00'], ['2026-12-04', '13:00'],
            ['2026-12-05', '09:00'], ['2026-12-05', '13:00'],
        ];
    }

    public function test_twelve_subjects_place_ten_and_name_the_two_overflow()
    {
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'L') as $letter) {
            $names[] = 'R35-CAP-' . $letter;
        }
        [$class, $subjects] = $this->makeClassWithSubjects('R35 Cap Class', $names, 'r35-cap');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $flash = session('flash_success');

        $rows = $this->rowList($class->id);
        $this->assertCount(10, $rows, 'Capacity is 5 days x 2 papers = 10 slots: exactly 10 rows must exist');
        $placedNames = array_column($rows, 0);
        $this->assertSame(array_slice($names, 0, 10), $placedNames,
            'The first 10 subjects in name order place; K and L must not');
        $this->assertSame($this->placedSequence(),
            array_map(function ($r) { return [$r[1], $r[2]]; }, $rows),
            'Placed papers fill Dec 1–5, session 1 then session 2, every day');
        $this->assertSame([], array_filter(array_column($rows, 1), function ($d) {
            return $d < '2026-12-01' || $d > '2026-12-05';
        }), 'No generated date may spill past the chosen 5 exam days');
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[10]->id,
        ]);
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[11]->id,
        ]);
        // Honest flash: the overflow subjects are NAMED, not
        // silently dropped, and the Manage path is offered.
        $this->assertStringContainsString(
            'Could not fit within the days given: R35 Cap Class — R35-CAP-K; R35 Cap Class — R35-CAP-L — extend the days or set those on the Manage page.',
            $flash,
            'Overflow must be named in the flash, not hidden behind a plain success message');
    }

    public function test_exactly_at_capacity_places_all_with_no_overflow_clause()
    {
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'J') as $letter) {
            $names[] = 'R35-EXACT-' . $letter;
        }
        [$class] = $this->makeClassWithSubjects('R35 Exact Class', $names, 'r35-exact');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $flash = session('flash_success');

        $rows = $this->rowList($class->id);
        $this->assertCount(10, $rows, '10 subjects at capacity 10: all place');
        $this->assertSame($names, array_column($rows, 0));
        $this->assertStringNotContainsString('Could not fit', $flash,
            'At capacity there is no overflow clause at all');
    }

    public function test_one_over_capacity_names_exactly_the_one_overflow()
    {
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'K') as $letter) {
            $names[] = 'R35-OVER-' . $letter;
        }
        [$class, $subjects] = $this->makeClassWithSubjects('R35 Over Class', $names, 'r35-over');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $flash = session('flash_success');

        $rows = $this->rowList($class->id);
        $this->assertCount(10, $rows, '11 subjects at capacity 10: exactly 10 rows');
        $this->assertSame(array_slice($names, 0, 10), array_column($rows, 0));
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => 1, 'my_class_id' => $class->id, 'subject_id' => $subjects[10]->id,
        ]);
        $this->assertStringContainsString(
            'Could not fit within the days given: R35 Over Class — R35-OVER-K — extend the days or set those on the Manage page.',
            $flash,
            'One-over capacity must name exactly the one unplaced subject');
        $this->assertSame([], array_filter(array_column($rows, 1), function ($d) {
            return $d < '2026-12-01' || $d > '2026-12-05';
        }), 'No generated date may spill past the chosen 5 exam days');
    }

    public function test_overflow_subjects_can_still_be_set_on_the_manage_page()
    {
        // The flash's own completion path, proven: the Manage
        // save (round-30 shape) sets a date for an overflow
        // subject without touching the generated rows.
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'L') as $letter) {
            $names[] = 'R35-CAP-' . $letter;
        }
        [$class, $subjects] = $this->makeClassWithSubjects('R35 Cap Class', $names, 'r35-cap');

        $this->actingAs($this->admin());
        $this->post(route('exam_dates.generate'), $this->generatePayload($class->id))->assertRedirect();
        $this->assertCount(10, $this->rowList($class->id));

        $before = $this->rowList($class->id);
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => $class->id,
            'dates' => [
                $subjects[10]->id => ['exam_date' => '2026-12-08', 'start_time' => '09:00', 'note' => ''],
            ],
        ]);
        $resp->assertRedirect();
        $after = $this->rowList($class->id);
        $this->assertCount(11, $after, 'Manage save adds the overflow subject: 10 generated + 1 manual');
        $this->assertSame($before, array_slice($after, 0, 10),
            'The 10 generated rows are untouched by the manual completion');
        $this->assertContains(['R35-CAP-K', '2026-12-08', '09:00'], $after,
            'The overflow subject now carries its manually set date');
    }

    public function test_overflow_flash_names_subjects_not_just_a_count()
    {
        // Guards the honest-capacity shape against a future
        // "2 subjects could not be placed" regression: the
        // flash must name subjects, because a count alone
        // does not tell the admin WHICH papers are undated.
        ExamDate::where('exam_id', 1)->delete();
        $names = [];
        foreach (range('A', 'L') as $letter) {
            $names[] = 'R35-CAP-' . $letter;
        }
        [$class] = $this->makeClassWithSubjects('R35 Cap Class', $names, 'r35-cap');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), $this->generatePayload($class->id));
        $resp->assertRedirect();
        $flash = session('flash_success');
        $this->assertStringContainsString('R35-CAP-K', $flash);
        $this->assertStringContainsString('R35-CAP-L', $flash);
        $this->assertStringNotContainsString('2 subject', $flash,
            'A bare count would be a regression from named overflow');
    }
}
