<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONE-TIME GENERATION (owner standing rule 2026-11-08, freeze
 * approved the same day): publishing an exam's admit cards now
 * FREEZES the card every family will see. One row per (exam,
 * student user id) holding the full card payload as JSON —
 * identity fields, the exam schedule rows exactly as rendered,
 * and the branding values in force at freeze time — so a later
 * live edit (a section transfer, a Settings branding change, a
 * date sheet corrected through the Principal return path) cannot
 * quietly rewrite a card the school has already released.
 * Unpublishing keeps the rows (the flag hides them); publishing
 * again re-freezes from live data. The fee gate is NOT part of
 * the payload — it stays live on purpose, so a frozen card still
 * blocks a student whose dues are unpaid. Office/bulk printing
 * never reads this table (it is the correction surface and stays
 * on live data). Plain unsignedInteger columns, no FK — the
 * house pattern for new tables on legacy increments keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admit_card_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('exam_id');
            $table->unsignedInteger('student_id');
            $table->longText('payload');
            $table->unsignedInteger('frozen_by')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admit_card_snapshots');
    }
};
