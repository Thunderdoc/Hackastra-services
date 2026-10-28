<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptm_slots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('my_class_id');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });

        Schema::create('ptm_bookings', function (Blueprint $table) {
            $table->id();
            // One booking per slot, enforced at the DB level: a second
            // booking for the same slot cannot be inserted, even under
            // a race between two concurrent requests.
            $table->unsignedBigInteger('ptm_slot_id')->unique();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('student_user_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptm_bookings');
        Schema::dropIfExists('ptm_slots');
    }
};
