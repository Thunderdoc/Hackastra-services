<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Parent fix 2026-10-28 (flagged by agent C round 9): the topbar
 * "Recent activity" bell rendered admitted-student rows linking to
 * students.show for every branch viewer — but students.show denies
 * accountants, so an accountant's bell carried dead links to a
 * denial page. The student rows are now SAT-only; receipt rows
 * stay for every branch viewer.
 *
 * The bell segment is extracted from the rendered dashboard HTML
 * (lav-notif-menu) so sidebar/dashboard links elsewhere on the
 * page cannot fake the assertion either way.
 */
class OwnerBellStudentLinksTest extends TestCase
{
    use DatabaseTransactions;

    protected function bellSegment(string $html): string
    {
        $start = strpos($html, 'lav-notif-menu');
        $this->assertNotFalse($start, 'Bell menu not rendered on dashboard');
        return substr($html, $start, 8000);
    }

    public function test_accountant_bell_has_no_student_links_but_keeps_receipts()
    {
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        $html = $this->actingAs($accountant)->get(route('dashboard'))->getContent();
        $bell = $this->bellSegment($html);

        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/students/#', $bell);
        $this->assertStringContainsString('receipts', $bell);
    }

    public function test_admin_bell_still_links_recent_students()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $html = $this->actingAs($admin)->get(route('dashboard'))->getContent();
        $bell = $this->bellSegment($html);

        $this->assertMatchesRegularExpression('#href="[^"]*/students/#', $bell);
    }
}
