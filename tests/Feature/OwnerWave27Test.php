<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-26 (wave 27) — timetable index phone UX.
 *
 * On a phone the four summary cards stacked into a full screen of
 * stats before a single timetable appeared. Phones now get one slim
 * strip with the same four numbers (CSS-switched at 767px); the
 * desktop cards are untouched. This guards the strip + the scoped
 * stats row class against accidental removal.
 */
class OwnerWave27Test extends TestCase
{
    use DatabaseTransactions;

    public function test_index_has_compact_phone_stats_strip()
    {
        $response = $this->actingAs(User::where('email', 'admin@hackstra.app')->first())
            ->get(route('tt.index'));

        $response->assertStatus(200);
        $response->assertSee('tt-stats-strip');
        $response->assertSee('tt-index-stats');
        $response->assertSee('timetables');
    }
}
