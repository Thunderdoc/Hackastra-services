<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F4 Slice 0 (owner order 2026-11-07, design §2.1): the Question
 * Bank was MCQ-only — every row forced to carry options (JSON NOT
 * NULL) and a correct_index, so a short-answer or long-answer
 * question could not be stored at all. This additive extension
 * adds the type/chapter/difficulty/model-answer columns and
 * relaxes options/correct_index to NULL for non-MCQ rows. Every
 * existing row keeps its meaning: question_type defaults to
 * 'mcq', chapter/difficulty/model_answer start NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->string('chapter', 120)->nullable();
            $table->string('difficulty', 10)->nullable();
            $table->string('question_type', 10)->default('mcq');
            $table->text('model_answer')->nullable();
            $table->json('options')->nullable()->change();
            $table->unsignedTinyInteger('correct_index')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('question_bank_questions', function (Blueprint $table) {
            $table->json('options')->nullable(false)->change();
            $table->unsignedTinyInteger('correct_index')->nullable(false)->change();
            $table->dropColumn(['chapter', 'difficulty', 'question_type', 'model_answer']);
        });
    }
};
