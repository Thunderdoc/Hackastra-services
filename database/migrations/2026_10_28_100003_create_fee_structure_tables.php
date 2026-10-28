<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('fee_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('my_class_id')->constrained('my_classes');
            $table->string('title');
            $table->integer('amount');
            $table->string('year');
            $table->timestamps();
        });

        Schema::create('fee_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_head_id')->constrained('fee_heads')->cascadeOnDelete();
            $table->integer('installment_no');
            $table->date('due_date')->nullable();
            $table->integer('amount');
            $table->timestamps();
        });

        Schema::create('concessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_record_id')->constrained('student_records');
            $table->foreignId('fee_head_id')->constrained('fee_heads')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('value', 10, 2);
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('concessions');
        Schema::dropIfExists('fee_installments');
        Schema::dropIfExists('fee_heads');
    }
};
