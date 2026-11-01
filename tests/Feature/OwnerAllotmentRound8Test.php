<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent F round 8 — regression watch, negative-grep re-run.
 *
 * FINDING (F-R8-1): MarkController::teacherSectionIds() used a RAW
 * subjects.teacher_id check to decide whether a teacher "teaches a
 * subject" in a class. An override-only teacher (Ritu Sharma, id 44:
 * ZERO direct subjects, ZERO led sections, 5 overrides) therefore
 * got ZERO sections from teacherSectionIds() — and tabulation()
 * gates the SHEET itself on that list (line ~786), so Ritu was
 * 403'd out of the tabulation sheet for her own override section
 * even though marks entry (manage(), class-level canTeachClass
 * gate) lets her enter those very marks. She could enter marks
 * she could never view tabulated. The fix: a teacher counts as a
 * subject teacher for the class when they hold a direct subject OR
 * any override in that class — matching the method's own docblock
 * ("a subject teacher gets every section of the classes they
 * teach") and manage()'s class-level granularity. A pure section
 * leader (no subject in the class) still sees only led sections.
 *
 * Anchor: Ritu's overrides are (55,67), (58,67), (61,67) in Class
 * 11 (my_class_id 12) and (65,79), (71,79) in Class 12. Exam 1 is
 * the global Term 1 exam; Class 11 section A (55) has seeded
 * marks, so the sheet renders 200 once the gate passes.
 */
class OwnerAllotmentRound8Test extends TestCase
{
    use DatabaseTransactions;

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email)
    {
        return User::create([
            'name' => 'Probe Teacher R8',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    /** One of Ritu's live override triples: [section, subject_id]. */
    protected function rituOverride(): array
    {
        $row = DB::table('section_subject_teachers')
            ->where('teacher_id', $this->ritu()->id)
            ->orderBy('section_id')->orderBy('subject_id')->first();
        $this->assertNotNull($row, 'Ritu must hold an override in the seed');
        return [Section::findOrFail($row->section_id), (int) $row->subject_id];
    }

    public function test_override_teacher_can_view_tabulation_for_override_section()
    {
        // Section segment (2026-10-30): her override sections are
        // resolved live — the anchor pair moved with the seed.
        [$section] = $this->rituOverride();
        $this->actingAs($this->ritu())
            ->get(route('marks.tabulation', [1, $section->my_class_id, $section->id]))
            ->assertOk();
    }

    public function test_override_teacher_tabulation_picker_lists_override_class_sections()
    {
        $response = $this->actingAs($this->ritu())
            ->get(route('marks.tabulation'))
            ->assertOk();

        $sectionIds = collect($response->viewData('sections'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Every section of every class she holds an override in
        // (a subject teacher gets all sections of her classes).
        $scopeClassIds = Subject::whereIn('id', DB::table('section_subject_teachers')
            ->where('teacher_id', $this->ritu()->id)->distinct()->pluck('subject_id'))
            ->pluck('my_class_id')->unique()->all();
        $this->assertNotEmpty($scopeClassIds);
        foreach (Section::whereIn('my_class_id', $scopeClassIds)->pluck('id') as $sid) {
            $this->assertContains((int) $sid, $sectionIds, 'Picker must list section ' . $sid);
        }
        // But NOT a class she has nothing in (Prep, Class 11).
        $this->assertNotContains(1, $sectionIds);
        $this->assertNotContains(55, $sectionIds);
    }

    public function test_section_leader_without_subject_sees_only_led_sections()
    {
        $leader = $this->makeTeacher(
            'TEST/R8/' . strtoupper(Str::random(6)),
            'probe-r8-leader@example.com'
        );
        // Leader of Class 11-C (57) only; teaches no subject anywhere.
        Section::where('id', 57)->update(['teacher_id' => $leader->id]);
        // No memo reset needed: TeacherScope reads fresh on every
        // call since round 8 (the old static memo is what made this
        // test fail in combined runs — a stale [] memo for a reused
        // id — and could serve stale scopes under Apache prefork).

        // Led section: gate passes (sheet renders or redirects for
        // data reasons, but never 403).
        $led = $this->actingAs($leader)
            ->get(route('marks.tabulation', [1, 12, 57]));
        $this->assertNotEquals(403, $led->status());

        // Any other section of the same class: still refused.
        $this->actingAs($leader)
            ->get(route('marks.tabulation', [1, 12, 55]))
            ->assertForbidden();
    }

    public function test_direct_subject_teacher_still_sees_all_sections()
    {
        // Ananya teaches Prep English (subject 1) directly: all Prep
        // sections (1, 2, 3) must remain visible — no regression from
        // the override-aware widening.
        $response = $this->actingAs($this->teacher())
            ->get(route('marks.tabulation'))
            ->assertOk();

        $sectionIds = collect($response->viewData('sections'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains(1, $sectionIds);
        $this->assertContains(2, $sectionIds);
        $this->assertContains(3, $sectionIds);
    }
}
