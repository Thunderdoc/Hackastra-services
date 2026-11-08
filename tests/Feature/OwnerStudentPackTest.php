<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 20 (2026-11-01): student-side pack — honest assignments
 * tile + next-school-day empty state (a), My Timetable today row +
 * summary strip (b), marksheet latest-result card (c), My Fees
 * date + method (d), My Attendance session default (e),
 * assignments full description + states (f).
 */
class OwnerStudentPackTest extends TestCase
{
    use DatabaseTransactions;

    private function studentUser(): User
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    public function test_dashboard_tile_is_one_honest_count_line()
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00:00')); // a Sunday
        try {
            $html = $this->actingAs($this->studentUser())->get(route('dashboard'))->assertOk()->getContent();
        } finally {
            Carbon::setTestNow();
        }
        // The contradicting phrasing is gone for good.
        $this->assertStringNotContainsString('open, already submitted', $html);
        $this->assertMatchesRegularExpression('/\d+ given · \d+ submitted|Nothing open right now/', $html);
        // Sunday: the designed next-school-day state names Monday.
        $this->assertStringContainsString('sd-nextday', $html);
        $this->assertStringContainsString('Next school day is', $html);
        $this->assertStringContainsString('Monday', $html);
    }

    public function test_my_timetable_highlights_today_and_summarises()
    {
        // A school day (Monday) so the today row exists in the grid.
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        $student = $this->studentUser();
        $html = $this->actingAs($student)->get(route('tt.mine'))->assertOk()->getContent();
        Carbon::setTestNow();
        if (strpos($html, 'sd-ttstrip') === false) {
            $this->markTestSkipped('No in-force timetable for the fixture student on this seed.');
        }
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00:00'));
        $response = $this->actingAs($student)->get(route('tt.mine'));
        Carbon::setTestNow();
        $summary = $response->viewData('mine_summary');
        $map = $response->viewData('map');
        $week = 0;
        foreach ((array) $map as $day => $cells) {
            foreach ((array) $cells as $cell) {
                if (!empty($cell['title'])) { $week++; }
            }
        }
        $this->assertSame($week, $summary['week']);
        $this->assertStringContainsString('periods this week', $html);
        $this->assertStringContainsString('free periods', $html);
        $this->assertStringContainsString('ttx-today', $html);
    }

    public function test_marksheet_landing_shows_latest_published_result()
    {
        $exam = Exam::findOrFail(1);
        $exam->update(['results_published' => true]);
        $student = $this->studentUser();
        $sr = StudentRecord::where('user_id', $student->id)->firstOrFail();
        $record = ExamRecord::firstOrCreate(
            ['exam_id' => $exam->id, 'student_id' => $student->id, 'subject_id' => 79],
            ['ave' => 81.5, 'my_class_id' => $sr->my_class_id, 'section_id' => $sr->section_id, 'year' => $exam->year]
        );
        $html = $this->actingAs($student)
            ->get(route('marks.year_selector', \App\Helpers\Qs::hash($student->id)))
            ->assertOk()->getContent();
        $this->assertStringContainsString('sd-latest-result', $html);
        $this->assertStringContainsString($exam->name, $html);
        $this->assertStringContainsString('View Marksheet', $html);
    }

    public function test_my_fees_show_payment_date_and_method()
    {
        $withReceipt = PaymentRecord::whereHas('receipt')->first();
        if (!$withReceipt) {
            $this->markTestSkipped('No payment record with a receipt on this seed.');
        }
        $student = User::findOrFail($withReceipt->student_id);
        $receipt = $withReceipt->receipt->sortByDesc('created_at')->first();
        $html = $this->actingAs($student)->get(route('student.fees'))->assertOk()->getContent();
        $this->assertStringContainsString('Paid On', $html);
        $this->assertStringContainsString('Method', $html);
        $this->assertStringContainsString($receipt->created_at->format('d M Y'), $html);
        if ($receipt->method) {
            $this->assertStringContainsString(ucfirst($receipt->method), $html);
        }
    }

    public function test_my_attendance_opens_on_the_whole_session()
    {
        $recordWithEntries = StudentRecord::whereIn('id',
            AttendanceSessionEntry::select('student_record_id')->distinct()->pluck('student_record_id')
        )->first();
        if (!$recordWithEntries) {
            $this->markTestSkipped('No attendance entries on this seed.');
        }
        $student = User::findOrFail($recordWithEntries->user_id);
        $response = $this->actingAs($student)->get(route('attendance.my'))->assertOk();
        $this->assertSame('session', $response->viewData('view'));
        $summary = $response->viewData('summary');
        $entries = AttendanceSessionEntry::where('student_record_id', $recordWithEntries->id)->get();
        $this->assertSame($entries->count(), $summary['marked']);
        $expected = (int) round((($entries->where('status', 'present')->count() + $entries->where('status', 'late')->count()) / max(1, $entries->count())) * 100);
        $this->assertSame($expected, $summary['percent']);
        $html = $response->getContent();
        // Re-expressed 2026-11-08 (LMS build): the month strip is
        // now the month CARDS (the cards are the picker, per the
        // owner-approved sample); the date-wise registers live in
        // the per-subject expansions (mw-register) in BOTH views —
        // the old "Period Registers only in month view" split is
        // gone. The data assertions above are unchanged.
        $this->assertStringContainsString('mw-monthcard', $html);
        $this->assertStringContainsString('mw-register', $html);

        $month = $entries->first()->session->date->format('Y-m');
        $monthHtml = $this->actingAs($student)->get(route('attendance.my', ['month' => $month]))->assertOk()->getContent();
        $this->assertStringContainsString('mw-register', $monthHtml);
        $this->assertStringContainsString('Whole session', $monthHtml);
    }

    public function test_assignments_page_shows_full_description_and_states()
    {
        $student = $this->studentUser();
        $record = StudentRecord::where('user_id', $student->id)->firstOrFail();
        $long = 'I20FULLDESC ' . str_repeat('This description must render in full on the student list, never cut mid-sentence. ', 3);
        $assignment = Assignment::create([
            'title' => 'I20 Full Description Assignment',
            'description' => $long,
            'my_class_id' => $record->my_class_id,
            'section_id' => $record->section_id,
            'subject_id' => \App\Models\Subject::where('my_class_id', $record->my_class_id)->value('id'),
            'teacher_id' => User::where('email', 'teacher@hackstra.app')->value('id'),
            'max_marks' => 20,
            'due_at' => now()->addDays(3),
        ]);
        // Re-expressed 2026-11-08 (LMS fold-in): the flat student
        // assignments list retired into courses — the student's
        // assignment surface is now the subject's course page
        // (title + state) and the assignment's own page, which is
        // where the full description renders, uncut, as before.
        $html = $this->actingAs($student)->get(route('courses.show', $assignment->subject_id))->assertOk()->getContent();
        $this->assertStringContainsString('I20 Full Description Assignment', $html);
        $this->assertStringContainsString('To submit', $html);
        $detail = $this->actingAs($student)->get(route('assignments.show', $assignment->id))->assertOk()->getContent();
        $this->assertStringContainsString($long, $detail);
        $this->assertStringNotContainsString('lms82-clamp2', $detail);
    }
}
