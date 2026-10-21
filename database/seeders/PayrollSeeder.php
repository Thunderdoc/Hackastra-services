<?php

namespace Database\Seeders;

use App\Models\Payroll;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo payrolls for October 2026 (Workstream AI).
 *
 * Covers the four named demo staff logins so the Payroll pages demo
 * well out of the box: the teacher's salary is already Paid, the rest
 * are Pending. Everyone else shows as "Not generated" on the index —
 * that is what the Bulk Generate button is for. Idempotent:
 * firstOrCreate on the (user_id, year, month) unique key, and the
 * whole seeder no-ops until the payrolls table exists.
 */
class PayrollSeeder extends Seeder
{
    public function run()
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('payrolls')) {
            return;
        }

        $creatorId = DB::table('users')->where('email', 'cj@cj.com')->value('id')
            ?: DB::table('users')->where('user_type', 'super_admin')->value('id');

        $rows = [
            // email, basic, allowances, deductions, status, paid day
            ['teacher@teacher.com', 45000, 6500, 2500, 'paid', 5],
            ['admin@admin.com', 60000, 8000, 4000, 'pending', null],
            ['accountant@accountant.com', 38000, 4500, 1800, 'pending', null],
            ['librarian@librarian.com', 28000, 3000, 1200, 'pending', null],
        ];

        foreach ($rows as [$email, $basic, $allowances, $deductions, $status, $paidDay]) {
            $userId = DB::table('users')->where('email', $email)->value('id');
            if (!$userId) {
                continue;
            }

            Payroll::firstOrCreate(
                ['user_id' => $userId, 'year' => 2026, 'month' => 10],
                [
                    'basic_salary' => $basic,
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                    'status' => $status,
                    'paid_at' => $paidDay ? Carbon::create(2026, 10, $paidDay, 10, 0, 0) : null,
                    'created_by' => $creatorId,
                ]
            );
        }
    }
}
