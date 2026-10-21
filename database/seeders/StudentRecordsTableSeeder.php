<?php

namespace Database\Seeders;

use App\Models\Section;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
        $this->createManyStudentRecords(3);
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

    protected function createManyStudentRecords(int $count)
    {
        $sections = Section::all();

        foreach ($sections as $section){
          User::factory()
                ->has(
                    StudentRecord::factory()
                    ->state([
                    'section_id' => $section->id,
                    'my_class_id' => $section->my_class_id,
                    'user_id' => function(User $user){
                        return ['user_id' => $user->id];
                    },
                ]), 'student_record')
                ->count($count)
                ->create([
                    'user_type' => 'student',
                    'password' => Hash::make('student'),
                ]);
        }

    }

    protected function createStudentRecord()
    {
        $section = Section::first();

        $user = User::factory()->create([
            'name' => 'Student CJ',
            'user_type' => 'student',
            'username' => 'student',
            'password' => Hash::make('cj'),
            'email' => 'student@student.com',

        ]);

        StudentRecord::factory()->create([
            'my_class_id' => $section->my_class_id,
            'user_id' => $user->id,
            'section_id' => $section->id
        ]);
    }
}
