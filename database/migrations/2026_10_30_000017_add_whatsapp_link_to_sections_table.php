<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section WhatsApp group link (owner order 2026-10-30): the
 * section's class teacher keeps one group link for their class;
 * students see it next to their class on their dashboard.
 */
class AddWhatsappLinkToSectionsTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('sections', 'whatsapp_link')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->string('whatsapp_link', 255)->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('sections', 'whatsapp_link')) {
            Schema::table('sections', function (Blueprint $table) {
                $table->dropColumn('whatsapp_link');
            });
        }
    }
}
