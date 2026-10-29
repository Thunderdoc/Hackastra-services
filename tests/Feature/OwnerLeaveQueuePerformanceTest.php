<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query-count pins for the two pages the leave plan flow made
 * heavy (live incident, 2026-10-29 night): with a pending plan
 * present, the admin leave queue and the substitutions board
 * each rendered in ~93,000 queries — 11 s on local disk and an
 * outright hang against the hosted database — because every plan
 * row re-asked every teacher "are you free?" through fresh
 * database walks. The SubstitutionDay snapshot answers those
 * questions from memory; these ceilings (roughly 10x the healthy
 * count, 1/200th of the incident count) exist so the explosion
 * class can never come back silently.
 */
class OwnerLeaveQueuePerformanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function countQueriesDuring(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }
        return $count;
    }

    public function test_leave_queue_and_board_stay_light_with_a_pending_plan()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();

        // A normal leave for a teaching Monday: the system quietly
        // arranges the cover plan at submit (8 pending rows).
        $this->actingAs($teacher)->post(route('leave.store'), [
            'type' => 'normal',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-02',
            'reason' => 'Performance pin fixture',
        ])->assertRedirect();

        $this->assertGreaterThan(0, DB::table('substitutions')->where('status', 'pending')->count(),
            'fixture must carry a pending plan, or the pin proves nothing');

        $leaveQueries = $this->countQueriesDuring(function () use ($admin) {
            $this->actingAs($admin)->get(route('leave.index'))->assertOk();
        });
        $this->assertLessThan(500, $leaveQueries,
            "leave queue rendered in {$leaveQueries} queries — the per-row free-teacher walk is back");

        $boardQueries = $this->countQueriesDuring(function () use ($admin) {
            $this->actingAs($admin)->get(route('substitutions.index'))->assertOk();
        });
        $this->assertLessThan(500, $boardQueries,
            "substitutions board rendered in {$boardQueries} queries — the record page must not pay for plan options");
    }
}
