<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling Engine Slice A (2026-10-23): the curriculum requirement
 * fields the staffing/allocation/timetable engines plan against.
 * Until now a subject row said only WHO teaches it — never HOW MUCH
 * teaching it needs. periods_per_week stays NULL until the school
 * configures it: "not configured" is an honest state the staffing
 * dashboard reports, never a silently invented number.
 */
class AddPlanningFieldsToSubjects extends Migration
{
    public function up()
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedTinyInteger('periods_per_week')->nullable();
            $table->string('subject_type', 20)->default('theory');
            $table->boolean('needs_double')->default(false);
        });
    }

    public function down()
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn(['periods_per_week', 'subject_type', 'needs_double']);
        });
    }
}
