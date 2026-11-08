<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publication gate (owner order 2026-11-08): admit cards join
 * results and date sheets as a PUBLISHED document. Until now the
 * student/parent self-service card was gated only by fees, so a
 * family could download an admit card for an exam the school had
 * never released. The new per-exam flag mirrors results_published:
 * default FALSE (nothing is published until the Principal
 * publishes it from the exam's Publication page), flipped only by
 * ExamController::publishAdmitCards / unpublishAdmitCards.
 * Office (teamSA) generation never reads the flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('admit_cards_published')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('admit_cards_published');
        });
    }
};
