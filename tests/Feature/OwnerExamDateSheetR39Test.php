<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 39) — past-date
 * scheduling, tested to a conclusion instead of carried as
 * an unprobed question.
 *
 * Brief-premise CORRECTION (not a finding): the brief asked
 * whether generate "refuses, warns, or happily schedules
 * papers in the past" as if unprobed. Both guards ALREADY
 * exist in the accumulated batch code — they were simply
 * never test-locked by an E round:
 * - generate() validate(): 'start_date' =>
 *   'required|date|after_or_equal:today'
 *   (ExamDateController.php ~line 127, with a batch
 *   2026-10-28 audit comment).
 * - save() (line ~376): a NEW past date is refused with
 *   flash 'Past dates cannot be set: {Subject} — {date}
 *   has already passed.'; an UNCHANGED stored past date
 *   never blocks an unrelated edit (grandfathering).
 * This round locks both guards + the downstream behaviour
 * of past rows (which can only exist via direct-DB rows,
 * since neither UI path creates them):
 *
 * (1) generate with a past start_date -> validation error
 *     on start_date, ZERO rows written.
 * (2) generate with start_date == today (boundary) ->
 *     succeeds; rows land on today + tomorrow.
 * (3) Manage save dating a paper in the past (NEW past
 *     date) -> refused with the exact flash, zero rows.
 * (4) Grandfathering: a stored past row re-posted
 *     UNCHANGED alongside a new future date -> succeeds;
 *     the past row survives byte-identical, the new paper
 *     lands.
 * (5) Downstream past surfaces: planted past sittings
 *     render on the date-sheet show page; auto-assign
 *     fills 0 of them with the exact skipped-past flash;
 *     a planted past duty still lists on the teacher's
 *     My Duties page (round-27 past visibility).
 *
 * Disposition: NO code changed. Past scheduling is
 * REFUSED at both UI entry points (generate hard,
 * save with a named flash), grandfathering keeps old
 * sheets editable, and past rows that exist anyway are
 * displayed and skipped by auto-assign, not hidden.
 * Back-filling a sheet therefore requires direct-DB
 * work — a deliberate consequence of the guards, stated
 * here rather than "fixed" unilaterally.
 */
