<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 20);
            $table->unsignedInteger('my_class_id')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('notes')->nullable();
            $table->string('session', 20)->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('my_class_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
