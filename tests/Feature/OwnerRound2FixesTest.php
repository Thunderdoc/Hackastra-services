<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 2 verification fixes (owner round-2 order, 2026-11-07):
 *
 * (1) THE APPLICANT HEARS THE DECISION. The leave cover chain
 *     notified deciders at submit, substitutes and their students
 *     at approval — but the applicant, the one person whose plans
 *     the decision changes, received nothing on approval OR
 *     rejection and only found out by re-opening My Leave
 *     (proven live in the Round-2 scripted flow: after 3 real
 *     decisions the applicant held zero notification rows).
 *     decide() now bells the applicant with the outcome, the
 *     dates and the decider's note when one was written.
 *
 * (2) PHONE-WIDTH PANEL HEADS (teacher home, 360px captures):
 *     the no-wrap panel head squeezed the title until the
 *     subtitle wrapped word-by-word, the leave-balance stat
 *     collapsed into a word-per-line column, and the timetable
 *     table's nowrap headers pushed the empty-state sentence
 *     off the card. qs.css carries the wrap rules; the balance
 *     stat is a class now, not an inline style.
 *
 * (3) SMALL PARITY NITS from the same sweep: the My Account
 *     "Signed in" activity label never wraps mid-phrase, the
 *     WhatsApp progress count never splits, the librarian trend
 *     legend sits at the panel's heading inset, and the role
 *     banner's light-mode phone overlay is strengthened.
 */