class OwnerExamDateSheetR39Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
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

    protected function makeExam($name, $term)
    {
        return Exam::create([
            'name' => $name,
            'term' => $term,
            'year' => '2026-2027',
            'my_class_id' => 0,
        ]);
    }

    protected function plantRow($examId, $classId, $subjectId, $date, $time, $note)
    {
        return ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => $date,
            'start_time' => $time,
            'note' => $note,
        ]);
    }

    protected function generatePayload($examId, $classId, $start)
    {
        return [
            'exam_id' => $examId,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => $start,
            'exam_days' => 2,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'replace',
        ];
    }

    public function test_generate_with_past_start_date_is_refused()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R39 Past Class', ['R39-PAST-A', 'R39-PAST-B'], 'r39-past');
        $exam = $this->makeExam('R39 Global Exam', 'R39 Global Term');
        $yesterday = Carbon::today()->subDay()->toDateString();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generatePayload($exam->id, $class->id, $yesterday));
        $resp->assertSessionHasErrors('start_date');
        $this->assertEquals(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->count());
    }

    public function test_generate_starting_today_succeeds()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R39 Today Class', ['R39-TOD-A', 'R39-TOD-B'], 'r39-tod');
        $exam = $this->makeExam('R39 Today Exam', 'R39 Today Term');
        $today = Carbon::today()->toDateString();
        $tomorrow = Carbon::today()->addDay()->toDateString();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generatePayload($exam->id, $class->id, $today));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $rows = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->orderBy('exam_date')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals($today, substr((string) $rows[0]->exam_date, 0, 10));
        $this->assertEquals($tomorrow, substr((string) $rows[1]->exam_date, 0, 10));
    }

    public function test_manage_save_new_past_date_is_refused()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R39 Save Class', ['R39-SAVE-A', 'R39-SAVE-B'], 'r39-save');
        $exam = $this->makeExam('R39 Save Exam', 'R39 Save Term');
        $past = Carbon::today()->subDays(30)->toDateString();

        $this->actingAs($this->admin());
        $resp = $this->from(route('exam_dates.index'))
            ->post(route('exam_dates.save'), [
                'exam_id' => $exam->id,
                'class_id' => $class->id,
                'dates' => [
                    $subjects[0]->id => ['exam_date' => $past, 'start_time' => '09:00', 'note' => ''],
                ],
            ]);
        $resp->assertRedirect();
        $this->assertEquals(
            'Past dates cannot be set: R39-SAVE-A — ' . $past . ' has already passed.',
            session('flash_danger'));
        $this->assertEquals(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->count());
    }

    public function test_grandfathered_past_row_survives_unrelated_edit()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R39 Grand Class', ['R39-GR-A', 'R39-GR-B'], 'r39-gr');
        $exam = $this->makeExam('R39 Grand Exam', 'R39 Grand Term');
        $past = Carbon::today()->subDays(30)->toDateString();
        $future = Carbon::today()->addDays(30)->toDateString();
        $planted = $this->plantRow($exam->id, $class->id, $subjects[0]->id, $past, '09:00', 'R39 kept note');

        $this->actingAs($this->admin());
        $resp = $this->from(route('exam_dates.index'))
            ->post(route('exam_dates.save'), [
                'exam_id' => $exam->id,
                'class_id' => $class->id,
                'dates' => [
                    $subjects[0]->id => ['exam_date' => $past, 'start_time' => '09:00', 'note' => 'R39 kept note'],
                    $subjects[1]->id => ['exam_date' => $future, 'start_time' => '09:00', 'note' => ''],
                ],
            ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_success');
        $kept = ExamDate::find($planted->id);
        $this->assertEquals($past, substr((string) $kept->exam_date, 0, 10));
        $this->assertEquals('09:00', substr((string) $kept->start_time, 0, 5));
        $this->assertEquals('R39 kept note', $kept->note);
        $this->assertDatabaseHas('exam_dates', [
            'exam_id' => $exam->id,
            'my_class_id' => $class->id,
            'subject_id' => $subjects[1]->id,
            'exam_date' => $future,
        ]);
    }

    public function test_past_sittings_render_but_auto_assign_skips_them_and_my_duties_lists_past()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R39 Down Class', ['R39-DOWN-A', 'R39-DOWN-B'], 'r39-down');
        $exam = $this->makeExam('R39 Down Exam', 'R39 Down Term');
        $past1 = Carbon::today()->subDays(30)->toDateString();
        $past2 = Carbon::today()->subDays(29)->toDateString();
        $sitting1 = $this->plantRow($exam->id, $class->id, $subjects[0]->id, $past1, '09:00', '');
        $sitting2 = $this->plantRow($exam->id, $class->id, $subjects[1]->id, $past2, '09:00', '');

        // Show page renders the past sittings (subjects named).
        $this->actingAs($this->admin());
        $show = $this->get(route('exam_dates.index', ['exam_id' => $exam->id, 'class_id' => $class->id]));
        $show->assertOk();
        $show->assertSee('R39-DOWN-A');
        $show->assertSee('R39-DOWN-B');

        // Auto-assign fills 0 past sittings, with the exact flash.
        $auto = $this->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id]);
        $auto->assertRedirect();
        $this->assertStringContainsString(
            'Auto-assignment complete: 0 sitting(s) got an invigilator. 2 past sitting(s) skipped',
            session('flash_success'));
        $this->assertEquals(0, ExamDuty::whereIn('exam_date_id', [$sitting1->id, $sitting2->id])->count());

        // A planted past duty still lists on the teacher's My Duties page.
        ExamDuty::create([
            'exam_date_id' => $sitting1->id,
            'teacher_id' => $this->teacher()->id,
            'assigned_by' => $this->admin()->id,
        ]);
        $mine = $this->actingAs($this->teacher())->get(route('exam_duties.my'));
        $mine->assertOk();
        $mine->assertSee('R39-DOWN-A');
    }
}
