<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 38) — the time-less
 * papers clash exemption, tested to a conclusion instead of
 * carried forward as a round-23 observation.
 *
 * Code facts read first (ExamDateController::save):
 * - clashGroups() keys rows by "date|time" (time truncated
 *   to HH:MM) and SKIPS any row with no date or no time
 *   (line ~412-416: `if (!$v['date'] || !$v['time'])`).
 *   In the post-save map, an empty start_time becomes null
 *   (`!empty($row['start_time']) ? substr(...) : null`),
 *   and stored rows map the same way — so null AND empty
 *   string are the same exemption.
 * - The clash rule's own wording is "Two papers cannot
 *   share one session" — a paper with no time occupies no
 *   session, so the exemption is the rule's own logic, not
 *   a hole in it.
 * - generate() can never produce a time-less row: session1
 *   is required and session2 defaults to 13:00 (line ~210),
 *   so every generated row carries a time. The exemption is
 *   reachable only via the Manage save (start_time nullable
 *   in save validation) or direct-DB rows.
 *
 * (1) Two papers, same date, BOTH time-less (form-posted
 *     empty times) -> SAVED, both rows stored with null
 *     start_time, success flash "2 date(s) set."
 * (2) Same date, ONE timed (09:00) + ONE time-less -> SAVED;
 *     the time-less paper does not clash with the timed one.
 * (3) Control: same date, BOTH timed 09:00 -> REFUSED with
 *     the exact clash flash, no rows written.
 * (4) Two PRE-EXISTING time-less rows on one date (direct
 *     DB) do not block an unrelated edit save — the rows
 *     survive byte-identical.
 * (5) Generate path: a real generate POST writes times on
 *     every row — zero time-less rows produced.
 *
 * Disposition: NO code changed. The exemption is the HONEST
 * shape of the session rule (no time = no session to share),
 * not a double-booking hole: a time-less paper cannot be
 * mistaken for a timed sitting, and the dated control proves
 * the guard still fires the moment both papers carry times.
 */
class OwnerExamDateSheetR38Test extends TestCase
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

    protected function savePost($examId, $classId, array $dates)
    {
        return $this->post(route('exam_dates.save'), [
            'exam_id' => $examId,
            'class_id' => $classId,
            'dates' => $dates,
        ]);
    }

    public function test_both_papers_timeless_on_same_date_save()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R38 Timeless Class', ['R38-TL-A', 'R38-TL-B'], 'r38-tl');
        $exam = $this->makeExam('R38 Global Exam', 'R38 Global Term');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $class->id, [
            $subjects[0]->id => ['exam_date' => '2026-12-01', 'start_time' => '', 'note' => ''],
            $subjects[1]->id => ['exam_date' => '2026-12-01', 'start_time' => '', 'note' => ''],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertSame(
            'Date sheet saved for R38 Global Exam — R38 Timeless Class: 2 date(s) set.',
            session('flash_success'));

        foreach ($subjects as $subject) {
            $row = ExamDate::where('exam_id', $exam->id)
                ->where('my_class_id', $class->id)
                ->where('subject_id', $subject->id)->firstOrFail();
            $this->assertSame('2026-12-01', (string) $row->exam_date);
            $this->assertNull($row->start_time);
        }
    }

    public function test_one_timed_one_timeless_same_date_saves()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R38 Mixed Class', ['R38-MX-A', 'R38-MX-B'], 'r38-mx');
        $exam = $this->makeExam('R38 Mixed Exam', 'R38 Mixed Term');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $class->id, [
            $subjects[0]->id => ['exam_date' => '2026-12-01', 'start_time' => '09:00', 'note' => ''],
            $subjects[1]->id => ['exam_date' => '2026-12-01', 'start_time' => '', 'note' => ''],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');

        $timed = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->where('subject_id', $subjects[0]->id)->firstOrFail();
        $this->assertSame('09:00', substr((string) $timed->start_time, 0, 5));
        $timeless = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->where('subject_id', $subjects[1]->id)->firstOrFail();
        $this->assertNull($timeless->start_time);
    }

    public function test_both_timed_same_session_is_refused()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R38 Clash Class', ['R38-CL-A', 'R38-CL-B'], 'r38-cl');
        $exam = $this->makeExam('R38 Clash Exam', 'R38 Clash Term');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $class->id, [
            $subjects[0]->id => ['exam_date' => '2026-12-01', 'start_time' => '09:00', 'note' => ''],
            $subjects[1]->id => ['exam_date' => '2026-12-01', 'start_time' => '09:00', 'note' => ''],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $flash = session('flash_danger');
        $this->assertStringContainsString('Two papers cannot share one session', $flash);
        $this->assertStringContainsString('R38-CL-A', $flash);
        $this->assertStringContainsString('R38-CL-B', $flash);
        $this->assertStringContainsString('2026-12-01 at 09:00', $flash);

        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->count());
    }

    public function test_preexisting_timeless_rows_do_not_block_unrelated_edit()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R38 Legacy Class', ['R38-LG-A', 'R38-LG-B', 'R38-LG-C'], 'r38-lg');
        $exam = $this->makeExam('R38 Legacy Exam', 'R38 Legacy Term');
        // Direct-DB time-less rows: the exemption's other source.
        $this->plantRow($exam->id, $class->id, $subjects[0]->id, '2026-12-01', null, 'R38-LG-A-NOTE');
        $this->plantRow($exam->id, $class->id, $subjects[1]->id, '2026-12-01', null, 'R38-LG-B-NOTE');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $class->id, [
            $subjects[2]->id => ['exam_date' => '2026-12-02', 'start_time' => '09:00', 'note' => ''],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');

        // Time-less rows byte-identical; the new paper landed.
        foreach ([0, 1] as $i) {
            $row = ExamDate::where('exam_id', $exam->id)
                ->where('my_class_id', $class->id)
                ->where('subject_id', $subjects[$i]->id)->firstOrFail();
            $this->assertSame('2026-12-01', (string) $row->exam_date);
            $this->assertNull($row->start_time);
            $this->assertSame('R38-LG-' . chr(65 + $i) . '-NOTE', $row->note);
        }
        $this->assertSame(1, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->where('subject_id', $subjects[2]->id)->count());
    }

    public function test_generate_never_produces_timeless_rows()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R38 Gen Class', ['R38-GN-A', 'R38-GN-B'], 'r38-gn');
        $exam = $this->makeExam('R38 Gen Exam', 'R38 Gen Term');

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => $exam->id,
            'scope' => 'one',
            'class_id' => $class->id,
            'start_date' => '2026-12-01',
            'exam_days' => 2,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'replace',
        ]);
        $resp->assertRedirect();

        $rows = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->get();
        $this->assertSame(2, $rows->count());
        foreach ($rows as $row) {
            $this->assertNotNull($row->start_time);
            $this->assertSame('09:00', substr((string) $row->start_time, 0, 5));
        }
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->whereNull('start_time')->count());
    }
}
