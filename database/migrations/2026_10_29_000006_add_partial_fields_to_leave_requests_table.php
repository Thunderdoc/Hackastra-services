<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial leave (owner order 2026-10-29): a leave need not swallow
 * whole days — half a day, or a few periods of one day, with the
 * SAME workflow (apply, bell, approve / emergency auto-approve,
 * system-arranged cover). day_part is null for a full-day leave;
 * 'first_half' covers periods 1-4 of the day, 'second_half'
 * periods 5-8, and 'periods' the explicit period_from..period_to
 * range. A partial leave is always a single day (start = end,
 * normalised at submit). Cover machinery (LeaveAutoCover /
 * LeaveCover) reads the window from LeaveRequest::periodWindow()
 * and only arranges / counts those periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('day_part', 20)->nullable();
            $table->unsignedTinyInteger('period_from')->nullable();
            $table->unsignedTinyInteger('period_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['day_part', 'period_from', 'period_to']);
        });
    }
};
