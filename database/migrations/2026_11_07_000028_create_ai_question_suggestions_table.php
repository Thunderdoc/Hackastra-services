<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 Slice 3 (design §2.2): the AI review queue. Nothing in this
 * table is printable — a suggestion reaches a paper or the bank
 * only after the requesting teacher reviews and approves it
 * (AiAssist product law: AI suggests, a named human confirms).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_question_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('my_class_id');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('question_paper_id')->nullable();
            $table->string('question_type', 10)->default('mcq');
            $table->text('question');
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('correct_index')->nullable();
            $table->text('model_answer')->nullable();
            $table->unsignedInteger('marks')->default(1);
            $table->string('chapter', 120)->nullable();
            $table->string('difficulty', 10)->nullable();
            $table->string('status', 12)->default('pending');
            $table->string('ai_model')->nullable();
            $table->json('prompt_context')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('bank_question_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_question_suggestions');
    }
};
