<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent E round 12 — streamed-section admit bulk WITH dates.
 *
 * Section 65 is Class 12-B, stream pcb, 40 active students.
 * Class 12 subjects: English Language (79, untagged = common),
 * Hindi (80, untagged), Mathematics (81, pcm only), Physics (82,
 * pcm,pcb), Chemistry (83, pcm,pcb), Biology (84, pcb),
 * Accountancy / Business Studies / Economics (commerce),
 * History / Political Science / Geography (arts).
 *
 * Probe sheet (exam 1, class 13): Physics dated Mon 02 Nov 2026
 * 09:00, Chemistry dated Tue 03 Nov 2026 09:00; Biology, English
 * and Hindi undated. The union rule (round 2) must append the
 * undated stream subjects with "—" instead of hiding them, and
 * the stream filter must keep every pcm/commerce/arts subject
 * off a pcb card. No exam-grid rows exist for Class 13, so the
 * exam_dates path drives the schedule.
 */
class OwnerAdmitSectionBulkR12Test extends TestCase
{
    use DatabaseTransactions;

    public function test_section_bulk_cards_carry_dated_pairings_and_union_dashes()
    {
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 82, 'exam_date' => '2026-11-02', 'start_time' => '09:00']);
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 83, 'exam_date' => '2026-11-03', 'start_time' => '09:00']);

        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $html = $this->actingAs($admin)
            ->get(route('admit_cards.print', [1, 13]) . '?section_id=65')
            ->assertOk()
            ->getContent();

        // 40 cards, one per section student.
        $this->assertStringContainsString('40 card(s)', $html);
        $this->assertSame(40, substr_count($html, 'ADMIT CARD'));

        // Dated pairings, once per card.
        $this->assertSame(40, substr_count($html, 'Physics'));
        $this->assertSame(40, substr_count($html, 'Mon, 02 Nov 2026'));
        $this->assertSame(40, substr_count($html, 'Chemistry'));
        $this->assertSame(40, substr_count($html, 'Tue, 03 Nov 2026'));
        $this->assertSame(80, substr_count($html, '9:00 AM'));

        // Undated stream subjects present via the union (not hidden).
        $this->assertSame(40, substr_count($html, 'Biology'));
        $this->assertSame(40, substr_count($html, 'English Language'));
        $this->assertSame(40, substr_count($html, 'Hindi'));
        // 3 undated subjects x (date "—" + time "—") x 40 cards.
        // Counted as <td> cells: the raw page holds 10 more "—"
        // (style comments, the toolbar "40 card(s) — …", layout JS
        // comments) that are not schedule cells.
        $this->assertSame(240, substr_count($html, '<td>—</td>'));

        // Out-of-stream subjects must not appear on a pcb card.
        foreach (['Mathematics', 'Accountancy', 'Business Studies', 'Economics', 'History', 'Political Science', 'Geography'] as $out) {
            $this->assertSame(0, substr_count($html, $out), "Out-of-stream subject {$out} leaked onto a pcb card");
        }
    }

    public function test_section_bulk_roster_is_section_only()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $html = $this->actingAs($admin)
            ->get(route('admit_cards.print', [1, 13]) . '?section_id=65')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('40 card(s)', $html);

        // A Class 12-A (section 64) student's name must not appear.
        $other = \DB::table('student_records')
            ->join('users', 'users.id', '=', 'student_records.user_id')
            ->where('student_records.my_class_id', 13)
            ->where('student_records.section_id', 64)
            ->where('student_records.grad', 0)
            ->value('users.name');
        $this->assertNotNull($other);
        $this->assertSame(0, substr_count($html, $other));
    }
}
