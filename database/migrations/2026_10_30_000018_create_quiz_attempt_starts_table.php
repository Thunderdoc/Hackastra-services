<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side quiz start stamps (loophole closed 2026-10-30):
 * the attempt page used to run a purely client-side countdown,
 * so a student could open the quiz, wait hours and still submit.
 * Opening the attempt page now stamps the start here (once —
 * refreshes never reset it), and submission is checked against
 * started_at + the quiz's time limit on the server.
 */
class CreateQuizAttemptStartsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('quiz_attempt_starts')) {
            Schema::create('quiz_attempt_starts', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('quiz_id');
                $table->unsignedInteger('student_record_id');
                $table->timestamp('started_at');
                $table->timestamps();
                $table->unique(['quiz_id', 'student_record_id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('quiz_attempt_starts');
    }
}
