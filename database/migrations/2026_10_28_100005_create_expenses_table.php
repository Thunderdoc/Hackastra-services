<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category', 100);
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->text('note')->nullable();
            // Users table uses increments(), so this is an unsigned int.
            // No FK constraint: mirrors the other Wave 1/2 tables, which
            // also skip FKs, so this migration stays independent.
            $table->unsignedInteger('recorded_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
