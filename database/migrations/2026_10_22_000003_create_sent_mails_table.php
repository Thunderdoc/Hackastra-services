<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSentMailsTable extends Migration
{
    /**
     * Log of platform mail sent by the Super Admin (Workstream DF).
     * Every send from the platform mail composer lands here — sent,
     * log-driver-recorded, or failed — so the tool is auditable and
     * never fire-and-forget. The full recipient address list is not
     * stored; audience + count + a short summary is enough to audit.
     */
    public function up()
    {
        Schema::create('sent_mails', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sender_id');
            $table->string('audience', 30); // user | admins | role
            $table->string('audience_label'); // human-readable target, e.g. "All Teachers"
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('subject');
            $table->text('body');
            $table->string('driver', 30); // smtp | log | array | sendmail
            $table->string('status', 20); // sent | logged | failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sent_mails');
    }
}
