<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner redesign 2026-10-28: (1) teachers and students never see
 * Super Admin content — no SA status, no SA words, anywhere on
 * reporter views; (2) only platform issues (app glitches, UI/UX
 * bugs and the like) go to the Super Admin — everything else is
 * sorted by the school alone; (3) an SA fix auto-resolves the
 * school side, so the reporter reads "Resolved" with no SA trace.
 */
class OwnerFeedbackRedesignTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function makeItem(array $overrides = [])
    {
        return FeedbackItem::create(array_merge([
            'reporter_id' => $this->teacher()->id,
            'reporter_name' => 'Ananya Sharma',
            'reporter_role' => 'teacher',
            'type' => 'problem',
            'category' => 'facility',
            'priority' => 'medium',
            'title' => 'Redesign probe report',
            'description' => 'The cooler dispenses warm water.',
            'status' => 'in_progress',
        ], $overrides));
    }

    protected function makeForwarded(array $overrides = [])
    {
        return $this->makeItem(array_merge([
            'forwarded_at' => now(),
            'forwarded_by' => $this->admin()->id,
            'sa_status' => 'received',
        ], $overrides));
    }

    public function test_reporter_detail_hides_all_super_admin_content()
    {
        $item = $this->makeForwarded([
            'sa_status' => 'fixed',
            'sa_reply' => 'SA SECRET WORDS',
            'admin_note' => 'School note here',
        ]);

        $response = $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id));
        $response->assertOk();
        $response->assertDontSee('Super Admin');
        $response->assertDontSee('SA SECRET WORDS');
        $response->assertDontSee('SA:');
        $response->assertSee('School note here');
        $response->assertSee('In Progress');
    }

    public function test_reporter_list_hides_all_super_admin_content()
    {
        $item = $this->makeForwarded([
            'sa_status' => 'fixed',
            'sa_reply' => 'SA SECRET WORDS',
            'admin_note' => 'School note here',
        ]);

        $response = $this->actingAs($this->teacher())->get(route('feedback.mine'));
        $response->assertOk();
        $response->assertDontSee('Super Admin');
        $response->assertDontSee('SA SECRET WORDS');
        $response->assertDontSee('SA:');
    }

    public function test_effective_status_is_school_side_only()
    {
        $item = $this->makeForwarded(['sa_status' => 'fixed']);
        $this->assertSame('In Progress', $item->effectiveStatusLabel());
    }

    public function test_forward_refuses_school_issues_but_allows_platform_issues()
    {
        $school = $this->makeItem(['category' => 'facility']);
        $platform = $this->makeItem(['category' => 'app_bug', 'title' => 'App probe']);

        $this->actingAs($this->admin())->post(route('feedback.forward'), [
            'ids' => [$school->id, $platform->id],
        ])->assertRedirect();

        $this->assertNull($school->fresh()->forwarded_at);
        $this->assertNotNull($platform->fresh()->forwarded_at);
    }

    public function test_forward_attested_platform_issue_goes_up()
    {
        $school = $this->makeItem(['category' => 'facility']);

        $this->actingAs($this->admin())->post(route('feedback.forward'), [
            'id' => $school->id,
            'platform_issue' => 1,
        ])->assertRedirect();

        $this->assertNotNull($school->fresh()->forwarded_at);
    }

    public function test_sa_fix_auto_resolves_school_side()
    {
        $item = $this->makeForwarded(['sa_status' => 'in_progress']);

        $this->actingAs($this->superAdmin())->patch(route('feedback.sa_update', $item->id), [
            'sa_status' => 'fixed',
            'sa_reply' => 'SA SECRET WORDS',
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('fixed', $fresh->sa_status);
        $this->assertSame('resolved', $fresh->status);

        $response = $this->actingAs($this->teacher())->get(route('feedback.mine.show', $item->id));
        $response->assertOk();
        $response->assertSee('Resolved');
        $response->assertDontSee('Super Admin');
        $response->assertDontSee('SA SECRET WORDS');
    }

    public function test_sa_bulk_close_auto_resolves_school_side()
    {
        $item = $this->makeForwarded(['sa_status' => 'received']);

        $this->actingAs($this->superAdmin())->post(route('feedback.sa_bulk_close'), [
            'ids' => [$item->id],
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('fixed', $fresh->sa_status);
        $this->assertSame('resolved', $fresh->status);
    }

    public function test_reopen_returns_school_side_to_in_progress()
    {
        $item = $this->makeForwarded(['sa_status' => 'fixed', 'status' => 'resolved']);

        $this->actingAs($this->admin())->post(route('feedback.reopen', $item->id))
            ->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('received', $fresh->sa_status);
        $this->assertSame('in_progress', $fresh->status);
    }
}
