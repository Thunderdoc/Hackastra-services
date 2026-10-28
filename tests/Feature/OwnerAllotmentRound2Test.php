<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Models\MyClass;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Teacher class allotment audit, round 2 (2026-10-28).
 *
 * (1) Readiness: a section whose subjects have NO teacher
 * resolution (no class-wide subject teacher AND no override)
 * must be visible on the sections index BEFORE timetable
 * generation — stream-aware, so a PCM section is never flagged
 * for lacking a Commerce teacher. (2) Edge combinations:
 * dual-role teacher (class-teacher of one section + override
 * teacher of another in the same class); stale overrides
 * (subject the class does not offer) surfaced + clearable via
 * the existing matrix PUT; allotment changes leave historical
 * attendance sessions untouched.
 */
class OwnerAllotmentRound2Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher($code, $email)
    {
        return User::create([
            'name' => 'Probe Teacher',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function makeClassWithSections($className)
    {
        $class = MyClass::create(['name' => $className]);
        $a = Section::create(['name' => 'R2-A', 'my_class_id' => $class->id]);
        $b = Section::create(['name' => 'R2-B', 'my_class_id' => $class->id]);

        return [$class, $a, $b];
    }

    public function test_sections_index_flags_unresolved_subjects()
    {
        [$class, $section] = $this->makeClassWithSections('Probe Class R2');
        $teacher = $this->makeTeacher('PROBE-R2-1', 'probe-r2-1@example.com');
        Subject::create(['name' => 'Probe Staffed Subject', 'my_class_id' => $class->id, 'teacher_id' => $teacher->id, 'slug' => 'probe-staffed-r2']);
        Subject::create(['name' => 'Probe No Teacher Subject', 'my_class_id' => $class->id, 'teacher_id' => null, 'slug' => 'probe-unstaffed-r2']);

        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $class->id]));
        $response->assertOk();
        $unresolved = $response->viewData('unresolvedSubjects');
        $this->assertEquals(['Probe No Teacher Subject'], $unresolved[$section->id]);
        $response->assertSee('1 unassigned: Probe No Teacher Subject', false);
        $this->assertStringNotContainsString('unassigned: Probe Staffed Subject', $response->getContent());
    }

    public function test_streamed_section_flags_only_own_stream_subjects()
    {
        // Class 12 (my_class_id 13): section 64 = pcm, 66 = commerce.
        // Accountancy (85) is commerce-only; nulling its teacher
        // must flag commerce sections and NOT pcm sections.
        // (Seeded override (66,85) must go too, or it resolves.)
        Subject::where('id', 85)->update(['teacher_id' => null]);
        DB::table('section_subject_teachers')->where('section_id', 66)->where('subject_id', 85)->delete();
        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => 13]));
        $response->assertOk();
        $unresolved = $response->viewData('unresolvedSubjects');
        $this->assertContains('Accountancy', $unresolved[66]);
        $this->assertNotContains('Accountancy', $unresolved[64]);
    }

    public function test_common_subject_flags_every_section_of_the_class()
    {
        // English (79) is common to every Class 12 stream.
        // (Seeded overrides for 79 must go too, or they resolve.)
        Subject::where('id', 79)->update(['teacher_id' => null]);
        $class13Sections = Section::where('my_class_id', 13)->pluck('id');
        DB::table('section_subject_teachers')->whereIn('section_id', $class13Sections)->where('subject_id', 79)->delete();
        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => 13]));
        $unresolved = $response->viewData('unresolvedSubjects');
        $this->assertNotEmpty($unresolved);
        foreach ($unresolved as $sectionId => $names) {
            $this->assertContains('English Language', $names, "Section $sectionId must flag common English");
        }
    }

    public function test_dual_role_teacher_class_teacher_and_override_same_class()
    {
        [$class, $a, $b] = $this->makeClassWithSections('Probe Class R2 Dual');
        $teacher = $this->makeTeacher('PROBE-R2-2', 'probe-r2-2@example.com');
        $subject = Subject::create(['name' => 'Probe Dual Subject', 'my_class_id' => $class->id, 'teacher_id' => null, 'slug' => 'probe-dual-r2']);
        // Class-teacher of section A + override teacher of section B, same class.
        $a->update(['teacher_id' => $teacher->id]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $b->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(TeacherScope::canTeachClass($teacher, $class->id));
        $ids = TeacherScope::classIds($teacher);
        $this->assertEquals(1, collect($ids)->filter(function ($id) use ($class) { return (int) $id === (int) $class->id; })->count());
        $this->assertEquals($teacher->id, SectionTeacher::teacherIdFor($b->id, $subject));

        // And the override resolves the section-B row on the index.
        $response = $this->actingAs($this->admin())->get(route('sections.index', ['class_id' => $class->id]));
        $this->assertNotContains('Probe Dual Subject', $response->viewData('unresolvedSubjects')[$b->id]);
    }

    public function test_stale_override_visible_and_clearable()
    {
        // Override for Class 12 English (79) on a Class 11 section
        // (55): the edit matrix lists only Class 11 subjects, so
        // without the stale block this row is invisible + unremovable.
        DB::table('section_subject_teachers')->insert([
            'section_id' => 55, 'subject_id' => 79, 'teacher_id' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs($this->admin())->get(route('sections.edit', 55));
        $response->assertOk();
        $response->assertSee('Stale overrides', false);
        $response->assertSee('English Language', false);

        // Keep the section's valid overrides, clear only the stale one.
        $valid = DB::table('section_subject_teachers')->where('section_id', 55)
            ->where('subject_id', '!=', 79)->pluck('teacher_id', 'subject_id');
        $teachers = [];
        foreach ($valid as $subjectId => $teacherId) {
            $teachers[$subjectId] = Qs::hash($teacherId);
        }
        $response = $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash(55)),
            ['teachers' => $teachers, 'clear_stale' => [79]]
        );
        $response->assertRedirect(route('sections.index'));
        $this->assertStringContainsString('Cleared 1 stale override', $response->getSession()->get('flash_success'));
        $this->assertEquals(0, DB::table('section_subject_teachers')->where('section_id', 55)->where('subject_id', 79)->count());
        $this->assertGreaterThan(0, DB::table('section_subject_teachers')->where('section_id', 55)->count());
    }

    public function test_forged_clear_stale_cannot_delete_valid_override()
    {
        // Section 55's valid override (subject 67, Ritu 44) must
        // survive a clear_stale naming a class subject.
        $before = DB::table('section_subject_teachers')->where('section_id', 55)->where('subject_id', 67)->count();
        $this->assertEquals(1, $before);
        $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash(55)),
            ['teachers' => [67 => Qs::hash(44)], 'clear_stale' => [67]]
        );
        $this->assertEquals(1, DB::table('section_subject_teachers')->where('section_id', 55)->where('subject_id', 67)->count());
    }

    public function test_allotment_change_keeps_historical_sessions()
    {
        // Seeded session 11: Class 11-A section period (record 12,
        // section 55, Mathematics subject 69), teacher 24, 360 entries.
        $before = DB::table('attendance_sessions')->where('id', 11)->first();
        $this->assertEquals(24, (int) $before->teacher_id);
        $this->assertEquals(360, DB::table('attendance_session_entries')->where('session_id', 11)->count());

        // Re-assign the section's Mathematics override to Ananya (4).
        $valid = DB::table('section_subject_teachers')->where('section_id', 55)->pluck('teacher_id', 'subject_id');
        $teachers = [];
        foreach ($valid as $subjectId => $teacherId) {
            $teachers[$subjectId] = Qs::hash($teacherId);
        }
        $teachers[69] = Qs::hash(4);
        $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash(55)),
            ['teachers' => $teachers]
        );

        // Current resolution changed, history did not.
        $this->assertEquals(4, SectionTeacher::teacherIdFor(55, Subject::find(69)));
        $after = DB::table('attendance_sessions')->where('id', 11)->first();
        $this->assertEquals(24, (int) $after->teacher_id);
        $this->assertEquals(360, DB::table('attendance_session_entries')->where('session_id', 11)->count());

        // Same for a class-teacher change on the section.
        $newLead = $this->makeTeacher('PROBE-R2-3', 'probe-r2-3@example.com');
        $this->actingAs($this->admin())->put(route('sections.update', 55), [
            'name' => 'A', 'teacher_id' => Qs::hash($newLead->id),
        ]);
        $afterLead = DB::table('attendance_sessions')->where('id', 11)->first();
        $this->assertEquals(24, (int) $afterLead->teacher_id);
        $this->assertEquals(360, DB::table('attendance_session_entries')->where('session_id', 11)->count());
    }
}
