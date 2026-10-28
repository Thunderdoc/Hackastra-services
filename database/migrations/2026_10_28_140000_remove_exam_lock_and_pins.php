<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Owner order 2026-10-28: Exam Lock removed completely. The PIN
 * system was already retired from routes/menus/pages (Workstream
 * BH, 2026-10-21) and only its dormant code, the lock_exam setting
 * and the settings-page toggle remained — this migration drops the
 * pins table and deletes the setting row for good. Results are
 * gated by login + the per-exam results_published switch only.
 */
class RemoveExamLockAndPins extends Migration
{
    public function up()
    {
        Schema::dropIfExists('pins');
        DB::table('settings')->where('type', 'lock_exam')->delete();
    }

    public function down()
    {
        Schema::create('pins', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 40)->unique();
            $table->string('used')->default(0);
            $table->string('times_used')->default(0);
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('student_id')->nullable();
            $table->timestamps();
        });
        DB::table('settings')->updateOrInsert(
            ['type' => 'lock_exam'],
            ['description' => 0]
        );
    }
}
