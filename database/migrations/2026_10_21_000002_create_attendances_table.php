<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAttendancesTable extends Migration
{
    public function up()
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('student_record_id')->index();
            $table->date('date')->index();
            $table->enum('status', ['present', 'absent', 'late'])->index();
            $table->string('remarks')->nullable();
            $table->unsignedInteger('recorded_by')->nullable();
            $table->timestamps();

            // One record per student per day — enforced by the database,
            // not only by application logic (audit §5).
            $table->unique(['student_record_id', 'date']);
            $table->foreign('student_record_id')
                ->references('id')->on('student_records')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('attendances');
    }
}
