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

    /**
     * Harness-ceiling restoration (2026-10-31): several PDF
     * endpoints (Reports/Mark/Attendance/IdCard controllers) call
     * ini_set('memory_limit', '512M') for their own request —
     * harmless per-request in production, but PHPUnit runs the
     * whole suite in ONE process, so the lowered ceiling leaks
     * into every later test. A random-order seed-777 run died at
     * test ~1830/1879 in DOMPDF's Cpdf with the 512M ceiling
     * despite the suite being launched at 1024M. Restore the
     * harness ceiling after each test so the launch value is the
     * ceiling every test actually runs under.
     */
    protected function tearDown(): void
    {
        ini_set('memory_limit', '1024M');

        parent::tearDown();
    }
}
