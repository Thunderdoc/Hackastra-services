<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings lock — unlock requests (owner order 2026-10-29).
 * When the Admin locks the school settings, editing reopens
 * only through this two-stage chain: the Admin's written
 * request (what changes, and why) -> the Principal approves ->
 * the Super Admin approves -> settings unlock and both the
 * Admin and the Principal are notified. User references are
 * plain unsignedInteger (legacy users PK), no foreign keys —
 * a request is an audit record and must survive account
 * changes.
 */
class CreateSettingsUnlockRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('settings_unlock_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('requested_by');
            $table->text('changes');
            $table->string('status', 24)->default('pending_principal');
            $table->unsignedInteger('principal_decided_by')->nullable();
            $table->timestamp('principal_decided_at')->nullable();
            $table->unsignedInteger('super_decided_by')->nullable();
            $table->timestamp('super_decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('settings_unlock_requests');
    }
}
