<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Support\Allocation;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Teacher class allotment audit (2026-10-28), round 1 fixes.
 *
 * Three allotment sources — sections.teacher_id (class-teacher),
 * subjects.teacher_id (class-wide subject teacher) and
 * section_subject_teachers (per-section overrides) — with the
 * override winning per SectionTeacher::teacherIdFor. The audit
 * found the class-level scope and the planning loads ignored the
 * third source entirely, and the write paths accepted non-teacher
 * users. Seed anchors used here (verify, do not hardcode blindly
 * if the seed changes): Ritu Sharma (id 44, teacher20@teacher.com)
 * teaches ONLY through overrides — one English override in each
 * of classes 2/5/8/11/13 (sections 4/19/34/49/64) since the
 * section segment (2026-10-30) — 30 placed periods, zero
 * class-level subjects, zero led sections. Scope assertions
 * below are computed from her live override map.
 */
class OwnerAllotmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    public function test_override_only_teacher_has_class_scope_for_override_classes_only()
    {
        $ritu = $this->ritu();
        $this->assertEquals('teacher', $ritu->user_type);
        // No class-level attachment at all in the seed.
        $this->assertEquals(0, Subject::where('teacher_id', $ritu->id)->count());
        $this->assertEquals(0, Section::where('teacher_id', $ritu->id)->count());

        // Her scope is exactly the classes her overrides touch —
        // computed from the live map (section segment moved her
        // from classes 12/13 to 2/5/8/11/13), nothing more.
        $scopeClassIds = Subject::whereIn('id', DB::table('section_subject_teachers')
            ->where('teacher_id', $ritu->id)->distinct()->pluck('subject_id'))
            ->pluck('my_class_id')->unique()->all();
        $this->assertNotEmpty($scopeClassIds, 'Ritu must hold overrides in the seed');
        foreach (\App\Models\MyClass::orderBy('id')->pluck('id') as $classId) {
            $this->assertSame(
                in_array($classId, $scopeClassIds, true),
                TeacherScope::canTeachClass($ritu, $classId),
                'canTeachClass(' . $classId . ') must match her override map'
            );
        }
        $this->assertFalse(TeacherScope::canTeachClass($ritu, 1), 'Prep: no assignment, must stay closed');
    }

    public function test_override_only_teacher_can_open_students_list_and_marks_for_override_class()
    {
        // Resolve one of her real override triples from the live
        // map (section segment: her first is section 4 / subject 7).
        $row = DB::table('section_subject_teachers')
            ->where('teacher_id', $this->ritu()->id)
            ->orderBy('section_id')->orderBy('subject_id')->first();
        $this->assertNotNull($row, 'Ritu must hold an override in the seed');
        $section = Section::findOrFail($row->section_id);

        $this->actingAs($this->ritu())
            ->get(route('students.list', $section->my_class_id))
            ->assertOk();
        // Exam 1 = global Term 1.
        $this->actingAs($this->ritu())
            ->get(route('marks.manage', [1, $section->my_class_id, $section->id, $row->subject_id]))
            ->assertOk();
        // And the negative: a class she does not teach stays closed.
        $this->actingAs($this->ritu())
            ->get(route('marks.manage', [1, 1, 1, 1]))
            ->assertForbidden();
    }

    public function test_effective_loads_count_override_cells()
    {
        $loads = SectionTeacher::effectiveLoads();
        // Ananya (id 4): 36 placed cells (probe-verified).
        $this->assertEquals(36, $loads[4] ?? 0, 'Ananya effective load');
        // Ritu (id 44): 30 placed cells, zero class-level periods.
        $this->assertEquals(30, $loads[44] ?? 0, 'Ritu effective load');
        $this->assertEquals($loads, Allocation::teacherLoads(), 'Allocation engine must use the same loads');
    }

    public function test_staffing_report_shows_override_teacher_committed_load()
    {
        $report = Staffing::report();
        $row = collect($report['teachers'])->firstWhere('teacher.id', 44);
        $this->assertNotNull($row, 'Ritu must appear in the staffing report');
        $this->assertEquals(30, $row['committed'], 'Committed must be placed cells, not the old 0');
        $this->assertContains('English Language', $row['subjects'], 'Override subjects must be listed');
    }

    public function test_section_create_refuses_non_teacher_class_teacher()
    {
        $before = Section::count();
        // A real student user, resolved live (user 57 was a student
        // before the section segment grew the roster — it is a
        // teacher now, so never hardcode the id).
        $student = User::where('user_type', 'student')->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->post(route('sections.store'), [
            'name' => 'Probe Section',
            'my_class_id' => 1,
            'teacher_id' => Qs::hash($student->id),
        ])->assertSessionHasErrors('teacher_id');
        $this->assertEquals($before, Section::count(), 'No section may be created with a student as class-teacher');
    }

    public function test_subject_create_refuses_non_teacher_subject_teacher()
    {
        $before = Subject::count();
        $this->actingAs($this->admin())->post(route('subjects.store'), [
            'name' => 'Probe Subject',
            'my_class_id' => 1,
            'teacher_id' => Qs::hash(5), // parent@
        ])->assertSessionHasErrors('teacher_id');
        $this->assertEquals($before, Subject::count(), 'No subject may be allocated to a parent');
    }

    public function test_section_create_ignores_crafted_active_flag()
    {
        // Additional sections are born deletable (active defaults
        // to 0; only the class-creation default section is
        // active=1 and delete-protected). A crafted active=1 must
        // not be able to mint a protected section.
        $this->actingAs($this->admin())->post(route('sections.store'), [
            'name' => 'Probe Active Section',
            'my_class_id' => 1,
            'teacher_id' => Qs::hash(4),
            'active' => 1, // not a form field: must not be mass-assigned
        ]);
        $section = Section::where('name', 'Probe Active Section')->first();
        $this->assertNotNull($section, 'Valid create still works');
        $this->assertEquals(0, (int) $section->active, 'Crafted active=1 must not stick');
    }

    public function test_update_teachers_reports_skipped_non_teacher_entries()
    {
        // Section 55 (Class 11-A) subject 67 already carries a
        // seeded override — whoever holds it, a skipped invalid
        // entry must leave it untouched (the holder changed with
        // the section segment, so read it live).
        // NOTE: sections.teachers.update uses {id} (Route::bind
        // decodes it), so the id must be hashed — unlike
        // sections.edit which uses {section} and takes raw ids.
        $existing = (int) DB::table('section_subject_teachers')
            ->where('section_id', 55)->where('subject_id', 67)->value('teacher_id');
        $this->assertGreaterThan(0, $existing, 'Seed must hold an override for (55, 67)');
        $response = $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash(55)),
            ['teachers' => [67 => Qs::hash(5)]] // parent@, not a teacher
        );
        $response->assertRedirect(route('sections.index'));
        $flash = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('Ignored invalid entries', $flash);
        $this->assertStringContainsString('English Language', $flash);
        $this->assertEquals($existing, (int) DB::table('section_subject_teachers')
            ->where('section_id', 55)->where('subject_id', 67)->value('teacher_id'),
            'Skipped row must leave the existing override untouched');
    }

    public function test_override_only_teacher_dashboard_renders_with_correct_kpis()
    {
        // Regression: before the fix this 500'd (Eloquent
        // unique() over 'sec:'/'class:' strings when the teacher
        // leads no sections), and before the scope fix the KPIs
        // read 0/0/0 while Today's Periods showed real periods.
        $response = $this->actingAs($this->ritu())->get('/dashboard');
        $response->assertOk();
        $response->assertSee('My Subjects', false);
        // Her Total Students KPI is the distinct grad=0 student
        // count over her scope classes (subject classes + override
        // subjects' classes + led sections' classes) — computed
        // exactly as HomeController computes it, so an earlier
        // test's admission cannot stale the figure (1360 on the
        // untouched seed since the section segment: 160+160+240+
        // 280+520 over classes 2/5/8/11/13). The blade renders it
        // with number_format, so assert the formatted figure.
        $rituUser = $this->ritu();
        $scopeClassIds = Subject::where('teacher_id', $rituUser->id)->pluck('my_class_id')
            ->merge(Subject::whereIn('id', DB::table('section_subject_teachers')
                ->where('teacher_id', $rituUser->id)->distinct()->pluck('subject_id'))->pluck('my_class_id'))
            ->merge(Section::where('teacher_id', $rituUser->id)->pluck('my_class_id'))
            ->filter()->unique()->values();
        $expectedStudents = (int) \App\Models\StudentRecord::where('grad', 0)
            ->whereIn('my_class_id', $scopeClassIds)->distinct()->count('user_id');
        $this->assertGreaterThan(0, $expectedStudents);
        $response->assertSee(number_format($expectedStudents), false);
    }

    public function test_subject_teacher_with_no_led_sections_dashboard_renders()
    {
        // Same latent trap, pre-existing: a teacher with subjects
        // but no led sections (Manoj Kumar, teacher9@) also 500'd
        // on the assignments unique() before the collect() fix.
        $manoj = User::where('email', 'teacher9@teacher.com')->firstOrFail();
        $this->actingAs($manoj)->get('/dashboard')->assertOk();
    }

    public function test_apply_allocation_notes_kept_overrides()
    {
        // Subject 79 (Class 12 English): class teacher Ananya (4),
        // 13 section overrides. Moving it to teacher 8 (eligible
        // for English) must say the overrides stay put.
        $this->assertEquals(4, (int) Subject::find(79)->teacher_id);
        $response = $this->actingAs($this->admin())->post(route('planning.allocation.apply'), [
            'subject_id' => 79,
            'teacher_id' => 8,
            'override_cap' => 1,
            'override_reason' => 'probe',
        ]);
        $response->assertRedirect();
        $flash = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('keep their override teacher', $flash);
        $this->assertEquals(8, (int) Subject::find(79)->teacher_id);
        $this->assertEquals(13, DB::table('section_subject_teachers')
            ->where('subject_id', 79)->count(), 'Overrides must survive a class-teacher move');
    }

    public function test_section_destroy_removes_its_overrides()
    {
        $section = Section::create(['name' => 'Probe Del', 'my_class_id' => 1, 'teacher_id' => 4, 'active' => 0]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id, 'subject_id' => 1, 'teacher_id' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin())->delete(route('sections.destroy', $section->id));
        $this->assertEquals(0, DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->count(), 'No orphan overrides after section delete');
    }

    public function test_subject_destroy_removes_its_overrides()
    {
        $subject = Subject::create(['name' => 'Probe Del Subject', 'my_class_id' => 1, 'teacher_id' => 4, 'slug' => 'probe-del-subject', 'periods_per_week' => 6]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => $subject->id, 'teacher_id' => 12,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->admin())->delete(route('subjects.destroy', $subject->id));
        $this->assertEquals(0, DB::table('section_subject_teachers')
            ->where('subject_id', $subject->id)->count(), 'No orphan overrides after subject delete');
    }

    public function test_duplicate_override_puts_upsert_to_one_row()
    {
        // The (section, subject) unique index + upsert logic mean
        // a repeated matrix save can never duplicate an override.
        $put = fn () => $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash(55)),
            ['teachers' => [68 => Qs::hash(4)]]
        );
        $put()->assertRedirect(route('sections.index'));
        $put()->assertRedirect(route('sections.index'));
        $rows = DB::table('section_subject_teachers')
            ->where('section_id', 55)->where('subject_id', 68)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(4, (int) $rows->first()->teacher_id);
    }

    public function test_sections_index_shows_override_counts()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => 12]));
        $response->assertOk();
        $response->assertSee('Overrides', false);
        $response->assertSee('override', false);
    }
}
