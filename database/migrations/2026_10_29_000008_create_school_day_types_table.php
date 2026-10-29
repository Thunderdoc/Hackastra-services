<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T2 (master prompt, owner order 2026-10-29): per-date day types.
 * One row per special date; a date with no row is a normal school
 * day. The type drives attendance expectations and substitution
 * auto-cover (see App\Support\DayType). New table — bigIncrements
 * is safe here (no legacy FK points at it).
 */
class CreateSchoolDayTypesTable extends Migration
{
    public function up()
    {
        Schema::create('school_day_types', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date')->unique();
            $table->string('type', 16); // half_day|exam_day|event_only|holiday|closure
            $table->unsignedTinyInteger('half_day_periods')->nullable();
            $table->string('note')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('school_day_types');
    }
}
