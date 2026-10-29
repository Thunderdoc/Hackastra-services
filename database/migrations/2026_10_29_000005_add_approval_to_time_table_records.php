<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetable approval flow (owner order 2026-10-29).
 *
 * A generated timetable used to go live the moment it was created
 * — the Admin had no review step and the Principal never approved
 * it. Records now carry an approval state:
 *   draft     — generated/edited, Admin is reviewing it
 *   submitted — Admin OK'd it, waiting on the Principal
 *   approved  — Principal approved; in force for the school
 * The column DEFAULT is 'approved' on purpose: every existing and
 * seeded record stays in force (grandfathered), and only records
 * created or regenerated after this change enter as drafts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_table_records', function (Blueprint $table) {
            $table->string('approval_status')->default('approved');
            $table->unsignedBigInteger('approval_submitted_by')->nullable();
            $table->timestamp('approval_submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('time_table_records', function (Blueprint $table) {
            $table->dropColumn([
                'approval_status', 'approval_submitted_by', 'approval_submitted_at',
                'approved_by', 'approved_at',
            ]);
        });
    }
};
