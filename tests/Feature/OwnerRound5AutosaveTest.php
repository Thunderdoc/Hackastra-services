<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Circular;
use App\Models\Mark;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 2 — P6: hk-draft autosave.
 *
 * One shared helper in hackstra-ux.js: a form opts in with
 * data-hk-draft="<full per-user key>" rendered by the blade (the
 * helper itself knows no user). Drafts live in localStorage under
 * 'hk-draft:' + key as a {ts, data} envelope, restore only when
 * fresh (< 7 days) and different from the server render, announce
 * themselves in a .hk-draft-note ("Draft saved HH:MM" /
 * "Unsaved draft from HH:MM restored" + Discard), and clear on
 * submit. Secrets never serialise (file / password / hidden types,
 * data-hk-draft-skip, and the pass|card|cvv|secret name denylist).
 *
 * Forms on the shared form_page pattern render their <form> tag
 * inside that partial, so settings / circulars / assignments opt
 * in through an in-form marker carrying the same attribute; raw
 * forms (lesson plans, quizzes) carry it on the <form> tag itself.
 *
 * Marks entry is deliberately NOT autosaved (the server draft
 * behind Save is the durable store): marks/edit only gains a
 * beforeunload guard tied to its existing #l86-dirty state.
 */
class OwnerRound5AutosaveTest extends TestCase
{
    use DatabaseTransactions;

    protected function superAdmin(): User
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function file(string $rel): string
    {
        return file_get_contents(base_path($rel));
    }

    /* ---------------- (a) helper source pins ---------------- */

    public function test_helper_section_exists_with_guardrails()
    {
        $ux = $this->file('public/assets/js/hackstra-ux.js');

        // The section and its opt-in contract.
        $this->assertStringContainsString('hk-draft autosave', $ux);
        $this->assertStringContainsString("var PREFIX = 'hk-draft:';", $ux);
        $this->assertStringContainsString("document.querySelectorAll('[data-hk-draft]')", $ux);
        // Debounced through the one shared debounce (P7).
        $this->assertStringContainsString('window.hkDebounce(function ()', $ux);
        // The denylist pattern, exactly as specified.
        $this->assertStringContainsString('/pass|card|cvv|secret/i', $ux);
        $this->assertStringContainsString('data-hk-draft-skip', $ux);
        // Never serialised types.
        $this->assertStringContainsString("type === 'file' || type === 'password' || type === 'hidden'", $ux);
        // 7-day expiry on a timestamped envelope.
        $this->assertStringContainsString('7 * 24 * 60 * 60 * 1000', $ux);
        $this->assertStringContainsString('Date.now() - envelope.ts > MAX_AGE_MS', $ux);
        $this->assertStringContainsString('JSON.stringify({ ts: ts, data: serialise(form) })', $ux);
        // The note element + its two messages.
        $this->assertStringContainsString('hk-draft-note text-muted small', $ux);
        $this->assertStringContainsString('Draft saved ', $ux);
        $this->assertStringContainsString('Unsaved draft from ', $ux);
        $this->assertStringContainsString(' restored', $ux);
        // Discard clears the key and reloads the server values.
        $this->assertStringContainsString('hk-draft-discard', $ux);
        $this->assertStringContainsString('window.location.reload()', $ux);
        // Submit clears the key (old() owns a validation bounce).
        $this->assertStringContainsString("form.addEventListener('submit'", $ux);
        $this->assertStringContainsString('localStorage.removeItem(store)', $ux);
        // Checkbox / radio state rides by checked, not by value.
        $this->assertStringContainsString("'::checked'", $ux);
        // Restore notifies widgets with a bubbling change event.
        $this->assertStringContainsString("new Event('change', { bubbles: true })", $ux);
    }

    /* ---------------- (b) rendered opt-in pins ---------------- */

    public function test_settings_page_opts_in_with_admin_id()
    {
        // The settings page belongs to the school Admin: the
        // PlatformOnly boundary redirects the Super Admin off it
        // ('settings' is not on the SA allowlist), so the opt-in
        // is pinned through the Admin who actually uses the page.
        $admin = $this->admin();
        $response = $this->actingAs($admin)->get(route('settings'));
        $response->assertOk();
        $response->assertSee('data-hk-draft="u' . $admin->id . '-settings"', false);
    }

