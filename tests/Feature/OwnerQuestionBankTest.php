<?php

namespace Tests\Feature;

use App\Models\QuestionBankQuestion;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 1 feature 6 (Question Bank): teacher-owned bank questions.
 * Teachers manage only their own questions; admins get 403; the
 * picker returns only the requesting teacher's questions filtered
 * by class + subject (the quiz create form consumes it later).
 */
class OwnerQuestionBankTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->first();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function payload(array $overrides = [])
    {
        return array_merge([
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question' => 'What is the capital of France?',
            'options' => ['London', 'Paris', 'Berlin', 'Madrid'],
            'correct_index' => 1,
            'marks' => 2,
        ], $overrides);
    }

    protected function makeQuestion($teacher, array $overrides = [])
    {
        return QuestionBankQuestion::create(array_merge([
            'teacher_id' => $teacher->id,
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question' => 'Bank fixture question',
            'options' => ['A', 'B', 'C', 'D'],
            'correct_index' => 0,
            'marks' => 1,
        ], $overrides));
    }

    public function test_teacher_creates_bank_question()
    {
        $response = $this->actingAs($this->teacher())
            ->post(route('question_bank.store'), $this->payload());

        $response->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseHas('question_bank_questions', [
            'teacher_id' => $this->teacher()->id,
            'question' => 'What is the capital of France?',
            'correct_index' => 1,
            'marks' => 2,
        ]);
    }

    public function test_teacher_create_with_fewer_than_two_options_fails()
    {
        $response = $this->actingAs($this->teacher())
            ->post(route('question_bank.store'), $this->payload([
                'options' => ['Only one', '', '', ''],
                'correct_index' => 0,
            ]));

        $response->assertSessionHasErrors('options');
        $this->assertDatabaseMissing('question_bank_questions', [
            'teacher_id' => $this->teacher()->id,
            'question' => 'What is the capital of France?',
        ]);
    }

    public function test_teacher_edits_own_question()
    {
        $question = $this->makeQuestion($this->teacher());

        $response = $this->actingAs($this->teacher())
            ->put(route('question_bank.update', $question->id), $this->payload([
                'question' => 'Edited bank question',
                'marks' => 5,
            ]));

        $response->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseHas('question_bank_questions', [
            'id' => $question->id,
            'question' => 'Edited bank question',
            'marks' => 5,
        ]);
    }

    public function test_teacher_deletes_own_question()
    {
        $question = $this->makeQuestion($this->teacher());

        $response = $this->actingAs($this->teacher())
            ->delete(route('question_bank.destroy', $question->id));

        $response->assertRedirect(route('question_bank.index'));
        $this->assertDatabaseMissing('question_bank_questions', ['id' => $question->id]);
    }

    public function test_foreign_teacher_cannot_edit_update_or_destroy()
    {
        $question = $this->makeQuestion($this->otherTeacher());

        $this->actingAs($this->teacher())
            ->get(route('question_bank.edit', $question->id))
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->put(route('question_bank.update', $question->id), $this->payload())
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->delete(route('question_bank.destroy', $question->id))
            ->assertForbidden();
        $this->assertDatabaseHas('question_bank_questions', ['id' => $question->id]);
    }

    public function test_picker_returns_only_requesting_teachers_questions()
    {
        $mine = $this->makeQuestion($this->teacher(), ['question' => 'Mine for picker']);
        $theirs = $this->makeQuestion($this->otherTeacher(), ['question' => 'Theirs for picker']);

        $response = $this->actingAs($this->teacher())
            ->getJson(route('question_bank.picker', ['class_id' => 13, 'subject_id' => 79]));

        $response->assertOk();
        $items = $response->json();
        $this->assertCount(1, $items);
        $this->assertSame($mine->id, $items[0]['id']);
        $this->assertSame('Mine for picker', $items[0]['question']);
        $this->assertSame(['A', 'B', 'C', 'D'], $items[0]['options']);
        $this->assertSame(1, $items[0]['marks']);
        $this->assertNotContains($theirs->id, array_column($items, 'id'));
    }

    public function test_admin_cannot_access_question_bank_index()
    {
        $this->actingAs($this->admin())
            ->get(route('question_bank.index'))
            ->assertForbidden();
    }
}
