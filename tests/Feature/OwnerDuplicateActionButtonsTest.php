<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Item 8 (owner, 2026-11-01): duplicate action buttons — the
 * Quizzes index rendered "New Quiz" twice (page header + inside
 * the listing card). The header action is the app-wide pattern
 * and stays; the in-card duplicate is removed. Sibling LMS index
 * pages were swept: Assignments, Study Materials and Lesson
 * Plans each carry exactly one create action (their empty-state
 * calls to action are the shared pattern, not duplicates).
 */
class OwnerDuplicateActionButtonsTest extends TestCase
{
    use DatabaseTransactions;

    private function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_quizzes_index_has_exactly_one_new_quiz_action()
    {
        $html = $this->actingAs($this->teacher())
            ->get(route('quizzes.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'New Quiz'));
        $this->assertSame(1, substr_count($html, route('quizzes.create')));
    }

    public function test_sibling_lms_indexes_have_exactly_one_create_action_each()
    {
        $teacher = $this->teacher();

        $assignments = $this->actingAs($teacher)
            ->get(route('assignments.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($assignments, '> New Assignment</a>'));

        $materials = $this->actingAs($teacher)
            ->get(route('materials.index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($materials, '> Add Material</a>'));

        if (Route::has('lesson_plans.index')) {
            // Lesson Plans index is not teacher-reachable (403 by existing
            // design) and its create action renders only for teachers, so no
            // role can ever see it twice; the no-duplicate invariant for its
            // button is pinned on the blade source: exactly one occurrence of
            // the action label (the header button; the empty-state text link
            // uses different words, per the shared pattern).
            $blade = file_get_contents(resource_path('views/pages/support_team/lesson_plans/index.blade.php'));
            $this->assertSame(1, substr_count($blade, 'New Lesson Plan'));
        }
    }
}
