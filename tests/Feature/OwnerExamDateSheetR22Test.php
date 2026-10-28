<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 22) — admit bulk
 * PRINT vs PDF parity. Unlike the date sheet (round 21), admit
 * cards have NO dompdf route at all: routes carry only index,
 * print (browser-print page), my, my_card. The PDF surface IS
 * the browser's Print -> Save as PDF of the admit_print page
 * (the toolbar button says exactly that), so parity means the
 * printed page and its printToPDF output carry the same cards
 * in the same order with the same pairings.
 *
 * This suite locks the HTML side (one card per section student,
 * identical dated pairings + undated "—" cells on every card).
 * The PDF side (40 pages A4, one card per page, pdftotext
 * pairings identical per card, zero deviations over 40 cards)
 * is proven outside the suite via CDP printToPDF + pdftotext
 * and reported with the round, because no in-process PDF text
 * extractor exists in vendor — the test does NOT claim a PDF
 * proof it did not run.
 *
 * Fixture: Class 1-A (section 4, 40 students), exam 1. Three
 * subjects dated (distinct dates/times), three undated, so
 * every card carries both row shapes at once. Class 1 has no
 * exam-1 timetable grid, so buildSchedule reads the exam_dates
 * source (the same source round 12's dated bulk used).
 */
class OwnerExamDateSheetR22Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function seedSheet()
    {
        $subjects = Subject::where('my_class_id', 2)->orderBy('id')->get();
        $this->assertCount(6, $subjects, 'Class 1 must carry exactly its 6 seeded subjects.');

        $dated = [
            ['date' => '2026-11-02', 'time' => '09:00'],
            ['date' => '2026-11-03', 'time' => '10:30'],
            ['date' => '2026-11-04', 'time' => '13:00'],
        ];
        foreach ($dated as $i => $d) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 2,
                'subject_id' => $subjects[$i]->id,
                'exam_date' => $d['date'],
                'start_time' => $d['time'],
                'note' => null,
            ]);
        }
    }

    /** Bulk print HTML: 40 cards, identical pairings, exact undated cells. */
    public function test_bulk_print_cards_carry_same_pairings_and_dashes()
    {
        $this->seedSheet();

        $response = $this->actingAs($this->admin())
            ->get('/exams/admit-cards/print/1/2?section_id=4');
        $response->assertOk();
        $html = $response->getContent();

        // One card per Class 1-A student, toolbar agrees.
        $this->assertSame(40, substr_count($html, 'class="admit-card"'));
        $this->assertStringContainsString('40 card(s)', $html);

        // Every dated pairing on every card (40 each, no swaps possible
        // at these counts: a swapped date would break two counts at once).
        foreach (['Mon, 02 Nov 2026', 'Tue, 03 Nov 2026', 'Wed, 04 Nov 2026',
                  '9:00 AM', '10:30 AM', '1:00 PM'] as $needle) {
            $this->assertSame(40, substr_count($html, $needle), "Expected 40 × {$needle}.");
        }

        // Undated subjects render exactly two "—" cells each (date + time):
        // 3 undated subjects × 2 cells × 40 cards = 240.
        $this->assertSame(240, substr_count($html, '<td>—</td>'));

        // Per-card row order: dated rows first (date order), then undated
        // rows alphabetically — the first card pins the sequence.
        // Subject rows carry FIVE cells (S/N, subject, date, time,
        // invigilator sign); match that exact shape across the whole
        // page (slicing from the first 'admit-subjects' would land in
        // the <style> block and catch the header table instead).
        preg_match_all('/<td class="text-center">\d+<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>/', $html, $m);
        $this->assertSame(240, count($m[0]), 'Expected 6 subject rows × 40 cards.');
        $this->assertSame([
            ['English Language', 'Mon, 02 Nov 2026', '9:00 AM'],
            ['Mathematics', 'Tue, 03 Nov 2026', '10:30 AM'],
            ['Science', 'Wed, 04 Nov 2026', '1:00 PM'],
            ['Computer Science', '—', '—'],
            ['Hindi', '—', '—'],
            ['Social Science', '—', '—'],
        ], array_map(null, array_slice($m[1], 0, 6), array_slice($m[2], 0, 6), array_slice($m[3], 0, 6)));
    }

    /** No out-of-stream or foreign-class subject may ride along. */
    public function test_bulk_print_cards_carry_no_extra_subjects()
    {
        $this->seedSheet();

        $response = $this->actingAs($this->admin())
            ->get('/exams/admit-cards/print/1/2?section_id=4');
        $response->assertOk();
        $html = $response->getContent();

        // Exactly 6 subject rows per card × 40 cards = 240 subject cells.
        // Exact '<td>name</td>' matches: '<td>Science</td>' does NOT match
        // '<td>Social Science</td>' or '<td>Computer Science</td>'.
        $subjectCells = 0;
        foreach (['English Language', 'Mathematics', 'Science',
                  'Social Science', 'Hindi', 'Computer Science'] as $name) {
            $subjectCells += substr_count($html, '<td>' . $name . '</td>');
        }
        $this->assertSame(240, $subjectCells);

        // No foreign/streamed subject rides along on a Class 1 card.
        foreach (['Physics', 'Chemistry', 'Biology', 'Accountancy'] as $foreign) {
            $this->assertStringNotContainsString('<td>' . $foreign . '</td>', $html);
        }
    }
}
