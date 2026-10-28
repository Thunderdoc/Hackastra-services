<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 10 (2026-10-28): generate-refusal RENDERED outcome.
 *
 * Rounds 1–4 locked the refusal DECISIONS (past start refused,
 * equal sessions refused, transactions roll back). This file locks
 * what the admin actually SEES: every refusal must land back on
 * the generate form with a readable message — never a 500, never
 * a half-written sheet. Validation errors render in the master
 * layout's error summary; flash_danger renders as a toast via
 * partials/js/custom_js. The nonexistent-exam / missing-class /
 * nonexistent-class refusals had no test at all before this file.
 */
class OwnerExamDateSheetR10Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function base(array $overrides = [])
    {
        return array_merge([
            'exam_id' => 1, 'scope' => 'one', 'class_id' => 11,
            'start_date' => '2026-12-01', 'exam_days' => 3, 'papers_per_day' => 2,
            'session1_time' => '09:00', 'session2_time' => '13:00',
            'mode' => 'skip',
        ], $overrides);
    }

    public function test_generate_with_nonexistent_exam_returns_to_form_with_message()
    {
        $this->actingAs($this->admin());
        $before = ExamDate::where('exam_id', 1)->count();

        $resp = $this->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['exam_id' => 99999]));
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $resp->assertSessionHas('flash_danger', 'Record Not Found');
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());

        // Rendered outcome: following the redirect, the page carries
        // the toast call with the exact message. (Bare 'Record Not
        // Found' would be vacuous — a generic AJAX 404 handler in
        // the layout already contains those words.)
        $this->followingRedirects()->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['exam_id' => 99999]))
            ->assertOk()
            ->assertSee("msg : 'Record Not Found'", false); // unescaped: assertSee escapes ' to &#039; by default
    }

    public function test_generate_scope_one_without_class_returns_to_form_with_message()
    {
        $this->actingAs($this->admin());
        $before = ExamDate::where('exam_id', 1)->count();
        $payload = $this->base();
        unset($payload['class_id']);

        $resp = $this->from(route('exam_dates.generate.form'))->post(route('exam_dates.generate'), $payload);
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $resp->assertSessionHas('flash_danger', 'Choose the class to generate for.');
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());
    }

    public function test_generate_scope_one_with_nonexistent_class_returns_to_form_with_message()
    {
        $this->actingAs($this->admin());
        $before = ExamDate::where('exam_id', 1)->count();

        $resp = $this->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['class_id' => 99999]));
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $resp->assertSessionHas('flash_danger', 'Record Not Found');
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());
    }

    public function test_equal_session_refusal_returns_to_form_with_message_and_no_rows()
    {
        $this->actingAs($this->admin());
        $before = ExamDate::where('exam_id', 1)->count();

        // Explicit equal sessions.
        $resp = $this->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['session2_time' => '09:00']));
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $this->assertStringContainsString('must differ', (string) $resp->getSession()->get('flash_danger'));
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());

        // Omitted session2 defaults to 13:00 — colliding with a 13:00
        // first session is refused the same way.
        $payload = $this->base(['session1_time' => '13:00']);
        unset($payload['session2_time']);
        $resp = $this->from(route('exam_dates.generate.form'))->post(route('exam_dates.generate'), $payload);
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $this->assertStringContainsString('must differ', (string) $resp->getSession()->get('flash_danger'));
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());
    }

    public function test_past_start_refusal_renders_error_on_the_form()
    {
        $this->actingAs($this->admin());
        $before = ExamDate::where('exam_id', 1)->count();

        $resp = $this->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['start_date' => '2020-01-06']));
        $resp->assertRedirect(route('exam_dates.generate.form'));
        $resp->assertSessionHasErrors('start_date');
        $this->assertSame($before, ExamDate::where('exam_id', 1)->count());

        // Rendered outcome: following the redirect, the form shows
        // the validation message in the master error summary.
        $this->followingRedirects()->from(route('exam_dates.generate.form'))
            ->post(route('exam_dates.generate'), $this->base(['start_date' => '2020-01-06']))
            ->assertOk()
            ->assertSee('after or equal to today');
    }
}
