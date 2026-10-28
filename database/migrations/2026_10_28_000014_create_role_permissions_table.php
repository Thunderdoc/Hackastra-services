<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom permission grants (2026-10-28). Additive table only — no
 * existing table or user_type rule is touched. The unique index is
 * the database-level duplicate-grant refusal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('permission', 100);
            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'permission']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
