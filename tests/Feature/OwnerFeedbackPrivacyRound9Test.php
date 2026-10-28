<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 9 — feedback reporter-privacy re-check, one
 * step beyond round 7 (which locked mine list/detail + the
 * forwarded-and-fixed "Resolved" reading).
 *
 * Settled redesign (2026-10-28): reporters NEVER see SA content.
 * They see only the school-side status + the Admin's response.
 * The SA reply is a platform-side note for SA + school Admin only.
 *
 * This suite locks the two remaining edges: (1) a reporter
 * reaching any non-reporter feedback route (admin inbox, admin
 * detail, SA index, SA detail) is refused, even for their OWN
 * item — the reporter surface is mine/* only; (2) even when an
 * sa_reply IS stored on their item, no reporter-facing page
 * renders it — while the Admin detail DOES show it, proving the
 * boundary is "who may see it", not "the note was never saved".
 */
class OwnerFeedbackPrivacyRound9Test extends TestCase
{
    use DatabaseTransactions;

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function forwardedFixedItem(User $reporter, string $saReply): FeedbackItem
    {
        return FeedbackItem::create([
            'reporter_id' => $reporter->id, 'reporter_name' => $reporter->name,
            'reporter_role' => 'student', 'type' => 'problem', 'category' => 'app_bug',
            'priority' => 'medium', 'title' => 'R9 privacy probe item',
            'description' => 'R9 reporter privacy probe text',
            'status' => 'resolved', 'admin_note' => 'R9 school response text',
            'forwarded_at' => now(), 'forwarded_by' => $this->admin()->id,
            'sa_status' => 'fixed', 'sa_reply' => $saReply,
        ]);
    }

    public function test_reporter_is_refused_every_non_reporter_feedback_route()
    {
        $item = $this->forwardedFixedItem($this->student(), 'R9 hidden SA note');

        // Own item, wrong surfaces: admin inbox + admin detail +
        // SA index + SA detail must all refuse the reporter.
        $this->actingAs($this->student())->get(route('feedback.index'))->assertForbidden();
        $this->actingAs($this->student())->get(route('feedback.show', $item->id))->assertForbidden();
        $this->actingAs($this->student())->get(route('feedback.sa_index'))->assertForbidden();
        $this->actingAs($this->student())->get(route('feedback.sa_show', $item->id))->assertForbidden();

        // And the SA itself may not reach the reporter surface for
        // someone else's item: PlatformOnly bounces SA school GETs
        // to /dashboard (302) before showMine's owner check — a
        // refusal either way, never a render. (Contrast the four
        // 403s above, where the controller gate fires.)
        $this->actingAs($this->sa())->get(route('feedback.mine.show', $item->id))
            ->assertRedirect('/dashboard');
    }

    public function test_reporter_pages_never_render_the_sa_reply()
    {
        $saReply = 'R9 hidden SA note ' . uniqid();
        $item = $this->forwardedFixedItem($this->student(), $saReply);

        // List + detail: school status + admin response visible,
        // SA note + SA wording nowhere.
        $this->actingAs($this->student())->get(route('feedback.mine'))
            ->assertOk()->assertDontSee($saReply)->assertDontSee('Super Admin');
        $this->actingAs($this->student())->get(route('feedback.mine.show', $item->id))
            ->assertOk()->assertSee('Resolved')->assertSee('R9 school response text')
            ->assertDontSee($saReply)->assertDontSee('Super Admin');
    }

    public function test_admin_detail_does_show_the_sa_reply_by_design()
    {
        $saReply = 'R9 admin-visible SA note ' . uniqid();
        $item = $this->forwardedFixedItem($this->student(), $saReply);

        // Control: the boundary is recipient-based — the Admin
        // (SA + Admin only, per the redesign) legitimately sees it.
        $this->actingAs($this->admin())->get(route('feedback.show', $item->id))
            ->assertOk()->assertSee($saReply);
    }
}
