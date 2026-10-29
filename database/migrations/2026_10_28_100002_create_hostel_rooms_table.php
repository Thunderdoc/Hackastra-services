<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostel_rooms', function (Blueprint $table) {
            $table->id();
            // dorms is a legacy increments() table (INT PK): the FK
            // column must be unsignedInteger — foreignId() would be
            // BIGINT and MariaDB refuses the constraint (errno 150),
            // which silently halted every later migration on the
            // live MariaDB while SQLite ignored the mismatch.
            $table->unsignedInteger('dorm_id');
            $table->foreign('dorm_id')->references('id')->on('dorms')->onDelete('cascade');
            $table->string('room_no');
            $table->unsignedInteger('capacity');
            $table->timestamps();

            $table->unique(['dorm_id', 'room_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hostel_rooms');
    }
};
