<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\MarkComponent;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\StudentRecord;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Quiz v2 (2026-10-28): bulk create box, draft → review → publish
 * with timings, and explicit Use-for-Internal push.
 *
 * Users are resolved by EMAIL, never hardcoded ids. The Internal
 * component is found with the same matching rule as the assignment
 * bridge (configured component named Internal / short INT), using
 * the ARRAY shape from MarkScheme::components() — never the models
 * from configuredComponents().
 */
class OwnerQuizV2Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher21@teacher.com')->first();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->first();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function studentRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->first();
    }

    /** Published, open, whole-class Prep English quiz (subject 1). */
    protected function makePublishedPrepQuiz(array $overrides = [])
    {
        $quiz = Quiz::create(array_merge([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'Prep Quiz V2',
            'time_limit_minutes' => 10,
            'status' => 'published',
        ], $overrides));
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is 2 + 2?',
            'options' => ['4', '5', '6', '7'],
            'correct_index' => 0,
            'marks' => 2,
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Pick the vowel.',
            'options' => ['B', 'A', 'C', 'D'],
            'correct_index' => 1,
            'marks' => 3,
        ]);
        return $quiz->fresh();
    }

    protected function attemptPrepQuiz(Quiz $quiz)
    {
        $questions = $quiz->questions()->orderBy('id')->get();
        $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$questions[0]->id => 0, $questions[1]->id => 0],
        ]);
        return QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $this->studentRecord()->id)->first();
    }

    /** The configured Internal component array for an exam+subject, or null. */
    protected function internalComponent($examId, $subjectId)
    {
        foreach (MarkScheme::components($examId, $subjectId) as $component) {
            if ($component['field'] !== null) {
                continue;
            }
            if ($component['name'] === 'Internal'
                || $component['name'] === 'Internal Assessment'
                || $component['short_code'] === 'INT'
                || stripos($component['name'], 'Internal') === 0) {
                return $component;
            }
        }
        return null;
    }

    public function test_bulk_create_parses_letter_and_number_answers()
    {
        $bulk = "What is 2 + 2?\nA) 3\nB) 4\nC) 5\nAnswer: B\n\nPick the vowel.\nA) B\nB) A\nC) C\nAnswer: 2";
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Bulk Quiz',
            'subject_id' => 1,
            'bulk_text' => $bulk,
            'bulk_marks' => 2,
        ]);
        $quiz = Quiz::where('title', 'Bulk Quiz')->first();
        $this->assertNotNull($quiz);
        $response->assertRedirect(route('quizzes.review', $quiz->id));
        $this->assertEquals('draft', $quiz->status);
        $questions = $quiz->questions()->orderBy('id')->get();
        $this->assertCount(2, $questions);
        $this->assertEquals(['3', '4', '5'], $questions[0]->options);
        $this->assertEquals(1, $questions[0]->correct_index);
        $this->assertEquals(2, $questions[0]->marks);
        $this->assertEquals(['B', 'A', 'C'], $questions[1]->options);
        $this->assertEquals(1, $questions[1]->correct_index);
        $this->assertEquals(2, $questions[1]->marks);
    }

    public function test_bulk_create_bad_block_is_reported_and_saves_nothing()
    {
        $bulk = "Good question?\nA) Yes\nB) No\nAnswer: A\n\nBad question?\nA) Yes\nB) No";
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Bad Bulk Quiz',
            'subject_id' => 1,
            'bulk_text' => $bulk,
        ]);
        $response->assertSessionHasErrors('bulk_text');
        $this->assertDatabaseMissing('quizzes', ['title' => 'Bad Bulk Quiz']);
        $errors = session('errors')->getBag('default')->get('bulk_text');
        $this->assertStringContainsString('Block 2', implode(' ', $errors));
    }

    public function test_bulk_and_inline_together_is_rejected()
    {
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Mixed Quiz',
            'subject_id' => 1,
            'bulk_text' => "Q?\nA) Yes\nB) No\nAnswer: A",
            'questions' => [[
                'question' => 'Inline?',
                'options' => ['Yes', 'No', '', ''],
                'correct_index' => 0,
                'marks' => 1,
            ]],
        ]);
        $response->assertSessionHasErrors('bulk_text');
        $this->assertDatabaseMissing('quizzes', ['title' => 'Mixed Quiz']);
    }

    public function test_draft_is_hidden_from_students_until_published()
    {
        $quiz = $this->makePublishedPrepQuiz(['status' => 'draft', 'title' => 'Draft Quiz']);
        $response = $this->actingAs($this->student())->get(route('quizzes.index'));
        $response->assertOk();
        $response->assertDontSee('Draft Quiz');
        $response = $this->actingAs($this->student())->get(route('quizzes.show', $quiz->id));
        $response->assertForbidden();
        $response = $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $response->assertRedirect(route('quizzes.index'));
    }

    public function test_review_edit_publish_and_window_gating()
    {
        // Create via the form (inline) → draft → review.
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Review Flow Quiz',
            'subject_id' => 1,
            'questions' => [[
                'question' => 'Original?',
                'options' => ['Yes', 'No', '', ''],
                'correct_index' => 0,
                'marks' => 1,
            ]],
        ]);
        $quiz = Quiz::where('title', 'Review Flow Quiz')->firstOrFail();
        $response->assertRedirect(route('quizzes.review', $quiz->id));
        $this->assertEquals('draft', $quiz->status);

        $response = $this->actingAs($this->teacher())->get(route('quizzes.review', $quiz->id));
        $response->assertOk();
        $response->assertSee('Original?');

        $question = $quiz->questions()->first();
        $response = $this->actingAs($this->teacher())->put(
            route('quizzes.questions.update', [$quiz->id, $question->id]),
            ['question' => 'Edited?', 'options' => ['Yes', 'No'], 'correct_index' => 1, 'marks' => 2]
        );
        $response->assertRedirect();
        $this->assertEquals('Edited?', $question->fresh()->question);
        $this->assertEquals(1, $question->fresh()->correct_index);

        // Bad window: end before start.
        $response = $this->actingAs($this->teacher())->post(route('quizzes.publish', $quiz->id), [
            'available_from' => '2026-10-30 10:00',
            'available_until' => '2026-10-29 10:00',
        ]);
        $response->assertSessionHasErrors('available_until');
        $this->assertEquals('draft', $quiz->fresh()->status);

        // Publish open-ended.
        $response = $this->actingAs($this->teacher())->post(route('quizzes.publish', $quiz->id), [
            'time_limit_minutes' => 15,
        ]);
        $response->assertRedirect();
        $this->assertEquals('published', $quiz->fresh()->status);

        // Student can now attempt.
        $response = $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $response->assertOk();
    }

    public function test_expired_window_hides_quiz_but_keeps_own_result()
    {
        $quiz = $this->makePublishedPrepQuiz([
            'title' => 'Expired Quiz',
            'available_until' => now()->subDay(),
        ]);
        $response = $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $response->assertRedirect(route('quizzes.index'));
        $response = $this->actingAs($this->student())->get(route('quizzes.index'));
        $response->assertDontSee('Expired Quiz');

        // Attempted while open, then expired: the student keeps
        // their own result on the show page.
        $open = $this->makePublishedPrepQuiz(['title' => 'Kept Result Quiz']);
        $this->attemptPrepQuiz($open);
        $open->update(['available_until' => now()->subDay()]);
        $response = $this->actingAs($this->student())->get(route('quizzes.show', $open->id));
        $response->assertOk();
        $response->assertSee('Your score: 2 / 5');
    }

    public function test_unpublish_blocked_with_attempts_allowed_without()
    {
        $quiz = $this->makePublishedPrepQuiz(['title' => 'Unpub Quiz']);
        $response = $this->actingAs($this->teacher())->post(route('quizzes.unpublish', $quiz->id));
        $response->assertRedirect();
        $this->assertEquals('draft', $quiz->fresh()->status);

        $quiz2 = $this->makePublishedPrepQuiz(['title' => 'Unpub Quiz 2']);
        $this->attemptPrepQuiz($quiz2);
        $response = $this->actingAs($this->teacher())->post(route('quizzes.unpublish', $quiz2->id));
        $response->assertSessionHas('flash_danger');
        $this->assertEquals('published', $quiz2->fresh()->status);
    }

    public function test_question_edit_locked_after_attempt_and_foreign_teacher_forbidden()
    {
        $quiz = $this->makePublishedPrepQuiz();
        $this->attemptPrepQuiz($quiz);
        $question = $quiz->questions()->first();
        $response = $this->actingAs($this->teacher())->put(
            route('quizzes.questions.update', [$quiz->id, $question->id]),
            ['question' => 'Hacked?', 'options' => ['Yes', 'No'], 'correct_index' => 0, 'marks' => 1]
        );
        $response->assertSessionHas('flash_danger');
        $this->assertNotEquals('Hacked?', $question->fresh()->question);

        $response = $this->actingAs($this->otherTeacher())->get(route('quizzes.review', $quiz->id));
        $response->assertForbidden();
        $response = $this->actingAs($this->otherTeacher())->post(route('quizzes.publish', $quiz->id), []);
        $response->assertForbidden();
    }

    public function test_admin_review_is_read_only()
    {
        $quiz = $this->makePublishedPrepQuiz();
        $response = $this->actingAs($this->admin())->get(route('quizzes.review', $quiz->id));
        $response->assertOk();
        $response->assertDontSee('Save question');
        $response->assertDontSee('Publish quiz');
    }

    public function test_push_to_internal_scales_and_is_idempotent()
    {
        $exam = Exam::find(1);
        $exam->update(['results_published' => false]);
        $component = $this->internalComponent(1, 1);
        $this->assertNotNull($component, 'Seed must carry a configured Internal component for exam 1 / Prep English');

        $quiz = $this->makePublishedPrepQuiz();
        $attempt = $this->attemptPrepQuiz($quiz);
        $this->assertEquals(2, $attempt->score);
        $this->assertEquals(5, $attempt->total);
        // 2/5 = 40% of Internal max 30 = 12.
        $expected = (int) round(40 / 100 * $component['max_marks']);

        $mark = Mark::where([
            'exam_id' => 1,
            'student_id' => $this->student()->id,
            'subject_id' => 1,
        ])->firstOrFail();

        $response = $this->actingAs($this->teacher())->post(route('quizzes.push_internal', $quiz->id), ['exam_id' => 1]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_success');
        $row = MarkComponent::where(['mark_id' => $mark->id, 'component_id' => $component['id']])->first();
        $this->assertNotNull($row);
        $this->assertEquals($expected, (int) $row->score);

        // Re-push overwrites, never duplicates.
        $response = $this->actingAs($this->teacher())->post(route('quizzes.push_internal', $quiz->id), ['exam_id' => 1]);
        $response->assertRedirect();
        $this->assertEquals(1, MarkComponent::where(['mark_id' => $mark->id, 'component_id' => $component['id']])->count());
        $this->assertEquals($expected, (int) MarkComponent::where(['mark_id' => $mark->id, 'component_id' => $component['id']])->first()->score);

        $this->assertTrue(AuditLog::where('action', 'quiz.internal_pushed')->where('subject_id', $quiz->id)->exists());
    }

    public function test_push_blocked_when_results_published()
    {
        $exam = Exam::find(1);
        $exam->update(['results_published' => true]);
        $quiz = $this->makePublishedPrepQuiz();
        $this->attemptPrepQuiz($quiz);
        $response = $this->actingAs($this->teacher())->post(route('quizzes.push_internal', $quiz->id), ['exam_id' => 1]);
        $response->assertSessionHas('flash_danger');
    }

    public function test_push_unavailable_for_legacy_scheme_says_so_plainly()
    {
        // Prep Mathematics (subject 2) under exam 1 has no configured
        // components — legacy scheme only.
        $this->assertNull($this->internalComponent(1, 2));
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 2,
            'title' => 'Legacy Quiz',
            'status' => 'published',
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Legacy Q?',
            'options' => ['Yes', 'No'],
            'correct_index' => 0,
            'marks' => 1,
        ]);
        $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$quiz->questions()->first()->id => 0],
        ]);
        $response = $this->actingAs($this->teacher())->post(route('quizzes.push_internal', $quiz->id), ['exam_id' => 1]);
        $response->assertSessionHas('flash_danger');
        $danger = session('flash_danger');
        $this->assertStringContainsString('legacy', strtolower($danger));
    }

    public function test_results_page_shows_push_form_and_availability()
    {
        $quiz = $this->makePublishedPrepQuiz();
        $response = $this->actingAs($this->teacher())->get(route('quizzes.results', $quiz->id));
        $response->assertOk();
        $response->assertSee('Use for Internal');
        $response->assertSee('Internal available');
    }

    public function test_super_admin_blocked_from_quiz_v2_routes()
    {
        $quiz = $this->makePublishedPrepQuiz();
        $response = $this->actingAs($this->superAdmin())->get(route('quizzes.review', $quiz->id));
        $response->assertRedirect();
        $response = $this->actingAs($this->superAdmin())->post(route('quizzes.publish', $quiz->id), []);
        $response->assertForbidden();
    }
}
