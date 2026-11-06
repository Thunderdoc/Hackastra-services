<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Concession;
use App\Models\FeeHead;
use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner order 2026-11-01: "Directly scrolling and searching for
 * a student is impossible" — every student picker walks
 * Class > Section > Student (only that section's ~40 students).
 * One shared feed (ajax get_section_students: active students,
 * name order, teacher-scoped to the writable-students rule) plus
 * an admission-number lookup that jumps the cascade straight to
 * the student. The class/section selects are filter-only —
 * student_record_id / student_id stays the single submitted
 * truth and every store flow is unchanged.
 */
class OwnerStudentPickerCascadeTest extends TestCase
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

    protected function nurse(): User
    {
        return User::where('email', 'nurse@hackstra.app')->firstOrFail();
    }

    /** The writable-students rule, re-derived independently. */
    protected function writableUserIds(User $teacher): array
    {
        $ids = TeacherScope::studentUserIds($teacher);
        if (Schema::hasTable('section_subject_teachers')) {
            $sectionIds = DB::table('section_subject_teachers')
                ->where('teacher_id', $teacher->id)->pluck('section_id')->all();
            if ($sectionIds) {
                $ids = array_merge($ids, StudentRecord::whereIn('section_id', $sectionIds)->pluck('user_id')->all());
            }
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function test_by_section_feed_returns_only_that_sections_students_in_name_order()
    {
        $expected = StudentRecord::where('section_id', 1)->where('grad', 0)->count();
        $this->assertGreaterThan(20, $expected, 'fixture: Prep A is a full section');

        $response = $this->actingAs($this->admin())->get(route('get_section_students', ['section_id' => 1]));
        $response->assertOk();
        $items = $response->json();
        $this->assertCount($expected, $items);

        $names = array_column($items, 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'the feed is in name order');

        foreach ($items as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('user_id', $item);
            $this->assertArrayHasKey('adm_no', $item);
            $record = StudentRecord::find($item['id']);
            $this->assertSame(1, (int) $record->section_id, 'no student from another section may leak in');
            $this->assertSame(0, (int) $record->grad);
            $this->assertSame($record->adm_no, $item['adm_no']);
        }

        // Students and parents never receive the roster feed.
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->actingAs($student)->get(route('get_section_students', ['section_id' => 1]))
            ->assertOk()->assertExactJson([]);
    }

    public function test_by_section_feed_scopes_a_teacher_to_her_writable_students()
    {
        $teacher = $this->teacher();
        $writable = $this->writableUserIds($teacher);

        // Prep A (section 1): Ananya is its class teacher — she
        // gets exactly her writable subset of the section.
        $response = $this->actingAs($teacher)->get(route('get_section_students', ['section_id' => 1]));
        $response->assertOk();
        $items = $response->json();
        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            $this->assertContains((int) $item['user_id'], $writable, 'a teacher feed may only carry writable students');
        }

        // A section in a class she has no part of (derived from
        // her real scope, not assumed): the feed is empty.
        $scopeClasses = TeacherScope::classIds($teacher);
        $foreignSection = null;
        foreach (\App\Models\Section::whereNotIn('my_class_id', $scopeClasses)->orderBy('id')->get() as $section) {
            $ids = StudentRecord::where('section_id', $section->id)->where('grad', 0)->pluck('user_id')
                ->map(function ($id) { return (int) $id; })->all();
            if ($ids && !array_intersect($ids, $writable)) {
                $foreignSection = $section;
                break;
            }
        }
        $this->assertNotNull($foreignSection, 'fixture: a section fully outside her scope must exist');
        $this->actingAs($teacher)->get(route('get_section_students', ['section_id' => $foreignSection->id]))
            ->assertOk()->assertExactJson([]);
    }

    public function test_adm_no_lookup_jumps_the_cascade_to_the_student()
    {
        $aarav = StudentRecord::whereHas('user', function ($q) {
            $q->where('email', 'student@hackstra.app');
        })->firstOrFail();

        $response = $this->actingAs($this->admin())
            ->get(route('get_student_by_adm_no', ['adm_no' => strtolower($aarav->adm_no)]));
        $response->assertOk();
        $found = $response->json();
        $this->assertSame($aarav->id, $found['id']);
        $this->assertSame((int) $aarav->my_class_id, $found['class_id']);
        $this->assertSame((int) $aarav->section_id, $found['section_id']);

        $this->actingAs($this->admin())
            ->get(route('get_student_by_adm_no', ['adm_no' => 'NO/SUCH/NUMBER']))
            ->assertOk()->assertExactJson([]);
    }

    /**
     * Every converted form: the cascade is on the page and the
     * giant pre-rendered student list is gone (option count stays
     * in the dozens, not the thousands).
     */
    public function test_picker_pages_carry_the_cascade_without_a_giant_list()
    {
        $pages = [
            'certificates.create' => $this->admin(),
            'documents.create' => $this->admin(),
            'fee_structure.concessions.create' => $this->admin(),
            'transport.assignments' => $this->admin(),
            // The hostel is the warden's alone — even the Admin is
            // refused there by design, so the warden opens this one.
            'hostel.allocations.create' => $this->warden(),
            // Health Records moved to the Nurse login (owner,
            // 2026-11-06) — the nurse opens this one.
            'health_records.create' => $this->nurse(),
            'discipline_records.create' => $this->admin(),
        ];
        foreach ($pages as $route => $actor) {
            $response = $this->actingAs($actor)->get(route($route));
            $response->assertOk();
            $html = $response->getContent();
            $this->assertStringContainsString('hk-cascade-class', $html, $route);
            $this->assertStringContainsString('hk-cascade-section', $html, $route);
            $this->assertStringContainsString('hk-cascade-student', $html, $route);
            $this->assertStringContainsString('get_section_students', $html, $route . ' must include the cascade feed wiring');
            $this->assertLessThan(400, substr_count($html, '<option'),
                $route . ' must not pre-render a giant student list');
        }
    }

    protected function warden(): User
    {
        return User::factory()->create(['user_type' => 'hostel']);
    }

    public function test_certificates_deep_link_preselects_through_the_cascade()
    {
        $sr = StudentRecord::where('section_id', 64)->where('grad', 0)->firstOrFail();
        $response = $this->actingAs($this->admin())
            ->get(route('certificates.create', ['student' => Qs::hash($sr->id)]));
        $response->assertOk();
        $response->assertSee('data-preselect-class="' . $sr->my_class_id . '"', false);
        $response->assertSee('data-preselect-section="' . $sr->section_id . '"', false);
        $response->assertSee('data-preselect-value="' . $sr->id . '"', false);
    }

    public function test_concession_edit_preselects_its_student()
    {
        // The seed ships no fee heads — the fixture makes its own.
        $head = FeeHead::create([
            'my_class_id' => 1,
            'title' => 'Cascade Pin Head',
            'amount' => 5000,
            'year' => '2026-2027',
        ]);
        $sr = StudentRecord::where('grad', 0)->firstOrFail();
        $concession = Concession::create([
            'student_record_id' => $sr->id,
            'fee_head_id' => $head->id,
            'type' => 'amount',
            'value' => 500,
            'reason' => 'Cascade pin fixture',
            'approved_by' => $this->admin()->id,
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('fee_structure.concessions.edit', ['concession' => $concession->id]));
        $response->assertOk();
        $response->assertSee('hk-cascade-student', false);
        $response->assertSee('data-preselect-value="' . $sr->id . '"', false);
        $response->assertSee('data-preselect-section="' . $sr->section_id . '"', false);
    }

    public function test_teacher_health_and_discipline_class_lists_are_scoped()
    {
        // Her writable classes, derived from the same union the
        // features use — she teaches in several classes (Prep as
        // class teacher, Class 12 English among them), so the
        // foreign class is picked from outside that real set.
        $teacher = $this->teacher();
        $writableClassIds = StudentRecord::whereIn('user_id', $this->writableUserIds($teacher))
            ->pluck('my_class_id')->unique()->map(function ($id) { return (int) $id; })->all();
        $this->assertContains(1, $writableClassIds, 'fixture: Prep is hers');
        $foreign = \App\Models\MyClass::whereNotIn('id', $writableClassIds)->orderBy('id')->firstOrFail();

        // The full-scope writer differs per route: discipline is
        // the Admin's; health records moved to the Nurse login
        // (owner, 2026-11-06), so the nurse is the one who sees
        // every class there.
        $fullScopeActors = [
            'health_records.create' => $this->nurse(),
            'discipline_records.create' => $this->admin(),
        ];
        foreach ($fullScopeActors as $route => $fullScopeActor) {
            $response = $this->actingAs($teacher)->get(route($route));
            $response->assertOk();
            // Prep is hers (class teacher of Prep A)…
            $response->assertSee('<option value="1">Prep</option>', false);
            // …a class outside her scope is not even offered.
            $response->assertDontSee('<option value="' . $foreign->id . '">' . $foreign->name . '</option>', false);

            $fullPage = $this->actingAs($fullScopeActor)->get(route($route));
            $fullPage->assertSee('<option value="' . $foreign->id . '">' . $foreign->name . '</option>', false);
        }
    }
}
