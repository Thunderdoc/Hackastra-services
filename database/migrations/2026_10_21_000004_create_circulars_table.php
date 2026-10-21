<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCircularsTable extends Migration
{
    public function up()
    {
        Schema::create('circulars', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('body');
            $table->string('audience', 20)->default('all')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->unsignedInteger('published_by')->nullable();
            $table->date('starts_at')->nullable()->index();
            $table->date('expires_at')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('circulars');
    }
}
