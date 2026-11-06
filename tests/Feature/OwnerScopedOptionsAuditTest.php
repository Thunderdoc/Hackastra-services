<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 5 (owner, 2026-11-01): scoped options site-wide — a
 * teacher's class/section/subject pickers offer only what she is
 * assigned, matching each feature's existing server-side scope.
 * Ananya's footprint anchors the pins: in Class 12 (id 13) she
 * effectively teaches English (79) in section 76 (M) ONLY; the
 * other twelve sections are overridden away from her, and the
 * stores refuse those pairs — so they must not be offered.
 */
class OwnerScopedOptionsAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_assignment_create_offers_only_her_effective_sections()
    {
        $html = $this->actingAs($this->teacher())
            ->get(route('assignments.create'))->assertOk()->getContent();

        $this->assertStringContainsString('>M — Class 12</option>', $html);
        $this->assertStringNotContainsString('>A — Class 12</option>', $html);
        // The cascade refill is the scoped variant, not the shared one.
        $this->assertStringContainsString('getClassSectionsScoped(this.value)', $html);
    }

    public function test_material_create_offers_only_her_effective_sections()
    {
        $html = $this->actingAs($this->teacher())
            ->get(route('materials.create'))->assertOk()->getContent();

        $this->assertStringContainsString('>M — Class 12</option>', $html);
        $this->assertStringNotContainsString('>A — Class 12</option>', $html);
        $this->assertStringContainsString('getClassSubjectsScoped(this.value)', $html);
    }

    public function test_quiz_create_offers_only_her_effective_sections()
    {
        $html = $this->actingAs($this->teacher())
            ->get(route('quizzes.create'))->assertOk()->getContent();

        $this->assertStringContainsString('value="76" data-class-id="13"', $html);
        $this->assertStringNotContainsString('value="64" data-class-id="13"', $html);
    }

    public function test_builder_options_for_a_teacher_are_her_sections_and_subjects()
    {
        $teacher = $this->teacher();
        $foreignSubject = Subject::where('my_class_id', 13)
            ->whereNotIn('id', SectionTeacher::unitSubjectIds($teacher))
            ->orderBy('id')->firstOrFail();

        $html = $this->actingAs($teacher)
            ->get(route('reports.builder'))->assertOk()->getContent();

        // Her section is in the map; a foreign section of her class is not.
        $this->assertStringContainsString('"id":76,"name":"M","my_class_id":13', $html);
        $this->assertStringNotContainsString('"id":64,"name":"A","my_class_id":13', $html);
        // Her subject is in the map; a classmate subject she does not teach is not.
        $this->assertStringContainsString('"id":79,', $html);
        $this->assertStringNotContainsString(
            '"id":' . $foreignSubject->id . ',"name":"' . $foreignSubject->name . '","my_class_id":13',
            $html
        );

        $adminHtml = $this->actingAs($this->admin())
            ->get(route('reports.builder'))->assertOk()->getContent();
        $this->assertStringContainsString('"id":64,"name":"A","my_class_id":13', $adminHtml);
        $this->assertStringContainsString(
            '"id":' . $foreignSubject->id . ',"name":"' . $foreignSubject->name . '","my_class_id":13',
            $adminHtml
        );
    }

    public function test_effective_feed_mode_narrows_only_when_asked_and_only_for_teachers()
    {
        $teacher = $this->teacher();

        $scoped = $this->actingAs($teacher)
            ->getJson(route('get_class_sections', [13]) . '?effective=1')->assertOk()->json();
        $this->assertSame([76], array_map(function ($r) { return (int) $r['id']; }, $scoped));

        // The shared default is untouched: same teacher, no opt-in,
        // gets the whole class's sections as every other page does.
        $plain = $this->actingAs($teacher)
            ->getJson(route('get_class_sections', [13]))->assertOk()->json();
        $this->assertCount(13, $plain);

        // The opt-in is inert for non-teachers.
        $adminScoped = $this->actingAs($this->admin())
            ->getJson(route('get_class_sections', [13]) . '?effective=1')->assertOk()->json();
        $this->assertCount(13, $adminScoped);

        $pair = $this->actingAs($teacher)
            ->getJson(route('get_class_subjects', [13]) . '?effective=1')->assertOk()->json();
        $this->assertSame([76], array_map(function ($r) { return (int) $r['id']; }, $pair['sections']));
        $subjectIds = array_map(function ($r) { return (int) $r['id']; }, $pair['subjects']);
        $this->assertContains(79, $subjectIds);
        $foreignSubject = Subject::where('my_class_id', 13)
            ->whereNotIn('id', SectionTeacher::unitSubjectIds($teacher))
            ->orderBy('id')->firstOrFail();
        $this->assertNotContains($foreignSubject->id, $subjectIds);
    }

    public function test_tabulation_feed_mode_matches_the_sheets_own_gate()
    {
        $teacher = $this->teacher();

        // She is a subject teacher of Class 12 (override), so the
        // tabulation gate allows EVERY section of the class - the feed
        // must offer all 13, unlike the marks-style effective feed.
        $tab = $this->actingAs($teacher)
            ->getJson(route('get_class_sections', [13]) . '?scope=tabulation')->assertOk()->json();
        $this->assertCount(13, $tab);

        // Pure class-teacher case, built as a fixture: make her lead
        // exactly one section of Class 2 (id 3), a class she teaches no
        // subject in. The tabulation feed then offers only that section,
        // exactly what the sheet's gate would accept.
        $led = \App\Models\Section::where('my_class_id', 3)->orderBy('id')->firstOrFail();
        \App\Models\Section::where('id', $led->id)->update(['teacher_id' => $teacher->id]);
        $tab2 = $this->actingAs($teacher)
            ->getJson(route('get_class_sections', [3]) . '?scope=tabulation')->assertOk()->json();
        $this->assertSame([$led->id], array_map(function ($r) { return (int) $r['id']; }, $tab2));

        // The mode is inert for non-teachers.
        $adminTab = $this->actingAs($this->admin())
            ->getJson(route('get_class_sections', [3]) . '?scope=tabulation')->assertOk()->json();
        $this->assertCount(\App\Models\Section::where('my_class_id', 3)->count(), $adminTab);
    }
}
