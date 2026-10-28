<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 17 — Super Admin forwarded-feedback badge.
 *
 * CORRECTION to the brief's premise, read from the code before
 * writing any test: the Super Admin has NO topbar bell at all.
 * top_menu.blade.php's SA branch renders nothing ("The Super
 * Admin gets no bell — platform activity lives on the Platform
 * Overview"), so the $lavBellBadge SA forwarded-received
 * component computed there is never rendered for the SA. The
 * SA's real forwarded surface is the SIDEBAR badge on the
 * "School Feedback" item (pages/super_admin/menu.blade.php):
 *   forwarded()->where('sa_status', 'received')->count()
 * rendered as a red badge-danger span, only when the count > 0.
 *
 * "Picked up" is also not a view: saShow() changes nothing, so
 * merely opening a forwarded item does NOT clear the badge.
 * Only saUpdate() to a non-'received' status (in_progress /
 * fixed / declined) or saBulkClose (fixed) drops the count —
 * and reopen() (school Admin only) puts sa_status back to
 * 'received', re-inflating the badge by design (re-escalation).
 *
 * These tests lock that shape on rendered pages + the query.
 */
class OwnerSaBellBadgeRound17Test extends TestCase
{
    use DatabaseTransactions;

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function schoolAdmin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function receivedCount()
    {
        return FeedbackItem::forwarded()->where('sa_status', 'received')->count();
    }

    protected function forwardedFixture(array $overrides = [])
    {
        return FeedbackItem::create(array_merge([
            'reporter_id' => User::where('email', 'teacher@hackstra.app')->firstOrFail()->id,
            'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'app_bug',
            'title' => 'SA badge probe report',
            'description' => 'A forwarded report the SA has not picked up.',
            'status' => 'in_progress',
            'forwarded_at' => now(),
            'forwarded_by' => $this->schoolAdmin()->id,
            'sa_status' => 'received',
        ], $overrides));
    }

    /**
     * The sidebar badge number next to "School Feedback", or null
     * when no badge renders (count 0). The adjacency to the exact
     * span is what scopes it to the sidebar anchor rather than
     * any other "School Feedback" mention on the page.
     */
    protected function saSidebarBadge($html)
    {
        if (preg_match('/School Feedback<\/span>\s*<span class="badge badge-danger ml-auto">(\d+)<\/span>/', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function topbarBadgeSpans($html)
    {
        preg_match_all('/<span class="lav-bell-badge">(\d+)<\/span>/', $html, $m);

        return $m[1];
    }

    protected function saDashboardHtml()
    {
        $response = $this->actingAs($this->superAdmin())->get(route('dashboard'));
        $response->assertOk();

        return $response->getContent();
    }

    public function test_sa_sidebar_badge_counts_unseen_forwarded()
    {
        $baseline = $this->receivedCount();
        $this->forwardedFixture();

        $html = $this->saDashboardHtml();

        // The sidebar item itself renders — so a badge value is a
        // real verdict, not a missing sidebar.
        $this->assertStringContainsString('School Feedback', $html);
        $this->assertSame($baseline + 1, $this->saSidebarBadge($html));
    }

    public function test_sa_has_no_topbar_bell_even_with_unseen_forwarded()
    {
        $this->forwardedFixture();
        $html = $this->saDashboardHtml();

        // SA branch of top_menu renders no bell button at all —
        // neither the staff "Recent activity" bell nor the
        // @else "Circulars" bell — and therefore no badge span.
        $this->assertStringNotContainsString('aria-label="Recent activity"', $html);
        $this->assertStringNotContainsString('aria-label="Circulars"', $html);
        $this->assertSame([], $this->topbarBadgeSpans($html));
    }

    public function test_sa_detail_view_does_not_clear_badge()
    {
        $baseline = $this->receivedCount();
        $item = $this->forwardedFixture();

        $response = $this->actingAs($this->superAdmin())
            ->get(route('feedback.sa_show', $item->id));
        $response->assertOk();

        // Viewing changes nothing: still 'received', badge intact.
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));
    }

    public function test_sa_update_clears_badge()
    {
        $baseline = $this->receivedCount();
        $item = $this->forwardedFixture();
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));

        $response = $this->actingAs($this->superAdmin())
            ->patch(route('feedback.sa_update', $item->id), [
                'sa_status' => 'in_progress',
                'sa_reply' => 'Looking into it.',
            ]);
        $response->assertRedirect();

        $this->assertSame('in_progress', $item->fresh()->sa_status);
        $this->assertSame($baseline, $this->receivedCount());
        // Baseline may itself be > 0 on the seed, so assert the
        // badge equals the baseline count rather than null.
        $this->assertSame($baseline > 0 ? $baseline : null, $this->saSidebarBadge($this->saDashboardHtml()));
    }

    public function test_non_forwarded_and_picked_up_items_do_not_inflate_badge()
    {
        $baseline = $this->receivedCount();

        // School-side only: never forwarded, status new.
        FeedbackItem::create([
            'reporter_id' => User::where('email', 'teacher@hackstra.app')->firstOrFail()->id,
            'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'facility',
            'title' => 'SA badge probe school-only report',
            'description' => 'Never attested, never forwarded.',
            'status' => 'new',
        ]);

        // Forwarded but already picked up (SA moved it along).
        $this->forwardedFixture([
            'title' => 'SA badge probe picked-up report',
            'sa_status' => 'in_progress',
        ]);

        $this->assertSame($baseline, $this->receivedCount());
        $this->assertSame($baseline > 0 ? $baseline : null, $this->saSidebarBadge($this->saDashboardHtml()));
    }

    public function test_admin_badge_unaffected_by_forwarded_items()
    {
        $admin = $this->schoolAdmin();
        $before = $this->actingAs($admin)->get(route('dashboard'));
        $before->assertOk();
        $beforeBadges = $this->topbarBadgeSpans($before->getContent());

        // Forwarded fixture carries school status in_progress, so
        // the admin NEW-feedback count cannot move either: the
        // only question is whether forwarding adds a component.
        $this->forwardedFixture();

        $after = $this->actingAs($admin)->get(route('dashboard'));
        $after->assertOk();
        $this->assertSame($beforeBadges, $this->topbarBadgeSpans($after->getContent()));
    }

    public function test_reopen_reinflates_badge()
    {
        $baseline = $this->receivedCount();
        $item = $this->forwardedFixture();

        // SA closes it: badge drops back to baseline.
        $this->actingAs($this->superAdmin())
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'fixed'])
            ->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame($baseline, $this->receivedCount());

        // School Admin reopens (re-escalation): back to received,
        // badge re-inflates by exactly the item.
        $this->actingAs($this->schoolAdmin())
            ->post(route('feedback.reopen', $item->id))
            ->assertRedirect();
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame($baseline + 1, $this->receivedCount());
        $this->assertSame($baseline + 1, $this->saSidebarBadge($this->saDashboardHtml()));
    }
}
