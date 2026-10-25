<?php

namespace Tests\Feature;

use App\Models\BulkImportJob;
use App\Models\Lga;
use App\Models\Nationality;
use App\Models\StaffRecord;
use App\Models\State;
use App\Support\Bulk\BulkImportService;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 11 (staff bulk
 * edit): rows match on email — the staff code is NOT a stable key
 * (UserController@update regenerates it) — only filled cells
 * change, role/code/password/status are never editable in bulk,
 * admin accounts stay in the single User form, and a completed
 * edit job rolls back to the old values recorded at validation
 * time. Staff-add rollback (Wave 9 code, untested until now) is
 * covered here too.
 */
class OwnerWave11Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function makeStaffUser($email, $type = 'teacher', $phone = '08030000001')
    {
        $user = User::create([
            'name' => 'Bulk Edit Test ' . $type,
            'email' => $email,
            'phone' => $phone,
            'dob' => null,
            'gender' => 'Male',
            'address' => 'Test Address Lane',
            'user_type' => $type,
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'password' => Hash::make($type),
            'username' => 'TEST/W11/' . strtoupper(\Illuminate\Support\Str::random(6)),
        ]);
        StaffRecord::create([
            'user_id' => $user->id,
            'code' => 'TEST/W11/' . strtoupper(\Illuminate\Support\Str::random(6)),
            'emp_date' => '2024-09-01',
        ]);
        return $user;
    }

    /** Stage a CSV staff job through the real service pipeline. */
    protected function stageJob($mode, $csv)
    {
        $rel = 'bulk-imports-test/w11-' . $mode . '-' . \Illuminate\Support\Str::random(8) . '.csv';
        $tmp = sys_get_temp_dir() . '/' . basename($rel);
        file_put_contents($tmp, $csv);
        Storage::put($rel, $csv);

        $job = BulkImportJob::create([
            'user_id' => $this->admin()->id,
            'module' => 'staff',
            'mode' => $mode,
            'original_filename' => 'staff.csv',
            'stored_path' => $rel,
            'file_hash' => hash_file('sha256', $tmp),
            'status' => 'uploaded',
        ]);
        $error = app(BulkImportService::class)->stageAndValidate($job);
        $this->assertNull($error, $error ?? '');
        return [$job->fresh(), $rel, $tmp];
    }

    protected function applyJob($job)
    {
        $svc = app(BulkImportService::class);
        $this->assertTrue($svc->confirm($job->fresh(), $job->fresh()->confirm_token));
        while ($job->fresh()->status === 'applying') {
            $svc->applyChunk($job->fresh());
        }
        $this->assertSame('completed', $job->fresh()->status);
    }

    protected function editHeader()
    {
        return 'name,user_type,email,gender,phone,phone2,dob,address,nationality,state,lga,blood_group,emp_date';
    }

    public function test_staff_edit_stages_applies_and_shows_old_new()
    {
        $user = $this->makeStaffUser('w11-edit@example.com');
        $csv = $this->editHeader() . "\n"
            . ",,w11-edit@example.com,,08039998888,,,New Address Lane 12,,,,,\n";
        [$job, $rel, $tmp] = $this->stageJob('update', $csv);
        try {
            $this->assertSame('validated', $job->status);
            $this->assertSame(1, (int) $job->update_count);
            $row = $job->rows()->first();
            $this->assertSame('valid_update', $row->verdict);
            $this->assertSame('08030000001', $row->changes['phone']['old']);
            $this->assertSame('08039998888', $row->changes['phone']['new']);
            $this->assertSame('Test Address Lane', $row->changes['address']['old']);

            $this->applyJob($job);
            $this->assertSame('08039998888', $user->fresh()->phone);
            $this->assertSame('New Address Lane 12', $user->fresh()->address);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_edit_skips_unknown_email()
    {
        $csv = $this->editHeader() . "\n,,nobody-here@example.com,,08039998888,,,,,,,,\n";
        [$job, $rel, $tmp] = $this->stageJob('update', $csv);
        try {
            $this->assertSame('validated', $job->status);
            $this->assertSame(1, (int) $job->skip_count);
            $this->assertSame('unmatched', $job->rows()->first()->verdict);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_edit_ignores_role_change()
    {
        $user = $this->makeStaffUser('w11-role@example.com');
        $csv = $this->editHeader() . "\n"
            . ",Accountant,w11-role@example.com,,08039997777,,,,,,,,\n";
        [$job, $rel, $tmp] = $this->stageJob('update', $csv);
        try {
            $row = $job->rows()->first();
            $this->assertSame('valid_update', $row->verdict);
            $this->assertArrayNotHasKey('user_type', $row->changes);
            $this->applyJob($job);
            $this->assertSame('teacher', $user->fresh()->user_type);
            $this->assertSame('08039997777', $user->fresh()->phone);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_edit_refuses_admin_accounts()
    {
        $csv = $this->editHeader() . "\n,,admin@hackstra.app,,08039996666,,,,,,,,\n";
        [$job, $rel, $tmp] = $this->stageJob('update', $csv);
        try {
            $this->assertSame(1, (int) $job->error_count);
            $this->assertSame('invalid', $job->rows()->first()->verdict);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_update_rollback_restores_old_values()
    {
        $user = $this->makeStaffUser('w11-rollback@example.com');
        $csv = $this->editHeader() . "\n,,w11-rollback@example.com,,08039995555,,,,,,,,\n";
        [$job, $rel, $tmp] = $this->stageJob('update', $csv);
        try {
            $this->applyJob($job);
            $this->assertSame('08039995555', $user->fresh()->phone);

            $result = app(BulkImportService::class)->rollback($job->fresh());
            $this->assertSame(1, $result['rolled_back']);
            $this->assertSame([], $result['skipped']);
            $this->assertSame('rolled_back', $job->fresh()->status);
            $this->assertSame('08030000001', $user->fresh()->phone);
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_add_rollback_deletes_created_staff()
    {
        $nal = Nationality::first()->name;
        $state = State::first();
        $lga = Lga::where('state_id', $state->id)->first()->name;
        $csv = $this->editHeader() . "\n"
            . "Wave Eleven Newbie,Teacher,w11-newbie@example.com,Male,08031112222,,1990-04-12,"
            . "\"12 Allen Avenue, Ikeja\",{$nal},{$state->name},{$lga},,2024-09-01\n";
        [$job, $rel, $tmp] = $this->stageJob('add', $csv);
        try {
            $this->assertSame('validated', $job->status);
            $this->assertSame(1, (int) $job->create_count);
            $this->applyJob($job);

            $created = User::where('email', 'w11-newbie@example.com')->first();
            $this->assertNotNull($created);
            $this->assertNotNull(StaffRecord::where('user_id', $created->id)->first());

            $result = app(BulkImportService::class)->rollback($job->fresh());
            $this->assertSame(1, $result['rolled_back']);
            $this->assertNull(User::where('email', 'w11-newbie@example.com')->first());
        } finally {
            Storage::delete($rel);
            @unlink($tmp);
        }
    }

    public function test_staff_edit_routes_and_templates_render()
    {
        $this->actingAs($this->admin());
        $this->get(route('bulk.staff.edit'))->assertOk();
        $this->get(route('bulk.templates.staff_edit'))->assertOk()
            ->assertDownload('staff-bulk-edit-template.csv');
        $this->get(route('bulk.exports.staff'))->assertOk()
            ->assertDownload('staff-current-records.csv');
    }
}
