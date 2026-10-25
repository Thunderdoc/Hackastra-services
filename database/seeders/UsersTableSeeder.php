<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Helpers\Qs;
use Illuminate\Support\Str;

class UsersTableSeeder extends Seeder
{

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        DB::table('users')->delete();

        $this->createNewUsers();
        // Six generated accounts per type (teachers 1-6) so the
        // Prep–Class 12 ladder has a real staff room behind it.
        $this->createManyUsers( 6);
        // Owner ask 2026-10-25 (more teachers): eleven more
        // subject-specialist teachers (7-17) so the demo staff room
        // is 18 strong — three specialists per subject, matching
        // SubjectsTableSeeder's specialist rotation. Teacher-only
        // (unlike createManyUsers, which adds every type), so the
        // admin/accountant/parent counts — and the SA users list —
        // do not bloat with them.
        $this->createMoreTeachers();
    }

    protected function createMoreTeachers()
    {
        $names = [
            7 => 'Vikram Rao', 8 => 'Sunita Patil', 9 => 'Manoj Kumar',
            10 => 'Divya Menon', 11 => 'Rohit Shetty', 12 => 'Pooja Desai',
            13 => 'Arjun Mehta', 14 => 'Sneha Kulkarni', 15 => 'Karan Singh',
            16 => 'Anjali Gupta', 17 => 'Varun Nair',
        ];
        $data = [];
        foreach ($names as $i => $name) {
            $data[] = ['phone' => '+91 98110 00' . (200 + $i),
                'name' => $name,
                'email' => 'teacher' . $i . '@teacher.com',
                'user_type' => 'teacher',
                'username' => 'teacher' . $i,
                'password' => Hash::make('teacher'),
                'code' => strtoupper(Str::random(10)),
                'remember_token' => Str::random(10),
            ];
        }
        DB::table('users')->insert($data);
    }

    protected function createNewUsers()
    {
        $password = Hash::make('hackstra'); // Default user password

        $d = [

            ['name' => 'Ankit Kumar',
                'email' => 'superadmin@hackstra.app',
                'phone' => '+91 98110 00001',
                'username' => 'superadmin',
                'password' => $password,
                'user_type' => 'super_admin',
                'code' => strtoupper(Str::random(10)),
                'remember_token' => Str::random(10),
            ],

            ['name' => 'Kavita Rao',
            'email' => 'admin@hackstra.app',
                'phone' => '+91 98110 00002',
            'password' => $password,
            'user_type' => 'admin',
            'username' => 'admin',
            'code' => strtoupper(Str::random(10)),
            'remember_token' => Str::random(10),
            ],

            /* Principal demo login (owner ask, 2026-10-23): the
               school head's view of the product for client demos.
               The principal IS the school's Admin in Hackstra's
               role model, so this account carries admin rights —
               a clean, shareable credential separate from the
               operator's own admin@ login. */
            ['name' => 'Rajesh Iyer',
            'email' => 'principal@hackstra.app',
                'phone' => '+91 98110 00009',
            'password' => $password,
            'user_type' => 'admin',
            'username' => 'principal',
            'code' => strtoupper(Str::random(10)),
            'remember_token' => Str::random(10),
            ],

            ['name' => 'Ananya Sharma',
                'email' => 'teacher@hackstra.app',
                'phone' => '+91 98110 00003',
                'user_type' => 'teacher',
                'username' => 'teacher',
                'password' => $password,
                'code' => strtoupper(Str::random(10)),
                'remember_token' => Str::random(10),
            ],

            ['name' => 'Kabir Anand',
                'email' => 'parent@hackstra.app',
                'phone' => '+91 98110 00004',
                'user_type' => 'parent',
                'username' => 'parent',
                'password' => $password,
                'code' => strtoupper(Str::random(10)),
                'remember_token' => Str::random(10),
            ],

            ['name' => 'Joseph Mathew',
                'email' => 'accountant@hackstra.app',
                'phone' => '+91 98110 00005',
                'user_type' => 'accountant',
                'username' => 'accountant',
                'password' => $password,
                'code' => strtoupper(Str::random(10)),
                'remember_token' => Str::random(10),
            ],
        ];
        DB::table('users')->insert($d);
    }

    protected function createManyUsers(int $count)
    {
        $data = [];
        /* Audit fix (2026-10-23): users.phone was NULL for every
           seeded account, so the Manage Users Phone column rendered
           blank on all rows. Demo numbers from the 98110 001xx block. */
        $phoneSeq = 100;
        $user_type = Qs::getAllUserTypes(['super_admin', 'librarian', 'student']);

        // Realistic demo names per generated account (keyed by user type,
        // then by the account number). Emails, usernames and passwords are
        // unchanged — only the display names are human.
        $demo_names = [
            'admin'      => [1 => 'Sanjay Mehta', 2 => 'Deepa Iyer', 3 => 'Vikram Singh'],
            'teacher'    => [1 => 'Rahul Verma', 2 => 'Priya Nair', 3 => 'Arun Prakash', 4 => 'Kavya Reddy', 5 => 'Amit Sharma', 6 => 'Neha Gupta'],
            'accountant' => [1 => 'Meera Krishnan', 2 => 'Imran Sheikh', 3 => 'Lakshmi Prasad'],
            'parent'     => [1 => 'Sunita Devi', 2 => 'Mohammed Faiz', 3 => 'Geeta Pillai'],
        ];

        for($i = 1; $i <= $count; $i++){

            foreach ($user_type as $k => $ut){

                $data[] = ['phone' => '+91 98110 00' . $phoneSeq++,
                    'name' => $demo_names[$user_type[$k]][$i] ?? ucfirst($user_type[$k]).' '.$i,
                    'email' => $user_type[$k].$i.'@'.$user_type[$k].'.com',
                    'user_type' => $user_type[$k],
                    'username' => $user_type[$k].$i,
                    'password' => Hash::make($user_type[$k]),
                    'code' => strtoupper(Str::random(10)),
                    'remember_token' => Str::random(10),
                ];

            }

        }

        DB::table('users')->insert($data);
    }
}
