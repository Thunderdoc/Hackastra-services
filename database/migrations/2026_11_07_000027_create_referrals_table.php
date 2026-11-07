<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5 Slice 4 (P16) — referrals: the SA-side referral LOG
 * (audit W6 §16, Option A — SA platform area only, no public
 * page). Onboarding is a conversation, so the SA records who
 * referred whom by hand; the reward note is free text holding
 * whatever was actually agreed — there is no reward amount
 * column and nothing is computed or paid from this table.
 * created_by is the SA user who logged the entry (unsigned int,
 * FK-free like the recent support tables). NOTE: this wave
 * shares the 000027 sequence number with the question-papers
 * migration — Laravel keys migrations by full name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referrals')) {
            return;
        }
        Schema::create('referrals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('referrer_name');
            $table->string('referred_name');
            $table->string('status', 20);
            $table->text('reward_note')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
