<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins for the demo gender/DOB backfill migration (2026-10-29
 * night): the persistent hosted demo was seeded before the
 * seeder filled Gender / Date of Birth, so its report columns
 * printed blank forever. The migration must (a) fill blanks for
 * the bundled demo school with the seeder's own deterministic
 * values — the named demo student keeps his canonical Male /
 * 2020-08-17 and does not shift the bulk sequence — and (b)
 * touch NOTHING for any other school name.
 */
class OwnerDemoGenderDobBackfillTest extends TestCase
{
    use DatabaseTransactions;

    protected function runBackfill(): void
    {
        require_once base_path('database/migrations/2026_10_29_000014_backfill_demo_student_gender_dob.php');
        (new \BackfillDemoStudentGenderDob())->up();
    }

    public function test_backfill_fills_blanks_and_keeps_the_named_student_canonical()
    {
        $aarav = User::where('email', 'student@hackstra.app')->firstOrFail();
        DB::table('users')->where('user_type', 'student')->update(['gender' => null, 'dob' => null]);

        $this->runBackfill();

        $this->assertSame(0, User::where('user_type', 'student')->whereNull('gender')->count());
        $this->assertSame(0, User::where('user_type', 'student')->whereNull('dob')->count());

        $aarav->refresh();
        $this->assertSame('Male', $aarav->gender);
        $this->assertStringStartsWith('2020-08-17', (string) $aarav->dob);

        // Bulk dates are spread, never the old 01 Jan / 02 Feb /
        // 03 Mar lockstep: in Prep A the first three bulk students
        // must not all carry month == day.
        $bulk = DB::table('users')->join('student_records', 'student_records.user_id', '=', 'users.id')
            ->where('student_records.section_id', 1)
            ->where('users.email', '!=', 'student@hackstra.app')
            ->orderBy('student_records.id')->limit(3)->pluck('users.dob')->all();
        $lockstep = collect($bulk)->every(function ($dob) {
            $p = explode('-', (string) $dob);
            return (int) $p[1] === (int) $p[2];
        });
        $this->assertFalse($lockstep, 'bulk DOBs fell back to the fake-looking lockstep pattern');
    }

    public function test_backfill_never_touches_a_real_schools_records()
    {
        Setting::updateOrCreate(['type' => 'system_name'], ['description' => 'Greenwood High School']);
        DB::table('users')->where('user_type', 'student')->update(['gender' => null, 'dob' => null]);

        $this->runBackfill();

        $this->assertGreaterThan(3000, User::where('user_type', 'student')->whereNull('gender')->count(),
            'a school that is not the bundled demo must keep its own blank records untouched');
    }
}
