<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateExamDatesTable extends Migration
{
    public function up()
    {
        // Exam Date Sheet module (owner-ordered 2026-10-22: "exam dates
        // will be set by admin"). Exams themselves hold only
        // name/term/session; this table is the per-subject date sheet:
        // one row per (exam, class, subject), written by the school
        // Admin and read — role-scoped — by teachers, students and
        // parents. Additive only: no existing table is touched.
        Schema::create('exam_dates', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_id')->index();
            $table->unsignedInteger('my_class_id')->index();
            $table->unsignedInteger('subject_id')->index();
            $table->date('exam_date');
            $table->time('start_time')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            // One date per subject per exam per class — the admin save
            // upserts on this key.
            $table->unique(['exam_id', 'my_class_id', 'subject_id'], 'exam_dates_unique_slot');
        });
    }

    public function down()
    {
        Schema::dropIfExists('exam_dates');
    }
}
