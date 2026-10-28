<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 29 — feedback full lifecycle re-sweep.
 *
 * The reporter + Admin + Super Admin triangle, driven end to end
 * through the real routes after the 2026-10-28 redesign and the
 * round-27 terminal-close proof. It goes beyond the redesign suite
 * (which locked the reporter-privacy shapes on planted rows) by
 * submitting, triaging, forwarding, SA-handling, closing, reopening
 * and bulk-closing through the actual controller actions, and it
 * re-proves the terminal-close + badge re-inflation shapes from
 * round 27 inside one lifecycle instead of isolated fixtures.
 *
 * Shapes locked here (all read from FeedbackController before
 * writing, not assumed):
 *  - forward() forwards app_bug items, or ANY category when the
 *    Admin attests platform_issue; already-forwarded and duplicate
 *    items are skipped, never re-stamped.
 *  - saUpdate to in_progress leaves the school status untouched;
 *    a NEW fix auto-resolves the school side.
 *  - a Fixed report refuses an SA status change away from Fixed
 *    (pop_error) and disables the SA detail select; only the school
 *    Admin reopen works (sa received + school in_progress, badge
 *    re-inflates by exactly the item).
 *  - the reporter detail shows the school status + admin note only
 *    — never SA content — while the Admin detail shows "SA reply:"
 *    by design (SA + school Admin only).
 */
