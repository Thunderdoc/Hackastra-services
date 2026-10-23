<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subjects may be VACANT: a class-subject can exist before a teacher
 * is assigned to it (and a teacher leaving must not force deleting
 * the subject). App\Support\Allocation already treats a null
 * teacher_id as a vacancy problem to solve; until this migration the
 * NOT NULL column made that state impossible to store.
 */
class MakeSubjectsTeacherIdNullable extends Migration
{
    public function up()
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedInteger('teacher_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->unsignedInteger('teacher_id')->nullable(false)->change();
        });
    }
}
