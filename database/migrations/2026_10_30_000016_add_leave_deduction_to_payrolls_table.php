<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave-on-payslip columns (owner orders 2026-10-30): a payroll
 * record now remembers the leave figures it was generated with —
 * how many approved leave days fell in its month, how many of
 * them were beyond the school's paid-leave quota, and the salary
 * deduction those extra days produced (already included in the
 * record's deductions total). Stored at generation time so the
 * printed payslip always agrees with the month it belongs to,
 * even after leave records or settings change later.
 */
class AddLeaveDeductionToPayrollsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('payrolls', 'leave_deduction')) {
            Schema::table('payrolls', function (Blueprint $table) {
                $table->decimal('leave_days', 6, 2)->nullable();
                $table->decimal('leave_extra_days', 6, 2)->nullable();
                $table->decimal('leave_deduction', 12, 2)->nullable();
            });
        }
    }

    public function down()
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropColumn(['leave_days', 'leave_extra_days', 'leave_deduction']);
        });
    }
}
