<?php
namespace Database\Seeders;

use App\Models\ClassType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class MyClassesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('my_classes')->delete();

        // Demo ladder, owner direction 2026-10-24: Prep through Class 12.
        // Class types follow the school ladder: Prep = Pre Nursery,
        // Classes 1-5 = Primary, 6-8 = Junior Secondary, 9-12 = Senior
        // Secondary. Types resolve by code, never by row position, so
        // the order of ClassTypesTableSeeder can never misassign them.
        $typeId = fn($code) => ClassType::where('code', $code)->value('id');

        $data = [
            ['name' => 'Prep', 'class_type_id' => $typeId('PN')],
            ['name' => 'Class 1', 'class_type_id' => $typeId('P')],
            ['name' => 'Class 2', 'class_type_id' => $typeId('P')],
            ['name' => 'Class 3', 'class_type_id' => $typeId('P')],
            ['name' => 'Class 4', 'class_type_id' => $typeId('P')],
            ['name' => 'Class 5', 'class_type_id' => $typeId('P')],
            ['name' => 'Class 6', 'class_type_id' => $typeId('J')],
            ['name' => 'Class 7', 'class_type_id' => $typeId('J')],
            ['name' => 'Class 8', 'class_type_id' => $typeId('J')],
            ['name' => 'Class 9', 'class_type_id' => $typeId('S')],
            ['name' => 'Class 10', 'class_type_id' => $typeId('S')],
            ['name' => 'Class 11', 'class_type_id' => $typeId('S')],
            ['name' => 'Class 12', 'class_type_id' => $typeId('S')],
            ];

        DB::table('my_classes')->insert($data);

    }
}
