<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Seed-baseline restoration (2026-10-30): the demo seed ships
     * with ZERO leave_requests, ZERO substitutions and ZERO
     * user_notifications, and the suite was authored against that
     * baseline. The leave-overlap guard (owner order 2026-10-30)
     * made cross-test residue in these three tables fatal to
     * unrelated scenarios — one test's approved leave refused the
     * next test's application for the same teacher and date.
     * Every test therefore starts from the documented baseline
     * again; anything a test needs, it creates itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['leave_requests', 'substitutions', 'user_notifications'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
    }
}
