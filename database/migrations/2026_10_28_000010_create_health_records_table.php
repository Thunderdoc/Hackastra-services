<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->date('visit_date');
            $table->text('complaint');
            $table->text('treatment')->nullable();
            $table->boolean('is_referred')->default(false);
            $table->string('referred_to')->nullable();
            $table->unsignedBigInteger('recorded_by');
            $table->timestamps();
            $table->index('student_id');
            $table->index('visit_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_records');
    }
};
