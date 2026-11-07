<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\FeeHead;
use App\Models\FeeInstallment;
use App\Models\MyClass;
use App\Support\Readiness;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 5 P15 — Onboarding checklist (audit W4 §15).
 *
 * The checklist engine (Readiness) gains the two checks it was
 * missing — school logo and fee structure — and the school
 * Admin's home renders the setup subset of those checks while
 * the readiness score is under 100 percent. Every check stays a
 * live count/query; nothing here is a stored "done" flag.
 *
 * Seed state, measured against the demo database before pinning
 * (2026-11-07): fee_heads is EMPTY (0 heads, 0 instalments) even
 * though the legacy payments ledger is full — so the fee check
 * is a fail on the seed. The seeded logo setting points at
 * storage/uploads/logo.svg and that file IS on disk in this
 * tree (SettingsTableSeeder copies the bundled mark there), so
 * the logo check's seed status follows the file's existence —
 * pinned below as the live truth, plus a deterministic
 * missing-file pin that does not depend on the seed at all.
 */
class OwnerRound5OnboardingTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function checkByLabel(string $label): ?array
    {
        foreach (Readiness::checks()['checks'] as $check) {
            if ($check['label'] === $label) {
                return $check;
            }
        }

        return null;
    }

    /* ---------------- The two new Readiness checks ---------------- */

    public function test_readiness_includes_logo_and_fee_structure_checks_with_seed_statuses()
    {
        $result = Readiness::checks();

        // Twelve original checks plus the two P15 additions, all
        // in the established shape the readiness page renders.
        $this->assertSame(14, $result['total']);
        $this->assertCount(14, $result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertSame(['status', 'label', 'detail', 'route'], array_keys($check));
        }

        $logo = $this->checkByLabel('School logo uploaded');
        $this->assertNotNull($logo, 'logo check missing');
        $this->assertSame('settings', $logo['route']);
        $this->assertNotSame('', $logo['detail']);
        // The seed status is whatever the disk truth is: ok only
        // when a logo is set AND its file actually exists.
        $setting = Qs::getSetting('logo');
        $expectedLogo = ($setting && Qs::logoUrlFileExists($setting)) ? 'ok' : 'warn';
        $this->assertSame($expectedLogo, $logo['status']);

        $fee = $this->checkByLabel('Fee structure defined');
        $this->assertNotNull($fee, 'fee structure check missing');
        $this->assertSame('fee_structure.index', $fee['route']);
        // Measured seed truth: zero fee heads for the session.
        $session = Qs::getCurrentSession();
        $this->assertSame(0, FeeHead::where('year', $session)->count());
        $this->assertSame('fail', $fee['status']);
    }

    public function test_logo_check_is_warn_when_the_setting_points_at_a_missing_file()
    {
        DB::table('settings')->where('type', 'logo')
            ->update(['description' => asset('storage/uploads/hk-p15-no-such-logo.svg')]);
        $this->assertSame('warn', $this->checkByLabel('School logo uploaded')['status']);

        DB::table('settings')->where('type', 'logo')->update(['description' => '']);
        $this->assertSame('warn', $this->checkByLabel('School logo uploaded')['status']);
    }

    public function test_fee_structure_check_walks_fail_warn_ok_with_real_rows()
    {
        $session = Qs::getCurrentSession();
        $this->assertSame('fail', $this->checkByLabel('Fee structure defined')['status']);

        $head = FeeHead::create([
            'my_class_id' => MyClass::orderBy('id')->firstOrFail()->id,
            'title' => 'P15 Probe Fee Head',
            'amount' => 10000,
            'year' => $session,
        ]);
        $this->assertSame('warn', $this->checkByLabel('Fee structure defined')['status']);

        FeeInstallment::create([
            'fee_head_id' => $head->id,
            'installment_no' => 1,
            'due_date' => '2026-11-01',
            'amount' => 10000,
        ]);
        $this->assertSame('ok', $this->checkByLabel('Fee structure defined')['status']);
    }

    /* ---------------- The dashboard checklist ---------------- */

    public function test_admin_dashboard_shows_the_setup_checklist_while_setup_is_incomplete()
    {
        $this->assertLessThan(100, Readiness::checks()['score'], 'seed must be below a full readiness score for this pin');

        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('dashboard'));
        $response->assertOk();
        $this->assertNotNull($response->viewData('setup_readiness'));

        $html = $response->getContent();
        $this->assertStringContainsString('hk-setup-checklist', $html);
        $this->assertStringContainsString('Get your school set up', $html);
        // The ordered setup steps, including the two new ones.
        foreach (['School profile', 'School logo', 'Session', 'Classes &amp; sections', 'Teachers', 'Students', 'Fee structure'] as $step) {
            $this->assertStringContainsString($step, $html);
        }
        // Readiness badge vocabulary, and the fee step's fix link.
        $this->assertStringContainsString('Missing', $html);
        $this->assertStringContainsString('href="' . route('fee_structure.index') . '"', $html);
    }

    public function test_admin_dashboard_points_record_payment_at_fee_structure_until_fees_exist()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('dashboard'))->assertOk()->getContent();

        // Seed: no fee heads, so the tile cannot record a payment.
        // Tile-scoped: the sidebar menu legitimately links the
        // payment form too, so only the quick-action tile counts.
        $tile = '" class="l17-qatile"><span class="l17-qi l17-tone-green">';
        $this->assertStringContainsString('href="' . route('fee_structure.index') . $tile, $html);
        $this->assertStringNotContainsString('href="' . route('payments.create') . $tile, $html);
        $this->assertStringContainsString('Record Payment', $html);
    }

    public function test_record_payment_tile_returns_once_fee_structure_exists()
    {
        $session = Qs::getCurrentSession();
        $head = FeeHead::create([
            'my_class_id' => MyClass::orderBy('id')->firstOrFail()->id,
            'title' => 'P15 Probe Fee Head',
            'amount' => 10000,
            'year' => $session,
        ]);
        FeeInstallment::create([
            'fee_head_id' => $head->id,
            'installment_no' => 1,
            'due_date' => '2026-11-01',
            'amount' => 10000,
        ]);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('dashboard'))->assertOk()->getContent();
        $tile = '" class="l17-qatile"><span class="l17-qi l17-tone-green">';
        $this->assertStringContainsString('href="' . route('payments.create') . $tile, $html);
    }

    /* ---------------- The readiness page keeps working ---------------- */

    public function test_readiness_planning_page_renders_the_two_new_checks()
    {
        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('planning.readiness'));
        $response->assertOk();
        $response->assertSee('School logo uploaded');
        $response->assertSee('Fee structure defined');
    }

    /* ---------------- Admin gating ---------------- */

    public function test_checklist_is_not_forced_on_non_admin_roles()
    {
        // Principal and accountant land on their own homes, which
        // never receive the checklist data or markup.
        foreach (['principal@hackstra.app', 'accountant@hackstra.app', 'teacher@hackstra.app'] as $email) {
            $response = $this->actingAs($this->user($email))->get(route('dashboard'));
            $response->assertOk();
            $response->assertDontSee('hk-setup-checklist');
            $response->assertViewMissing('setup_readiness');
        }

        // The strong pin: the Super Admin never receives the
        // checklist either. PlatformOnly redirects SA off the
        // school board (dashboard.school → dashboard), so even
        // following redirects the SA lands on the platform home
        // with no checklist data and no checklist markup — the
        // gate is the school Admin write role, and the platform
        // surface is untouched by this slice.
        $sa = $this->actingAs($this->user('superadmin@hackstra.app'))
            ->followingRedirects()->get(route('dashboard.school'));
        $sa->assertOk();
        $sa->assertDontSee('hk-setup-checklist');
        $sa->assertViewMissing('setup_readiness');
    }
}
