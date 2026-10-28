<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 1 feature 1 — Online test/quiz (2026-10-28).
 *
 * Users are resolved by EMAIL, never hardcoded ids. Quiz scores stay
 * separate by design: no test here touches exam/internal marks.
 */
class OwnerQuizTest extends TestCase
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

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    protected function studentRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->first();
    }

    /** A whole-class Prep quiz (subject 1 = teacher 4's Prep English), published and open. */
    protected function makePrepQuiz()
    {
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'Prep Quiz',
            'time_limit_minutes' => 10,
            'status' => 'published',
        ]);
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

    /** A Class 12 quiz — outside the demo student's class (Prep). */
    protected function makeClass12Quiz()
    {
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 13,
            'section_id' => null,
            'subject_id' => 79,
            'title' => 'Class 12 Quiz',
            'time_limit_minutes' => null,
        ]);
        QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'Class 12 question?',
            'options' => ['Yes', 'No'],
            'correct_index' => 0,
            'marks' => 1,
        ]);
        return $quiz->fresh();
    }

    public function test_teacher_creates_quiz_with_questions()
    {
        // Section 74 (Class 12 K) is one of teacher 4's section-override
        // sections for subject 79; section 65 (B) belongs to teacher 44,
        // so a section-65 create by teacher 4 must 403 — see
        // SectionTeacher::teacherIdFor.
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Class 12 K English Quiz',
            'subject_id' => 79,
            'section_id' => 74,
            'time_limit_minutes' => 30,
            'questions' => [
                [
                    'question' => 'Choose the noun.',
                    'options' => ['Run', 'Apple', 'Quickly', 'Blue'],
                    'correct_index' => 1,
                    'marks' => 2,
                ],
            ],
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('quizzes', [
            'title' => 'Class 12 K English Quiz',
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 13,
            'section_id' => 74,
            'subject_id' => 79,
        ]);
        $quiz = Quiz::where('title', 'Class 12 K English Quiz')->first();
        $this->assertEquals(1, $quiz->questions()->count());
        $this->assertEquals('Apple', $quiz->questions()->first()->options[1]);
        $response->assertRedirect(route('quizzes.review', $quiz->id));
    }

    public function test_teacher_create_without_questions_fails_validation()
    {
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Empty Quiz',
            'subject_id' => 79,
            'questions' => [],
        ]);
        $response->assertSessionHasErrors('questions');
        $this->assertDatabaseMissing('quizzes', ['title' => 'Empty Quiz']);
    }

    public function test_teacher_create_with_too_few_options_fails_validation()
    {
        $response = $this->actingAs($this->teacher())->post(route('quizzes.store'), [
            'title' => 'Bad Options Quiz',
            'subject_id' => 79,
            'questions' => [
                [
                    'question' => 'Only one option?',
                    'options' => ['Solo', '', '', ''],
                    'correct_index' => 0,
                    'marks' => 1,
                ],
            ],
        ]);
        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('quizzes', ['title' => 'Bad Options Quiz']);
    }

    public function test_student_attempt_auto_grades_to_correct_score()
    {
        $quiz = $this->makePrepQuiz();
        $questions = $quiz->questions()->orderBy('id')->get();

        $response = $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [
                $questions[0]->id => 0,
                $questions[1]->id => 0,
            ],
        ]);
        $response->assertRedirect(route('quizzes.show', $quiz->id));

        $attempt = QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $this->studentRecord()->id)
            ->first();
        $this->assertNotNull($attempt);
        // Q1 correct (2 marks), Q2 wrong (0 of 3) => 2 / 5.
        $this->assertEquals(2, $attempt->score);
        $this->assertEquals(5, $attempt->total);
        $this->assertNotNull($attempt->submitted_at);
        $answers = QuizAnswer::where('attempt_id', $attempt->id)->orderBy('question_id')->get();
        $this->assertTrue((bool) $answers[0]->is_correct);
        $this->assertFalse((bool) $answers[1]->is_correct);
    }

    public function test_teacher_results_page_shows_per_student_per_question_correctness()
    {
        $quiz = $this->makePrepQuiz();
        $questions = $quiz->questions()->orderBy('id')->get();
        $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$questions[0]->id => 0, $questions[1]->id => 0],
        ]);

        $response = $this->actingAs($this->teacher())->get(route('quizzes.results', $quiz->id));
        $response->assertOk();
        $response->assertSee($this->student()->name);
        $response->assertSee('2 / 5');
        $response->assertSee('What is 2 + 2?');
        $response->assertSee('Correct');
        $response->assertSee('Wrong');
    }

    public function test_student_cannot_attempt_another_class_quiz()
    {
        $quiz = $this->makeClass12Quiz();
        $response = $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $response->assertForbidden();
        $response = $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [],
        ]);
        $response->assertForbidden();
    }

    public function test_teacher_cannot_view_foreign_teacher_results()
    {
        $quiz = $this->makePrepQuiz();
        $response = $this->actingAs($this->otherTeacher())->get(route('quizzes.results', $quiz->id));
        $response->assertForbidden();
        $response = $this->actingAs($this->otherTeacher())->get(route('quizzes.show', $quiz->id));
        $response->assertForbidden();
    }

    public function test_second_attempt_redirects_without_duplicate_row()
    {
        $quiz = $this->makePrepQuiz();
        $questions = $quiz->questions()->orderBy('id')->get();
        $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$questions[0]->id => 0, $questions[1]->id => 1],
        ]);

        $response = $this->actingAs($this->student())->get(route('quizzes.attempt', $quiz->id));
        $response->assertRedirect(route('quizzes.show', $quiz->id));
        $response = $this->actingAs($this->student())->post(route('quizzes.submit', $quiz->id), [
            'answers' => [$questions[0]->id => 0, $questions[1]->id => 1],
        ]);
        $response->assertRedirect(route('quizzes.show', $quiz->id));
        $this->assertEquals(1, QuizAttempt::where('quiz_id', $quiz->id)
            ->where('student_record_id', $this->studentRecord()->id)
            ->count());
    }

    public function test_student_cannot_create_quiz_and_admin_index_lists_all()
    {
        $response = $this->actingAs($this->student())->get(route('quizzes.create'));
        $response->assertForbidden();
        $quiz = $this->makePrepQuiz();
        $response = $this->actingAs($this->admin())->get(route('quizzes.index'));
        $response->assertOk();
        $response->assertSee('Prep Quiz');
    }
}
