<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teacher slot preferences (owner ask 2026-11-06, audit gap G2:
 * "teacher ko sirf uske section ka slot hi choose karne ka option
 * aaye"). Until now a teacher had NO slot surface at all — every
 * timetable write was Admin-only. This table stores the slots a
 * teacher asks the generator to favour: one row per chosen
 * (weekday, period).
 *
 * The slot identity is (day NAME, period NUMBER) — the same
 * identity the generator itself places by (its day loop runs on
 * day names, its slot loop on the 1-based period index within
 * the day). time_slots rows cannot key this: they are per-record
 * rows, deleted and recreated on every regenerate, so a stored
 * time_slot_id would point at a different period after the next
 * run. (day, period) survives regeneration and means the same
 * thing in every grid the generator builds.
 *
 * Preferences are REQUESTS, never rules: the generator reads
 * them only as a soft tie-break key (see TimeTableController::
 * generate), so a preference can never override a clash, a cap
 * or a weekly target.
 */
class CreateTeacherSlotPreferencesTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('teacher_slot_preferences')) {
            Schema::create('teacher_slot_preferences', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id');
                $table->string('day', 16);
                $table->unsignedInteger('period');
                $table->timestamps();
                $table->unique(['user_id', 'day', 'period']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('teacher_slot_preferences');
    }
}
