<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demo-data repair (owner live check, 2026-10-29 night): the
 * hosted demo database was seeded ONCE, before the student
 * seeder learned to fill Gender and Date of Birth — so every
 * student report on the live demo printed those two columns
 * blank, and because the database is persistent now, no future
 * deploy would ever reseed it. This migration fills ONLY blank
 * gender/dob values, ONLY for the bundled demo school
 * (system_name "Hackstra Academy"), with the same deterministic
 * spread the seeder now uses. A real school's own records —
 * blank or filled — are never touched: the name gate keeps this
 * a demo repair, and blanks a school entered deliberately stay
 * blank everywhere else.
 */
class BackfillDemoStudentGenderDob extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('student_records')) {
            return;
        }
        if (!Schema::hasColumn('users', 'gender') || !Schema::hasColumn('users', 'dob')) {
            return;
        }

        $schoolName = null;
        if (Schema::hasTable('settings')) {
            $schoolName = DB::table('settings')->where('type', 'system_name')->value('description');
        }
        if (trim((string) $schoolName) !== 'Hackstra Academy') {
            return;
        }

        $year = (int) date('Y');
        $bySection = DB::table('student_records')
            ->orderBy('section_id')->orderBy('id')
            ->get(['id', 'user_id', 'section_id', 'my_class_id'])
            ->groupBy('section_id');

        foreach ($bySection as $sectionId => $records) {
            $i = 0;
            foreach ($records as $rec) {
                $user = DB::table('users')->where('id', $rec->user_id)->first(['id', 'email', 'gender', 'dob']);
                if ($user) {
                    // The one named demo student is created by the
                    // seeder OUTSIDE the bulk loop, with canonical
                    // values — a fresh seed gives him Male /
                    // 2020-08-17, so the repair must too, and he
                    // must not consume a bulk index (the seeder's
                    // $i never counted him; counting him here
                    // would flip every Prep A parity vs a seed).
                    if ($user->email === 'student@hackstra.app') {
                        $updates = [];
                        if (empty($user->gender)) {
                            $updates['gender'] = 'Male';
                        }
                        if (empty($user->dob)) {
                            $updates['dob'] = '2020-08-17';
                        }
                        if ($updates) {
                            DB::table('users')->where('id', $rec->user_id)->update($updates);
                        }
                        continue;
                    }
                    $updates = [];
                    if (empty($user->gender)) {
                        $updates['gender'] = (((int) $sectionId + $i) % 2 === 0) ? 'Male' : 'Female';
                    }
                    if (empty($user->dob)) {
                        $updates['dob'] = sprintf(
                            '%04d-%02d-%02d',
                            $year - (4 + (int) $rec->my_class_id),
                            (($i * 5) % 12) + 1,
                            (($i * 7) % 28) + 1
                        );
                    }
                    if ($updates) {
                        DB::table('users')->where('id', $rec->user_id)->update($updates);
                    }
                }
                $i++;
            }
        }
    }

    public function down()
    {
        // Data repair only — filled values are indistinguishable
        // from real ones afterwards, so there is nothing safe to
        // reverse.
    }
}
