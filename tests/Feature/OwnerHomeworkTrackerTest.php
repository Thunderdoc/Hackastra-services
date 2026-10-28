<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Homework tracker (Wave 1 feature 3): read-only aggregation over the
 * existing assignment tables. Counts are always computed from the
 * database inside the test, never hardcoded, so they hold against
 * the real seeded data.
 *
 * Seeded anchors probed 2026-10-28: both seeded assignments belong
 * to teacher@hackstra.app — assignment 1 (Prep, no section) has 1
 * submission and 0 graded; assignment 2 (Class 3, no section) has
 * 200 submissions and 2 graded. Graded = marks_awarded non-null OR
 * feedback non-null/non-empty (no boolean graded column exists).
 */
class OwnerHomeworkTrackerTest extends TestCase
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

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

    protected function rosterFor(Assignment $assignment)
    {
        return StudentRecord::where('my_class_id', $assignment->my_class_id)
            ->when($assignment->section_id, function ($q) use ($assignment) {
                $q->where('section_id', $assignment->section_id);
            })
            ->where('grad', 0)
            ->orderBy('id')
            ->get();
    }

    protected function countsFor(Assignment $assignment): array
    {
        $roster = $this->rosterFor($assignment);
        $submissions = AssignmentSubmission::where('assignment_id', $assignment->id)->get();
        $submittedIds = $submissions->pluck('student_id')->unique();
        $submitted = $roster->pluck('user_id')->intersect($submittedIds)->count();
        $graded = $submissions->filter(function ($submission) {
            return $submission->marks_awarded !== null
                || ($submission->feedback !== null && trim((string) $submission->feedback) !== '');
        })->count();

        return [
            'roster' => $roster->count(),
            'submitted' => $submitted,
            'pending' => $roster->count() - $submitted,
            'graded' => $graded,
        ];
    }

    public function test_teacher_index_shows_own_assignments_with_db_counts()
    {
        $response = $this->actingAs($this->teacher())
            ->get(route('homework_tracker.index'));
        $response->assertOk();

        $assignments = Assignment::where('teacher_id', $this->teacher()->id)->get();
        $this->assertNotEmpty($assignments);
        foreach ($assignments as $assignment) {
            $response->assertSee($assignment->title);
            $counts = $this->countsFor($assignment);
            $response->assertSee('Submitted: ' . $counts['submitted'] . ' of ' . $counts['roster']);
            $response->assertSee('Pending: ' . $counts['pending']);
            $response->assertSee('Graded: ' . $counts['graded']);
        }
    }

    public function test_pending_list_excludes_submitters_and_includes_pending()
    {
        $assignment = Assignment::where('teacher_id', $this->teacher()->id)
            ->where('title', 'English Language - Reading Comprehension')
            ->firstOrFail();
        $counts = $this->countsFor($assignment);
        $this->assertGreaterThan(0, $counts['submitted']);
        $this->assertGreaterThan(0, $counts['pending']);

        $roster = $this->rosterFor($assignment);
        $submittedIds = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->pluck('student_id')->unique();
        $submittedStudent = $roster->firstWhere('user_id', $submittedIds->first());
        $pendingStudent = $roster->first(function ($record) use ($submittedIds) {
            return !$submittedIds->contains($record->user_id);
        });
        $this->assertNotNull($submittedStudent);
        $this->assertNotNull($pendingStudent);

        $response = $this->actingAs($this->teacher())
            ->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]));
        $response->assertOk();
        $response->assertSee('Pending: ' . $counts['pending']);
        // Scope name checks to the detail section: the master layout's
        // activity widget also names recently admitted students, so a
        // whole-page DontSee would false-fail on layout chrome.
        $content = $response->getContent();
        $marker = 'Students still pending';
        $detail = strstr($content, $marker);
        $this->assertNotFalse($detail);
        $this->assertStringContainsString(optional($pendingStudent->user)->name, $detail);
        $this->assertStringNotContainsString(optional($submittedStudent->user)->name, $detail);
    }

    public function test_foreign_teacher_assignment_absent_from_index_and_detail_forbidden()
    {
        $foreign = Assignment::create([
            'teacher_id' => $this->otherTeacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'Foreign Tracker Probe Assignment',
            'due_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($this->teacher())
            ->get(route('homework_tracker.index'));
        $response->assertOk();
        $response->assertDontSee('Foreign Tracker Probe Assignment');

        $this->actingAs($this->teacher())
            ->get(route('homework_tracker.index', ['assignment_id' => $foreign->id]))
            ->assertForbidden();
    }

    public function test_admin_index_shows_all_assignments()
    {
        $foreign = Assignment::create([
            'teacher_id' => $this->otherTeacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'Foreign Tracker Probe Assignment',
            'due_at' => now()->addWeek(),
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('homework_tracker.index'));
        $response->assertOk();
        foreach (Assignment::all() as $assignment) {
            $response->assertSee($assignment->title);
        }
        $this->assertTrue(Assignment::where('id', $foreign->id)->exists());
    }

    public function test_assignment_with_no_submissions_shows_full_roster_pending()
    {
        $assignment = Assignment::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 13,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'No Submission Tracker Probe Assignment',
            'due_at' => now()->addWeek(),
        ]);
        $counts = $this->countsFor($assignment);
        $this->assertSame(0, $counts['submitted']);
        $this->assertSame($counts['roster'], $counts['pending']);
        $this->assertGreaterThan(0, $counts['pending']);

        $firstPending = $this->rosterFor($assignment)->first();
        $response = $this->actingAs($this->teacher())
            ->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]));
        $response->assertOk();
        $response->assertSee('Pending: ' . $counts['pending']);
        $response->assertSee(optional($firstPending->user)->name);
    }
}
