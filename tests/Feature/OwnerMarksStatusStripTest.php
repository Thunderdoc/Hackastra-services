<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MarkSubmission;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Item 11 (owner, 2026-11-01): the marks entry page's two tall
 * banners collapse into ONE slim status strip — publication pill,
 * submission pill, the single "Saving updates the live result."
 * line (published + draft only) and the Submit Marks button at
 * the right end, wired exactly as before. All four state
 * combinations are pinned; behaviour (submit lock, admin reopen,
 * post-publish editing) stays covered by OwnerMarksLifecycleTest.
 *
 * Unit: Term 1 / Class 12 / section 76 / subject 79 (the shared
 * lifecycle anchor). NOTE: the banners live in manage.blade.php —
 * the entry page the selector posts to.
 */
class OwnerMarksStatusStripTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function openPage(User $as): string
    {
        $this->actingAs($this->user('admin@hackstra.app'))->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
        ]);
        return $this->actingAs($as)->get(route('marks.manage', [1, 13, 76, 79]))
            ->assertOk()->getContent();
    }

    private function publish(): void
    {
        Exam::where('id', 1)->update(['results_published' => 1]);
    }

    private function submit(): void
    {
        MarkSubmission::create([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
            'submitted_by' => $this->user('teacher@hackstra.app')->id,
        ]);
    }

    public function test_draft_unpublished_strip()
    {
        $html = $this->openPage($this->user('teacher@hackstra.app'));
        $this->assertStringContainsString('l11-strip', $html);
        $this->assertStringContainsString('Results: Not published', $html);
        $this->assertStringContainsString('Your marks: Draft', $html);
        $this->assertStringNotContainsString('Saving updates the live result.', $html);
        $this->assertStringContainsString('Submit Marks', $html);
        $this->assertStringNotContainsString('Results published.', $html);
        $this->assertStringNotContainsString('Draft — not yet submitted', $html);
    }

    public function test_draft_published_strip_carries_the_live_result_line()
    {
        $this->publish();
        $html = $this->openPage($this->user('teacher@hackstra.app'));
        $this->assertStringContainsString('Results: Published', $html);
        $this->assertStringContainsString('Your marks: Draft', $html);
        $this->assertStringContainsString('Saving updates the live result.', $html);
        $this->assertStringContainsString('Submit Marks', $html);
    }

    public function test_submitted_unpublished_strip()
    {
        $this->submit();
        $html = $this->openPage($this->user('teacher@hackstra.app'));
        $this->assertStringContainsString('Results: Not published', $html);
        $this->assertStringContainsString('Submitted — Locked', $html);
        $this->assertStringNotContainsString('Saving updates the live result.', $html);
        $this->assertStringNotContainsString('Submit Marks', $html);
        $this->assertStringNotContainsString('Reopen for correction', $html,
            'reopen is the admin\'s action only');
    }

    public function test_submitted_published_strip_and_admin_reopen()
    {
        $this->publish();
        $this->submit();
        $html = $this->openPage($this->user('admin@hackstra.app'));
        $this->assertStringContainsString('Results: Published', $html);
        $this->assertStringContainsString('Submitted — Locked', $html);
        $this->assertStringNotContainsString('Saving updates the live result.', $html);
        $this->assertStringNotContainsString('Submit Marks', $html);
        $this->assertStringContainsString('Reopen for correction', $html);
    }

    public function test_not_yet_open_strip_keeps_the_opening_date()
    {
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => Carbon::today()->addDays(3)->format('Y-m-d'), 'start_time' => '09:00',
        ]);
        $html = $this->openPage($this->user('teacher@hackstra.app'));
        $this->assertStringContainsString('Your marks: Draft', $html);
        $this->assertStringContainsString('Entry opens', $html);
        $this->assertStringNotContainsString('Submit Marks', $html);
    }
}
