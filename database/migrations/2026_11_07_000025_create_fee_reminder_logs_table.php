<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F5 (Round 4) — fee_reminder_logs: simultaneously the scheduled
 * reminder engine's dedupe store (dedupe_key UNIQUE), its audit
 * trail and the admin log page's data source (design §3.3).
 * FK-free like the recent support tables; student_id follows the
 * app convention of holding users.id (UNSIGNED INT).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fee_reminder_logs')) {
            return;
        }
        Schema::create('fee_reminder_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('student_id');
            $table->unsignedInteger('student_record_id')->nullable();
            $table->unsignedInteger('parent_user_id')->nullable();
            $table->string('year');
            $table->string('channel', 16);
            $table->string('trigger', 16);
            $table->integer('rule_offset')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('fee_head_id')->nullable();
            $table->unsignedBigInteger('installment_id')->nullable();
            $table->integer('amount_due');
            $table->unsignedInteger('sent_by')->nullable();
            $table->string('status', 20);
            $table->string('provider_ref')->nullable();
            $table->string('dedupe_key', 191)->unique();
            $table->timestamps();

            $table->index(['student_id', 'year']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_reminder_logs');
    }
};
