<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk Import & Bulk Edit staging + history (Bulk System Phase A,
 * design: docs/BULK_SYSTEM_AUDIT.md §5).
 *
 * Two additive tables — nothing existing is altered:
 *  - bulk_import_jobs: one row per uploaded file (the import history).
 *  - bulk_import_rows: one row per spreadsheet row (staging, preview
 *    verdicts, per-row apply state), so a chunk-applied job can be
 *    interrupted and resumed without ambiguity.
 */
class CreateBulkImportTables extends Migration
{
    public function up()
    {
        Schema::create('bulk_import_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');            // initiating admin
            $table->string('module');                      // 'students' | 'staff'
            $table->string('mode');                        // 'add' | 'update'
            $table->string('original_filename');
            $table->string('stored_path');                 // private disk path
            $table->string('file_hash', 64);               // sha256 of the upload
            $table->string('status');                      // uploaded → staged →
                                                           // validated → applying →
                                                           // completed | failed | discarded
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);   // duplicate + unmatched + no-change
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('applied_create_count')->default(0);
            $table->unsignedInteger('applied_update_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('confirm_token', 64)->nullable();    // single-use (§6.2)
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_import_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('job_id')->index();    // → bulk_import_jobs.id
            $table->unsignedInteger('row_number');         // spreadsheet line (header = 1)
            $table->json('raw');                           // cells as uploaded (by column name)
            $table->json('resolved')->nullable();          // names → ids, normalised values
            $table->string('fingerprint', 64);             // duplicate detection (§6.2)
            $table->string('verdict');                     // staged | valid_create | valid_update |
                                                           // duplicate | unmatched | invalid |
                                                           // applied | failed
            $table->json('errors')->nullable();            // [{field, value, message}]
            $table->json('changes')->nullable();           // update mode: field → {old, new}
            $table->unsignedInteger('record_id')->nullable(); // created/updated users.id
            $table->text('failure')->nullable();           // apply-time exception, truncated
            $table->timestamps();
            $table->index(['job_id', 'verdict']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('bulk_import_rows');
        Schema::dropIfExists('bulk_import_jobs');
    }
}
