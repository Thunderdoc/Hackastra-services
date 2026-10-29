<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section capacity (owner order 2026-10-29): each section may
 * carry a fixed number of seats, set by the school. NULL or 0
 * means unlimited. When an admission targets a full section,
 * the system allots the next section of the same class that
 * still has room and says so (App\Support\SectionCapacity).
 */
class AddCapacityToSectionsTable extends Migration
{
    public function up()
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->nullable();
        });
    }

    public function down()
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });
    }
}
