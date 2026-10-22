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
        $this->createManyUsers( 3);
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
            'teacher'    => [1 => 'Rahul Verma', 2 => 'Priya Nair', 3 => 'Arun Prakash'],
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
