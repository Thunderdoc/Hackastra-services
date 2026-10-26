<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-26 cross-section share: a
 * teacher teaching the same subject in 9-A and 9-C shares one
 * upload/assignment to the other section — server-side file
 * copy, no re-upload, no duplicates on re-share.
 */
class OwnerWave24Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->first();
    }

    protected function fixtures()
    {
        $class = MyClass::create(['name' => 'Wave24 Class']);
        $subject = Subject::create([
            'name' => 'W24 Subject', 'my_class_id' => $class->id, 'slug' => 'w24-' . $class->id,
            'teacher_id' => $this->teacher()->id, 'periods_per_week' => 6,
        ]);
        $a = Section::create(['name' => 'W24 A', 'my_class_id' => $class->id, 'active' => 1]);
        $b = Section::create(['name' => 'W24 B', 'my_class_id' => $class->id, 'active' => 1]);
        return [$class, $subject, $a, $b];
    }

    public function test_material_share_copies_file_without_reupload()
    {
        Storage::fake('public');
        Storage::disk('public')->put('materials/w24-notes.txt', 'shared notes');
        [$class, $subject, $a, $b] = $this->fixtures();
        $material = StudyMaterial::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => $class->id,
            'section_id' => $a->id, 'subject_id' => $subject->id,
            'title' => 'W24 Notes', 'file_path' => 'materials/w24-notes.txt',
            'file_hash' => md5('shared notes'),
        ]);

        $this->actingAs($this->teacher());
        $this->get(route('materials.share', $material->id))->assertOk()
            ->assertSee('W24 B', false);
        $resp = $this->post(route('materials.share.store', $material->id), ['section_ids' => [$b->id]]);
        $resp->assertRedirect(route('materials.index'));
        $this->assertStringContainsString('Shared to 1 section', (string) $resp->getSession()->get('flash_success'));

        $seeded = StudyMaterial::where('my_class_id', $class->id)->where('section_id', $b->id)->first();
        $this->assertNotNull($seeded);
        $this->assertSame('W24 Notes', $seeded->title);
        $this->assertSame(md5('shared notes'), $seeded->file_hash);
        $this->assertNotSame('materials/w24-notes.txt', $seeded->file_path);
        $this->assertTrue(Storage::disk('public')->exists($seeded->file_path));

        // Re-sharing the same section skips instead of duplicating.
        $resp = $this->post(route('materials.share.store', $material->id), ['section_ids' => [$b->id]]);
        $this->assertStringContainsString('already had it', (string) $resp->getSession()->get('flash_success'));
        $this->assertSame(1, StudyMaterial::where('my_class_id', $class->id)->where('section_id', $b->id)->count());
    }

    public function test_material_share_guards()
    {
        [$class, $subject, $a, $b] = $this->fixtures();
        $material = StudyMaterial::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => $class->id,
            'section_id' => $a->id, 'subject_id' => $subject->id, 'title' => 'W24 Guard',
        ]);
        // Another teacher cannot share (or even open) it.
        $this->actingAs($this->otherTeacher());
        $this->get(route('materials.share', $material->id))->assertForbidden();
        // The owner cannot seed it to a section where they do not teach the subject.
        $foreign = Section::create(['name' => 'W24 X', 'my_class_id' => 2, 'active' => 1]);
        $this->actingAs($this->teacher());
        $this->post(route('materials.share.store', $material->id), ['section_ids' => [$foreign->id]])->assertForbidden();
        // Whole-class material has nothing to share.
        $whole = StudyMaterial::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => $class->id,
            'section_id' => null, 'subject_id' => $subject->id, 'title' => 'W24 Whole',
        ]);
        $this->get(route('materials.share', $whole->id))->assertRedirect(route('materials.index'));
    }

    public function test_assignment_share_copies_attachment_with_no_submissions()
    {
        Storage::fake('public');
        Storage::disk('public')->put('assignments/w24-q.txt', 'question');
        [$class, $subject, $a, $b] = $this->fixtures();
        $assignment = Assignment::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => $class->id,
            'section_id' => $a->id, 'subject_id' => $subject->id,
            'title' => 'W24 Homework', 'max_marks' => 20,
            'due_at' => now()->addWeek(), 'attachment_path' => 'assignments/w24-q.txt',
        ]);

        $this->actingAs($this->teacher());
        $this->get(route('assignments.share', $assignment->id))->assertOk()
            ->assertSee('W24 B', false);
        $resp = $this->post(route('assignments.share.store', $assignment->id), ['section_ids' => [$b->id]]);
        $resp->assertRedirect(route('assignments.index'));

        $seeded = Assignment::where('my_class_id', $class->id)->where('section_id', $b->id)->first();
        $this->assertNotNull($seeded);
        $this->assertSame('W24 Homework', $seeded->title);
        $this->assertNotSame('assignments/w24-q.txt', $seeded->attachment_path);
        $this->assertTrue(Storage::disk('public')->exists($seeded->attachment_path));
        $this->assertSame(0, $seeded->submissions()->count());
    }
}
