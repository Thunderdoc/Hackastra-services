<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 Slice 1 (design §2.2): generated question papers. A paper is
 * a frozen SNAPSHOT (Quiz/QuizQuestion precedent): its questions
 * are copies in question_paper_questions, never live references,
 * so later bank edits/deletes cannot change a printed paper.
 * FK-less unsignedBigInteger columns, house style.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_papers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('my_class_id');
            $table->unsignedBigInteger('section_id')->nullable();
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('exam_id')->nullable();
            $table->string('title');
            $table->string('exam_label')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->unsignedInteger('total_marks')->default(0);
            $table->string('seed', 64);
            $table->json('spec');
            $table->string('status', 12)->default('draft');
            $table->boolean('allow_repeat_questions')->default(false);
            $table->string('pdf_path')->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->string('answer_key_pdf_path')->nullable();
            $table->timestamps();
        });

        Schema::create('question_paper_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('question_paper_id');
            $table->unsignedBigInteger('bank_question_id')->nullable();
            $table->string('source', 10)->default('bank');
            $table->string('section_key', 20);
            $table->unsignedInteger('position')->default(0);
            $table->string('question_type', 10)->default('mcq');
            $table->text('question');
            $table->json('options')->nullable();
            $table->unsignedTinyInteger('correct_index')->nullable();
            $table->text('model_answer')->nullable();
            $table->unsignedInteger('marks')->default(1);
            $table->string('chapter', 120)->nullable();
            $table->string('difficulty', 10)->nullable();
            $table->char('text_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_paper_questions');
        Schema::dropIfExists('question_papers');
    }
};
