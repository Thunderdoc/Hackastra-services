<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Batch fix 2026-10-25: teachers uploading the same file twice
     * (one multi-file pick, or a re-upload) produced silent duplicate
     * rows (DWD, DWD (2)…). The content hash lets store() skip a file
     * that is already a material for the same class + subject +
     * section instead of creating another row.
     */
    public function up(): void
    {
        Schema::table('study_materials', function (Blueprint $table) {
            $table->string('file_hash', 32)->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('study_materials', function (Blueprint $table) {
            $table->dropColumn('file_hash');
        });
    }
};
