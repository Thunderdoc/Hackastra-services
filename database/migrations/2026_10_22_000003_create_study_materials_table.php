<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStudyMaterialsTable extends Migration
{
    public function up()
    {
        Schema::create('study_materials', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('teacher_id')->index();
            $table->unsignedInteger('my_class_id')->index();
            // NULL section = the material is for the whole class.
            $table->unsignedInteger('section_id')->nullable()->index();
            $table->unsignedInteger('subject_id')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_path')->nullable();
            $table->string('link_url')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('study_materials');
    }
}
