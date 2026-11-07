<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-teacher workload caps (owner direction 2026-11-06:
 * "teacher capacity badhao" — caps stop being one global pair
 * for every teacher). Two NULLABLE columns on users:
 * max_periods_per_day / max_periods_per_week. NULL means "use
 * the school default" (Settings teacher_max_periods_day/week
 * via App\Support\Staffing) — the override wins only when set,
 * resolved in exactly one place (Staffing::capsFor), so the
 * generator, validator, allocation engine, planning apply and
 * the teacher's own Workload page can never disagree about a
 * teacher's cap. Set from the teacher's Edit User form; there is
 * deliberately no separate caps module.
 */
class AddTeacherCapsToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'max_periods_per_day')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('max_periods_per_day')->nullable();
            });
        }
        if (!Schema::hasColumn('users', 'max_periods_per_week')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedInteger('max_periods_per_week')->nullable();
            });
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['max_periods_per_day', 'max_periods_per_week']);
        });
    }
}
