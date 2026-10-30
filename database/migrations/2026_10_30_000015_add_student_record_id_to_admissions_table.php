<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a converted admission enquiry to the student record it
 * produced (owner loophole review, 2026-10-30). Conversion used
 * to be a manual board status with no link back to the student,
 * so one approved enquiry could be admitted any number of
 * times. The column is a plain unsignedInteger (student_records
 * uses an increments PK) with NO foreign key, per the live
 * schema-parity rule.
 */
class AddStudentRecordIdToAdmissionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('admissions', 'student_record_id')) {
            Schema::table('admissions', function (Blueprint $table) {
                $table->unsignedInteger('student_record_id')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('admissions', 'student_record_id')) {
            Schema::table('admissions', function (Blueprint $table) {
                $table->dropColumn('student_record_id');
            });
        }
    }
}
