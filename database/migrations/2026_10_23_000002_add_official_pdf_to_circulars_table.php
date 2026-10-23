<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddOfficialPdfToCircularsTable extends Migration
{
    /**
     * The auto-generated, digitally signed official PDF of a
     * circular (principal signature + school seal rendered from
     * Settings by App\Support\CircularOfficialPdf). The file lives
     * on the local disk beside the manual attachments; these two
     * columns are its only record — path relative to the local
     * disk root, and when the stored copy was generated.
     */
    public function up()
    {
        Schema::table('circulars', function (Blueprint $table) {
            $table->string('official_pdf_path')->nullable();
            $table->timestamp('official_pdf_generated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('circulars', function (Blueprint $table) {
            $table->dropColumn(['official_pdf_path', 'official_pdf_generated_at']);
        });
    }
}
