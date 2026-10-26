<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\StudyMaterial;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 evening owner reports:
 * (1) study-material uploads no longer create silent duplicate
 * rows for the same file; (2) the internal-marks N field is hidden
 * for the All mode (it is ignored there); (3) teacher leave apply
 * creates a pending request.
 */
class OwnerWave19Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    protected function materialPayload(array $files, string $title = 'DWD')
    {
        return [
            'title' => $title,
            'description' => 'WDWD',
            'my_class_id' => 1,
            'section_id' => 1,
            'subject_id' => 1,
            'files' => $files,
        ];
    }

    protected function materialCount()
    {
        return StudyMaterial::where('my_class_id', 1)->where('subject_id', 1)
            ->where('section_id', 1)->where('teacher_id', $this->teacher()->id)->count();
    }

    public function test_material_upload_skips_identical_files_in_one_request()
    {
        $before = $this->materialCount();
        $this->actingAs($this->teacher());
        $resp = $this->post(route('materials.store'), $this->materialPayload([
            UploadedFile::fake()->createWithContent('a.pdf', 'same-content'),
            UploadedFile::fake()->createWithContent('b.pdf', 'same-content'),
        ]));
        $resp->assertRedirect(route('materials.index'));

        $this->assertSame($before + 1, $this->materialCount());
        $this->assertTrue(StudyMaterial::where('title', 'DWD')->where('my_class_id', 1)->exists());
        $this->assertStringContainsString('duplicate', (string) $resp->getSession()->get('flash_success'));
    }

    public function test_material_upload_skips_already_uploaded_file()
    {
        $this->actingAs($this->teacher());
        $this->post(route('materials.store'), $this->materialPayload([
            UploadedFile::fake()->createWithContent('a.pdf', 'once-content'),
        ]))->assertRedirect(route('materials.index'));
        $afterFirst = $this->materialCount();

        $resp = $this->from(route('materials.create'))->post(route('materials.store'), $this->materialPayload([
            UploadedFile::fake()->createWithContent('a.pdf', 'once-content'),
        ]));
        $resp->assertRedirect();
        $this->assertSame($afterFirst, $this->materialCount());
        $this->assertStringContainsString('already uploaded', (string) $resp->getSession()->get('flash_danger'));
    }

    public function test_material_upload_keeps_numbering_for_different_files()
    {
        $this->actingAs($this->teacher());
        $this->post(route('materials.store'), $this->materialPayload([
            UploadedFile::fake()->createWithContent('a.pdf', 'content-one'),
            UploadedFile::fake()->createWithContent('b.pdf', 'content-two'),
        ]))->assertRedirect(route('materials.index'));

        $this->assertTrue(StudyMaterial::where('title', 'DWD')->where('my_class_id', 1)->exists());
        $this->assertTrue(StudyMaterial::where('title', 'DWD (2)')->where('my_class_id', 1)->exists());
    }

    public function test_internal_n_field_hidden_for_all_mode()
    {
        $this->actingAs($this->teacher());
        $this->get(route('assignments.internal_preview', 1))->assertOk()
            ->assertSee('avg_n_wrap', false)
            ->assertDontSee('Ignored for All', false);
    }

    public function test_teacher_leave_apply_creates_pending_request()
    {
        $this->actingAs($this->teacher());
        $this->from(route('leave.my'))->post(route('leave.store'), [
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-03',
            'reason' => 'Wave 19 test leave',
        ])->assertRedirect(route('leave.my'));

        $this->assertTrue(LeaveRequest::where('user_id', $this->teacher()->id)
            ->where('reason', 'Wave 19 test leave')->where('status', 'pending')->exists());
    }
}
