<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 (Round 4) — Exam Seating: two tables only (design §3).
 *
 * exam_seating_plans: one plan per exam per SLOT — a slot is all
 * exam_dates of one exam sharing (exam_date, start_time), the
 * ExamDateController::manage / Invigilation grouping. Status lives
 * ON the plan row (draft|submitted|published, §7.1); source_hash is
 * the §7.2 staleness fingerprint over the plan's inputs.
 *
 * exam_seating_seats: one snapshot row per student per plan — the
 * student's name / adm no / class / section AS GENERATED (§2
 * principle 4), so a later roster edit never silently rewrites a
 * printed hall sheet.
 *
 * Column-type law (§3.1): legacy core PKs are increments (INT), so
 * FK-style columns are unsignedInteger — EXCEPT room_id, which must
 * be unsignedBigInteger because rooms was created bigIncrements.
 * NO database FK constraints (rooms-migration precedent): integrity
 * is enforced in App\Support\SeatingPlanner + the controller, and
 * room deletion is guarded in RoomController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exam_seating_plans')) {
            Schema::create('exam_seating_plans', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('exam_id')->index();
                $table->date('exam_date');
                $table->time('start_time')->nullable();
                $table->string('status', 20)->default('draft');
                $table->string('mode', 20)->default('separate');
                $table->unsignedInteger('total_students')->default(0);
                $table->unsignedInteger('total_seats')->default(0);
                $table->string('source_hash', 64);
                $table->unsignedInteger('generated_by')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->unsignedInteger('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->unsignedInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();

                $table->unique(['exam_id', 'exam_date', 'start_time'], 'exam_seating_plans_slot_unique');
            });
        }

        if (!Schema::hasTable('exam_seating_seats')) {
            Schema::create('exam_seating_seats', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('plan_id')->index();
                $table->unsignedBigInteger('room_id')->index(); // rooms.id is bigIncrements — §3.1
                $table->unsignedInteger('exam_date_id');
                $table->unsignedInteger('student_record_id')->index();
                $table->unsignedInteger('my_class_id');
                $table->unsignedInteger('section_id')->nullable();
                // --- snapshot fields (design principle 4) ---
                $table->string('student_name');
                $table->string('adm_no')->nullable();
                $table->string('class_name');
                $table->string('section_name')->nullable();
                $table->string('subject_name');
                $table->string('room_name');
                $table->unsignedInteger('seat_no');
                $table->timestamps();

                $table->unique(['plan_id', 'exam_date_id', 'student_record_id'], 'exam_seating_seats_student_unique');
                $table->unique(['plan_id', 'room_id', 'seat_no'], 'exam_seating_seats_seat_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_seating_seats');
        Schema::dropIfExists('exam_seating_plans');
    }
};
