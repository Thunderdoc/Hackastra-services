<?php

namespace Tests\Feature;

use App\Models\Mark;
use App\Models\MarkSubmission;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-11-08: after a teacher SUBMITS a unit's marks,
 * she could not open the entry page again at all — the selector's
 * entry-state answered "not enabled" and the button stayed dead,
 * so she could not even SEE the marks she had entered. The unit
 * now opens READ-ONLY for its effective teacher: entered marks
 * visible, every input disabled, a Submitted — locked banner, no
 * save/submit controls. Editing stays locked server-side exactly
 * as before (save + re-submit refused; only the school Admin can
 * reopen, audited — that rule does not change), and a different
 * teacher still cannot open the unit at all.
 *
 * Fixture: Term 1 (exam 1), Class 12 (13), section 76, English
 * (79) — Ananya (teacher@) is the section's effective teacher.
 */
class OwnerMarksSubmittedReadOnlyTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array{0:int,1:int} [mark id, component id] of the distinctive cell */
    private $distinctive = [0, 0];

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function unit(): array
    {
        return [1, 13, 76, 79];
    }

    protected function unitRow(): array
    {
        return ['exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79];
    }

    /** Create the unit's mark rows the way the real flow does. */
    protected function ensureRows()
    {
        $this->actingAs($this->admin())->post(route('marks.selector'), [
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
        ]);
        $this->assertGreaterThan(0, Mark::where($this->unitRow() + ['year' => '2026-2027'])->count(),
            'fixture: selector must create the unit mark rows');
    }

    /** A complete valid save payload: every cell 10, one distinctive cell 37. */
    protected function payload(): array
    {
        $marks = Mark::where($this->unitRow() + ['year' => '2026-2027'])->get();
        $payload = [];
        foreach (MarkScheme::configuredComponents(1, 79) as $comp) {
            foreach ($marks as $mk) {
                $payload['comp_' . $mk->id . '_' . $comp->id] = 10;
            }
        }
        $wide = MarkScheme::configuredComponents(1, 79)->filter(function ($c) {
            return (int) $c->max_marks >= 37;
        })->first();
        $first = $marks->sortBy('id')->first();
        $payload['comp_' . $first->id . '_' . $wide->id] = 37;
        $this->distinctive = [(int) $first->id, (int) $wide->id];
        return $payload;
    }

    /** Save as the teacher, then submit the unit as the teacher. */
    protected function saveAndSubmit(): void
    {
        $this->ensureRows();
        $this->actingAs($this->teacher())->put(route('marks.update', $this->unit()), $this->payload())
            ->assertOk();
        $this->actingAs($this->teacher())->post(route('marks.submit', $this->unit()))
            ->assertRedirect();
        $this->assertNotNull(MarkSubmission::forUnit(1, 76, 79), 'fixture: unit must be submitted');
    }

    public function test_submitting_teacher_can_open_the_unit_read_only()
    {
        $this->saveAndSubmit();

        // The selector's door: not entry-enabled, but viewable.
        $state = $this->actingAs($this->teacher())->getJson(
            route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79'
        );
        $state->assertOk();
        $this->assertFalse($state->json('enabled'));
        $this->assertTrue($state->json('view_only'));
        $this->assertStringContainsString('already submitted', (string) $state->json('reason'));
        $this->assertSame('Submitted', $state->json('summary.entry_label'));

        // The selector page carries the View Marks wiring.
        $selector = $this->actingAs($this->teacher())->get(route('marks.index'));
        $selector->assertOk();
        $selector->assertSee('View Marks', false);
        $selector->assertSee('view_only', false);

        // The entry page itself: 200, banner, entered marks, all
        // inputs disabled, and NO save controls at all.
        $page = $this->actingAs($this->teacher())->get(route('marks.manage', $this->unit()));
        $page->assertOk();
        $page->assertSee('Submitted — Locked');
        $page->assertSee('locked and view-only');
        $page->assertDontSee('Update Marks');
        $page->assertDontSee('Submit Marks');
        $page->assertDontSee('Reopen for correction');

        $html = $page->getContent();
        preg_match_all('/<input[^>]*name="comp_\d+_\d+"[^>]*>/', $html, $tags);
        $this->assertNotEmpty($tags[0], 'read-only grid must still render the entered marks');
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('disabled', $tag, 'every mark input must be disabled: ' . $tag);
        }
        $distinctiveName = 'name="comp_' . $this->distinctive[0] . '_' . $this->distinctive[1] . '"';
        $found = collect($tags[0])->first(function ($t) use ($distinctiveName) {
            return strpos($t, $distinctiveName) !== false;
        });
        $this->assertNotNull($found, 'the distinctive cell must render');
        $this->assertStringContainsString('value="37"', $found, 'the entered score must be visible read-only');
    }

    public function test_save_and_resubmit_stay_refused_after_submit()
    {
        $this->saveAndSubmit();

        $mark = Mark::findOrFail($this->distinctive[0]);
        $comp = MarkScheme::configuredComponents(1, 79)->firstWhere('id', $this->distinctive[1]);
        $before = MarkScheme::scoreFor($mark, ['id' => $comp->id, 'field' => null] + $comp->toArray());

        $changed = $this->payload();
        $changed['comp_' . $this->distinctive[0] . '_' . $this->distinctive[1]] = 20;
        $this->actingAs($this->teacher())->put(route('marks.update', $this->unit()), $changed)
            ->assertForbidden();

        $after = MarkScheme::scoreFor(Mark::findOrFail($this->distinctive[0]), ['id' => $comp->id, 'field' => null] + $comp->toArray());
        $this->assertSame($before, $after, 'a refused save must not move any score');

        $this->actingAs($this->teacher())->post(route('marks.submit', $this->unit()))
            ->assertRedirect();
        $this->assertSame(1, MarkSubmission::where($this->unitRow())->count(),
            're-submitting must not stack a second submission row');
    }

    public function test_admin_reopen_restores_full_editing()
    {
        $this->saveAndSubmit();

        $this->actingAs($this->admin())->post(route('marks.reopen', $this->unit()))
            ->assertRedirect();
        $this->assertNull(MarkSubmission::forUnit(1, 76, 79));

        $state = $this->actingAs($this->teacher())->getJson(
            route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79'
        );
        $this->assertTrue($state->json('enabled'));
        $this->assertEmpty($state->json('view_only'));

        $page = $this->actingAs($this->teacher())->get(route('marks.manage', $this->unit()));
        $page->assertOk();
        $page->assertSee('Submit Marks');
        $page->assertSee('Update Marks');
        $this->assertDoesNotMatchRegularExpression('/name="comp_\d+_\d+"[^>]*disabled/', $page->getContent(),
            'reopened grid inputs must be editable again');

        $this->actingAs($this->teacher())->put(route('marks.update', $this->unit()), $this->payload())
            ->assertOk();
    }

    public function test_another_teacher_cannot_open_the_submitted_unit()
    {
        $this->saveAndSubmit();

        $other = User::where('user_type', 'teacher')->where('id', '!=', $this->teacher()->id)->firstOrFail();

        $this->actingAs($other)->get(route('marks.manage', $this->unit()))
            ->assertForbidden();

        $state = $this->actingAs($other)->getJson(
            route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79'
        );
        $state->assertOk();
        $this->assertFalse($state->json('enabled'));
        $this->assertEmpty($state->json('view_only'), 'view-only is the effective teacher\'s door only');
    }
}
