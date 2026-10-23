<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling Engine Slice F (2026-10-23): exam invigilation. One
 * duty = one invigilator for one date-sheet sitting (an ExamDate
 * row). exam_date_id is unique — a sitting has at most one
 * invigilator in this model; relief/additional invigilators for
 * very large halls are a future extension, not faked here.
 */
class CreateExamDutiesTable extends Migration
{
    public function up()
    {
        Schema::create('exam_duties', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_date_id')->unique();
            $table->unsignedInteger('teacher_id')->index();
            $table->unsignedInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->foreign('exam_date_id')->references('id')->on('exam_dates')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('exam_duties');
    }
}
