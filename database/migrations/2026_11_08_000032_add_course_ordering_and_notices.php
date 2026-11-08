<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LMS course build (owner-approved samples, 2026-11-08): course
 * items (study materials, assignments, quizzes) gain a PIN flag
 * and a teacher-controlled SEQUENCE so a subject's course page can
 * render "pinned first, then the teacher's order" instead of one
 * flat date-sorted list. Course notices get their own store —
 * subject + class/section scoped, authored by the teacher, with
 * the same pin/sequence treatment, so a pinned notice can sit
 * above everything in the course until it is unpinned.
 *
 * FK-free like the recent support tables; ids follow the app
 * convention of UNSIGNED INT primary/foreign keys. Schema API
 * only (no driver-specific SQL), so the migration runs the same
 * on SQLite (local/CI) and TiDB (live).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['study_materials', 'assignments', 'quizzes'] as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'is_pinned')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->boolean('is_pinned')->default(false);
                });
            }
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'seq')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedInteger('seq')->default(0);
                });
            }
        }

        if (!Schema::hasTable('course_notices')) {
            Schema::create('course_notices', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('teacher_id');
                $table->unsignedInteger('my_class_id');
                $table->unsignedInteger('section_id')->nullable();
                $table->unsignedInteger('subject_id');
                $table->text('body');
                $table->boolean('is_pinned')->default(false);
                $table->unsignedInteger('seq')->default(0);
                $table->timestamps();

                $table->index(['subject_id', 'section_id']);
                $table->index(['my_class_id', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_notices');

        foreach (['study_materials', 'assignments', 'quizzes'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'is_pinned')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('is_pinned');
                });
            }
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'seq')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('seq');
                });
            }
        }
    }
};
