<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StudentRecordsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->createStudentRecord();
        // Owner direction 2026-10-24: a full demo school — about 40
        // students in every section, not a handful.
        $this->createManyStudentRecords(40);
        $this->backfillAdmissionNumbers();
    }

    protected function backfillAdmissionNumbers()
    {
        // Batch 6 (audit: receipt "Adm. No." was blank): the factories never
        // set adm_no, so every seeded student had a blank admission number
        // on receipts and marksheets. Mirror StudentRecordController@store:
        // the admission number is APP_CODE/ClassType/YearAdmitted/Number
        // and doubles as the student's username.
        $appCode = strtoupper(\App\Helpers\Qs::getAppCode());
        StudentRecord::with(['my_class.class_type', 'user'])
            ->where(function ($q) {
                $q->whereNull('adm_no')->orWhere('adm_no', '');
            })
            ->each(function ($sr) use ($appCode) {
                $ct = optional(optional($sr->my_class)->class_type)->code ?: 'N';
                $year = $sr->year_admitted ?: date('Y');
                do {
                    $admNo = $appCode . '/' . $ct . '/' . $year . '/' . mt_rand(1000, 99999);
                } while (StudentRecord::where('adm_no', $admNo)->exists());
                $sr->adm_no = $admNo;
                $sr->save();
                if ($sr->user && $sr->user->username !== $admNo) {
                    $sr->user->username = $admNo;
                    $sr->user->save();
                }
            });
    }

    /* Bulk insert (2026-10-24): the factory path (one Eloquent
       create per user + one per record, with events) runs at ~200
       students/minute — 3,040 students would take ~15 minutes, and
       the Render demo entrypoint seeds BEFORE Apache starts, so a
       slow seed risks the deploy never going live. Raw chunked
       inserts seed the same rows in seconds. Columns mirror the
       factories exactly (UserFactory + StudentRecordFactory), and
       adm_no/username follow backfillAdmissionNumbers' rule
       (APP_CODE/ClassType/Year/Number) with a running number instead
       of random retries, so uniqueness needs no per-row query. The
       backfill step below still runs for the one named student. */
    protected function createManyStudentRecords(int $count)
    {
        $sections = Section::with('my_class.class_type')->get();
        $session = \App\Helpers\Qs::getCurrentSession();
        $appCode = strtoupper(\App\Helpers\Qs::getAppCode());
        $password = Hash::make('student');
        $now = now();
        $faker = fake();
        $usedPhones = [];

        $userRows = [];
        $meta = [];
        $seq = 1000;
        foreach ($sections as $section) {
            $ctCode = optional(optional($section->my_class)->class_type)->code ?: 'N';
            for ($i = 0; $i < $count; $i++) {
                $admNo = $appCode . '/' . $ctCode . '/' . date('Y') . '/' . $seq++;
                $email = $faker->unique()->safeEmail;
                do {
                    $phone = '+91 ' . $faker->numerify('98########');
                } while (isset($usedPhones[$phone]));
                $usedPhones[$phone] = true;
                $userRows[] = [
                    'name' => $faker->name,
                    'phone' => $phone,
                    'email' => $email,
                    'username' => $admNo,
                    'password' => $password,
                    'user_type' => 'student',
                    // Deterministic gender + date of birth (NO
                    // faker draws — the name/email sequence other
                    // anchors depend on must not shift). Without
                    // these, every student report prints two
                    // permanently blank columns (owner: the PDFs
                    // look bekar). Age follows the class: Prep is
                    // 5, each higher class one year older.
                    'gender' => (($section->id + $i) % 2 === 0) ? 'Male' : 'Female',
                    // Month/day are SPREAD by multiplication, not
                    // lockstep: ($i % 12)+1 / ($i % 28)+1 printed
                    // 01 Jan, 02 Feb, 03 Mar... down the register —
                    // visibly fake in a demo (owner screenshot,
                    // 2026-10-29). The spread keeps every date
                    // deterministic with no faker draws.
                    'dob' => sprintf('%04d-%02d-%02d',
                        (int) date('Y') - (4 + (int) $section->my_class_id),
                        (($i * 5) % 12) + 1,
                        (($i * 7) % 28) + 1),
                    'code' => strtoupper(Str::random(10)),
                    'remember_token' => Str::random(10),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $meta[] = [
                    'email' => $email,
                    'section_id' => $section->id,
                    'my_class_id' => $section->my_class_id,
                    'adm_no' => $admNo,
                ];
            }
        }

        foreach (array_chunk($userRows, 500) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $ids = DB::table('users')
            ->whereIn('email', array_column($meta, 'email'))
            ->pluck('id', 'email');

        $recordRows = [];
        foreach ($meta as $m) {
            $recordRows[] = [
                'user_id' => $ids[$m['email']],
                'my_class_id' => $m['my_class_id'],
                'section_id' => $m['section_id'],
                'session' => $session,
                'adm_no' => $m['adm_no'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($recordRows, 500) as $chunk) {
            DB::table('student_records')->insert($chunk);
        }
    }

    protected function createStudentRecord()
    {
        $section = Section::first();

        $user = User::factory()->create([
            'name' => 'Aarav Sharma',
            'user_type' => 'student',
            'username' => 'student',
            'password' => Hash::make('hackstra'),
            'email' => 'student@hackstra.app',
            'gender' => 'Male',
            'dob' => '2020-08-17',

        ]);

        StudentRecord::factory()->create([
            'my_class_id' => $section->my_class_id,
            'user_id' => $user->id,
            'section_id' => $section->id
        ]);
    }
}
