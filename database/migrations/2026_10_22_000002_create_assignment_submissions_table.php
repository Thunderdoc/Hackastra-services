<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssignmentSubmissionsTable extends Migration
{
    public function up()
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('assignment_id')->index();
            // users.id of the student — the same convention marks use
            // (marks.student_id references users, not student_records).
            $table->unsignedInteger('student_id')->index();
            // Stored on the private (local) disk; served only through
            // the authorized assignments.download action.
            $table->string('attachment_path')->nullable();
            $table->text('text_answer')->nullable();
            $table->dateTime('submitted_at');
            $table->boolean('is_late')->default(false);
            $table->decimal('marks_awarded', 8, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedInteger('graded_by')->nullable();
            $table->dateTime('graded_at')->nullable();
            $table->timestamps();

            // One submission per student per assignment; resubmission
            // updates the same row.
            $table->unique(['assignment_id', 'student_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('assignment_submissions');
    }
}
