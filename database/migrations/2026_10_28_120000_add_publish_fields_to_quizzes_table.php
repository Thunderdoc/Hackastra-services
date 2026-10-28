<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quiz v2 — draft/publish + timing window.
 *
 * Named 120000 (not the next 0000xx slot) so it sorts after every
 * Wave 2 migration (100001–100005) even if more 0000xx/1000xx files
 * land concurrently: '1' > '0' at the first differing digit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->string('status')->default('draft');
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['status', 'available_from', 'available_until']);
        });
    }
};
