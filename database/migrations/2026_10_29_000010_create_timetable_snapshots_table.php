<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T4 — timetable grid snapshots (master prompt, owner order
 * 2026-10-29). A snapshot is a self-contained JSON copy of one
 * record's slots + cells, taken when a grid is approved and
 * before a regenerate replaces it, restorable as a NEW draft
 * record. ttr_id is a plain unsignedInteger with no foreign
 * key on purpose: a snapshot must survive its record being
 * deleted — the payload carries everything restore needs
 * (the substitutions.time_table_id precedent).
 */
class CreateTimetableSnapshotsTable extends Migration
{
    public function up()
    {
        Schema::create('timetable_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('ttr_id')->nullable()->index();
            $table->string('label', 160);
            $table->longText('payload');
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('timetable_snapshots');
    }
}
