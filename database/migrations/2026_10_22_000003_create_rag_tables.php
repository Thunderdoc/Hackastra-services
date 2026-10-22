<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRagTables extends Migration
{
    /**
     * RAG core, Wave 1 (Workstream DH; design docs/AI_LAYER.md §1).
     *
     * rag_documents is the corpus registry: one row per indexed source.
     * Wave 1 sources are published circulars (source_id = circulars.id,
     * body read live at index time) and admin-pasted school policy texts
     * (source_id null, body stored here, authored in Settings →
     * Knowledge Base).
     *
     * rag_chunks holds the searchable pieces: chunk text, the audience
     * roles allowed to retrieve it (copied from the source's own
     * audience model at index time — the permission filter runs BEFORE
     * ranking, §1.4), and the embedding as a JSON array (brute-force
     * cosine in PHP is the design's Step 1 vector store, §1.7).
     *
     * Deliberately NOT here: marks, payment records, receipts,
     * attendance or student records. Personal/transactional data is
     * never embedded (§1.1).
     */
    public function up()
    {
        Schema::create('rag_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('source_type', 30)->index(); // circular | policy_text
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->longText('body')->nullable();
            $table->string('audience', 30)->nullable(); // copied circular audience (display)
            $table->boolean('is_public')->default(false); // policy_text visibility flag
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['source_type', 'source_id']);
        });

        Schema::create('rag_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rag_document_id')->index();
            $table->string('source_type', 30)->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->string('section_title')->nullable();
            $table->text('text');
            $table->text('audience_roles'); // JSON array of user types allowed to retrieve
            $table->longText('embedding')->nullable(); // JSON array of floats
            $table->string('embedding_model')->nullable();
            $table->string('content_hash', 64)->nullable()->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('rag_chunks');
        Schema::dropIfExists('rag_documents');
    }
}
