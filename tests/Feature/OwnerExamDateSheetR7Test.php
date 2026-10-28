<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 7) — results × date-sheet
 * interplay, empty-class documents, and past-start replace mode.
 *
 * (1) Publishing/unpublishing results must not depend on date
 * sheets existing: the results gate is the per-exam
 * `results_published` switch plus login, and Notify::resultsPublished
 * notifies by session/class — never by exam_dates rows.
 * (2) A class with zero subjects must still render its date-sheet
 * print view and PDF (200, no 500), with honest empty text rather
 * than a note that assumes subjects exist below.
 * (3) Past-start generate in mode=replace is refused by validation
 * before any write, so a class that already has sheets keeps them
 * byte-identical — the refusal is mode-independent by design.
 */
class OwnerExamDateSheetR7Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function freshExamWithClass($classId)
    {
        return Exam::create([
            'name' => 'R7 Probe Exam',
            'term' => 1,
            'year' => Qs::getCurrentSession(),
            'results_published' => false,
            'my_class_id' => $classId,
        ]);
    }

    public function test_publish_and_unpublish_work_with_no_date_sheet()
    {
        $class = MyClass::create(['name' => 'R7 No Sheet Class']);
        $exam = $this->freshExamWithClass($class->id);
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->count());

        $this->actingAs($this->admin());
        $this->post(route('exams.publish_results', $exam->id))->assertRedirect();
        $this->assertTrue((bool) $exam->fresh()->results_published);

        $this->post(route('exams.unpublish_results', $exam->id))->assertRedirect();
        $this->assertFalse((bool) $exam->fresh()->results_published);
    }

    public function test_publish_works_with_date_sheet_present()
    {
        $class = MyClass::create(['name' => 'R7 Dated Class']);
        $subject = Subject::create([
            'name' => 'R7 Dated Subject',
            'my_class_id' => $class->id,
            'teacher_id' => 4,
            'slug' => 'r7-dated-' . $class->id,
            'periods_per_week' => 6,
        ]);
        $exam = $this->freshExamWithClass($class->id);
        ExamDate::create([
            'exam_id' => $exam->id,
            'my_class_id' => $class->id,
            'subject_id' => $subject->id,
            'exam_date' => '2026-12-02',
            'start_time' => '09:00:00',
        ]);

        $this->actingAs($this->admin());
        $this->post(route('exams.publish_results', $exam->id))->assertRedirect();
        $this->assertTrue((bool) $exam->fresh()->results_published);
        // The date sheet is untouched by publishing.
        $this->assertSame(1, ExamDate::where('exam_id', $exam->id)->count());

        $this->post(route('exams.unpublish_results', $exam->id))->assertRedirect();
        $this->assertFalse((bool) $exam->fresh()->results_published);
        $this->assertSame(1, ExamDate::where('exam_id', $exam->id)->count());
    }

    public function test_empty_class_date_sheet_print_and_pdf_render()
    {
        $class = MyClass::create(['name' => 'R7 Empty Class']);
        $this->assertSame(0, Subject::where('my_class_id', $class->id)->count());

        $this->actingAs($this->admin());
        $print = $this->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]));
        $print->assertOk();
        $print->assertSee('R7 Empty Class');
        // Honest empty text, not the announced-note that assumes
        // subjects exist below the note.
        $print->assertSee('No subjects are configured for this class yet');
        $print->assertDontSee('The subjects below will carry their dates');

        $pdf = $this->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]));
        $pdf->assertOk();
        $pdf->assertDownload();
    }

    public function test_past_start_replace_keeps_existing_sheets()
    {
        $before = ExamDate::where('exam_id', 1)->where('my_class_id', 1)
            ->orderBy('subject_id')->get()->map(function ($r) {
                return $r->subject_id . '|' . $r->exam_date . '|' . substr((string) $r->start_time, 0, 5);
            })->all();
        // Seed two existing sittings if the class has none in this run.
        if (count($before) < 2) {
            ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 1, 'exam_date' => '2026-12-02', 'start_time' => '09:00:00']);
            ExamDate::create(['exam_id' => 1, 'my_class_id' => 1, 'subject_id' => 2, 'exam_date' => '2026-12-03', 'start_time' => '09:00:00']);
            $before = ExamDate::where('exam_id', 1)->where('my_class_id', 1)
                ->orderBy('subject_id')->get()->map(function ($r) {
                    return $r->subject_id . '|' . $r->exam_date . '|' . substr((string) $r->start_time, 0, 5);
                })->all();
        }

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => 1,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'exam_days' => 3,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'replace',
        ]);
        $resp->assertSessionHasErrors('start_date');

        $after = ExamDate::where('exam_id', 1)->where('my_class_id', 1)
            ->orderBy('subject_id')->get()->map(function ($r) {
                return $r->subject_id . '|' . $r->exam_date . '|' . substr((string) $r->start_time, 0, 5);
            })->all();
        $this->assertSame($before, $after, 'Past-start replace must not touch existing sheets');
    }
}
