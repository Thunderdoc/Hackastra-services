<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5 Slice 3 (P12) — saved_views: per-user, per-list saved
 * filter sets for the operational lists (students, users, fee
 * defaulters, audit log). Ports the ReportTemplate pattern
 * (owner-private rows, params as a JSON array) to list filters.
 * FK-free like the recent support tables; user_id follows the
 * app convention of an UNSIGNED INT users.id. NOTE: this wave
 * shares the 000025 sequence number with the F5 fee-reminder
 * migration — Laravel keys migrations by full name, not by the
 * numeric prefix, so both run exactly once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('saved_views')) {
            return;
        }
        Schema::create('saved_views', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('list_key', 50);
            $table->string('name', 100);
            $table->text('params');
            $table->timestamps();

            $table->index(['user_id', 'list_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
