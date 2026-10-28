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
            $table->foreignId('dorm_id')->constrained('dorms')->cascadeOnDelete();
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
