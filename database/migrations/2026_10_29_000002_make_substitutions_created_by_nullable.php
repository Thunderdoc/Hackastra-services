<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner order 2026-10-29: leave cover is auto-arranged by the
 * system (emergency leave immediately, normal leave on approval).
 * System-created substitution rows have no human arranger, so
 * created_by becomes nullable; the board renders those rows as
 * "System (auto)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->unsignedInteger('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->unsignedInteger('created_by')->nullable(false)->change();
        });
    }
};
