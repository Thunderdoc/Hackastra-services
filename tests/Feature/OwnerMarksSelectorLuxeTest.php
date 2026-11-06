<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\MarkScheme;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 10 (owner choice, 2026-11-01): the marks selector page is
 * rebuilt "Light Luxe" — slim header, ONE card with the stepper,
 * the four selects, four REAL-data stat tiles and the status line;
 * the promo banner card and the three feature cards are removed.
 * Behaviour (cascade, entry-state gating, Not Enabled, Reset,
 * item-4 scoping) is pinned by the existing marks suite; these
 * pins cover the new chrome and the summary payload the tiles
 * render from.
 */
class OwnerMarksSelectorLuxeTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_page_has_luxe_chrome_and_no_promo_or_feature_cards()
    {
        foreach (['admin@hackstra.app', 'teacher@hackstra.app'] as $email) {
            $html = $this->actingAs($this->user($email))
                ->get(route('marks.index'))->assertOk()->getContent();

            $this->assertStringContainsString('l10-head', $html);
            $this->assertStringContainsString('l10-tiles', $html);
            $this->assertStringContainsString('l10-status', $html);
            $this->assertStringContainsString('id="l84-steps"', $html);
            $this->assertStringNotContainsString('Record and manage student marks', $html);
            $this->assertStringNotContainsString('l84-banner', $html);
            $this->assertStringNotContainsString('l84-info-cards', $html);
            $this->assertStringNotContainsString('Supported Exam Types', $html);
            $this->assertStringNotContainsString('Select Exam Details', $html);
        }
    }

    public function test_tiles_start_on_the_neutral_placeholder()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('marks.index'))->assertOk()->getContent();

        foreach (['l10-t-students', 'l10-t-subject', 'l10-t-max', 'l10-t-entry'] as $id) {
            $this->assertStringContainsString('id="' . $id . '">&mdash;</span>', $html,
                $id . ' must start on the neutral placeholder, never a number');
        }
        $this->assertStringContainsString(
            'Select exam, class, section and subject — the entry state will show here.', $html);
    }

    public function test_entry_state_summary_carries_the_units_real_data()
    {
        // Anchor unit (same as OwnerMarksEntryStateButtonTest):
        // Term 1 / Class 12 / section 76 / subject 79 — Ananya's.
        $res = $this->actingAs($this->user('teacher@hackstra.app'))->getJson(
            route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79'
        );
        $res->assertOk();
        $this->assertTrue($res->json('enabled'));

        $expectedStudents = StudentRecord::where('section_id', 76)->where('grad', 0)->count();
        $this->assertSame($expectedStudents, $res->json('summary.students'));
        $this->assertSame(Subject::findOrFail(79)->name, $res->json('summary.subject'));
        $scheme = MarkScheme::forExamSubject(1, 79);
        $this->assertSame($scheme['max_total'], $res->json('summary.max'));
        $this->assertStringContainsString((string) $scheme['components'][0]['max_marks'], (string) $res->json('summary.scheme'));
        $this->assertSame('Open', $res->json('summary.entry_label'));
    }

    public function test_summary_is_not_served_for_a_unit_that_is_not_the_teachers()
    {
        $other = User::where('user_type', 'teacher')
            ->where('email', '!=', 'teacher@hackstra.app')->firstOrFail();

        $res = $this->actingAs($other)->getJson(
            route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79'
        );
        $res->assertOk();
        $this->assertFalse($res->json('enabled'));
        $this->assertNull($res->json('summary'), 'no roster/scheme data leaks for a unit she may not enter');
    }
}
