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
     * for Prep–Class 12). The ladder below is the CLASS-TEACHER map:
     * SectionsTableSeeder reads it for sections.teacher_id, and it
     * is deliberately UNCHANGED by the 2026-10-25 subject expansion
     * — a class's section leaders stay the same teachers.
     *
     * Subject teaching is a DIFFERENT map (owner ask 2026-10-25,
     * more teachers + more subjects): six subjects per class, each
     * taught by one of three subject specialists, rotating across
     * classes in id order. With six periods a week per subject, a
     * specialist teaches 4–5 classes (24–30 of the 40 weekly slots),
     * leaving the auto-generator room to place every period without
     * teacher clashes. The demo staff room is 18 teachers: the 7
     * originals plus the 11 specialists UsersTableSeeder adds.
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

    /** Subject name => three specialist teacher emails, in rotation order. */
    protected static $specialistEmailBySubject = [
        'English Language' => ['teacher@hackstra.app', 'teacher1@teacher.com', 'teacher2@teacher.com'],
        'Mathematics' => ['teacher3@teacher.com', 'teacher4@teacher.com', 'teacher5@teacher.com'],
        'Science' => ['teacher6@teacher.com', 'teacher7@teacher.com', 'teacher8@teacher.com'],
        'Social Science' => ['teacher9@teacher.com', 'teacher10@teacher.com', 'teacher11@teacher.com'],
        'Hindi' => ['teacher12@teacher.com', 'teacher13@teacher.com', 'teacher14@teacher.com'],
        'Computer Science' => ['teacher15@teacher.com', 'teacher16@teacher.com', 'teacher17@teacher.com'],
    ];

    public static function demoTeacherEmailForClass($className)
    {
        return static::$teacherEmailByClass[$className] ?? null;
    }

    protected function createSubjects()
    {
        $subjects = ['English Language', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science'];
        $sub_slug = ['Eng', 'Math', 'Sci', 'Soc', 'Hin', 'CS'];
        $teachers = User::where('user_type', 'teacher')->get()->keyBy('email');
        $fallback_teacher_id = User::where('user_type', 'teacher')->first()->id;
        $my_classes = MyClass::orderBy('id')->get();

        foreach ($my_classes as $classIndex => $my_class) {
            $data = [];
            foreach ($subjects as $i => $subjectName) {
                $emails = static::$specialistEmailBySubject[$subjectName];
                $email = $emails[$classIndex % count($emails)];
                $teacher_id = isset($teachers[$email])
                    ? $teachers[$email]->id
                    : $fallback_teacher_id;

                $data[] = [
                    'name' => $subjectName,
                    'slug' => $sub_slug[$i],
                    'my_class_id' => $my_class->id,
                    'teacher_id' => $teacher_id,
                    // Demo curriculum requirements (Scheduling Engine
                    // Slice A): plausible weekly loads so the staffing
                    // dashboard demonstrates real demand/capacity math.
                    // Six subjects at six periods = 36 of the 40
                    // weekly slots, so a full day still has room.
                    'periods_per_week' => 6,
                    'subject_type' => 'theory',
                ];
            }

            DB::table('subjects')->insert($data);
        }

    }

}
