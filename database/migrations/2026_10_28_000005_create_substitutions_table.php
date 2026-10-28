<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 feature 4 (Substitution): one row covers one timetable
 * period on one date — the absent teacher and the teacher covering
 * it. The (date, time_table_id) unique key enforces one substitute
 * per period per date; the clash check in the controller (not a
 * database rule) keeps a substitute from being double-booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('substitutions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('time_table_id');
            $table->date('date');
            $table->unsignedInteger('absent_teacher_id');
            $table->unsignedInteger('substitute_teacher_id');
            $table->unsignedInteger('created_by');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['date', 'time_table_id']);
            $table->index(['substitute_teacher_id', 'date']);
            $table->index(['absent_teacher_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('substitutions');
    }
};
