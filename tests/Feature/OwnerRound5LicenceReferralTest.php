<?php

namespace Tests\Feature;

use App\Models\Referral;
use App\Models\SchoolLicence;
use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 4 — P14 licence record + P16 referral plumbing.
 *
 * Honesty contract under test:
 *  - The licence is an SA-maintained RECORD only (plan name as
 *    sold, status, dates) — no price fields, no enforcement.
 *    The school Admin sees it read-only on their dashboard, and
 *    sees NOTHING licence-related while no record exists.
 *  - The referral programme is OFF precisely while the
 *    referral_reward_text setting is blank: then the school
 *    Admin sees no referral surface at all. SA sets the reward
 *    text + code and the Admin sees exactly those, read-only.
 *  - The referral LOG is SA-side only: SA adds entries and
 *    updates their status; the school Admin cannot open the
 *    SA pages (the super_admin middleware bounces them to
 *    login) and cannot save a licence.
 *
 * Seeded users are fetched by EMAIL, never by numeric id.
 * Probe rows/settings are cleared inside the transaction first
 * so the tests hold regardless of seed leftovers; the
 * transaction rolls everything back.
 */
class OwnerRound5LicenceReferralTest extends TestCase
{
    use DatabaseTransactions;

    protected function superAdmin(): User
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function clearLicenceAndReferralState(): void
    {
        SchoolLicence::query()->delete();
        Referral::query()->delete();
        Setting::whereIn('type', ['referral_code', 'referral_reward_text'])->delete();
    }

    /* ---------------- P14: licence record ---------------- */

    public function test_sa_creates_licence_and_admin_sees_it_read_only_on_dashboard()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_licence.update'), [
                'plan_name' => 'P14 Probe Plan',
                'status' => 'active',
                'starts_on' => '2026-11-01',
                'renews_on' => '2027-11-01',
                'notes' => 'P14 probe note',
            ])
            ->assertRedirect(route('platform_licence.edit'));

        $licence = SchoolLicence::current();
        $this->assertNotNull($licence);
        $this->assertSame('P14 Probe Plan', $licence->plan_name);
        $this->assertSame('active', $licence->status);
        $this->assertSame('2027-11-01', $licence->renews_on->format('Y-m-d'));

        // The SA's platform snapshot carries the plan + status badge.
        $this->actingAs($this->superAdmin())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('P14 Probe Plan')
            ->assertSee('Active');

        // The school Admin sees plan + status + renewal read-only.
        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Your school licence')
            ->assertSee('P14 Probe Plan')
            ->assertSee('Active')
            ->assertSee('01 Nov 2027');
    }

    public function test_sa_edits_the_current_licence_instead_of_stacking_records()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_licence.update'), [
                'plan_name' => 'P14 First Plan', 'status' => 'trial',
            ])->assertRedirect(route('platform_licence.edit'));
        $this->actingAs($this->superAdmin())
            ->post(route('platform_licence.update'), [
                'plan_name' => 'P14 Renewed Plan', 'status' => 'active',
            ])->assertRedirect(route('platform_licence.edit'));

        $this->assertSame(1, SchoolLicence::count());
        $this->assertSame('P14 Renewed Plan', SchoolLicence::current()->plan_name);
    }

    public function test_no_licence_record_means_admin_sees_nothing_licence_related()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Your school licence');
    }

    public function test_school_admin_cannot_save_a_licence()
    {
        $this->clearLicenceAndReferralState();

        // The super_admin middleware bounces non-SA users to login.
        $this->actingAs($this->admin())
            ->post(route('platform_licence.update'), [
                'plan_name' => 'P14 Forged Plan', 'status' => 'active',
            ])
            ->assertRedirect(route('login'));

        $this->assertSame(0, SchoolLicence::count());
    }

    public function test_licence_status_enum_rejects_a_bogus_status()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_licence.update'), [
                'plan_name' => 'P14 Bogus Plan', 'status' => 'bogus-status',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, SchoolLicence::count());
    }

    /* ---------------- P16: referral programme surface ---------------- */

    public function test_referral_surface_hidden_while_reward_text_blank()
    {
        $this->clearLicenceAndReferralState();

        // Even with a code set, a blank reward text = programme OFF.
        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.programme'), [
                'referral_code' => 'HACKSTRA-PROBE1',
                'referral_reward_text' => '',
            ])
            ->assertRedirect(route('platform_referrals.index'));
        $this->assertFalse(Referral::programmeOn());

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Refer a school')
            ->assertDontSee('HACKSTRA-PROBE1');
    }

    public function test_sa_sets_reward_text_and_code_and_admin_sees_them_read_only()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.programme'), [
                'referral_code' => 'HACKSTRA-PROBE2',
                'referral_reward_text' => 'P16 probe reward: one month credited on onboarding.',
            ])
            ->assertRedirect(route('platform_referrals.index'));
        $this->assertTrue(Referral::programmeOn());

        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Refer a school')
            ->assertSee('HACKSTRA-PROBE2')
            ->assertSee('P16 probe reward: one month credited on onboarding.');
    }

    public function test_sa_can_regenerate_the_referral_code()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.regenerate_code'))
            ->assertRedirect(route('platform_referrals.index'));

        $code = Referral::code();
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^HACKSTRA-[A-HJ-KM-NP-Z2-9]{6}$/', $code);
    }

    /* ---------------- P16: SA referral log ---------------- */

    public function test_sa_adds_a_referral_entry_and_updates_its_status()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.store'), [
                'referrer_name' => 'P16 Probe Referrer School',
                'referred_name' => 'P16 Probe Referred School',
                'status' => 'logged',
                'reward_note' => 'P16 probe reward note',
            ])
            ->assertRedirect(route('platform_referrals.index'));

        $entry = Referral::where('referrer_name', 'P16 Probe Referrer School')->firstOrFail();
        $this->assertSame('P16 Probe Referred School', $entry->referred_name);
        $this->assertSame('logged', $entry->status);
        $this->assertSame($this->superAdmin()->id, (int) $entry->created_by);

        // The entry appears in the SA log with its status control.
        $this->actingAs($this->superAdmin())->get(route('platform_referrals.index'))
            ->assertOk()
            ->assertSee('P16 Probe Referrer School')
            ->assertSee('P16 Probe Referred School')
            ->assertSee('P16 probe reward note');

        // Status update works.
        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.status', $entry->id), ['status' => 'onboarded'])
            ->assertRedirect(route('platform_referrals.index'));
        $this->assertSame('onboarded', $entry->fresh()->status);
    }

    public function test_referral_status_enum_rejects_a_bogus_status()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->superAdmin())
            ->post(route('platform_referrals.store'), [
                'referrer_name' => 'P16 Bogus Referrer',
                'referred_name' => 'P16 Bogus Referred',
                'status' => 'bogus-status',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Referral::count());
    }

    public function test_school_admin_cannot_open_the_sa_referral_or_licence_pages()
    {
        $this->clearLicenceAndReferralState();

        $this->actingAs($this->admin())->get(route('platform_referrals.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get(route('platform_licence.edit'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->admin())
            ->post(route('platform_referrals.store'), [
                'referrer_name' => 'P16 Forged Referrer',
                'referred_name' => 'P16 Forged Referred',
                'status' => 'logged',
            ])
            ->assertRedirect(route('login'));

        $this->assertSame(0, Referral::count());
    }
}
