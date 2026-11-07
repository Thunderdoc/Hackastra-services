<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 3 Phase 1 (owner order 2026-11-07): a substitution row
 * must be able to name NOBODY — the office can cancel a period's
 * class outright (status 'cancelled', no substitute), and the
 * uncovered-periods surface defines a gap partly as a row whose
 * substitute is NULL. The original table made the column NOT
 * NULL, so neither state could be stored at all: cancelling a
 * class failed on the constraint, and the principal dashboard's
 * NULL-substitute count could never be non-zero. Making the
 * column nullable is backward compatible — every existing row
 * keeps its substitute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->unsignedInteger('substitute_teacher_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('substitutions', function (Blueprint $table) {
            $table->unsignedInteger('substitute_teacher_id')->nullable(false)->change();
        });
    }
};
