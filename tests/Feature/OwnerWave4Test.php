<?php

namespace Tests\Feature;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackItem;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 4: feedback
 * attachments end to end, SA bulk close from the list, teacher-home
 * left-column charts, and the payments search card fix.
 */
class OwnerWave4Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeFeedbackItem($overrides = [])
    {
        $item = new FeedbackItem();
        $item->reporter_id = $overrides['reporter_id'] ?? 4;
        $item->reporter_name = $overrides['reporter_name'] ?? 'Ananya Sharma';
        $item->reporter_role = $overrides['reporter_role'] ?? 'teacher';
        $item->type = 'problem';
        $item->category = 'other';
        $item->priority = 'medium';
        $item->title = $overrides['title'] ?? 'Probe feedback';
        $item->description = 'Probe description';
        $item->status = 'new';
        $item->forwarded_at = $overrides['forwarded_at'] ?? null;
        $item->forwarded_by = $overrides['forwarded_by'] ?? null;
        $item->sa_status = $overrides['sa_status'] ?? null;
        $item->save();

        return $item;
    }

    public function test_feedback_with_attachment_saves_attachment()
    {
        Storage::fake('public');
        $teacher = User::find(4);
        $response = $this->actingAs($teacher)->post(route('feedback.store'), [
            'type' => 'problem',
            'category' => 'other',
            'priority' => 'medium',
            'title' => 'Probe feedback attachment',
            'description' => 'Testing attachment save',
            'attachments' => [
                UploadedFile::fake()->create('shot.png', 100, 'image/png'),
            ],
        ]);
        $response->assertRedirect(route('feedback.mine'));
        $item = FeedbackItem::where('title', 'Probe feedback attachment')->first();
        $this->assertNotNull($item);
        $this->assertSame(1, FeedbackAttachment::where('feedback_id', $item->id)->count());
        $this->assertStringStartsWith('uploads/feedback/feedback_', FeedbackAttachment::where('feedback_id', $item->id)->first()->path);
    }

    public function test_sa_bulk_close_marks_forwarded_fixed()
    {
        $sa = User::where('user_type', 'super_admin')->first();
        $a = $this->makeFeedbackItem(['title' => 'Probe bulk A', 'forwarded_at' => now(), 'forwarded_by' => 2, 'sa_status' => 'received']);
        $b = $this->makeFeedbackItem(['title' => 'Probe bulk B', 'forwarded_at' => now(), 'forwarded_by' => 2, 'sa_status' => 'received']);
        $response = $this->actingAs($sa)->post(route('feedback.sa_bulk_close'), ['ids' => [$a->id, $b->id]]);
        $response->assertRedirect(route('feedback.sa_index'));
        $this->assertSame('fixed', $a->fresh()->sa_status);
        $this->assertSame('fixed', $b->fresh()->sa_status);
    }

    public function test_sa_bulk_close_ignores_unforwarded()
    {
        $sa = User::where('user_type', 'super_admin')->first();
        $raw = $this->makeFeedbackItem(['title' => 'Probe raw never forwarded']);
        $response = $this->actingAs($sa)->post(route('feedback.sa_bulk_close'), ['ids' => [$raw->id]]);
        $response->assertRedirect(route('feedback.sa_index'));
        $this->assertNull($raw->fresh()->sa_status);
    }

    public function test_sa_bulk_close_forbidden_for_teacher()
    {
        $teacher = User::find(4);
        $a = $this->makeFeedbackItem(['title' => 'Probe bulk C', 'forwarded_at' => now(), 'forwarded_by' => 2, 'sa_status' => 'received']);
        $response = $this->actingAs($teacher)->post(route('feedback.sa_bulk_close'), ['ids' => [$a->id]]);
        $response->assertForbidden();
        $this->assertSame('received', $a->fresh()->sa_status);
    }

    public function test_sa_bulk_close_empty_selection()
    {
        $sa = User::where('user_type', 'super_admin')->first();
        $response = $this->actingAs($sa)->post(route('feedback.sa_bulk_close'), ['ids' => []]);
        $response->assertRedirect(route('feedback.sa_index'));
        $response->assertSessionHas('flash_danger');
    }

    public function test_teacher_home_has_left_column_charts()
    {
        $teacher = User::find(4);
        $response = $this->actingAs($teacher)->get(route('dashboard'));
        $response->assertStatus(200);
        $response->assertSee('t10-leftcol', false);
        $response->assertSee('Attendance Overview', false);
    }

    public function test_payments_page_has_unclipped_search_card()
    {
        $admin = User::where('user_type', 'admin')->first();
        $response = $this->actingAs($admin)->get(route('payments.index'));
        $response->assertStatus(200);
        $response->assertSee('l93-search-card', false);
    }
}
