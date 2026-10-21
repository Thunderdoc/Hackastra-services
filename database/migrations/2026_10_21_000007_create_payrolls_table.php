<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePayrollsTable extends Migration
{
    /**
     * Staff payroll (Workstream AI): one salary record per staff member
     * per month. net_pay is computed by the Payroll model on save
     * (basic + allowances - deductions) and stored so reports and
     * payslips never recompute it differently. Status tracking only —
     * there is no bank/payout gateway integration; "paid" means the
     * accounts team marked the salary as disbursed.
     */
    public function up()
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('allowances', 12, 2)->default(0);
            $table->decimal('deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('remarks')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'year', 'month']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payrolls');
    }
}
