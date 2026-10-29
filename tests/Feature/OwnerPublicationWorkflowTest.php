<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Result publication workflow (owner order 2026-10-29).
 *
 * The Admin prepares: the publication page lists every teacher
 * whose (section, subject) marks are still unsubmitted, the Admin
 * reminds them (bell, "within 2 days"), and only when ALL units
 * are submitted can the Admin send a publication request. The
 * Principal alone publishes — the request waits in the Command
 * Center's Approval Center.
 */
class OwnerPublicationWorkflowTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    /** A class-scoped exam with exactly one marks unit: Class 12-E English. */
    protected function examWithOneUnit(): Exam
    {
        $id = DB::table('exams')->insertGetId([
            'name' => 'Publication Probe Exam',
            'term' => 1,
            'year' => '2026-2027',
            'my_class_id' => 13,
            'results_published' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => $id,
            'my_class_id' => 13,
            'section_id' => 68,
            'subject_id' => 79,
        ]);
        return Exam::findOrFail($id);
    }

    public function test_full_request_then_principal_publishes_flow()
    {
        $exam = $this->examWithOneUnit();
        $admin = $this->admin();
        $teacher = $this->teacher();
        $principal = $this->principal();

        // The Admin sees exactly who has not submitted.
        $page = $this->actingAs($admin)->get(route('exams.publication', $exam->id));
        $page->assertOk();
        $page->assertSee('Still waiting on these teachers');
        $page->assertSee('Ananya Sharma');
        $page->assertSee('0 of 1');

        // The request is refused while marks are pending.
        $this->actingAs($admin)->post(route('exams.request_publication', $exam->id));
        $this->assertNull(Exam::find($exam->id)->publish_requested_at);

        // The Admin reminds the teacher — a bell lands for her.
        $this->actingAs($admin)->post(route('exams.notify_pending', $exam->id), [
            'section_id' => 68,
            'subject_id' => 79,
        ])->assertRedirect();
        $this->assertTrue(UserNotification::where('user_id', $teacher->id)
            ->where('type', 'marks_pending')->exists());

        // The teacher submits; the Admin can now send the request.
        $this->actingAs($teacher)->post(route('marks.submit', [$exam->id, 13, 68, 79]))
            ->assertRedirect();
        $page = $this->actingAs($admin)->get(route('exams.publication', $exam->id));
        $page->assertSee('All marks are submitted');
        $this->actingAs($admin)->post(route('exams.request_publication', $exam->id))
            ->assertRedirect();
        $exam = Exam::find($exam->id);
        $this->assertNotNull($exam->publish_requested_at);
        $this->assertTrue(UserNotification::where('user_id', $principal->id)
            ->where('type', 'publication_requested')->exists());

        // The request waits in the Principal's Approval Center.
        $dash = $this->actingAs($principal)->get(route('dashboard'));
        $dash->assertOk();
        $dash->assertSee('Publication Probe Exam');
        $dash->assertSee('Review & publish');

        // The Admin cannot publish — the Principal alone publishes.
        $this->actingAs($admin)->post(route('exams.publish_results', $exam->id))
            ->assertForbidden();
        $this->assertSame(0, (int) Exam::find($exam->id)->results_published);

        $this->actingAs($principal)->post(route('exams.publish_results', $exam->id))
            ->assertRedirect();
        $exam = Exam::find($exam->id);
        $this->assertSame(1, (int) $exam->results_published);
        $this->assertNull($exam->publish_requested_at);

        // Unpublish is likewise the Principal's alone.
        $this->actingAs($admin)->post(route('exams.unpublish_results', $exam->id))
            ->assertForbidden();
        $this->actingAs($principal)->post(route('exams.unpublish_results', $exam->id))
            ->assertRedirect();
        $this->assertSame(0, (int) Exam::find($exam->id)->results_published);
    }

    public function test_teacher_cannot_open_the_publication_page()
    {
        $exam = $this->examWithOneUnit();
        $this->actingAs($this->teacher())->get(route('exams.publication', $exam->id))
            ->assertRedirect();
    }

    public function test_request_refused_when_no_marks_exist()
    {
        $id = DB::table('exams')->insertGetId([
            'name' => 'Empty Probe Exam',
            'term' => 2,
            'year' => '2026-2027',
            'my_class_id' => 13,
            'results_published' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $page = $this->actingAs($this->admin())->get(route('exams.publication', $id));
        $page->assertOk();
        $page->assertSee('No marks entered yet');
        $this->actingAs($this->admin())->post(route('exams.request_publication', $id));
        $this->assertNull(Exam::find($id)->publish_requested_at);
    }
}
