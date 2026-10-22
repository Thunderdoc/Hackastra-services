<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCircularAttachmentsTable extends Migration
{
    public function up()
    {
        // Circular attachments (Loop 105): the same shape as
        // feedback_attachments (Workstream CW) — one row per stored
        // file with its original name and sniffed mime. The files
        // themselves live on the LOCAL disk and are served only
        // through CircularController@downloadAttachment, which
        // applies the circular's own audience gate: unlike feedback
        // screenshots (school-internal, publicly linked), a circular
        // is addressed to a specific audience, so its files must not
        // be reachable by a bare public URL.
        Schema::create('circular_attachments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('circular_id')->index();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('circular_attachments');
    }
}
