<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserTypesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $data = [
            ['title' => 'accountant', 'name' => 'Accountant', 'level' => 5],
            ['title' => 'parent', 'name' => 'Parent', 'level' => 4],
            ['title' => 'teacher', 'name' => 'Teacher', 'level' => 3],
            ['title' => 'admin', 'name' => 'Admin', 'level' => 2],
            /* Limited principal role (owner order 2026-10-28):
               level 2 like Admin, so the school Admin provisions
               principal accounts (level gate: type level must not
               be below the actor's) and sees them in the staff
               list — but the role's powers come from its own
               gates, never from team membership. */
            ['title' => 'principal', 'name' => 'Principal', 'level' => 2],
            ['title' => 'super_admin', 'name' => 'Super Admin', 'level' => 1],
            /* Audit R-15 (2026-10-23): this row shipped commented out,
               which made the Librarian role un-creatable from Manage
               Users — no type row, no dropdown option, for any role.
               Enabled at level 6 (below every existing type, so the
               level gates in UserController keep their meaning). */
            ['title' => 'librarian', 'name' => 'Librarian', 'level' => 6],
        ];
        DB::table('user_types')->insert($data);
    }
}
