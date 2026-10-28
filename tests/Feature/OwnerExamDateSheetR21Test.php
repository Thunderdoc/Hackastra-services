<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\Subject;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 21) — date-sheet
 * PRINT vs PDF parity. printSheet() and pdf() render the SAME
 * blade (date_sheet_print) with the SAME resolveScope() data;
 * the only for_pdf branches are the font (line 15) and the
 * window.print() script (line 178) — neither touches a row.
 * Page counts were proven in round 1, but pairing EQUALITY
 * (same subject/date/time/note in both) was never proven.
 *
 * This suite locks the print-HTML side (every dated pairing
 * + every "Not announced" row) and the PDF delivery (200,
 * application/pdf, correct filename). The PDF-TEXT side of
 * the parity proof is done outside the suite (scratch-server
 * run + pdftotext, reported with the round) because no
 * in-process PDF text extractor exists in vendor — the test
 * does NOT claim a parity proof it did not run.
 *
 * Fixture: Class 1 (my_class_id 2), exam 1. Three subjects
 * dated (distinct dates/times/notes), the rest undated, so
 * the sheet carries both row shapes at once.
 */
class OwnerExamDateSheetR21Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Date 3 Class 1 subjects, leave the rest undated. Returns [dated, undated]. */
    protected function seedSheet()
    {
        $subjects = Subject::where('my_class_id', 2)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(4, $subjects->count(), 'Class 1 needs 4+ subjects for a mixed sheet.');

        $dated = [
            ['date' => '2026-11-02', 'time' => '09:00', 'note' => 'R21NOTE-ALPHA'],
            ['date' => '2026-11-03', 'time' => '10:30', 'note' => 'R21NOTE-BETA'],
            ['date' => '2026-11-04', 'time' => '13:00', 'note' => null],
        ];
        foreach ($dated as $i => $d) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 2,
                'subject_id' => $subjects[$i]->id,
                'exam_date' => $d['date'],
                'start_time' => $d['time'],
                'note' => $d['note'],
            ]);
        }

        return [$subjects->take(3), $subjects->slice(3)];
    }

    public function test_print_html_carries_every_dated_pairing()
    {
        [$dated, $undated] = $this->seedSheet();

        $html = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 2]))
            ->assertOk()
            ->getContent();

        $dates = ['2026-11-02' => '09:00 AM', '2026-11-03' => '10:30 AM', '2026-11-04' => '01:00 PM'];
        foreach ($dated as $i => $subject) {
            $date = array_keys($dates)[$i];
            $this->assertStringContainsString($subject->name, $html, "Dated subject {$subject->name} missing from print HTML.");
            $this->assertStringContainsString(Carbon::parse($date)->format('D, d M Y'), $html, "Date label for {$date} missing from print HTML.");
            $this->assertStringContainsString($dates[$date], $html, "Time label {$dates[$date]} missing from print HTML.");
        }
        $this->assertStringContainsString('R21NOTE-ALPHA', $html);
        $this->assertStringContainsString('R21NOTE-BETA', $html);

        // Dated rows sort before undated rows (rows() sort key '0|…' vs '1|…').
        $firstAnnounced = strpos($html, 'Not announced');
        $this->assertNotFalse($firstAnnounced, 'Undated rows must render "Not announced".');
        $this->assertLessThan($firstAnnounced, strpos($html, $dated[0]->name), 'Dated rows must precede undated rows.');
    }

    public function test_print_html_marks_every_undated_subject_not_announced()
    {
        [$dated, $undated] = $this->seedSheet();

        $html = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 2]))
            ->assertOk()
            ->getContent();

        foreach ($undated as $subject) {
            $this->assertStringContainsString($subject->name, $html, "Undated subject {$subject->name} missing from print HTML.");
        }
        $this->assertSame(
            $undated->count(),
            substr_count($html, 'Not announced'),
            'One "Not announced" cell per undated subject, no more, no fewer.'
        );
    }

    public function test_pdf_delivers_same_sheet_as_download()
    {
        $this->seedSheet();

        $response = $this->actingAs($this->admin())
            ->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => 2]));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('exam-date-sheet-', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'));
        $this->assertGreaterThan(1000, strlen($response->getContent()), 'PDF body must be real bytes, not an empty download.');
    }
}
