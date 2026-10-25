<?php

namespace Tests\Feature;

use App\Models\BulkImportJob;
use App\Models\BulkImportRow;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 9 (bulk rollback):
 * a completed bulk job can be undone — created accounts are
 * deleted, edited values are restored from the old values recorded
 * at validation time — and rows that cannot be reversed safely are
 * skipped with a reason, never force-deleted.
 */
class OwnerWave9Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function makeStudentUser($suffix)
    {
        $user = User::create([
            'name' => 'Rollback Test ' . $suffix,
            'email' => null,
            'phone' => null,
            'dob' => null,
            'gender' => 'Male',
            'address' => 'Test Address',
            'user_type' => 'student',
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'password' => Hash::make('student'),
            'username' => 'TEST/RB/2026/' . $suffix,
        ]);
        $sr = StudentRecord::create([
            'user_id' => $user->id,
            'my_class_id' => 1,
            'section_id' => 1,
            'adm_no' => 'TEST/RB/2026/' . $suffix,
            'session' => '2026-2027',
            'year_admitted' => '2026',
        ]);
        return [$user, $sr];
    }

    protected function makeJob($module, $mode, $rows)
    {
        $job = BulkImportJob::create([
            'user_id' => $this->admin()->id,
            'module' => $module,
            'mode' => $mode,
            'original_filename' => 'test.csv',
            'stored_path' => 'bulk/test.csv',
            'file_hash' => hash('sha256', 'test'),
            'status' => 'completed',
            'total_rows' => count($rows),
        ]);
        foreach ($rows as $i => $row) {
            BulkImportRow::create(array_merge([
                'job_id' => $job->id,
                'row_number' => $i + 2,
                'raw' => [],
                'fingerprint' => hash('sha256', 'test-' . $job->id . '-' . $i),
                'verdict' => 'applied',
            ], $row));
        }
        return $job;
    }

    public function test_add_rollback_deletes_created_student()
    {
        [$user, $sr] = $this->makeStudentUser('00001');
        $job = $this->makeJob('students', 'add', [
            ['record_id' => $user->id],
        ]);

        $this->actingAs($this->admin())
            ->post(route('bulk.jobs.rollback', $job->id))
            ->assertRedirect(route('bulk.jobs.show', $job->id))
            ->assertSessionHas('flash_success');

        $this->assertNull(User::find($user->id));
        $this->assertNull(StudentRecord::find($sr->id));
        $this->assertSame('rolled_back', $job->fresh()->status);
        $this->assertSame('rolled_back', $job->rows()->first()->verdict);
    }

    public function test_add_rollback_skips_student_with_marks()
    {
        [$user, $sr] = $this->makeStudentUser('00002');
        Mark::create([
            'student_id' => $user->id,
            'exam_id' => 1,
            'subject_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'year' => '2026-2027',
            't1' => 10,
        ]);
        $job = $this->makeJob('students', 'add', [
            ['record_id' => $user->id],
        ]);

        $this->actingAs($this->admin())
            ->post(route('bulk.jobs.rollback', $job->id))
            ->assertSessionHas('flash_danger');

        // Nothing moved: account kept, job still completed, row still applied.
        $this->assertNotNull(User::find($user->id));
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('applied', $job->rows()->first()->verdict);
        $this->assertNotNull($job->rows()->first()->failure);
    }

    public function test_update_rollback_restores_old_values()
    {
        [$user, $sr] = $this->makeStudentUser('00003');
        $user->update(['name' => 'New Name']);
        $sr->update(['section_id' => 2]);

        $job = $this->makeJob('students', 'update', [[
            'record_id' => $user->id,
            'resolved' => [
                'sr_id' => $sr->id,
                'user_id' => $user->id,
                'user' => ['name' => 'New Name'],
                'record' => ['section_id' => 2],
                'old_user' => ['name' => 'Rollback Test 00003'],
                'old_record' => ['section_id' => 1],
            ],
        ]]);

        $this->actingAs($this->admin())
            ->post(route('bulk.jobs.rollback', $job->id))
            ->assertSessionHas('flash_success');

        $this->assertSame('Rollback Test 00003', User::find($user->id)->name);
        $this->assertSame(1, (int) StudentRecord::find($sr->id)->section_id);
        $this->assertSame('rolled_back', $job->fresh()->status);
    }

    public function test_update_rollback_without_old_values_is_skipped()
    {
        [$user, $sr] = $this->makeStudentUser('00004');
        $job = $this->makeJob('students', 'update', [[
            'record_id' => $user->id,
            'resolved' => [
                'sr_id' => $sr->id,
                'user_id' => $user->id,
                'user' => ['name' => 'New Name'],
                'record' => [],
            ],
        ]]);

        $this->actingAs($this->admin())
            ->post(route('bulk.jobs.rollback', $job->id))
            ->assertSessionHas('flash_danger');

        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame('applied', $job->rows()->first()->verdict);
    }

    public function test_rollback_refuses_unfinished_job()
    {
        $job = $this->makeJob('students', 'add', []);
        $job->update(['status' => 'applying']);

        $this->actingAs($this->admin())
            ->post(route('bulk.jobs.rollback', $job->id))
            ->assertSessionHas('flash_danger');

        $this->assertSame('applying', $job->fresh()->status);
    }
}
