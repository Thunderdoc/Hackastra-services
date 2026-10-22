<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAssignmentsTable extends Migration
{
    public function up()
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('teacher_id')->index();
            $table->unsignedInteger('my_class_id')->index();
            // NULL section = the assignment targets the whole class.
            $table->unsignedInteger('section_id')->nullable()->index();
            $table->unsignedInteger('subject_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('attachment_path')->nullable();
            $table->decimal('max_marks', 8, 2)->default(10);
            $table->dateTime('due_at')->index();
            $table->boolean('allow_late')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('assignments');
    }
}
