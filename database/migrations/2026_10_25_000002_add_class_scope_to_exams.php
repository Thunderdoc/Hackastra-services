<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner build 2026-10-25 — class-scoped exams. One exam per
     * term per session used to be global (unique term+year); now
     * each exam covers either ALL classes (my_class_id = 0, the
     * historical behaviour) or ONE class. MySQL treats NULLs in a
     * unique index as distinct, so the sentinel is 0, not NULL —
     * otherwise two global exams could coexist. Existing exams
     * backfill to 0 (global) via the column default.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedInteger('my_class_id')->default(0)->after('id');
        });
        Schema::table('exams', function (Blueprint $table) {
            $table->dropUnique('exams_term_year_unique');
            $table->unique(['term', 'year', 'my_class_id']);
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropUnique('exams_term_year_my_class_id_unique');
            $table->unique(['term', 'year']);
            $table->dropColumn('my_class_id');
        });
    }
};
