<?php

namespace Tests\Feature;

use App\Models\QuestionBankQuestion;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Wave 1 integration: the pieces the six feature workers could not
 * cover alone — sidebar navigation for all six features (added by
 * the coordinator), the question-bank picker contract the quiz form
 * consumes (including correct_index), the picker markup on the quiz
 * create page, and the Super Admin block (no Wave 1 route is
 * PlatformOnly-listed).
 */
class OwnerWave1IntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    public function test_teacher_sidebar_links_all_wave1_features()
    {
        $response = $this->actingAs($this->teacher())->get(route('dashboard'));
        $response->assertOk();
        foreach (['/quizzes', '/lesson-plans', '/homework-tracker', '/substitutions', '/class-overview', '/question-bank'] as $path) {
            $response->assertSee($path, false);
        }
    }

    public function test_quiz_create_page_carries_bank_picker()
    {
        $response = $this->actingAs($this->teacher())->get(route('quizzes.create'));
        $response->assertOk();
        $response->assertSee('id="bank-question"', false);
        $response->assertSee('id="load-bank"', false);
        $response->assertSee('id="add-from-bank"', false);
        $response->assertSee('/question-bank/picker', false);
    }

    public function test_bank_picker_returns_correct_index_for_owner()
    {
        $teacher = $this->teacher();
        QuestionBankQuestion::create([
            'teacher_id' => $teacher->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'question' => 'Integration probe question',
            'options' => ['A', 'B', 'C', 'D'],
            'correct_index' => 2,
            'marks' => 3,
        ]);

        $response = $this->actingAs($teacher)->getJson(
            route('question_bank.picker', ['class_id' => 1, 'subject_id' => 1])
        );
        $response->assertOk();
        $items = $response->json();
        $this->assertNotEmpty($items);
        $found = collect($items)->firstWhere('question', 'Integration probe question');
        $this->assertNotNull($found);
        $this->assertSame(2, $found['correct_index']);
        $this->assertSame(['A', 'B', 'C', 'D'], $found['options']);
    }

    public function test_super_admin_is_blocked_from_wave1_routes()
    {
        $response = $this->actingAs($this->superAdmin())->get(route('quizzes.index'));
        $response->assertRedirect();
        $response = $this->actingAs($this->superAdmin())->get(route('question_bank.index'));
        $response->assertRedirect();
    }

    public function test_wave1_teacher_pages_all_load()
    {
        $teacher = $this->teacher();
        foreach (['lesson_plans.index', 'homework_tracker.index', 'substitutions.index', 'class_overview.index', 'question_bank.index'] as $route) {
            $this->actingAs($teacher)->get(route($route))->assertOk();
        }
    }
}
