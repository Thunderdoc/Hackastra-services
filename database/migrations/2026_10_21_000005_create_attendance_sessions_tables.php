<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Period-wise (subject) attendance — Workstream AC.
 *
 * Day-wise attendance (attendances table) says a student was in school
 * that day; it cannot say which periods they attended. These tables
 * log attendance per timetable period: a teacher takes the register
 * for their own period, records the topic taught, and on submit the
 * record is final — there is deliberately no update path.
 *
 * The unique key on (date, time_table_id) is the database-level lock:
 * one submitted session per period per day, whoever tries to write it.
 */
class CreateAttendanceSessionsTables extends Migration
{
    public function up()
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date')->index();
            $table->unsignedInteger('time_table_id')->nullable();
            $table->unsignedInteger('my_class_id')->index();
            $table->unsignedInteger('section_id')->nullable();
            $table->unsignedInteger('subject_id')->index();
            $table->unsignedInteger('teacher_id')->index();
            $table->string('topic');
            $table->string('status', 20)->default('submitted')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['date', 'time_table_id']);
            $table->foreign('time_table_id')
                ->references('id')->on('time_tables')
                ->onDelete('set null');
            $table->foreign('my_class_id')
                ->references('id')->on('my_classes')
                ->onDelete('cascade');
            $table->foreign('section_id')
                ->references('id')->on('sections')
                ->onDelete('set null');
            $table->foreign('subject_id')
                ->references('id')->on('subjects')
                ->onDelete('cascade');
            $table->foreign('teacher_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });

        Schema::create('attendance_session_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('session_id')->index();
            $table->unsignedInteger('student_record_id')->index();
            $table->enum('status', ['present', 'absent', 'late'])->index();
            $table->timestamps();

            $table->unique(['session_id', 'student_record_id']);
            $table->foreign('session_id')
                ->references('id')->on('attendance_sessions')
                ->onDelete('cascade');
            $table->foreign('student_record_id')
                ->references('id')->on('student_records')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendance_session_entries');
        Schema::dropIfExists('attendance_sessions');
    }
}
