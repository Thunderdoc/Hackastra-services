<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAiUsageTable extends Migration
{
    /**
     * AI Assist metering (Workstream CX): one row per generation attempt
     * (ok / error / capped) so usage is auditable and the per-user daily
     * cap can be enforced from data, not memory. Written by
     * App\Services\AiAssist alongside an App\Support\Audit entry.
     */
    public function up()
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('task', 50)->index();
            $table->string('model')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('status', 20)->default('ok')->index();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('ai_usage');
    }
}
