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
        // do not bloat with them. Batch 2026-10-25 (streams) adds
        // two commerce specialists (18-19), taking the room to 20.
        // Batch 2026-10-26 (streams staffing) adds thirteen more
        // (20-32), taking the room to 33: senior section grids are
        // staffed through per-section overrides (DemoDataSeeder),
        // and the extended families below are what those overrides
        // rotate — the class-level rotation in SubjectsTableSeeder
        // deliberately stays at three specialists so Prep–Class 10
        // assignments never move.
        $this->createMoreTeachers();
    }

    protected function createMoreTeachers()
    {
        $names = [
            7 => 'Vikram Rao', 8 => 'Sunita Patil', 9 => 'Manoj Kumar',
            10 => 'Divya Menon', 11 => 'Rohit Shetty', 12 => 'Pooja Desai',
            13 => 'Arjun Mehta', 14 => 'Sneha Kulkarni', 15 => 'Karan Singh',
            16 => 'Anjali Gupta', 17 => 'Varun Nair',
            // Batch 2026-10-25 (streams): commerce specialists for
            // the Class 11/12 Accountancy + Business Studies rows.
            18 => 'Suresh Iyer', 19 => 'Meera Joshi',
            // Batch 2026-10-26 (streams staffing): a school with 22
            // senior sections cannot staff full 6-period section
            // grids with 20 teachers — one teacher would need 78+
            // periods a week. Thirteen more specialists take the
            // room to 33 so every section grid fills without any
            // teacher passing 40 periods.
            20 => 'Ritu Sharma', 21 => 'Nikhil Rao', 22 => 'Farah Khan',
            23 => 'Deepak Yadav', 24 => 'Meera Nair', 25 => 'Sanjay Verma',
            26 => 'Asha Patil', 27 => 'Ravi Kumar', 28 => 'Kavita Singh',
            29 => 'Mohit Jain', 30 => 'Lakshmi Iyer', 31 => 'Vikash Gupta',
            32 => 'Neha Sharma',
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
               Limited principal role (owner order 2026-10-28):
               'principal' is its own user_type — school-wide read
               + designated approvals, NOT an admin account, so no
               settings, user writes, fee writes, or timetable edits
               ever pass for this login. */
            ['name' => 'Rajesh Iyer',
            'email' => 'principal@hackstra.app',
                'phone' => '+91 98110 00009',
            'password' => $password,
            'user_type' => 'principal',
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
            'admin'      => [1 => 'Sanjay Mehta', 2 => 'Deepa Iyer', 3 => 'Vikram Singh', 4 => 'Rohit Khanna', 5 => 'Anita Desai', 6 => 'Manoj Pillai'],
            'teacher'    => [1 => 'Rahul Verma', 2 => 'Priya Nair', 3 => 'Arun Prakash', 4 => 'Kavya Reddy', 5 => 'Amit Sharma', 6 => 'Neha Gupta'],
            'accountant' => [1 => 'Meera Krishnan', 2 => 'Imran Sheikh', 3 => 'Lakshmi Prasad'],
            'parent'     => [1 => 'Sunita Devi', 2 => 'Mohammed Faiz', 3 => 'Geeta Pillai'],
        ];

        for($i = 1; $i <= $count; $i++){

            foreach ($user_type as $k => $ut){

                /* One Admin per school (owner order 2026-10-29
                   night): the filler rounds used to mint six extra
                   Admin accounts (admin1..6@admin.com). A school
                   has exactly ONE Admin — the named admin@ account
                   above — so the filler slot now seeds a teacher
                   instead. Email/username stay on the old pattern
                   so every other seeded id and count is unchanged. */
                $rowType = $user_type[$k] === 'admin' ? 'teacher' : $user_type[$k];

                $data[] = ['phone' => '+91 98110 00' . $phoneSeq++,
                    'name' => $demo_names[$user_type[$k]][$i] ?? ucfirst($user_type[$k]).' '.$i,
                    'email' => $user_type[$k].$i.'@'.$user_type[$k].'.com',
                    'user_type' => $rowType,
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
