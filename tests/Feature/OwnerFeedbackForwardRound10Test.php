<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 10 — feedback forwarding gates, exercised
 * through the real endpoints (not fixture state alone).
 *
 * Settled redesign (2026-10-28, PLATFORM-ONLY FORWARDING): the
 * Super Admin receives ONLY category app_bug OR items the Admin
 * attests via the platform_issue checkbox. Everything else is
 * sorted by the school alone and never leaves the inbox.
 *
 * Companion to round 9's privacy suite (which proved reporters
 * never see SA content on fixture state): here the item travels
 * the full pipeline — admin forward, SA fix — and the reporter
 * surface is checked at the end of that real journey.
 */
class OwnerFeedbackForwardRound10Test extends TestCase
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

    protected function makeItem(string $category, string $suffix): FeedbackItem
    {
        return FeedbackItem::create([
            'reporter_id' => $this->student()->id, 'reporter_name' => $this->student()->name,
            'reporter_role' => 'student', 'type' => 'problem', 'category' => $category,
            'priority' => 'medium', 'title' => 'R10 forward probe ' . $suffix . ' ' . uniqid(),
            'description' => 'R10 forwarding-gate probe text',
            'status' => 'new', 'admin_note' => 'R10 school response text',
        ]);
    }

    public function test_facility_item_forward_is_refused()
    {
        $item = $this->makeItem('facility', 'facility-refused');

        $this->actingAs($this->admin())
            ->post(route('feedback.forward'), ['ids' => [$item->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('feedback_items', [
            'id' => $item->id, 'forwarded_at' => null,
        ]);

        // SA inbox must not carry it: platform-only means the SA
        // never even sees the unforwarded school item.
        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertDontSee($item->title);
    }

    public function test_app_bug_item_forwards()
    {
        $item = $this->makeItem('app_bug', 'appbug-ok');

        $this->actingAs($this->admin())
            ->post(route('feedback.forward'), ['ids' => [$item->id]])
            ->assertRedirect();

        $this->assertDatabaseHas('feedback_items', [
            'id' => $item->id, 'sa_status' => 'received',
        ]);
        $this->assertNotNull($item->fresh()->forwarded_at);

        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertSee($item->title);
    }

    public function test_platform_issue_attested_facility_item_forwards()
    {
        $item = $this->makeItem('facility', 'facility-attested');

        // The attestation covers reports filed under the wrong
        // category: one checkbox, and the facility item forwards.
        $this->actingAs($this->admin())
            ->post(route('feedback.forward'), ['ids' => [$item->id], 'platform_issue' => '1'])
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->forwarded_at);
        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertSee($item->title);
    }

    public function test_reporter_sees_no_sa_content_after_real_forward_and_fix()
    {
        $saReply = 'R10 hidden SA note ' . uniqid();
        $item = $this->makeItem('facility', 'facility-journey');

        // Full pipeline through the real endpoints: attested
        // forward, then SA marks it Fixed with a platform-side note.
        $this->actingAs($this->admin())
            ->post(route('feedback.forward'), ['ids' => [$item->id], 'platform_issue' => '1'])
            ->assertRedirect();
        $this->actingAs($this->sa())
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'fixed', 'sa_reply' => $saReply])
            ->assertRedirect(route('feedback.sa_show', $item->id));

        // SA fix auto-resolves the school side: the reporter reads
        // "Resolved" + the school's response, with no SA content.
        $this->actingAs($this->student())->get(route('feedback.mine'))
            ->assertOk()->assertDontSee($saReply)->assertDontSee('Super Admin');
        $this->actingAs($this->student())->get(route('feedback.mine.show', $item->id))
            ->assertOk()->assertSee('Resolved')->assertSee('R10 school response text')
            ->assertDontSee($saReply)->assertDontSee('Super Admin');
    }
}
