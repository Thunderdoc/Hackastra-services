<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Result publication workflow (owner order 2026-10-29).
 *
 * Publishing is now a two-step office flow: the school Admin
 * prepares and, once every subject's marks are submitted, sends a
 * publication REQUEST; the Principal alone publishes. These two
 * columns carry the pending request (null = no request waiting).
 * The request is cleared when the Principal publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedBigInteger('publish_requested_by')->nullable();
            $table->timestamp('publish_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['publish_requested_by', 'publish_requested_at']);
        });
    }
};
