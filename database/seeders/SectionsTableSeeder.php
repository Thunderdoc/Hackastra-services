<?php
namespace Database\Seeders;

use App\Models\MyClass;
use App\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class SectionsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('sections')->delete();
        $classIds = MyClass::pluck('id', 'name')->all();

        // Section plan, owner direction 2026-10-24: varied sets per
        // class — small in Prep, wider higher up (Class 11 runs A–I,
        // Class 12 runs A–M). Classes resolve by name, never by row
        // position, so class-order changes can never misassign them.
        $plan = [
            'Prep' => ['A', 'B', 'C'],
            'Class 1' => ['A', 'B', 'C', 'D'],
            'Class 2' => ['A', 'B', 'C', 'D'],
            'Class 3' => ['A', 'B', 'C', 'D', 'E'],
            'Class 4' => ['A', 'B', 'C', 'D'],
            'Class 5' => ['A', 'B', 'C', 'D', 'E'],
            'Class 6' => ['A', 'B', 'C', 'D', 'E'],
            'Class 7' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'Class 8' => ['A', 'B', 'C', 'D', 'E'],
            'Class 9' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'Class 10' => ['A', 'B', 'C', 'D', 'E', 'F', 'G'],
            'Class 11' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'],
            'Class 12' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'],
        ];

        $data = [];
        foreach ($plan as $className => $sections) {
            if (!isset($classIds[$className])) {
                continue;
            }
            foreach ($sections as $sec) {
                $data[] = ['name' => $sec, 'my_class_id' => $classIds[$className], 'active' => 1];
            }
        }

        // Class-teacher of each section = the teacher assigned to that
        // class in SubjectsTableSeeder's demo map (Workstream AT), so
        // TeacherScope's sections source names exactly the same
        // classes as its subjects source for every teacher.
        $classNames = MyClass::pluck('name', 'id')->all();
        $teacherIds = User::where('user_type', 'teacher')->pluck('id', 'email')->all();
        foreach ($data as &$row) {
            $email = SubjectsTableSeeder::demoTeacherEmailForClass($classNames[$row['my_class_id']] ?? null);
            $row['teacher_id'] = ($email && isset($teacherIds[$email])) ? $teacherIds[$email] : null;
        }
        unset($row);

        DB::table('sections')->insert($data);
    }
}
