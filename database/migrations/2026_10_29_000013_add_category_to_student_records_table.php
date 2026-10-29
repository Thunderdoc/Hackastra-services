<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student category (owner order 2026-10-29, via the main chat):
 * the admission form asks Gender, Blood Group and Category —
 * General / OBC / SC / ST / EWS — instead of the Nigerian LGA
 * leftover. Plain nullable string, no foreign keys (P25-safe on
 * every engine).
 */
class AddCategoryToStudentRecordsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('student_records', 'category')) {
            Schema::table('student_records', function (Blueprint $table) {
                $table->string('category', 20)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('student_records', 'category')) {
            Schema::table('student_records', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
}