class OwnerRound2FixesTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function userByEmail(string $email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function submitLeave(User $teacher, string $date, string $reason): LeaveRequest
    {
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => $date,
            'end_date' => $date,
            'reason' => $reason,
            'leave_type' => 'normal',
        ])->assertRedirect();

        return LeaveRequest::where('user_id', $teacher->id)
            ->where('reason', $reason)->firstOrFail();
    }

    public function test_approval_notifies_the_applicant_with_dates_and_note()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $leave = $this->submitLeave($teacher, '2026-11-23', 'Round2 notify approve');

        $this->assertSame(0, UserNotification::where('user_id', $teacher->id)
            ->where('type', 'leave_decided')->count());

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'approved',
            'decision_note' => 'See you Monday',
        ])->assertRedirect();

        $note = UserNotification::where('user_id', $teacher->id)
            ->where('type', 'leave_decided')->latest('id')->first();
        $this->assertNotNull($note, 'applicant received no decision notification');
        $this->assertSame('Leave approved', $note->title);
        $this->assertStringContainsString('23 Nov 2026', $note->body);
        $this->assertStringContainsString('See you Monday', $note->body);
        $this->assertNull($note->read_at);
    }

    public function test_rejection_notifies_the_applicant()
    {
        $teacher = $this->userByEmail('teacher2@teacher.com');
        $leave = $this->submitLeave($teacher, '2026-11-24', 'Round2 notify reject');

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), [
            'status' => 'rejected',
        ])->assertRedirect();

        $note = UserNotification::where('user_id', $teacher->id)
            ->where('type', 'leave_decided')->latest('id')->first();
        $this->assertNotNull($note, 'rejected applicant received no notification');
        $this->assertSame('Leave rejected', $note->title);
        $this->assertStringContainsString('24 Nov 2026', $note->body);
    }

    public function test_round2_css_and_blade_rules_present()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        // Teacher-home phone rules.
        $this->assertStringContainsString('.t10-panel-balance{margin-left:auto;text-align:right}', $css);
        $this->assertStringContainsString('.t10-panel-head{flex-wrap:wrap}', $css);
        $this->assertStringContainsString('.t10-table th{white-space:normal;letter-spacing:0}', $css);
        // Parity nits.
        $this->assertStringContainsString('.acct-activity-text strong{white-space:nowrap}', $css);
        $this->assertStringContainsString('.acct-activity-text small{display:block;margin-left:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}', $css);
        $this->assertStringContainsString('.wa-prog,.wa-prog b{white-space:nowrap}', $css);
        $this->assertStringContainsString('.l117-trend-legend{padding-left:22px;padding-right:22px}', $css);
        $this->assertStringContainsString('html:not(.hk-dark) .hk-bn-overlay', $css);

        $home = file_get_contents(resource_path('views/pages/teacher/home.blade.php'));
        $this->assertStringContainsString('t10-panel-balance', $home);
        $this->assertStringNotContainsString('style="margin-left:auto; text-align:right;"', $home);
    }

    public function test_teacher_home_renders_the_balance_class()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $this->actingAs($teacher)->get(route('home'))
            ->assertOk()
            ->assertSee('t10-panel-balance', false);
    }

    /* ---------- ID-card header logo (live defect 2026-11-07) ----------
       The demo seed's 'logo' setting is a storage URL whose file is
       not in the shipped webroot; brand() used to pass it raw to the
       card header, so the live single-card page showed a broken-image
       icon front and back. The brand must resolve through the
       existence-checked path: configured logo when the file exists,
       the bundled school logo when it does not — never the dead URL. */

    private function setLogoSetting($url): void
    {
        \App\Models\Setting::where('type', 'logo')->update(['description' => $url]);
    }

    public function test_id_card_brand_falls_back_when_logo_file_is_missing()
    {
        $dead = 'http://127.0.0.1:8993/storage/uploads/no-such-logo.png';
        $this->setLogoSetting($dead);

        $screen = \App\Support\IdCardFactory::brand(false);
        $this->assertNotSame($dead, $screen['logo_img']);
        $this->assertStringContainsString('demo-assets/school-logo.svg', (string) $screen['logo_img']);

        // PDF: an SVG fallback has no raster form — logo drops to the
        // monogram (null), and the dead URL must not leak through.
        $pdf = \App\Support\IdCardFactory::brand(true);
        $this->assertNull($pdf['logo_img']);

        // imageSrc itself refuses a missing local file on screen
        // (covers the stamp/signature slots too).
        $this->assertNull(\App\Support\IdCardFactory::imageSrc($dead, false));
    }

    /* ---------- PDF charset (annual report card mojibake) ----------
       Dompdf documents that inline data-URI images without a declared
       charset can be mis-sniffed as single-byte text — the annual
       report card printed "Cumulative Report ?? Academic Year" (the
       middle dot destroyed) while its term twin rendered fine. Every
       PDF shell must declare UTF-8 in its head. */

    public function test_every_pdf_shell_declares_utf8_charset()
    {
        $shells = [
            'attendance/defaulters_pdf', 'attendance/report_pdf', 'attendance/student_report_pdf',
            'attendance_periods/print', 'attendance_periods/roster_print', 'circulars/pdf',
            'exams/date_sheet_print', 'marks/print/annual_pdf', 'marks/print/bulk_pdf',
            'payments/collection_pdf', 'payments/receipt', 'payroll/payslip_pdf',
            'payroll/payslips_pdf_all', 'reports/print', 'students/id_card_bulk_pdf',
            'timetables/mine_print', 'timetables/print',
        ];
        foreach ($shells as $shell) {
            $path = resource_path('views/pages/support_team/' . $shell . '.blade.php');
            $this->assertFileExists($path);
            $this->assertMatchesRegularExpression('/charset\s*=\s*"?(utf-8|text\/html;\s*charset=utf-8)/i', file_get_contents($path), $shell . ' must declare UTF-8');
        }
    }

    public function test_annual_report_pdf_keeps_its_middle_dot()
    {
        $admin = $this->userByEmail('admin@hackstra.app');
        $student = $this->userByEmail('student@hackstra.app');

        $response = $this->actingAs($admin)->get(route('marks.pdf_annual', [\App\Helpers\Qs::hash($student->id), '2026-2027']));
        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'ann') . '.pdf';
        file_put_contents($tmp, $response->getContent());
        $text = shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        unlink($tmp);
        $this->assertStringContainsString('Cumulative Report · Academic Year', (string) $text);
        $this->assertStringNotContainsString('Cumulative Report ??', (string) $text);
    }

    public function test_id_card_back_head_fits_its_two_lines()
    {
        // The back head's fixed height must fit the school name plus
        // the DETAILS line — at 18pt the DETAILS line was clipped.
        $styles = file_get_contents(resource_path('views/pages/support_team/partials/id_card_styles.blade.php'));
        $this->assertStringContainsString('.idc2-bhead{border-bottom:2pt solid #c9a227;height:20pt}', $styles);
    }

    public function test_id_card_brand_keeps_a_real_uploaded_logo()
    {
        // The seeded logo.svg exists in the local webroot (through
        // the public/storage symlink), so it must survive untouched.
        $seeded = \App\Helpers\Qs::getSetting('logo');
        $screen = \App\Support\IdCardFactory::brand(false);
        $this->assertSame($seeded, $screen['logo_img']);
    }

    public function test_single_id_card_page_never_renders_the_dead_logo_url()
    {
        $dead = 'http://127.0.0.1:8993/storage/uploads/no-such-logo.png';
        $this->setLogoSetting($dead);

        $admin = $this->userByEmail('admin@hackstra.app');
        $sr = \App\Models\StudentRecord::whereHas('user', function ($q) {
            $q->where('email', 'student@hackstra.app');
        })->firstOrFail();

        $html = $this->actingAs($admin)
            ->get(route('students.id_card', \App\Helpers\Qs::hash($sr->id)))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('no-such-logo.png', $html);
        $this->assertStringContainsString('demo-assets/school-logo.svg', $html);
    }
}
