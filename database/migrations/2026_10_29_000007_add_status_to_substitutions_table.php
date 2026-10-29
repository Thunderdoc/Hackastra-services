<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Substitution plan state (owner order 2026-10-29): the system
 * auto-arranges cover for a teacher's leave, but the arrangement is
 * a PLAN until the Admin reviews and submits it. Rows therefore
 * carry a status: 'pending' (system-arranged, awaiting the Admin's
 * submit — no access, no substitute bell yet) or 'confirmed' (live:
 * access + bells active). Existing rows and every manually arranged
 * row are 'confirmed' — a manual arrangement IS the Admin's own
 * submitted decision, so the default grandfathers them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->string('status', 16)->default('confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
