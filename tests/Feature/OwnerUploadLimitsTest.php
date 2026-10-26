<?php

namespace Tests\Feature;

use App\Models\StudyMaterial;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Owner rule 2026-10-25: teachers may upload study materials up to
 * 200 MB per file (materials store is teacher-only, so the raise is
 * teacher-only by construction); student assignment submissions stay
 * capped at 2 MB. A real 200 MB file is proven live, not in tests —
 * here an 11 MB file proves the teacher rule cleared the old 10 MB
 * cap, and a 3 MB file proves the student cap bites.
 */
class OwnerUploadLimitsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_teacher_material_above_old_10mb_cap_passes()
    {
        $teacher = User::find(4); // Ananya Sharma, teaches English, Prep (class 1)
        $response = $this->actingAs($teacher)->post(route('materials.store'), [
            'title' => 'Probe big material',
            'my_class_id' => 1,
            'subject_id' => 1,
            'files' => [
                UploadedFile::fake()->create('big.pdf', 11000, 'application/pdf'),
            ],
        ]);
        $response->assertRedirect(route('materials.index'));
        $this->assertNotNull(StudyMaterial::where('title', 'Probe big material')->first());
    }

    public function test_student_submission_above_2mb_fails()
    {
        $student = User::where('email', 'student@hackstra.app')->first(); // Prep Section A
        $response = $this->actingAs($student)->post(route('assignments.submit', 1), [
            'attachment' => UploadedFile::fake()->create('big.pdf', 3000, 'application/pdf'),
        ]);
        $response->assertSessionHasErrors('attachment');
    }

    public function test_student_submission_small_file_passes()
    {
        $student = User::where('email', 'student@hackstra.app')->first();
        $response = $this->actingAs($student)->post(route('assignments.submit', 1), [
            'attachment' => UploadedFile::fake()->create('small.pdf', 1000, 'application/pdf'),
            'text_answer' => 'Probe answer',
        ]);
        $response->assertSessionHasNoErrors();
    }
}
