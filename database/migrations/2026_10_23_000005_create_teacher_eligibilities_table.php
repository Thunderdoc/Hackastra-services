<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduling Engine Slice A (2026-10-23): teacher eligibility —
 * the subjects a teacher is QUALIFIED to teach, keyed by subject
 * name (subjects are per-class rows; eligibility is a capability of
 * the teacher, independent of any one class). This is distinct from
 * subjects.teacher_id, which records the CURRENT assignment. The
 * allocation engine may only propose teachers from this set (plus
 * teachers already teaching that subject name somewhere).
 */
class CreateTeacherEligibilitiesTable extends Migration
{
    public function up()
    {
        Schema::create('teacher_eligibilities', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('teacher_id')->index();
            $table->string('subject_name', 150);
            $table->timestamps();

            $table->unique(['teacher_id', 'subject_name']);
            $table->foreign('teacher_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('teacher_eligibilities');
    }
}
