<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\ShareTargets;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Agent F round 19 — material SHARE after override removal.
 * Same family as round 18 (override removal: scope loss vs
 * ownership persistence), but share is a NEW surface, not an
 * edit of an existing row — so the round-18 principle says a
 * removed teacher must gain NO new surface. The question is
 * whether share already honours that, or is ownership-only.
 *
 * Code read first (StudyMaterialController::share/shareStore,
 * in F scope): BOTH methods gate on teacher ownership ONLY
 * ($material->teacher_id != Auth::id() → 403) — but the TARGET
 * list comes from ShareTargets::forSubject(Auth::id(), $subject,
 * $material->section_id), which filters sections of the same
 * class by SectionTeacher::teacherIdFor($section->id, $subject)
 * === $teacherId. So share is ownership-gated at the source and
 * effective-teacher-gated at the target: a removed teacher can
 * still OPEN the share page for her own row, but can only seed
 * sections she CURRENTLY teaches. These tests lock that shape.
 *
 * Fixture (fully fresh, rolls back): a fresh class with three
 * sections S, T2, T3 and one fresh subject directly taught by
 * teacher D. Fresh override teacher O holds override rows on
 * S and T2 only (NOT T3) and CREATES a material for (S, subject)
 * while the S override stands. Admin then removes the S
 * override via the real matrix save (blank clears, round 5).
 * After that removal: teacherIdFor(S) == D, teacherIdFor(T2)
 * == O, teacherIdFor(T3) == D — and O still holds class scope
 * via the surviving T2 override (removal is per-section, not
 * per-class), which is exactly why the share tests must be
 * per-target, not per-class.
 *
 * Route mechanics: materials.share / materials.share.store /
 * materials.destroy / materials.download all take the RAW
 * material id ({material} is not a hash-bound {id} param);
 * sections.teachers.update takes Qs::hash($section->id).
 */
