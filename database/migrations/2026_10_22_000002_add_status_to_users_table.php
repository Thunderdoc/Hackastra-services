<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStatusToUsersTable extends Migration
{
    /**
     * Account lifecycle state for the Users module (Workstream DF):
     * active (default) / suspended / terminated. Suspended and
     * terminated accounts cannot sign in and any live session is
     * ended on its next request (EnsureActiveAccount middleware);
     * the person's records are always preserved — lifecycle is a
     * state, never a deletion.
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('status_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_at']);
        });
    }
}
