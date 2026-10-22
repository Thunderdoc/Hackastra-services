<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserNotificationsTable extends Migration
{
    public function up()
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->increments('id');
            // Recipient — users.id, the convention the rest of the app
            // uses (marks.student_id, submissions.student_id).
            $table->unsignedInteger('user_id')->index();
            // Machine type: assignment_graded, internal_pushed, ...
            $table->string('type', 50)->index();
            $table->string('title');
            $table->text('body')->nullable();
            // In-app destination the bell item links to (path/URL).
            $table->string('link')->nullable();
            $table->dateTime('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_notifications');
    }
}
