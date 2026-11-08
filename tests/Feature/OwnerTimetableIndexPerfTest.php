<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Timetables index query-budget pin (2026-11-07 night fix).
 *
 * The live page took ~2 minutes: index() runs the validator's
 * cleanRecordIds() over the whole year, and the validator
 * resolved every cell's effective teacher through
 * SectionTeacher::teacherIdFor() — one Schema::hasTable check
 * plus one SELECT per call. 2,601 cells × 2 sweeps × 2 queries
 * ≈ 10.4k queries (profiled locally: 10,501 for the controller
 * alone), invisible on SQLite, fatal at TiDB network latency.
 * The validator now preloads the override map once
 * (overrideMap()/effectiveTeacherId(), the LeaveAutoCover
 * idiom; differential-checked identical on 9,531 resolutions).
 * This pin keeps the whole index under a hard query budget the
 * per-cell storm could never meet.
 */
class OwnerTimetableIndexPerfTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_timetables_index_stays_under_query_budget()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $response = $this->actingAs($admin)->get(route('tt.index'));
        $response->assertOk();

        $this->assertLessThan(400, $count,
            "Timetables index ran {$count} queries — the per-cell teacherIdFor storm is back (was ~10,500 before the bulk fix, ~130 after).");
        // The seeded year validates clean; the pill data must be there.
        $response->assertViewHas('validated_ids', function ($ids) {
            return is_array($ids) && count($ids) > 0;
        });
    }
}
