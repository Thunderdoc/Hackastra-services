<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner CI fix (2026-10-30): the demo seed read the WALL CLOCK for
 * content dates, so a fresh seed produced a different dataset
 * every day. Seeded 2026-10-29 the Parent-Teacher Meeting landed
 * on 01 Nov; seeded 2026-10-30 it landed on Mon, 02 Nov 2026 —
 * the exact date three unrelated tests plant — and CI went red
 * while every local snapshot gate stayed green. Content dates
 * are now anchored to App\Support\DemoToday (2026-10-29). These
 * assertions hold on ANY run date; if a seeder ever reads the
 * clock again, they fail.
 */
class OwnerDemoSeedDeterminismTest extends TestCase
{
    public function test_school_events_are_anchored_to_the_demo_date()
    {
        $events = DB::table('school_events')
            ->pluck('starts_at', 'title');

        $this->assertSame('2026-11-01', $events['Parent-Teacher Meeting'] ?? null);
        $this->assertSame('2026-11-04', $events['Annual Sports Day'] ?? null);
        $this->assertSame('2026-10-17', $events['Term 1 Orientation Day'] ?? null);
    }

    public function test_period_attendance_covers_prep_english_with_a_topic()
    {
        // PeriodAttendanceSeeder's six school days end on the
        // anchored demo date; the rotation always reaches a Prep
        // (class 1) English Language (subject 1) period.
        $topic = AttendanceSession::where('my_class_id', 1)
            ->where('subject_id', 1)
            ->whereNotNull('topic')
            ->value('topic');

        $this->assertNotEmpty($topic);
    }
}
