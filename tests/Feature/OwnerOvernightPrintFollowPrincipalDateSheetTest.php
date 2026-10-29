<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * S5-FOLLOWUP (2026-10-29, AUD-TIMETABLE finding F2 / item 13):
 * principal GET /exam-dates/show returned 200 but rendered NO
 * #ds_class select at all, because date_sheet.blade.php gated the
 * class selector on viewer in ['teacher', 'admin'] while
 * ExamDateController::resolveScope()'s principal branch already
 * builds $classes = MyClassRepo::all() (natural order: Prep,
 * Class 1 … Class 12). The principal could only switch class by
 * hand-crafting ?class_id=.
 *
 * Fix scope is the selector gate ONLY: teacher/admin/principal.
 * This file proves, before the fix, that the principal page has
 * no #ds_class, and after it, that the principal dropdown is
 * present in the same natural order the admin gets, with the
 * teacher/admin dropdowns unchanged.
 */
class OwnerOvernightPrintFollowPrincipalDateSheetTest extends TestCase
{
    use DatabaseTransactions;

    protected function principal(): User
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function currentExam(): Exam
    {
        return Exam::where('year', Qs::getCurrentSession())->orderBy('id')->firstOrFail();
    }

    /** Option labels of a <select id=...> from rendered HTML, or null if absent. */
    protected function selectOptions(?string $html, string $selectId): ?array
    {
        $start = strpos($html, 'id="' . $selectId . '"');
        if ($start === false) {
            return null;
        }
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, "Select #{$selectId} never closes");
        $segment = substr($html, $start, $end - $start);
        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $segment, $m);

        return array_map(function ($label) {
            return trim(strip_tags(html_entity_decode($label, ENT_QUOTES, 'UTF-8')));
        }, $m[1]);
    }

    protected function showHtml(User $user, array $params = []): string
    {
        $params = array_merge(['exam_id' => $this->currentExam()->id], $params);

        return $this->actingAs($user)
            ->get(route('exam_dates.show', $params))
            ->assertOk()
            ->getContent();
    }

    public function test_principal_show_has_class_dropdown_in_natural_order()
    {
        $html = $this->showHtml($this->principal());
        $options = $this->selectOptions($html, 'ds_class');
        $this->assertNotNull($options, 'Principal date sheet renders no #ds_class select');
        $this->assertSame(
            ['Prep', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'],
            $options,
            'Principal dropdown is not the full class list in natural order'
        );
        // Selector behaviour unchanged: GET form, class_id field, auto-submit.
        $this->assertStringContainsString('<select id="ds_class" name="class_id"', $html);
    }

    public function test_principal_class_switch_via_dropdown_value_renders_that_class()
    {
        $class3 = \App\Models\MyClass::where('name', 'Class 3')->firstOrFail();
        $html = $this->showHtml($this->principal(), ['class_id' => $class3->id]);
        $this->assertStringContainsString('Class: <strong>Class 3</strong>', $html);
        // The dropdown still marks the chosen class selected.
        $this->assertMatchesRegularExpression(
            '/<select[^>]*id="ds_class"[^>]*>.*?<option[^>]*value="' . $class3->id . '"[^>]*selected/s',
            $html
        );
    }

    public function test_admin_dropdown_unchanged_full_natural_order()
    {
        $options = $this->selectOptions($this->showHtml($this->admin()), 'ds_class');
        $this->assertNotNull($options, 'Admin date sheet lost its #ds_class select');
        $this->assertSame(
            ['Prep', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'],
            $options
        );
    }

    public function test_teacher_dropdown_unchanged_seeded_scope()
    {
        $options = $this->selectOptions($this->showHtml($this->teacher()), 'ds_class');
        $this->assertNotNull($options, 'Teacher date sheet lost its #ds_class select');
        // Seeded demo-teacher scope (OwnerOvernightPrintTest item 13).
        $this->assertSame(['Prep', 'Class 3', 'Class 6', 'Class 7', 'Class 9', 'Class 12'], $options);
    }
}