    public function test_circulars_create_and_edit_opt_in()
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin)->get(route('circulars.create'));
        $create->assertOk();
        $create->assertSee('data-hk-draft="u' . $admin->id . '-circular-new"', false);

        $circular = Circular::create([
            'title' => 'P6 Autosave Probe Circular',
            'body' => 'Probe body for the autosave edit pin.',
            'audience' => 'all', 'priority' => 'normal',
            'published_by' => $admin->id, 'is_published' => true,
        ]);
        $edit = $this->actingAs($admin)->get(route('circulars.edit', $circular->id));
        $edit->assertOk();
        // The edit key carries the record id — drafts never collide.
        $edit->assertSee('data-hk-draft="u' . $admin->id . '-circular-' . $circular->id . '"', false);
    }

    public function test_assignments_create_and_edit_opt_in()
    {
        $teacher = $this->teacher();

        $create = $this->actingAs($teacher)->get(route('assignments.create'));
        $create->assertOk();
        $create->assertSee('data-hk-draft="u' . $teacher->id . '-assignment-new"', false);

        $assignment = Assignment::create([
            'teacher_id' => $teacher->id,
            'my_class_id' => 13, 'section_id' => 65, 'subject_id' => 79,
            'title' => 'P6 Autosave Probe Assignment',
            'description' => 'probe', 'max_marks' => 10,
            'due_at' => '2030-01-01 10:00:00', 'allow_late' => true,
        ]);
        $edit = $this->actingAs($teacher)->get(route('assignments.edit', $assignment->id));
        $edit->assertOk();
        $edit->assertSee('data-hk-draft="u' . $teacher->id . '-assignment-' . $assignment->id . '"', false);
    }

    public function test_quiz_create_opts_in_for_teacher()
    {
        $teacher = $this->teacher();
        $response = $this->actingAs($teacher)->get(route('quizzes.create'));
        $response->assertOk();
        $response->assertSee('data-hk-draft="u' . $teacher->id . '-quiz-new"', false);
    }

    public function test_lesson_plan_create_blade_opts_in_but_route_stays_closed()
    {
        // The create blade carries the opt-in on its <form> tag…
        $blade = $this->file('resources/views/pages/support_team/lesson_plans/create.blade.php');
        $this->assertStringContainsString('data-hk-draft="u{{ Auth::id() }}-lessonplan-new"', $blade);

        // …but no role can currently render it: LessonPlanController
        // @create aborts 403 for everyone since the overnight-menus
        // removal (item 17) — teachers were the only writers and are
        // out; admin/principal keep read oversight only. The opt-in
        // is in place for the day the module is restored.
        $this->actingAs($this->admin())->get(route('lesson_plans.create'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('lesson_plans.create'))->assertForbidden();
    }

    /* ---------------- (c) marks unload guard ---------------- */

    public function test_marks_edit_has_unload_guard_tied_to_dirty_state()
    {
        $blade = $this->file('resources/views/pages/support_team/marks/edit.blade.php');
        $this->assertStringContainsString("window.addEventListener('beforeunload'", $blade);
        // The guard reads the page's existing dirty state…
        $this->assertStringContainsString("document.getElementById('l86-dirty')", $blade);
        $this->assertStringContainsString('input.l86-dirty', $blade);
        // …and a submit disarms it (the My Account precedent's shape).
        $this->assertStringContainsString('l86Submitting', $blade);
        // No autosave / storage was added for marks.
        $this->assertStringNotContainsString('localStorage.setItem', $blade);
        $this->assertStringNotContainsString('localStorage.getItem', $blade);
        $this->assertStringNotContainsString('data-hk-draft', $blade);
    }

    public function test_marks_manage_page_renders_the_guard_for_teacher()
    {
        // Same fixture as OwnerMarksLifecycleTest: the selector
        // creates the unit's rows (exam 1, class 13, section 76,
        // subject 79), then the teacher's manage page renders the
        // edit grid — guard included.
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13,
            'section_id' => 76, 'subject_id' => 79,
        ]);
        $this->assertGreaterThan(0, Mark::where([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76,
            'subject_id' => 79, 'year' => '2026-2027',
        ])->count(), 'fixture: selector must create the unit mark rows');

        $page = $this->actingAs($this->teacher())->get(route('marks.manage', [1, 13, 76, 79]));
        $page->assertOk();
        $page->assertSee("window.addEventListener('beforeunload'", false);
        $page->assertSee("document.getElementById('l86-dirty')", false);
    }
}
