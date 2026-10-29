<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks submission lifecycle (owner order 2026-10-29).
 *
 * One row = one (exam, section, subject) unit whose marks the
 * teacher has SUBMITTED. While the row exists the unit is locked:
 * the entry grid renders read-only and the save endpoint refuses.
 * The school Admin reopens a unit by deleting the row (audited).
 * Entry itself is additionally date-gated by the exam's date
 * sheet (exam_dates): no row there, no gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mark_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_id')->index();
            $table->unsignedInteger('my_class_id');
            $table->unsignedInteger('section_id');
            $table->unsignedInteger('subject_id');
            $table->unsignedBigInteger('submitted_by');
            $table->timestamps();

            $table->unique(['exam_id', 'section_id', 'subject_id'], 'mark_submissions_unique_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mark_submissions');
    }
};
