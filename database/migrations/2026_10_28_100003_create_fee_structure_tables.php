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
            // my_classes is a legacy increments() table (INT PK):
            // unsignedInteger FK — foreignId() (BIGINT) makes
            // MariaDB refuse the constraint (errno 150).
            $table->unsignedInteger('my_class_id');
            $table->foreign('my_class_id')->references('id')->on('my_classes');
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
            // student_records + users are legacy increments() tables
            // (INT PKs): unsignedInteger FKs, same errno-150 reason.
            $table->unsignedInteger('student_record_id');
            $table->foreign('student_record_id')->references('id')->on('student_records');
            $table->foreignId('fee_head_id')->constrained('fee_heads')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('value', 10, 2);
            $table->string('reason')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users');
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
