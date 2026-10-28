<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 16 — principal bell badge composition.
 *
 * top_menu.blade.php builds $lavBellBadge as:
 *   unreadCount(caller) + admin-only NEW-feedback count
 *   + SA-only forwarded-received count
 *   + unseenUpdatesCountFor(caller) — the caller's OWN reports
 *     that changed since they last opened My Feedback.
 *
 * The principal cannot reach the admin feedback inbox (teamSA —
 * principal gets 302 there), so the question is whether the
 * principal's badge wrongly includes the admin's NEW-feedback
 * component (a dead count pointing at an inbox they cannot open).
 * It does not: the NEW component is gated by userIsAdmin() only,
 * and the principal falls in the @else (Circulars) bell branch.
 * These tests lock that shape on the rendered dashboard.
 */
class OwnerPrincipalBellBadgeRound16Test extends TestCase
{
    use DatabaseTransactions;

    protected function freshPrincipal()
    {
        return User::create([
            'name' => 'Bell Probe Principal',
            'email' => 'bellprobe-principal@hackstra.test',
            'password' => bcrypt('hackstra'),
            'code' => 'BELL-PRIN-1',
            'user_type' => 'principal',
        ]);
    }

    protected function freshAdmin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function newFeedbackFromSomeoneElse()
    {
        return FeedbackItem::create([
            'reporter_id' => User::where('email', 'teacher@hackstra.app')->firstOrFail()->id,
            'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'facility',
            'title' => 'Bell probe new report',
            'description' => 'A NEW report the principal did not file.',
            'status' => 'new',
        ]);
    }

    protected function badgeSpans($html)
    {
        preg_match_all('/<span class="lav-bell-badge">(\d+)<\/span>/', $html, $m);

        return $m[1];
    }

    public function test_principal_badge_ignores_new_feedback()
    {
        $this->newFeedbackFromSomeoneElse();
        $principal = $this->freshPrincipal();

        $response = $this->actingAs($principal)->get(route('dashboard'));
        $response->assertOk();
        $html = $response->getContent();

        // The bell itself renders (Circulars branch) — so an absent
        // badge is a real verdict, not a missing topbar.
        $this->assertStringContainsString('aria-label="Circulars"', $html);
        $this->assertSame([], $this->badgeSpans($html));
    }

    public function test_principal_badge_counts_own_unread_notification()
    {
        $this->newFeedbackFromSomeoneElse();
        $principal = $this->freshPrincipal();
        UserNotification::create([
            'user_id' => $principal->id,
            'type' => 'test',
            'title' => 'Bell probe notification',
            'body' => 'One unread notification for the principal.',
            'link' => '#',
            'read_at' => null,
        ]);

        $response = $this->actingAs($principal)->get(route('dashboard'));
        $response->assertOk();

        $this->assertSame(['1'], $this->badgeSpans($response->getContent()));
    }

    public function test_principal_badge_counts_own_unseen_feedback_update()
    {
        $principal = $this->freshPrincipal();
        $report = FeedbackItem::create([
            'reporter_id' => $principal->id,
            'reporter_name' => 'Bell Probe Principal',
            'reporter_role' => 'principal',
            'type' => 'suggestion',
            'category' => 'facility',
            'title' => 'Principal own report',
            'description' => 'Filed by the principal, then triaged.',
            'status' => 'acknowledged',
            'reporter_seen_at' => null,
        ]);
        $this->assertNotNull($report->id);

        $response = $this->actingAs($principal)->get(route('dashboard'));
        $response->assertOk();

        $this->assertSame(['1'], $this->badgeSpans($response->getContent()));
    }

    public function test_admin_badge_still_includes_new_feedback()
    {
        $this->newFeedbackFromSomeoneElse();
        $admin = $this->freshAdmin();
        $expected = (string) FeedbackItem::where('status', 'new')->count();
        $this->assertGreaterThanOrEqual(1, (int) $expected);

        $response = $this->actingAs($admin)->get(route('dashboard'));
        $response->assertOk();

        // Admin unread notifications are 0 on the seed, so the badge
        // is exactly the NEW-feedback count.
        $this->assertSame([$expected], $this->badgeSpans($response->getContent()));
    }
}
