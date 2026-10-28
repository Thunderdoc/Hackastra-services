<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discipline_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('recorded_by');
            $table->date('incident_date');
            $table->string('type');
            $table->text('description');
            $table->text('action_taken')->nullable();
            $table->timestamps();

            $table->index('student_id');
            $table->index('recorded_by');
            $table->index('incident_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discipline_records');
    }
};
