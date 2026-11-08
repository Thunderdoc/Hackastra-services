<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call(BloodGroupsTableSeeder::class);
        $this->call(GradesTableSeeder::class);
        $this->call(DormsTableSeeder::class);
        $this->call(ClassTypesTableSeeder::class);
        $this->call(UserTypesTableSeeder::class);
        $this->call(MyClassesTableSeeder::class);
        $this->call(NationalitiesTableSeeder::class);
        $this->call(StatesTableSeeder::class);
        $this->call(LgasTableSeeder::class);
        $this->call(SettingsTableSeeder::class);
        $this->call(UsersTableSeeder::class);
        $this->call(SubjectsTableSeeder::class);
        $this->call(SectionsTableSeeder::class);
        $this->call(StudentRecordsTableSeeder::class);
        $this->call(SkillsTableSeeder::class);
        $this->call(DemoDataSeeder::class);
        // Right after the demo exams exist: fresh installs get
        // the assessment periods + exam links the migration
        // backfill only produces on upgrades (see the seeder).
        $this->call(AssessmentPeriodsTableSeeder::class);
        $this->call(PeriodAttendanceSeeder::class);
        $this->call(PayrollSeeder::class);
        $this->call(UserCardDataSeeder::class);
        $this->call(DemoBrandSeeder::class);
        $this->call(DemoCoverSeeder::class);
    }
}
