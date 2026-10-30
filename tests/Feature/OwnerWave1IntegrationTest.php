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
        // Overnight menus 2026-10-29, item 17: the teacher
        // sidebar lost Lesson Plans, Homework Tracker,
        // Substitutions and Question Bank (menu-hide for the
        // latter three, full removal for Lesson Plans). Quizzes
        // and Class Overview stay.
        $response = $this->actingAs($this->teacher())->get(route('dashboard'));
        $response->assertOk();
        foreach (['/quizzes', '/class-overview'] as $path) {
            $response->assertSee($path, false);
        }
        foreach (['/lesson-plans', '/homework-tracker', '/question-bank'] as $path) {
            $response->assertDontSee($path, false);
        }
        // The board stays hidden (overnight order stands) — exact
        // hrefs, because the teacher's OWN extra-classes page
        // (owner order 2026-10-30) legitimately lives under
        // /substitutions/mine and must not trip this pin.
        $response->assertDontSee('href="' . route('substitutions.index') . '"', false);
        $response->assertDontSee('href="' . route('substitutions.create') . '"', false);
        $response->assertSee('href="' . route('substitutions.mine') . '"', false);
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
        // Overnight menus 2026-10-29, item 17: Homework Tracker,
        // Question Bank and Class Overview are menu-hide only
        // (servers unchanged), so they still load for teachers.
        // Lesson Plans is fully removed (403) and Substitutions
        // is admin-only (403) — both are refused, not loaded.
        $teacher = $this->teacher();
        foreach (['homework_tracker.index', 'class_overview.index', 'question_bank.index'] as $route) {
            $this->actingAs($teacher)->get(route($route))->assertOk();
        }
        foreach (['lesson_plans.index', 'substitutions.index'] as $route) {
            $this->actingAs($teacher)->get(route($route))->assertForbidden();
        }
    }
}
