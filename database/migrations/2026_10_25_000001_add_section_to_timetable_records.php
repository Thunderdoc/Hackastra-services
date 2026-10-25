<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner build 2026-10-25 — section-wise timetables: a record may
     * belong to ONE section of its class (section_id set) instead of
     * the whole class (section_id NULL, the historical behaviour and
     * the fallback when a section has no own grid). A second table
     * carries per-section subject teachers: a section's teacher for
     * a subject overrides the subject's class-wide teacher.
     */
    public function up(): void
    {
        Schema::table('time_table_records', function (Blueprint $table) {
            $table->unsignedInteger('section_id')->nullable()->after('my_class_id');
            $table->index('section_id');
        });

        Schema::create('section_subject_teachers', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('section_id');
            $table->unsignedInteger('subject_id');
            $table->unsignedInteger('teacher_id');
            $table->timestamps();
            $table->unique(['section_id', 'subject_id']);
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_subject_teachers');
        Schema::table('time_table_records', function (Blueprint $table) {
            $table->dropIndex(['section_id']);
            $table->dropColumn('section_id');
        });
    }
};
