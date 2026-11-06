<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Item 4 (owner, 2026-11-01): the marks selector's dropdowns are
 * scoped to a teacher's assignments — what is not hers does not
 * SHOW. The feed is the selector's own endpoint
 * (marks.selector_options): sections = sections with at least one
 * enterable unit, subjects for a section = subjects she
 * effectively teaches there (SectionTeacher semantics — overrides
 * count, units overridden away from her do not). Admin unchanged.
 * The brief's example claimed she has no Class 12 unit; on this
 * seed she DOES teach Class 12 English (subject 79) in exactly
 * one section (76) — the pins use that real footprint and derive
 * a genuinely foreign class at runtime.
 */
class OwnerMarksSelectorScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function feed(User $as, array $params): array
    {
        return $this->actingAs($as)
            ->getJson(route('marks.selector_options', $params))
            ->assertOk()->json();
    }

    private function ids(array $rows): array
    {
        return array_map(function ($r) { return (int) $r['id']; }, $rows);
    }

    public function test_teacher_feed_for_class_12_lists_only_her_section_and_subject()
    {
        $teacher = $this->user('teacher@hackstra.app');

        $feed = $this->feed($teacher, ['class_id' => 13]);
        // Exactly one of Class 12's 13 sections is hers.
        $this->assertSame([76], $this->ids($feed['sections']));
        // Her only Class 12 subject is English (79).
        $this->assertSame([79], $this->ids($feed['subjects']));

        $forSection = $this->feed($teacher, ['class_id' => 13, 'section_id' => 76]);
        $this->assertSame([79], $this->ids($forSection['subjects']));

        // A section where every unit is overridden away from her:
        // no subjects at all (this is the pick that used to end in
        // "This subject is not assigned to you").
        $foreign = $this->feed($teacher, ['class_id' => 13, 'section_id' => 64]);
        $this->assertSame([], $this->ids($foreign['subjects']));
    }

    public function test_admin_feed_for_class_12_is_unscoped()
    {
        $admin = $this->user('admin@hackstra.app');

        $feed = $this->feed($admin, ['class_id' => 13, 'section_id' => 64]);
        $this->assertCount(13, $feed['sections']);
        $this->assertEqualsCanonicalizing(
            Subject::where('my_class_id', 13)->pluck('id')->map(function ($i) { return (int) $i; })->all(),
            $this->ids($feed['subjects'])
        );
    }

    public function test_teacher_index_class_options_are_her_enterable_classes_only()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $html = $this->actingAs($teacher)->get(route('marks.index'))->assertOk()->getContent();

        $this->assertStringContainsString('value="13">Class 12</option>', $html);
        $this->assertStringContainsString('value="1">Prep</option>', $html);
        // A class with no unit of hers offers no option at all.
        $this->assertStringNotContainsString('value="2">Class 1</option>', $html);
        $this->assertStringNotContainsString('value="11">Class 10</option>', $html);

        $adminHtml = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('marks.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="2">Class 1</option>', $adminHtml);
        $this->assertStringContainsString('value="11">Class 10</option>', $adminHtml);
    }

    public function test_an_override_only_unit_appears_in_her_options()
    {
        $teacher = $this->user('teacher@hackstra.app');
        // Class 2 (id 3): she has no unit there on the plain seed.
        $before = $this->feed($teacher, ['class_id' => 3]);
        $this->assertSame([], $this->ids($before['sections']));

        // Reassign one (section, subject) override of the class to
        // her — the allotment action itself (the seed's override
        // grid is dense: no pair is without a row). The subject is
        // one she does not teach at class level.
        $section = Section::where('my_class_id', 3)->orderBy('id')->firstOrFail();
        $subject = Subject::where('my_class_id', 3)
            ->where('teacher_id', '!=', $teacher->id)
            ->orderBy('id')->firstOrFail();
        DB::table('section_subject_teachers')->updateOrInsert(
            ['section_id' => $section->id, 'subject_id' => $subject->id],
            ['teacher_id' => $teacher->id]
        );

        $feed = $this->feed($teacher, ['class_id' => 3, 'section_id' => $section->id]);
        $this->assertContains($section->id, $this->ids($feed['sections']));
        $this->assertSame([$subject->id], $this->ids($feed['subjects']));

        // …and the class itself joins her selector options.
        $html = $this->actingAs($teacher)->get(route('marks.index'))->assertOk()->getContent();
        $this->assertStringContainsString('value="3">Class 2</option>', $html);
    }

    public function test_selector_page_uses_the_marks_feed_not_the_shared_one()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('marks.index'))->assertOk()->getContent();
        $this->assertStringContainsString('getMarkClassSubjects(this.value)', $html);
        $this->assertStringContainsString('refineMarkSubjects()', $html);
    }
}
