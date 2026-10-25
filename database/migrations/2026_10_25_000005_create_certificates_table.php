<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('student_record_id')->nullable();
            $table->enum('type', ['bonafide', 'transfer']);
            $table->string('serial', 30)->unique();
            $table->string('student_name', 150);
            $table->string('adm_no', 50)->nullable();
            $table->string('class_name', 100)->nullable();
            $table->string('section_name', 100)->nullable();
            $table->string('session', 20)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
