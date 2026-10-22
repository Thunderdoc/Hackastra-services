<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFeedbackItemsTable extends Migration
{
    public function up()
    {
        // Feedback & issue flow (Workstream CW): everyone in the school
        // reports to the school Admin; the Admin dedupes + forwards the
        // compiled items to the Super Admin, who replies with the fix.
        // NOTE (future SaaS phase): these tables gain a school_id tenant
        // column when multi-tenancy lands — deliberately NOT built here.
        Schema::create('feedback_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('reporter_id')->index();
            // Snapshots: the reporter's name/role as they were when the
            // report was filed (accounts can be renamed later).
            $table->string('reporter_name');
            $table->string('reporter_role', 20);
            // What kind of report, which area, how urgent (reporter's view).
            $table->string('type', 20)->default('problem')->index();
            $table->string('category', 20)->index();
            $table->string('priority', 10)->default('medium')->index();
            $table->string('title');
            $table->text('description');
            $table->string('context')->nullable();
            // Auto-log: captured server-side / from the form's hidden
            // field — the reporter never types these.
            $table->string('page_url', 1024)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('status', 20)->default('new')->index();
            $table->unsignedInteger('duplicate_of_id')->nullable()->index();
            $table->text('admin_note')->nullable();
            $table->timestamp('forwarded_at')->nullable()->index();
            $table->unsignedInteger('forwarded_by')->nullable();
            $table->string('sa_status', 20)->nullable();
            $table->text('sa_reply')->nullable();
            // Bell hook (topbar): the reporter's badge counts own items
            // changed since they last opened My Feedback.
            $table->timestamp('reporter_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('feedback_id')->index();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('feedback_items');
    }
}