class OwnerAllotmentRound19Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R19',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Fresh class + sections S/T2/T3 + subject directly taught by $direct. */
    protected function fixture(User $direct, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeShareClass R19' . $suffix]);
        $mkSection = function (string $name) use ($class, $direct) {
            return Section::create([
                'name' => $name,
                'my_class_id' => $class->id,
                'teacher_id' => $direct->id,
            ]);
        };
        $s = $mkSection('R19 probe S');
        $t2 = $mkSection('R19 probe T2');
        $t3 = $mkSection('R19 probe T3');
        $subject = Subject::create([
            'name' => 'Probe Share Subject R19' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
            'slug' => 'probe-share-r19' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $s, $t2, $t3, $subject];
    }

    protected function giveOverride(User $teacher, Section $section, Subject $subject): void
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** O creates a LINK material for (S, subject) while the S override stands. */
    protected function setupOverrideWithMaterial(User $override, MyClass $class, Section $section, Subject $subject, string $title): StudyMaterial
    {
        $this->assertSame($override->id, SectionTeacher::teacherIdFor($section->id, $subject));
        $this->actingAs($override)
            ->post(route('materials.store'), [
                'title' => $title,
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'link_url' => 'https://example.com/probe-r19',
            ])
            ->assertRedirect(route('materials.index'));
        $material = StudyMaterial::where('title', $title)->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $material->teacher_id);

        return $material;
    }

    /** O creates a FILE material for (S, subject) while the S override stands. */
    protected function setupOverrideWithFileMaterial(User $override, MyClass $class, Section $section, Subject $subject, string $title): StudyMaterial
    {
        Storage::fake('public');
        $this->assertSame($override->id, SectionTeacher::teacherIdFor($section->id, $subject));
        $this->actingAs($override)
            ->post(route('materials.store'), [
                'title' => $title,
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'files' => [UploadedFile::fake()->createWithContent('probe-r19.txt', 'probe content r19')],
            ])
            ->assertRedirect(route('materials.index'));
        $material = StudyMaterial::where('title', $title)->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $material->teacher_id);
        $this->assertNotNull($material->file_path);

        return $material;
    }

    /** Admin removes the override on $section via the real matrix save (blank clears). */
    protected function removeOverride(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), [
                'teachers' => [$subject->id => ''],
            ])
            ->assertRedirect(route('sections.index'));
        $this->assertDatabaseMissing('section_subject_teachers', [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
        ]);
    }

    public function test_share_targets_follow_current_teaching_after_partial_removal()
    {
        $d = $this->makeTeacher('PROBE-R19-D', 'probe-r19-d@example.com');
        $o = $this->makeTeacher('PROBE-R19-O', 'probe-r19-o@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'A');
        $this->giveOverride($o, $s, $subject);
        $this->giveOverride($o, $t2, $subject);
        $material = $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 share probe A');

        $this->removeOverride($s, $subject);

        // Resolution moved per-section: S fell back to D, T2 still O, T3 still D.
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject));
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($t2->id, $subject));
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($t3->id, $subject));
        // Scope persists via the surviving T2 override — removal is per-section.
        $this->assertSame([$class->id], TeacherScope::classIds($o));
        // The helper agrees: only T2 is a target.
        $this->assertSame([$t2->id], ShareTargets::forSubject($o->id, $subject, $s->id)->map(function ($sec) { return (int) $sec->id; })->all());

        // The share page itself still opens (ownership-only gate),
        // but its target list contains ONLY the still-taught section.
        $response = $this->actingAs($o)->get(route('materials.share', $material->id));
        $response->assertOk();
        $targetIds = collect($response->viewData('targets'))->map(function ($sec) { return (int) $sec->id; })->all();
        $this->assertSame([$t2->id], $targetIds);
    }

    public function test_share_to_untaught_section_is_refused_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R19-D2', 'probe-r19-d2@example.com');
        $o = $this->makeTeacher('PROBE-R19-O2', 'probe-r19-o2@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'B');
        $this->giveOverride($o, $s, $subject);
        $this->giveOverride($o, $t2, $subject);
        $material = $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 share probe B');

        $this->removeOverride($s, $subject);

        // T3 is a section O never taught: the target check 403s,
        // no seeded row appears there.
        $this->actingAs($o)
            ->post(route('materials.share.store', $material->id), ['section_ids' => [$t3->id]])
            ->assertForbidden();
        $this->assertDatabaseMissing('study_materials', [
            'section_id' => $t3->id,
            'subject_id' => $subject->id,
            'title' => 'R19 share probe B',
        ]);
    }

    public function test_share_to_still_taught_section_succeeds_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R19-D3', 'probe-r19-d3@example.com');
        $o = $this->makeTeacher('PROBE-R19-O3', 'probe-r19-o3@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'C');
        $this->giveOverride($o, $s, $subject);
        $this->giveOverride($o, $t2, $subject);
        $material = $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 share probe C');

        $this->removeOverride($s, $subject);

        // T2 is still taught via its surviving override: seeding
        // there is legitimate current teaching, not a stale surface.
        $this->actingAs($o)
            ->post(route('materials.share.store', $material->id), ['section_ids' => [$t2->id]])
            ->assertRedirect(route('materials.index'));
        $copy = StudyMaterial::where('section_id', $t2->id)
            ->where('subject_id', $subject->id)
            ->where('title', 'R19 share probe C')
            ->firstOrFail();
        $this->assertSame($o->id, (int) $copy->teacher_id);
        $this->assertSame($o->id, SectionTeacher::teacherIdFor($t2->id, $subject));
    }

    public function test_share_after_full_removal_is_refused_everywhere()
    {
        $d = $this->makeTeacher('PROBE-R19-D4', 'probe-r19-d4@example.com');
        $o = $this->makeTeacher('PROBE-R19-O4', 'probe-r19-o4@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'D');
        $this->giveOverride($o, $s, $subject);
        $this->giveOverride($o, $t2, $subject);
        $material = $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 share probe D');

        $this->removeOverride($s, $subject);
        $this->removeOverride($t2, $subject);
        $this->assertSame([], TeacherScope::classIds($o));
        $this->assertTrue(ShareTargets::forSubject($o->id, $subject, $s->id)->isEmpty());

        // No target survives: even the previously-taught T2 now 403s.
        $this->actingAs($o)
            ->post(route('materials.share.store', $material->id), ['section_ids' => [$t2->id]])
            ->assertForbidden();
        $this->assertDatabaseMissing('study_materials', [
            'section_id' => $t2->id,
            'subject_id' => $subject->id,
            'title' => 'R19 share probe D',
        ]);
    }

    public function test_download_own_material_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R19-D5', 'probe-r19-d5@example.com');
        $o = $this->makeTeacher('PROBE-R19-O5', 'probe-r19-o5@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'E');
        $this->giveOverride($o, $s, $subject);
        $material = $this->setupOverrideWithFileMaterial($o, $class, $s, $subject, 'R19 download probe E');

        $this->removeOverride($s, $subject);

        // Download gate is ownership (teacher_id == caller): her own
        // row still downloads after removal, like assignment edit.
        $this->actingAs($o)
            ->get(route('materials.download', $material->id))
            ->assertOk();
    }

    public function test_delete_own_material_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R19-D6', 'probe-r19-d6@example.com');
        $o = $this->makeTeacher('PROBE-R19-O6', 'probe-r19-o6@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'F');
        $this->giveOverride($o, $s, $subject);
        $material = $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 delete probe F');

        $this->removeOverride($s, $subject);

        // Destroy gate is ownership (own teacher, or admin): her own
        // row still deletes after removal.
        $this->actingAs($o)
            ->delete(route('materials.destroy', $material->id))
            ->assertRedirect(route('materials.index'));
        $this->assertDatabaseMissing('study_materials', ['id' => $material->id]);
    }

    public function test_create_for_removed_subject_is_refused()
    {
        $d = $this->makeTeacher('PROBE-R19-D7', 'probe-r19-d7@example.com');
        $o = $this->makeTeacher('PROBE-R19-O7', 'probe-r19-o7@example.com');
        [$class, $s, $t2, $t3, $subject] = $this->fixture($d, 'G');
        $this->giveOverride($o, $s, $subject);
        $this->setupOverrideWithMaterial($o, $class, $s, $subject, 'R19 create probe G');

        $this->removeOverride($s, $subject);

        // Regression control (round-6 gate): store re-validates via
        // teacherIdFor, now D — new creation for S is refused.
        $this->actingAs($o)
            ->post(route('materials.store'), [
                'title' => 'R19 create probe G second',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $s->id,
                'subject_id' => $subject->id,
                'link_url' => 'https://example.com/probe-r19-second',
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing('study_materials', ['title' => 'R19 create probe G second']);
    }
}
