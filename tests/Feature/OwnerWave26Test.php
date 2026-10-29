<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-26 (wave 26) — streamed admit cards + date sheets.
 *
 * Before this wave, a Class 12 bulk admit print gave every student
 * all 12 subjects, including out-of-stream papers, and the class
 * date sheet did the same — because buildSchedule and the date
 * sheet scope were per (exam, class) with no section dimension.
 * Now a streamed student reads only their stream's subjects
 * (common + own stream) on their own card, on the bulk print, and
 * on the date sheet (own view, or an explicit section for staff).
 * The unsectioned admin date-sheet view stays complete: it is the
 * management view, and every subject still needs its date.
 */
class OwnerWave26Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    /** A Class 12 PCM-section (A) student + ExamDate rows for all 12 Class 12 subjects. */
    protected function seedClass12Sheet()
    {
        $student = StudentRecord::where('my_class_id', 13)->where('section_id', 64)->first();
        $this->assertNotNull($student, 'seed must hold a Class 12 Section A student');

        $subjects = \App\Models\Subject::where('my_class_id', 13)->orderBy('id')->get();
        $this->assertCount(12, $subjects, 'Class 12 must hold 12 subjects');

        foreach ($subjects as $i => $subject) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 13,
                'subject_id' => $subject->id,
                'exam_date' => '2026-12-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'start_time' => '09:00:00',
            ]);
        }

        return $student;
    }

    public function test_my_card_shows_only_stream_subjects()
    {
        $student = $this->seedClass12Sheet();

        // Acting as admin bypasses the fee-defaulter card block.
        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', [1, $student->user_id]));

        $response->assertStatus(200);
        foreach (['English Language', 'Hindi', 'Mathematics', 'Physics', 'Chemistry'] as $name) {
            $response->assertSee($name);
        }
        foreach (['Biology', 'Accountancy', 'Business Studies', 'Economics', 'History', 'Political Science', 'Geography'] as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_bulk_print_section_filters_schedule()
    {
        $this->seedClass12Sheet();

        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, 13]) . '?section_id=64');

        $response->assertStatus(200);
        foreach (['English Language', 'Physics', 'Chemistry'] as $name) {
            $response->assertSee($name);
        }
        foreach (['Biology', 'Accountancy', 'History'] as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_date_sheet_print_section_filters_rows()
    {
        $this->seedClass12Sheet();

        $response = $this->actingAs($this->admin())
            ->get(route('exam_dates.print') . '?exam_id=1&class_id=13&section_id=64');

        $response->assertStatus(200);
        foreach (['English Language', 'Physics', 'Chemistry'] as $name) {
            $response->assertSee($name);
        }
        foreach (['Biology', 'Accountancy', 'History'] as $name) {
            $response->assertDontSee($name);
        }
        $response->assertSee('(PCM)');
    }

    public function test_date_sheet_student_view_is_stream_filtered()
    {
        $student = $this->seedClass12Sheet();

        // Item 22 (overnight 2026-10-29): the sheet is gated until the
        // principal publishes it; stream filtering is what this test
        // proves, so publish the (exam 1, Class 12) sheet first.
        ExamDateSheetState::create([
            'exam_id' => 1, 'my_class_id' => 13,
            'status' => ExamDateSheetState::PUBLISHED,
        ]);

        $response = $this->actingAs(User::find($student->user_id))
            ->get(route('exam_dates.show') . '?exam_id=1');

        $response->assertStatus(200);
        foreach (['English Language', 'Physics'] as $name) {
            $response->assertSee($name);
        }
        foreach (['Biology', 'Accountancy', 'History'] as $name) {
            $response->assertDontSee($name);
        }
    }

    public function test_unstreamed_class_cards_unchanged()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, 1]));

        $response->assertStatus(200);
        foreach (['English Language', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'] as $name) {
            $response->assertSee($name);
        }
    }
}
