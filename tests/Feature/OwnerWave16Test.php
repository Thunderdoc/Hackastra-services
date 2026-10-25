<?php

namespace Tests\Feature;

use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 16 (ticket
 * close/reopen): a Super Admin-closed (Fixed) report cannot be
 * reopened by the SA — not via the detail form, not via bulk
 * close — while the school Admin can reopen it, which puts it
 * back to Received on the SA board (re-escalation).
 */
class OwnerWave16Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function makeItem($saStatus, $forwarded = true)
    {
        return FeedbackItem::create([
            'reporter_id' => $this->admin()->id,
            'reporter_name' => 'Test Reporter',
            'reporter_role' => 'parent',
            'type' => 'problem',
            'category' => 'facility',
            'priority' => 'high',
            'title' => 'Wave 16 test report',
            'description' => 'The cooler is not cooling.',
            'status' => 'in_progress',
            'forwarded_at' => $forwarded ? now() : null,
            'forwarded_by' => $forwarded ? $this->admin()->id : null,
            'sa_status' => $saStatus,
        ]);
    }

    public function test_sa_cannot_reopen_a_fixed_report()
    {
        $item = $this->makeItem('fixed');
        $this->actingAs($this->superAdmin());
        $this->patch(route('feedback.sa_update', $item->id), [
            'sa_status' => 'received',
            'sa_reply' => 'Trying to reopen.',
        ])->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertNull($item->fresh()->sa_reply);
    }

    public function test_sa_can_still_reply_on_a_fixed_report()
    {
        $item = $this->makeItem('fixed');
        $this->actingAs($this->superAdmin());
        $this->patch(route('feedback.sa_update', $item->id), [
            'sa_status' => 'fixed',
            'sa_reply' => 'Follow-up: part replaced.',
        ])->assertRedirect();
        $this->assertSame('fixed', $item->fresh()->sa_status);
        $this->assertSame('Follow-up: part replaced.', $item->fresh()->sa_reply);
    }

    public function test_sa_detail_page_disables_status_on_fixed_report()
    {
        $item = $this->makeItem('fixed');
        $this->actingAs($this->superAdmin());
        $this->get(route('feedback.sa_show', $item->id))->assertOk()
            ->assertSee('cannot reopen this report', false);
    }

    public function test_bulk_close_skips_already_fixed_reports()
    {
        $fixed = $this->makeItem('fixed');
        $open = $this->makeItem('received');
        $this->actingAs($this->superAdmin());
        $this->post(route('feedback.sa_bulk_close'), ['ids' => [$fixed->id, $open->id]])
            ->assertRedirect()
            ->assertSessionHas('flash_success', '1 report(s) closed (marked Fixed).');
        $this->assertSame('fixed', $open->fresh()->sa_status);
        $this->assertSame('fixed', $fixed->fresh()->sa_status);
    }

    public function test_bulk_close_reports_all_already_closed()
    {
        $fixed = $this->makeItem('fixed');
        $this->actingAs($this->superAdmin());
        $this->post(route('feedback.sa_bulk_close'), ['ids' => [$fixed->id]])
            ->assertRedirect()
            ->assertSessionHas('flash_info');
    }

    public function test_sa_list_hides_close_for_fixed_reports()
    {
        $fixed = $this->makeItem('fixed');
        $this->actingAs($this->superAdmin());
        $this->get(route('feedback.sa_index'))->assertOk()
            ->assertDontSee('<input type="hidden" name="ids[]" value="' . $fixed->id . '">', false)
            ->assertSee('Closed', false);
    }

    public function test_school_admin_reopens_a_fixed_report()
    {
        $item = $this->makeItem('fixed');
        $this->actingAs($this->admin());
        $this->post(route('feedback.reopen', $item->id))
            ->assertRedirect()
            ->assertSessionHas('flash_success');
        $this->assertSame('received', $item->fresh()->sa_status);
    }

    public function test_school_admin_cannot_reopen_a_non_fixed_report()
    {
        $item = $this->makeItem('received');
        $this->actingAs($this->admin());
        $this->post(route('feedback.reopen', $item->id))->assertRedirect();
        $this->assertSame('received', $item->fresh()->sa_status);
    }

    public function test_reopen_refuses_non_forwarded_and_non_admin()
    {
        $item = $this->makeItem('fixed', false);
        $this->actingAs($this->admin());
        $this->post(route('feedback.reopen', $item->id))->assertNotFound();

        $forwarded = $this->makeItem('fixed');
        $this->actingAs($this->teacher());
        $this->post(route('feedback.reopen', $forwarded->id))->assertForbidden();
        $this->assertSame('fixed', $forwarded->fresh()->sa_status);
    }

    public function test_school_show_page_offers_reopen_on_fixed_reports()
    {
        $item = $this->makeItem('fixed');
        $this->actingAs($this->admin());
        $this->get(route('feedback.show', $item->id))->assertOk()
            ->assertSee('Reopen — send back to Super Admin', false);
    }
}