class OwnerFeedbackLifecycleRound29Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

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

    /** Submit one item through the real store route; returns the row. */
    protected function submitAs(User $user, string $title, string $category = 'app_bug')
    {
        $this->actingAs($user)->post(route('feedback.store'), [
            'type' => 'problem',
            'category' => $category,
            'priority' => 'medium',
            'title' => $title,
            'description' => 'R29 lifecycle probe body for ' . $title,
        ])->assertRedirect(route('feedback.mine'));

        return FeedbackItem::where('title', $title)->firstOrFail();
    }

    /** Forward ids through the real forward route (admin). */
    protected function forwardAsAdmin(array $ids, bool $platformIssue = false)
    {
        $payload = ['ids' => $ids];
        if ($platformIssue) {
            $payload['platform_issue'] = '1';
        }

        return $this->actingAs($this->admin())
            ->from(route('feedback.index'))
            ->post(route('feedback.forward'), $payload);
    }

    /** Admin sets school status + note through the real update route. */
    protected function triageAsAdmin(FeedbackItem $item, string $status, string $note)
    {
        $this->actingAs($this->admin())
            ->patch(route('feedback.update', $item->id), [
                'status' => $status,
                'admin_note' => $note,
            ])->assertRedirect();
    }

    /** SA sets handling status (+ optional platform note) via saUpdate. */
    protected function saAct(FeedbackItem $item, string $saStatus, ?string $reply = null)
    {
        $payload = ['sa_status' => $saStatus];
        if ($reply !== null) {
            $payload['sa_reply'] = $reply;
        }

        return $this->actingAs($this->sa())
            ->from(route('feedback.sa_show', $item->id))
            ->patch(route('feedback.sa_update', $item->id), $payload);
    }

    /** The SA sidebar badge number, parsed from SA dashboard HTML (round-27 shape). */
    protected function saSidebarBadge()
    {
        $html = $this->actingAs($this->sa())->get(route('dashboard'))->assertOk()->getContent();
        if (preg_match('/School Feedback<\/span>\s*<span class="badge badge-danger ml-auto">(\d+)<\/span>/', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    protected function receivedCount()
    {
        return FeedbackItem::forwarded()->where('sa_status', 'received')->count();
    }

    /** App-bug item, forwarded, acknowledged by Admin with a note. */
    protected function acknowledgedBug(string $title)
    {
        $item = $this->submitAs($this->teacher(), $title, 'app_bug');
        $this->forwardAsAdmin([$item->id])->assertRedirect();
        $this->assertNotNull($item->fresh()->forwarded_at);
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->triageAsAdmin($item, 'acknowledged', 'R29 school note for ' . $title);

        return $item->fresh();
    }

    public function test_teacher_submits_app_bug_and_facility_both_store_as_new()
    {
        $bug = $this->submitAs($this->teacher(), 'R29BUG submit shape', 'app_bug');
        $facility = $this->submitAs($this->teacher(), 'R29FAC submit shape', 'facility');

        $this->assertSame('new', $bug->status);
        $this->assertSame('new', $facility->status);
        $this->assertSame($this->teacher()->id, $bug->reporter_id);
        $this->assertSame($this->teacher()->id, $facility->reporter_id);
        $this->assertNull($bug->forwarded_at);
        $this->assertNull($facility->forwarded_at);
    }

    public function test_forward_sends_app_bug_only_and_skips_facility()
    {
        $bug = $this->submitAs($this->teacher(), 'R29BUG forward shape', 'app_bug');
        $facility = $this->submitAs($this->teacher(), 'R29FAC forward shape', 'facility');

        $this->forwardAsAdmin([$bug->id, $facility->id])
            ->assertRedirect()
            ->assertSessionHas('flash_success', '1 item forwarded to the Super Admin. 1 skipped (not a platform issue — the school handles those).');

        $this->assertNotNull($bug->fresh()->forwarded_at);
        $this->assertSame('received', $bug->fresh()->sa_status);
        $this->assertNull($facility->fresh()->forwarded_at);
        $this->assertNull($facility->fresh()->sa_status);

        // SA list shows only the forwarded item.
        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()
            ->assertSee('R29BUG forward shape')
            ->assertDontSee('R29FAC forward shape');
    }

    public function test_reforward_is_a_noop_and_forwarded_at_is_frozen()
    {
        $bug = $this->submitAs($this->teacher(), 'R29BUG reforward shape', 'app_bug');
        $this->forwardAsAdmin([$bug->id])->assertRedirect();
        $stamped = $bug->fresh()->forwarded_at;
        $this->assertNotNull($stamped);

        $this->forwardAsAdmin([$bug->id])
            ->assertRedirect()
            ->assertSessionHas('flash_success', '0 items forwarded to the Super Admin. 1 skipped (duplicate or already forwarded).');

        $this->assertTrue($bug->fresh()->forwarded_at->equalTo($stamped));
        $this->assertSame('received', $bug->fresh()->sa_status);
    }

    public function test_admin_acknowledge_and_response_visible_to_reporter()
    {
        $item = $this->acknowledgedBug('R29BUG ack shape');

        $this->assertSame('acknowledged', $item->status);
        $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id))
            ->assertOk()
            ->assertSee('Acknowledged')
            ->assertSee('R29 school note for R29BUG ack shape')
            ->assertDontSee('Super Admin');
    }

    public function test_facility_forwarded_only_via_platform_issue_attestation()
    {
        $facility = $this->submitAs($this->teacher(), 'R29FAC attest shape', 'facility');

        // Without attestation the school-side item stays home.
        $this->forwardAsAdmin([$facility->id])->assertRedirect();
        $this->assertNull($facility->fresh()->forwarded_at);
        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertDontSee('R29FAC attest shape');

        // With the Admin's platform_issue attestation it forwards.
        $this->forwardAsAdmin([$facility->id], true)->assertRedirect();
        $this->assertNotNull($facility->fresh()->forwarded_at);
        $this->assertSame('received', $facility->fresh()->sa_status);
        $this->actingAs($this->sa())->get(route('feedback.sa_index'))
            ->assertOk()->assertSee('R29FAC attest shape');
    }

    public function test_sa_in_progress_leaves_school_status_unchanged_and_hides_reply()
    {
        $item = $this->acknowledgedBug('R29BUG sa progress shape');

        $this->saAct($item, 'in_progress', 'R29 SA SECRET WORDS')->assertRedirect();
        $item = $item->fresh();
        $this->assertSame('in_progress', $item->sa_status);
        $this->assertSame('acknowledged', $item->status);

        $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id))
            ->assertOk()
            ->assertSee('Acknowledged')
            ->assertSee('R29 school note for R29BUG sa progress shape')
            ->assertDontSee('Super Admin')
            ->assertDontSee('R29 SA SECRET WORDS');
    }

    public function test_sa_fix_auto_resolves_and_reporter_still_sees_no_sa_content()
    {
        $item = $this->acknowledgedBug('R29BUG sa fix shape');
        $this->saAct($item, 'in_progress', 'R29 SA SECRET WORDS')->assertRedirect();
        $this->saAct($item, 'fixed')->assertRedirect();

        $item = $item->fresh();
        $this->assertSame('fixed', $item->sa_status);
        $this->assertSame('resolved', $item->status);

        // Detail page: resolved + school note, no SA trace.
        $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id))
            ->assertOk()
            ->assertSee('Resolved')
            ->assertSee('R29 school note for R29BUG sa fix shape')
            ->assertDontSee('Super Admin')
            ->assertDontSee('R29 SA SECRET WORDS');

        // List page: same privacy (the redesign shape, re-proven live).
        $this->actingAs($this->teacher())->get(route('feedback.mine'))
            ->assertOk()
            ->assertDontSee('Super Admin')
            ->assertDontSee('R29 SA SECRET WORDS');
    }

    public function test_sa_terminal_close_select_disabled_and_change_refused()
    {
        $item = $this->acknowledgedBug('R29BUG terminal shape');
        $this->saAct($item, 'fixed')->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('resolved', $item->fresh()->status);

        $html = $this->actingAs($this->sa())->get(route('feedback.sa_show', $item->id))
            ->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<select name="sa_status"[^>]*disabled/', $html);

        // Status change AWAY from Fixed is refused; statuses intact.
        $this->saAct($item, 'in_progress')
            ->assertRedirect()
            ->assertSessionHas('pop_error');
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('resolved', $item->fresh()->status);

        // Reply-only save on a closed report still goes through (blade
        // supports it via a hidden field) — statuses untouched.
        $this->saAct($item, 'fixed', 'R29 closed-note')->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('resolved', $item->fresh()->status);
        $this->assertSame('R29 closed-note', $item->fresh()->sa_reply);
    }

    public function test_admin_reopen_reinflates_badge_and_sa_can_act_again()
    {
        $item = $this->acknowledgedBug('R29BUG reopen shape');
        $this->saAct($item, 'fixed')->assertRedirect();

        $baseline = $this->receivedCount();
        // Badge renders only when the count is > 0 (round-27 shape).
        $this->assertSame($baseline > 0 ? $baseline : null, $this->saSidebarBadge());

        $this->actingAs($this->admin())->post(route('feedback.reopen', $item->id))
            ->assertRedirect();
        $item = $item->fresh();
        $this->assertSame('received', $item->sa_status);
        $this->assertSame('in_progress', $item->status);
        $this->assertSame($baseline + 1, $this->receivedCount());
        $this->assertSame($baseline + 1, $this->saSidebarBadge());

        // SA acts again on the re-escalated item, then re-closes it.
        $this->saAct($item, 'in_progress', 'R29 second look')->assertRedirect();
        $this->assertSame('in_progress', $item->fresh()->sa_status);
        $this->saAct($item, 'fixed')->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('resolved', $item->fresh()->status);
    }

    public function test_reporter_forbidden_from_sa_and_admin_actions()
    {
        $item = $this->acknowledgedBug('R29BUG negative shape');

        $this->actingAs($this->teacher())
            ->patch(route('feedback.sa_update', $item->id), ['sa_status' => 'fixed'])
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->post(route('feedback.reopen', $item->id))
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->post(route('feedback.sa_bulk_close'), ['ids' => [$item->id]])
            ->assertForbidden();

        // Nothing changed under the refused attempts.
        $this->assertSame('received', $item->fresh()->sa_status);
        $this->assertSame('acknowledged', $item->fresh()->status);
    }

    public function test_student_reporter_sees_only_own_items()
    {
        $own = $this->submitAs($this->student(), 'R29STU own shape', 'facility');
        $foreign = $this->submitAs($this->teacher(), 'R29BUG foreign shape', 'app_bug');

        $this->actingAs($this->student())->get(route('feedback.mine'))
            ->assertOk()
            ->assertSee('R29STU own shape')
            ->assertDontSee('R29BUG foreign shape');

        $this->actingAs($this->student())->get(route('feedback.mine.show', $own->id))->assertOk();
        $this->actingAs($this->student())->get(route('feedback.mine.show', $foreign->id))->assertForbidden();
    }

    public function test_sa_bulk_close_resolves_both_school_statuses()
    {
        $bug = $this->acknowledgedBug('R29BUG bulk shape');
        $facility = $this->submitAs($this->teacher(), 'R29FAC bulk shape', 'facility');
        $this->forwardAsAdmin([$facility->id], true)->assertRedirect();
        $this->assertSame('received', $facility->fresh()->sa_status);

        $this->actingAs($this->sa())
            ->from(route('feedback.sa_index'))
            ->post(route('feedback.sa_bulk_close'), ['ids' => [$bug->id, $facility->id]])
            ->assertRedirect()
            ->assertSessionHas('flash_success', '2 report(s) closed (marked Fixed).');

        $this->assertSame('fixed', $bug->fresh()->sa_status);
        $this->assertSame('resolved', $bug->fresh()->status);
        $this->assertSame('fixed', $facility->fresh()->sa_status);
        $this->assertSame('resolved', $facility->fresh()->status);
    }

    public function test_admin_detail_shows_sa_reply_by_design_reporter_never_does()
    {
        $item = $this->acknowledgedBug('R29BUG asymmetry shape');
        $this->saAct($item, 'in_progress', 'R29 SA SECRET WORDS')->assertRedirect();

        // Admin detail: SA reply line is present by design (SA +
        // school Admin only).
        $this->actingAs($this->admin())->get(route('feedback.show', $item->id))
            ->assertOk()
            ->assertSee('SA reply:')
            ->assertSee('R29 SA SECRET WORDS');

        // Reporter detail: same item, no SA trace at all.
        $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id))
            ->assertOk()
            ->assertDontSee('SA reply:')
            ->assertDontSee('R29 SA SECRET WORDS')
            ->assertDontSee('Super Admin');
    }
}
