<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGatewayColumnsToReceiptsTable extends Migration
{
    /**
     * Workstream CU — let a receipt say HOW it was paid. Manual counter
     * receipts keep NULL here (the receipt view renders them exactly as
     * before); online receipts store method='online_razorpay' and the
     * gateway payment id in gateway_ref so the printed receipt can show
     * "Online (Razorpay)" plus the transaction reference.
     */
    public function up()
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('method', 30)->nullable();
            $table->string('gateway_ref')->nullable();
        });
    }

    public function down()
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['method', 'gateway_ref']);
        });
    }
}
