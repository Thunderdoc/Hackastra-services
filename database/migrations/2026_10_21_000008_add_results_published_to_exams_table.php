<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddResultsPublishedToExamsTable extends Migration
{
    /**
     * Workstream BH — Result Publish switch (owner decision 2026-10-21:
     * "Result Publish switch — Pins khatm kar do"). An exam's results are
     * hidden from students/parents until the school publishes them from
     * the Exam List. Existing exams start UNPUBLISHED — that is the
     * point of the switch; the demo seeder publishes its seeded exams
     * explicitly so the demo stays complete.
     */
    public function up()
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('results_published')->default(false);
        });
    }

    public function down()
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('results_published');
        });
    }
}
