<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch M1 (configurable marking schemes): components are data, not
 * columns. An exam-subject with NO rows in exam_subject_components
 * keeps the legacy CA1(20) + CA2(20) + Exam(60) scheme over the marks
 * table's t1/t2/exm columns — zero score migration.
 */
class CreateMarkingSchemeTables extends Migration
{
    public function up()
    {
        Schema::create('exam_subject_components', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_id');
            $table->unsignedInteger('subject_id');
            $table->string('name', 60);
            $table->string('short_code', 10);
            $table->unsignedSmallInteger('max_marks');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();

            $table->foreign('exam_id')->references('id')->on('exams')->onDelete('cascade');
            $table->foreign('subject_id')->references('id')->on('subjects')->onDelete('cascade');
            $table->unique(['exam_id', 'subject_id', 'name']);
        });

        Schema::create('mark_components', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mark_id');
            $table->unsignedInteger('component_id');
            // NULL = not entered; 0 = a real zero. Never conflate the two.
            $table->smallInteger('score')->nullable();
            $table->timestamps();

            $table->foreign('mark_id')->references('id')->on('marks')->onDelete('cascade');
            $table->foreign('component_id')->references('id')->on('exam_subject_components')->onDelete('cascade');
            $table->unique(['mark_id', 'component_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mark_components');
        Schema::dropIfExists('exam_subject_components');
    }
}
