<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserPreferencesTable extends Migration
{
    /**
     * Per-user account preferences for the My Account page (Batch 10):
     * notification opt-ins and display formats. Key/value rows so new
     * preferences never need a schema change. Read/written only for the
     * authenticated user themselves (MyAccountController).
     */
    public function up()
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('key', 60);
            $table->string('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_preferences');
    }
}
