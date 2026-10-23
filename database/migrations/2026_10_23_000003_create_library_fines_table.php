<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library fines (owner, 2026-10-23): a fine imposed by the library on
 * a borrower. For students the fine is mirrored into the main fee
 * ledger (a Payment + PaymentRecord) so it appears in the student's
 * fees, statements, balances and defaulters like any other charge;
 * payment_record_id links the two and their statuses stay in sync.
 * Fines are financial history — rows are never deleted, only waived.
 */
class CreateLibraryFinesTable extends Migration
{
    public function up()
    {
        Schema::create('library_fines', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('book_request_id')->nullable()->index();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedInteger('amount');
            $table->unsignedInteger('days_overdue')->default(0);
            $table->string('reason');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('payment_record_id')->nullable()->index();
            $table->unsignedInteger('imposed_by')->nullable();
            $table->timestamps();

            $table->foreign('book_request_id')->references('id')->on('book_requests')->onDelete('set null');
            $table->foreign('payment_record_id')->references('id')->on('payment_records')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('library_fines');
    }
}
