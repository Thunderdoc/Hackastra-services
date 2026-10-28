<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh-migrate guard (2026-10-28, exam-timetable round 2):
        // this migration sorts BEFORE 100004_create_inventory_tables,
        // so on a fresh migrate the table does not exist yet. The
        // create migration now carries sku itself; this one only
        // covers databases migrated incrementally before that.
        if (Schema::hasTable('inventory_items') && !Schema::hasColumn('inventory_items', 'sku')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->string('sku', 100)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_items') && Schema::hasColumn('inventory_items', 'sku')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->dropColumn('sku');
            });
        }
    }
};
