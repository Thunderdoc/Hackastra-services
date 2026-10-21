<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSchoolEventsTable extends Migration
{
    public function up()
    {
        Schema::create('school_events', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->string('type', 30)->default('event')->index();
            $table->text('description')->nullable();
            $table->date('starts_at')->index();
            $table->date('ends_at')->nullable();
            $table->boolean('all_day')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('school_events');
    }
}
