<?php

namespace Tests\Feature;

use App\Models\QuestionBankQuestion;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 2 — P19: ONE confirmation standard for the whole app.
 *
 * Three generations coexisted: the shared styled modal
 * (data-confirm / window.hackstraConfirm in hackstra-ux.js), the
 * legacy SweetAlert confirmDelete(id) / confirmReset(id) pair in
 * custom_js, and native return confirm('...') handlers — plus one
 * completely bare delete (quiz question on the review page).
 *
 * This suite pins the unified end state:
 *  (a) a source scan: no blade and no app JS file may contain a
 *      native confirm( call. The allowlist is exactly the shared
 *      API surface — hackstraConfirm( itself, and the bridge entry
 *      points confirmDelete( / confirmReset( whose DEFINITIONS now
 *      live over hackstraConfirm (their call sites remain, with the
 *      same names, so the 14 legacy pages needed no rewiring of
 *      their hidden forms). data-confirm attributes never match the
 *      scan because the scan looks for the call shape "confirm(".
 *  (b) the bridge contract in custom_js: both functions take the
 *      new optional display-name argument, build the standard
 *      messages, and still submit the SAME hidden forms
 *      (form#item-delete-{id} / form#item-reset-{id}). The old swal
 *      confirm wording is gone. Assertion decision: the file must
 *      contain exactly ONE remaining swal({ — that is pop()'s
 *      notification call, which the slice explicitly leaves alone;
 *      pinning the count at 1 proves both confirm definitions are
 *      swal-free without touching the notification path.
 *  (c) the formerly bare quiz-question delete now carries the
 *      danger confirm with the question named in the message.
 *  (d) a migrated legacy surface (classes index) still renders its
 *      delete trigger and its item-delete-{id} hidden forms, now
 *      passing the record name to the bridge.
 *  (e) a former native-confirm surface (question bank index)
 *      renders data-confirm instead of onsubmit confirm().
 *
 * Users are resolved by EMAIL, never hardcoded ids.
 */
class OwnerRound5ConfirmUnificationTest extends TestCase
{
    use DatabaseTransactions;

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

    /* ---------------- (a) source scan ---------------- */

    public function test_no_native_confirm_calls_remain_in_blades_or_app_js()
    {
        $files = [];
        $viewIt = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($viewIt as $f) {
            if ($f->isFile() && substr($f->getFilename(), -10) === '.blade.php') {
                $files[] = $f->getPathname();
            }
        }
        foreach (['public/assets/js/hackstra-ux.js', 'public/assets/js/app.js', 'public/assets/js/custom.js'] as $rel) {
            $files[] = base_path($rel);
        }
        $this->assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $path) {
            $content = file_get_contents($path);
            // Strip the allowed shared-API call shapes; any confirm(
            // left over is a native call (or a new unreviewed one).
            $stripped = str_replace(
                ['hackstraConfirm(', 'confirmDelete(', 'confirmReset('],
                '',
                $content
            );
            if (strpos($stripped, 'confirm(') !== false || strpos($stripped, 'return confirm') !== false) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }
        $this->assertSame([], $offenders, 'Native confirm() remains in: ' . implode(', ', $offenders));
    }

    /* ---------------- (b) the bridge ---------------- */

    public function test_confirm_delete_and_reset_are_bridges_over_hackstra_confirm()
    {
        $js = $this->file('resources/views/partials/js/custom_js.blade.php');

        // Same entry points, extended with the optional display name.
        $this->assertStringContainsString('function confirmDelete(id, name)', $js);
        $this->assertStringContainsString('function confirmReset(id, name)', $js);

        // The standard messages, named and generic.
        $this->assertStringContainsString("'Delete ' + name + '? This cannot be undone.'", $js);
        $this->assertStringContainsString("'Delete this item? This cannot be undone.'", $js);
        $this->assertStringContainsString("'Reset ' + name + '? This cannot be undone.'", $js);
        $this->assertStringContainsString("'Reset this item? This cannot be undone.'", $js);
        $this->assertStringContainsString('danger: true', $js);

        // The same hidden forms are submitted, exactly as before.
        $this->assertStringContainsString("$('form#item-delete-'+id).submit();", $js);
        $this->assertStringContainsString("$('form#item-reset-'+id).submit();", $js);

        // The SweetAlert confirm wording is gone, and the only swal
        // left in the file is pop()'s notification call (see the
        // class docblock for why the count is pinned at exactly 1).
        $this->assertStringNotContainsString('will not be able to recover', $js);
        $this->assertStringNotContainsString('This will reset this item to default state', $js);
        $this->assertSame(1, substr_count($js, 'swal({'));
    }

    /* ---------------- (c) the formerly bare quiz delete ---------------- */

    public function test_quiz_review_delete_question_carries_named_danger_confirm()
    {
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'title' => 'Confirm Probe Quiz',
            'time_limit_minutes' => 10,
            'status' => 'published',
        ]);
        $question = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question' => 'What is the capital of France?',
            'options' => ['London', 'Paris', 'Berlin', 'Madrid'],
            'correct_index' => 1,
            'marks' => 2,
        ]);

        $response = $this->actingAs($this->teacher())->get(route('quizzes.review', $quiz->id));
        $response->assertOk();
        $html = $response->getContent();

        // Same form, same target — now gated by the shared modal,
        // with the question itself named in the message.
        $this->assertStringContainsString(route('quizzes.questions.destroy', [$quiz->id, $question->id]), $html);
        $this->assertStringContainsString('data-confirm="Delete the question', $html);
        $this->assertStringContainsString('What is the capital of France?', $html);
        $this->assertStringContainsString('This cannot be undone.', $html);
        $this->assertStringContainsString('data-confirm-title="Delete Question"', $html);
        $this->assertStringContainsString('data-confirm-action="Delete"', $html);
        $this->assertStringContainsString('data-confirm-danger', $html);
        $this->assertStringNotContainsString('return confirm', $html);
    }

    /* ---------------- (d) a migrated legacy surface ---------------- */

    public function test_classes_index_keeps_hidden_delete_forms_and_named_triggers()
    {
        $response = $this->actingAs($this->admin())->get(route('classes.index'));
        $response->assertOk();
        $html = $response->getContent();

        // The hidden-form mechanics are untouched: item-delete-{id}.
        $this->assertMatchesRegularExpression('/id="item-delete-\d+"/', $html);
        // The trigger now hands the bridge the record's display name
        // (carried in data-item-name so a name containing quotes or
        // an apostrophe can never break the inline handler).
        $this->assertStringContainsString('confirmDelete(this.id, this.dataset.itemName)', $html);
        $this->assertMatchesRegularExpression(
            '/data-item-name="[^"]+" onclick="confirmDelete\(this\.id, this\.dataset\.itemName\)"/',
            $html
        );
        $this->assertStringNotContainsString('return confirm', $html);
    }

    /* ---------------- (e) a former native-confirm surface ---------------- */

    public function test_question_bank_index_renders_data_confirm_instead_of_native()
    {
        QuestionBankQuestion::create([
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 13,
            'subject_id' => 79,
            'section_id' => null,
            'question' => 'Which planet is known as the Red Planet?',
            'options' => ['Venus', 'Mars', 'Jupiter', 'Saturn'],
            'correct_index' => 1,
            'marks' => 1,
        ]);

        $response = $this->actingAs($this->teacher())->get(route('question_bank.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-confirm="Delete the question', $html);
        $this->assertStringContainsString('Which planet is known as the Red Planet?', $html);
        $this->assertStringContainsString('from your bank? This cannot be undone.', $html);
        $this->assertStringContainsString('data-confirm-danger', $html);
        $this->assertStringNotContainsString('return confirm', $html);
        $this->assertStringNotContainsString('onsubmit', $html);
    }
}
