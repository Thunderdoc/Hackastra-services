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
        $c = MyClass::pluck('id')->all();

        $data = [
            ['name' => 'Gold', 'my_class_id' => $c[0], 'active' => 1],
            ['name' => 'Diamond', 'my_class_id' => $c[0], 'active' => 0],
            ['name' => 'Silver', 'my_class_id' => $c[1], 'active' => 1],
            ['name' => 'Lemon', 'my_class_id' => $c[1], 'active' => 0],
            ['name' => 'Bronze', 'my_class_id' => $c[2], 'active' => 1],
            ['name' => 'Silver', 'my_class_id' => $c[3], 'active' => 1],
            ['name' => 'Diamond', 'my_class_id' => $c[4], 'active' => 1],
            ['name' => 'Blue', 'my_class_id' => $c[5], 'active' => 1],
            ['name' => 'A', 'my_class_id' => $c[6], 'active' => 1],
            ['name' => 'A', 'my_class_id' => $c[7], 'active' => 1],
            ['name' => 'A', 'my_class_id' => $c[8], 'active' => 1],
            ['name' => 'A', 'my_class_id' => $c[9], 'active' => 1],
        ];

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
