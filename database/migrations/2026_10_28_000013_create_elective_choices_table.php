<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Student Elective Choice (Class 11/12) — 2026-10-28.
     * Own table only; no marks/tabulation/exam-scope column is touched.
     * No unique constraint on (student_record_id, subject_id): a
     * rejected row must not block a fresh pending re-pick, so the
     * one-live-row-per-subject rule is enforced in the controller.
     */
    public function up(): void
    {
        Schema::create('elective_choices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_record_id');
            $table->unsignedBigInteger('subject_id');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamps();
            $table->index(['student_record_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elective_choices');
    }
};
