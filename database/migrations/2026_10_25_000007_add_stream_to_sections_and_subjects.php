<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch 2026-10-25: Class 11/12 streams. Sections carry ONE
     * stream tag (A=PCM, B=PCB, C=Commerce, D=Arts in the seed;
     * later sections cycle the same pattern, and an admin may tag
     * any section from the section form). Subjects carry a
     * comma-separated tag list: a shared subject (Physics,
     * Chemistry) belongs to two streams, a blank tag means common
     * to every stream. See App\Support\Streams for the semantics.
     */
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('stream', 20)->nullable()->after('teacher_id');
        });
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('stream', 50)->nullable()->after('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('stream');
        });
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('stream');
        });
    }
};
