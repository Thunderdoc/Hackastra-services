<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5 Slice 4 (P14) — school_licences: the SA-maintained
 * licence RECORD for this school (audit W6 §14, Option A — the
 * smallest honest step). Single-school deployment: the table
 * holds the commercial record for THIS school; the current
 * record is the latest row and the SA edits that row (or creates
 * the first). Deliberately NO price / amount / currency columns
 * — the record states the plan name exactly as sold, its status
 * and its dates, and nothing gates on it (no enforcement).
 * FK-free, increments PK, hasTable guard — the recent-migration
 * idiom (cf. 000025 saved_views). NOTE: this wave shares the
 * 000026 sequence number with the question-bank migration —
 * Laravel keys migrations by full name, so both run once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('school_licences')) {
            return;
        }
        Schema::create('school_licences', function (Blueprint $table) {
            $table->increments('id');
            $table->string('plan_name', 100);
            $table->string('status', 20);
            $table->date('starts_on')->nullable();
            $table->date('renews_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_licences');
    }
};
