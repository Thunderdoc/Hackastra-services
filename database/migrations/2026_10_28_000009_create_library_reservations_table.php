<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library reservations (2026-10-28): a student's/parent's hold on a
 * catalogue book. Deliberately its OWN table, not a BookRequest
 * status: BookRequest only ever holds issued/returned, and the
 * issue/return flow (which keeps Book.issued_copies in step) is
 * untouched. Availability here is derived, never cached: a book is
 * reservable while total_copies > issued_copies, so cancelling a
 * reservation needs no counter repair. A collected reservation is
 * history only — no endpoint sets it in this feature.
 */
class CreateLibraryReservationsTable extends Migration
{
    public function up()
    {
        Schema::create('library_reservations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('book_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('student_record_id')->nullable()->index();
            $table->string('status', 20)->default('reserved')->index();
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('student_record_id')->references('id')->on('student_records')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('library_reservations');
    }
}
