<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateGatewayTransactionsTable extends Migration
{
    /**
     * Workstream CU — online payment gateway audit trail.
     *
     * One row per gateway order. payment_id carries a UNIQUE index:
     * it is the idempotency anchor — a browser callback and a webhook
     * reporting the same Razorpay payment can never create two school
     * payment records, because the second record attempt finds this
     * payment_id already captured and confirms state instead.
     */
    public function up()
    {
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 30)->default('razorpay');
            $table->string('order_id')->index();
            $table->string('payment_id')->nullable()->unique();
            $table->unsignedBigInteger('student_id')->index();
            $table->unsignedBigInteger('pr_id')->index(); // payment_records.id (the invoice line paid)
            $table->string('invoice_ref')->nullable();   // payment_records.ref_no at order time
            $table->unsignedBigInteger('amount');        // rupees, server-computed from the invoice balance
            $table->string('status', 30)->default('initiated'); // initiated|captured|failed|verification_failed
            $table->unsignedBigInteger('receipt_id')->nullable();
            $table->string('payload_hash', 64)->nullable();     // sha256 of the last verified gateway payload
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('gateway_transactions');
    }
}
