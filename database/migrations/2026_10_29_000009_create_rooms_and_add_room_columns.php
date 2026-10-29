<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3 (master prompt, owner order 2026-10-29): rooms and labs.
 * A room is a physical space (classroom or lab) with a seat
 * capacity. A subject may require a lab; a timetable cell may
 * carry the room it runs in. room_id carries NO foreign key —
 * the substitutions.time_table_id precedent: grids must never
 * be blocked by a referential cleanup, and room deletion is
 * guarded in the controller instead.
 */
class CreateRoomsAndAddRoomColumns extends Migration
{
    public function up()
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('kind', 16)->default('classroom'); // classroom|lab
            $table->unsignedInteger('capacity')->default(0);
            $table->timestamps();
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->boolean('needs_lab')->default(false);
        });

        Schema::table('time_tables', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('time_tables', function (Blueprint $table) {
            $table->dropColumn('room_id');
        });
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('needs_lab');
        });
        Schema::dropIfExists('rooms');
    }
}
