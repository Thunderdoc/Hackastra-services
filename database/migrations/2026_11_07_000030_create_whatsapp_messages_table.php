<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F1 (Round 4, design §2.1) — whatsapp_messages: the WhatsApp
 * outbox AND sent-log in one table. Triggers only insert `queued`
 * rows; ParentWhatsApp::drain() sends them. Skipped/suppressed
 * rows are kept with their status so the log can explain why a
 * parent got nothing. The duplicate-send guarantee lives in the
 * DB: dedupe_key is UNIQUE (design §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_messages')) {
            return;
        }
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_user_id')->nullable();
            $table->unsignedInteger('student_record_id')->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('event', 40);
            $table->string('template_name', 80);
            $table->string('template_language', 10)->default('en');
            $table->text('params')->nullable();
            $table->text('body_preview')->nullable();
            $table->string('related_type', 60)->nullable();
            $table->unsignedInteger('related_id')->nullable();
            $table->string('dedupe_key', 120)->unique();
            $table->string('status', 20);
            $table->string('provider_message_id', 80)->nullable();
            $table->string('error_code', 30)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('triggered_by')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index('parent_user_id');
            $table->index('student_record_id');
            $table->index('event');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
