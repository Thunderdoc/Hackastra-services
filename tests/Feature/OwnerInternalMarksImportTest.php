<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ExamDate;
use App\Models\Mark;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\StudentRecord;
use App\Support\InternalMarksImporter;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Internal-marks importer (owner order 2026-10-29).
 *
 * From the marks screen the teacher fills the Internal component
 * from assignments or quizzes — average of all, best N, or
 * latest N — scaled onto the component's maximum. Students with
 * no graded work stay blank. The import is a marks write: the
 * submit lock and the exam-date gate refuse it exactly like a
 * typed save, and the older per-module bridge buttons now
 * respect the same lock.
 *
 * Fixture: Term 1 (exam 1), Class 12-E (section 68), English (79)
 * — Internal max 30, effective teacher Ananya.
 */
class OwnerInternalMarksImportTest extends TestCase
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

    protected function ensureRows()
    {
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68, 'subject_id' => 79,
        ]);
    }

    protected function internalScore($userId)
    {
        $mark = Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
            'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $userId,
        ])->first();
        $component = InternalMarksImporter::internalComponent(1, 79);
        return $mark ? MarkScheme::scoreFor($mark, $component) : null;
    }

    public function test_import_from_assignments_and_quizzes_with_modes()
    {
        $this->ensureRows();
        $teacher = $this->teacher();
        $records = StudentRecord::where('section_id', 68)->orderBy('id')->take(3)->get();
        [$sA, $sB, $sC] = [$records[0], $records[1], $records[2]];

        // Premise: the seed already carries Internal scores for this
        // unit — clear the three fixture students' Internal cells so
        // "stays blank" is testable. The import must not touch sC.
        $component = InternalMarksImporter::internalComponent(1, 79);
        foreach ($records as $rec) {
            $mark = Mark::where([
                'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
                'subject_id' => 79, 'year' => '2026-2027', 'student_id' => $rec->user_id,
            ])->first();
            if ($mark) {
                \App\Models\MarkComponent::where('mark_id', $mark->id)
                    ->where('component_id', $component['id'])->delete();
            }
        }
        $this->assertNull($this->internalScore($sC->user_id));

        $a1 = Assignment::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 13, 'subject_id' => 79,
            'title' => 'Import Probe A1', 'max_marks' => 50, 'due_at' => now()->addWeek(),
        ]);
        $a2 = Assignment::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 13, 'subject_id' => 79,
            'title' => 'Import Probe A2', 'max_marks' => 50, 'due_at' => now()->addWeek(),
        ]);
        Assignment::where('id', $a1->id)->update(['created_at' => now()->subDays(2)]);
        Assignment::where('id', $a2->id)->update(['created_at' => now()->subDay()]);
        // sA: 80% then 40%. sB: 50% once. sC: nothing.
        AssignmentSubmission::create(['assignment_id' => $a1->id, 'student_id' => $sA->user_id, 'marks_awarded' => 40, 'submitted_at' => now()]);
        AssignmentSubmission::create(['assignment_id' => $a2->id, 'student_id' => $sA->user_id, 'marks_awarded' => 20, 'submitted_at' => now()]);
        AssignmentSubmission::create(['assignment_id' => $a1->id, 'student_id' => $sB->user_id, 'marks_awarded' => 25, 'submitted_at' => now()]);

        $import = function ($payload) use ($teacher) {
            return $this->actingAs($teacher)->post(route('marks.import_internal', [1, 13, 68, 79]), $payload);
        };

        // Average of all: sA (80+40)/2 = 60% of 30 = 18; sB 50% = 15; sC blank.
        $import(['source_kind' => 'assignments', 'mode' => 'all'])->assertRedirect();
        $this->assertSame(18, $this->internalScore($sA->user_id));
        $this->assertSame(15, $this->internalScore($sB->user_id));
        $this->assertNull($this->internalScore($sC->user_id));

        // Best 1: sA takes his 80% = 24.
        $import(['source_kind' => 'assignments', 'mode' => 'best', 'avg_n' => 1])->assertRedirect();
        $this->assertSame(24, $this->internalScore($sA->user_id));

        // Latest 1: sA takes the newer assignment's 40% = 12.
        $import(['source_kind' => 'assignments', 'mode' => 'latest', 'avg_n' => 1])->assertRedirect();
        $this->assertSame(12, $this->internalScore($sA->user_id));

        // Quizzes: sA scored 30/40 = 75% → 23 (rounded).
        $quiz = Quiz::create([
            'teacher_id' => $teacher->id, 'my_class_id' => 13, 'section_id' => 68,
            'subject_id' => 79, 'title' => 'Import Probe Quiz', 'status' => 'published',
        ]);
        QuizAttempt::create([
            'quiz_id' => $quiz->id, 'student_record_id' => $sA->id,
            'score' => 30, 'total' => 40, 'submitted_at' => now(),
        ]);
        $import(['source_kind' => 'quizzes', 'quiz_ids' => [$quiz->id], 'mode' => 'all'])->assertRedirect();
        $this->assertSame(23, $this->internalScore($sA->user_id));

        // The panel is offered on the marks page.
        $page = $this->actingAs($teacher)->get(route('marks.manage', [1, 13, 68, 79]));
        $page->assertOk();
        $page->assertSee('from assignments or quizzes');

        // After submission the import is refused, and the old
        // assignment bridge button respects the same lock.
        $this->actingAs($teacher)->post(route('marks.submit', [1, 13, 68, 79]))->assertRedirect();
        $import(['source_kind' => 'assignments', 'mode' => 'all'])->assertForbidden();
        $this->actingAs($teacher)->post(route('assignments.push_internal', $a1->id), ['exam_id' => 1])
            ->assertRedirect();
        $this->assertSame(23, $this->internalScore($sA->user_id), 'bridge push must not write into a submitted unit');
    }

    public function test_import_refused_before_entry_opens()
    {
        $this->ensureRows();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => '2027-07-16',
        ]);
        $this->actingAs($this->admin())->post(route('marks.import_internal', [1, 13, 68, 79]), [
            'source_kind' => 'assignments', 'mode' => 'all',
        ])->assertForbidden();
    }
}
