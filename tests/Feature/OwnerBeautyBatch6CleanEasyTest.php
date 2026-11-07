<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Beauty Batch 6 (2026-11-07, audit Clean/Easy family E1-E13):
 * text walls folded or chipped, duplicated figures given ONE home,
 * duplicated/conflicting states collapsed, and one-bar charts
 * replaced by honest "so far" lines. Plus the Batch-5 sighting:
 * the principal Finance panel formatted money with Western digit
 * grouping while the rest of the app uses Indian grouping.
 *
 * Two audit items were NOT implemented as written, on settled
 * doctrine, and are pinned as-is instead:
 *  - E11: the substitutions board does NOT default its date
 *    filter to today (owner order 2026-10-30, in the controller
 *    docblock: blank = the whole record). The fix is copy only —
 *    the unfiltered scope is stated on the page.
 *  - E6: the teacher Today's Tasks SUB-LINE count (Audit Fix
 *    Wave 1) and the topbar Notifications grading item stay; what
 *    went is the duplicated grading-queue ROW inside the task
 *    list, leaving the Needs attention panel as the one detailed
 *    grading surface.
 * Presentation and copy only: no route, POST or behaviour change.
 */
class OwnerBeautyBatch6CleanEasyTest extends TestCase
{
    use DatabaseTransactions;

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_e1_teacher_whatsapp_panel_is_english_only()
    {
        $response = $this->actingAs($this->user('teacher@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('Submit carefully — once a link is saved, it cannot be changed.');
        $response->assertSee('If a wrong link gets saved, only the school Admin can reset it — teachers cannot edit it.');
        $response->assertDontSee('Dhyan se');
        $response->assertDontSee('Galat link');
    }

    public function test_e6_grading_queue_lives_in_needs_attention_only()
    {
        $response = $this->actingAs($this->user('teacher@hackstra.app'))->get('/home');

        $response->assertOk();
        // The detailed grading surface (seed carries ungraded
        // submissions for this teacher).
        $response->assertSee('Needs attention');
        $response->assertSee('ungraded submissions');
        // The duplicated queue row inside Today's Tasks is gone.
        $response->assertDontSee('Grade assignment submissions');
    }

    public function test_e6_empty_attendance_month_is_one_line_not_a_wall()
    {
        // Seed registers cover Sep-Oct 2026; the overview month is
        // the current calendar month, which holds no registers, so
        // every class row is null and the panel must collapse to
        // the single summary line (no per-class repeats, no chart).
        $response = $this->actingAs($this->user('teacher@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('No periods marked yet in');
        $response->assertDontSee('— no marked periods yet in');
    }

    public function test_e2_attendance_information_is_a_collapsed_details()
    {
        $response = $this->actingAs($this->user('teacher@hackstra.app'))->get(route('attendance.periods.index'));

        $response->assertOk();
        $response->assertSee('<details class="ma-info">', false);
        $response->assertSee('How attendance works');
        $response->assertDontSee('Important Information');
        // The lock warning's one home is the banner subtitle; the
        // old always-open bullet repeating it is gone.
        $response->assertDontSee("Once a period's attendance is submitted, it will be locked");
    }

    public function test_e3_leave_banner_no_longer_repeats_the_tile_counts()
    {
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('leave.index'));

        $response->assertOk();
        $response->assertSee('hk-mod-banner', false);
        $response->assertDontSee('hk-mod-stat', false);
        // The figures' one home: the stat tiles + coverage strip.
        $response->assertSee('Pending Requests');
        $response->assertSee('lv-cover-strip', false);
    }

    public function test_e4_books_banner_no_longer_repeats_the_kpi_cards()
    {
        $response = $this->actingAs($this->user('librarian@hackstra.app'))->get(route('books.index'));

        $response->assertOk();
        $response->assertSee('hk-mod-banner', false);
        $response->assertDontSee('hk-mod-stat', false);
        $response->assertSee('Total Titles');
        $response->assertSee('Available Copies');
    }

    public function test_e5_principal_analytics_are_chips_not_prose()
    {
        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('pr-chip', false);
        $response->assertSee('— class averages');
        $response->assertDontSee('class averages:');
        $response->assertSee('Top subjects by Term 1 average');
    }

    public function test_finance_panel_uses_indian_digit_grouping()
    {
        $year = now()->year;
        $collected = (int) DB::table('payment_records')->whereYear('updated_at', $year)->sum('amt_paid');
        $total = (int) DB::table('payment_records')
            ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
            ->whereYear('payment_records.updated_at', $year)->sum('payments.amount');
        $pending = max(0, $total - $collected);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');

        $response->assertOk();
        // The blade prints the rupee sign as the &#8377; entity.
        $response->assertSee('&#8377;' . Money::indianFormat($collected), false);
        $response->assertSee('&#8377;' . Money::indianFormat($pending), false);
        if (number_format($collected) !== Money::indianFormat($collected)) {
            $response->assertDontSee('&#8377;' . number_format($collected), false);
        }
    }

    public function test_e7_student_home_states_submitted_late_once()
    {
        $student = $this->user('student@hackstra.app');
        $record = \App\Models\StudentRecord::where('user_id', $student->id)->firstOrFail();
        $subject = \App\Models\Subject::where('my_class_id', $record->my_class_id)->firstOrFail();

        // Seeded branch: assignment 1 was submitted BEFORE its
        // (now past) due moment — on time. Its row must no longer
        // pair a red Overdue chip with the Submitted badge.
        $response = $this->actingAs($student)->get('/home');
        $response->assertOk();
        $response->assertSee('Due &middot; 03 Nov', false);
        $response->assertDontSee('Overdue &middot; 03 Nov', false);

        // Late branch: a fixture assignment submitted after its
        // due moment collapses to the single "Submitted late"
        // chip — no second badge beside it.
        $late = \App\Models\Assignment::create([
            'teacher_id' => $this->user('teacher@hackstra.app')->id,
            'my_class_id' => $record->my_class_id,
            'section_id' => $record->section_id,
            'subject_id' => $subject->id,
            'title' => 'Batch 6 Late Fixture',
            'due_at' => now()->subDays(2),
            'allow_late' => 1,
        ]);
        \App\Models\AssignmentSubmission::create([
            'assignment_id' => $late->id,
            'student_id' => $student->id,
            'submitted_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($student)->get('/home');
        $response->assertOk();
        $response->assertSee('Submitted late &middot; ' . now()->subDays(2)->format('d M'), false);
    }

    public function test_e8_builder_downloads_live_in_the_docbar_only()
    {
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('reports.builder'));

        $response->assertOk();
        $html = $response->getContent();
        // Count buttons, not the words (a CSS comment in the page
        // stylesheet also names them).
        $this->assertSame(1, substr_count($html, 'Download PDF</button>'));
        $this->assertSame(1, substr_count($html, 'Download Word</button>'));
        $this->assertStringContainsString('Save Template', $html);
        $this->assertStringNotContainsString('Save &amp; Export', $html);
        $this->assertStringNotContainsString('id="rbPdfBtn"', $html);
        $this->assertStringNotContainsString('id="rbWordBtn"', $html);
    }

    public function test_e9_admin_enrollment_chart_waits_for_three_months()
    {
        // Seed enrolments sit in fewer than three of the trailing
        // six months, so the panel states the total instead of
        // drawing one column among zeroes.
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('Student Enrollment Trend');
        $response->assertSee('Enrolment so far:');
    }

    public function test_e9_accountant_collections_state_the_total_first()
    {
        $response = $this->actingAs($this->user('accountant@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('Collected so far:');
    }

    public function test_e9_librarian_trend_states_activity_first()
    {
        $response = $this->actingAs($this->user('librarian@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('So far in this period:');
    }

    public function test_e10_generation_rules_are_chips()
    {
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('tt.generate.form'));

        $response->assertOk();
        $response->assertSee('How generation decides');
        $response->assertSee('Mandatory — never broken');
        $response->assertSee('Preferences — best effort, always reported');
        $response->assertSee('gen-chip', false);
        $response->assertSee('No teacher in two places at once');
        $response->assertSee('Smallest room that fits');
        $response->assertSee('Anything unmet is named in the result');
    }

    public function test_e11_substitutions_board_states_its_scope()
    {
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('substitutions.index'));

        $response->assertOk();
        // Settled doctrine (2026-10-30): blank date = whole record.
        // The scope is spelled out, never silently "today".
        $response->assertSee('Showing all dates');
        $response->assertSee('No substitutions arranged yet — this sheet covers every date');
    }

    public function test_e12_nurse_keeps_the_topbar_bell()
    {
        $response = $this->actingAs($this->user('nurse@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('id="lavNotifBell"', false);
    }

    public function test_e13_admission_card_subtitle_is_the_session()
    {
        $response = $this->get(route('admission_enquiry.form'));

        $response->assertOk();
        $response->assertSee('Admission Enquiry');
        $response->assertSee('Admissions for session ' . Qs::getCurrentSession());
    }

    public function test_batch6_stylesheet_additions_are_present()
    {
        $css = $this->css();

        $this->assertStringContainsString('.gen-chip{background:#eef4ff', $css);
        $this->assertStringContainsString('html.hk-dark .gen-chip{', $css);
        $this->assertStringContainsString('details.ma-info summary.ma-info-head{cursor:pointer', $css);
    }
}
