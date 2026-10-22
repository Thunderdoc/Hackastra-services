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
     * rebalance, owner decision 2026-10-21). Teacher Chike keeps only
     * Nursery 1, Nursery 2 and Primary 1; every other class belongs to
     * one of the other seeded teacher accounts, so the demo obeys the
     * same assignment-scoping rules as a real school (a teacher sees
     * exactly the classes this map gives them — never all students).
     * SectionsTableSeeder reads this same map for sections.teacher_id,
     * so subject scope and class-teacher scope can never drift apart.
     */
    protected static $teacherEmailByClass = [
        'Nursery 1' => 'teacher@hackstra.app',
        'Nursery 2' => 'teacher@hackstra.app',
        'Primary 1' => 'teacher@hackstra.app',
        'Nursery 3' => 'teacher1@teacher.com',
        'JSS 3' => 'teacher1@teacher.com',
        'SSS 3' => 'teacher1@teacher.com',
        'Primary 2' => 'teacher2@teacher.com',
        'SSS 1' => 'teacher2@teacher.com',
        'JSS 2' => 'teacher3@teacher.com',
        'SSS 2' => 'teacher3@teacher.com',
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
                    'teacher_id' => $teacher_id
                ],

                [
                    'name' => $subjects[1],
                    'slug' => $sub_slug[1],
                    'my_class_id' => $my_class->id,
                    'teacher_id' => $teacher_id
                ],

            ];

            DB::table('subjects')->insert($data);
        }

    }

}
