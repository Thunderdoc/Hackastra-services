<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Overnight menus 2026-10-29, item 22: date-sheet approval workflow.
 * One state row per (exam, class): draft (admin editing) ->
 * submitted (awaiting the Principal) -> published (Principal
 * approved; the sheet students, parents and teachers see). No row
 * means draft. This is a dedicated date-sheet flag, separate from
 * results_published (the marks switch).
 */
class CreateExamDateSheetStatesTable extends Migration
{
    public function up()
    {
        Schema::create('exam_date_sheet_states', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_id');
            $table->unsignedInteger('my_class_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'my_class_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('exam_date_sheet_states');
    }
}
