<?php

namespace Database\Seeders;

use App\Models\MyClass;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SubjectsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('subjects')->delete();

        $this->createSubjects();
    }

    /**
     * Demo teacher assignment per class (Workstream AT — seed
     * rebalance, owner decision 2026-10-21; ladder rebuilt 2026-10-24
     * for Prep–Class 12). Seven teacher accounts share the ladder so
     * no teacher carries more than two classes: with two subjects at
     * six periods a week each, two classes need 24 of the 40 weekly
     * slots, leaving the auto-generator room to place every period
     * without teacher clashes. SectionsTableSeeder reads this same
     * map for sections.teacher_id, so subject scope and class-teacher
     * scope can never drift apart.
     */
    protected static $teacherEmailByClass = [
        'Prep' => 'teacher@hackstra.app',
        'Class 1' => 'teacher1@teacher.com',
        'Class 2' => 'teacher2@teacher.com',
        'Class 3' => 'teacher3@teacher.com',
        'Class 4' => 'teacher4@teacher.com',
        'Class 5' => 'teacher5@teacher.com',
        'Class 6' => 'teacher6@teacher.com',
        'Class 7' => 'teacher@hackstra.app',
        'Class 8' => 'teacher1@teacher.com',
        'Class 9' => 'teacher2@teacher.com',
        'Class 10' => 'teacher3@teacher.com',
        'Class 11' => 'teacher4@teacher.com',
        'Class 12' => 'teacher5@teacher.com',
    ];

    public static function demoTeacherEmailForClass($className)
    {
        return static::$teacherEmailByClass[$className] ?? null;
    }

    protected function createSubjects()
    {
        $subjects = ['English Language', 'Mathematics'];
        $sub_slug = ['Eng', 'Math'];
        $teachers = User::where('user_type', 'teacher')->get()->keyBy('email');
        $fallback_teacher_id = User::where('user_type', 'teacher')->first()->id;
        $my_classes = MyClass::all();

        foreach ($my_classes as $my_class) {

            $email = static::demoTeacherEmailForClass($my_class->name);
            $teacher_id = ($email && isset($teachers[$email]))
                ? $teachers[$email]->id
                : $fallback_teacher_id;

            $data = [

                [
                    'name' => $subjects[0],
                    'slug' => $sub_slug[0],
                    'my_class_id' => $my_class->id,
                    'teacher_id' => $teacher_id,
                    // Demo curriculum requirements (Scheduling Engine
                    // Slice A): plausible weekly loads so the staffing
                    // dashboard demonstrates real demand/capacity math.
                    'periods_per_week' => 6,
                    'subject_type' => 'theory',
                ],

                [
                    'name' => $subjects[1],
                    'slug' => $sub_slug[1],
                    'my_class_id' => $my_class->id,
                    'teacher_id' => $teacher_id,
                    'periods_per_week' => 6,
                    'subject_type' => 'theory',
                ],

            ];

            DB::table('subjects')->insert($data);
        }

    }

}
