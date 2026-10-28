<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student Documents Vault (2026-10-28): one row per uploaded student
 * document. stored_path is the random, non-guessable path on the
 * public disk (documents/...), never the raw original filename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_documents', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_record_id');
            $table->unsignedInteger('uploaded_by');
            $table->string('title');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type')->nullable();
            $table->unsignedInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->index('student_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
