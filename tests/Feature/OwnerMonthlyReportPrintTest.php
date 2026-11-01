<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\User;
use Tests\TestCase;

/**
 * Owner fix 2026-10-30, from his Monthly Attendance Report
 * screenshots (Class 12—A, Sep–Oct 2026): every KPI was 0 and
 * every student "No Records" because PeriodAttendanceSeeder
 * only covered six days in late October and never reached
 * Class 12 at all; page 2 of the print started with a large
 * blank gap and the Class Summary clipped at the page bottom.
 * The seeder now backfills 1 Sep – 21 Oct for every record
 * (probe dates excluded, phase-1 window untouched) and the
 * document CSS carries explicit break rules. These pins hold
 * both halves.
 */
class OwnerMonthlyReportPrintTest extends TestCase
{
    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_backfill_landed_and_probe_dates_stay_session_free()
    {
        // Phase 1 alone was 36 sessions; the backfill takes the
        // seeded total far beyond it.
        $this->assertGreaterThan(1000, AttendanceSession::count());

        // Pinned test-probe days: the suite plants its own
        // sessions / take-attendance probes on them, so the seed
        // must never place a register there.
        foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-19', '2026-10-20'] as $date) {
            $this->assertSame(0, AttendanceSession::whereDate('date', $date)->count(), "Seeded session found on probe date {$date}");
        }

        // Every seeded session sits inside the demo window —
        // nothing leaks into the November probe Mondays.
        $max = AttendanceSession::max('date');
        $this->assertNotNull($max);
        $this->assertLessThanOrEqual('2026-10-29', substr((string) $max, 0, 10));
    }

    public function test_class_12_a_sep_oct_report_has_real_data()
    {
        $res = $this->actingAs($this->admin())->get(route('attendance.report.print', [
            'class_id' => 13,
            'section_id' => 64,
            'month' => '2026-10',
            'months' => 2,
        ]));
        $res->assertOk();

        $this->assertGreaterThan(0, (int) $res->viewData('sessions_held'));

        $totals = $res->viewData('totals');
        $this->assertGreaterThan(0, (int) $totals['present']);
        $this->assertGreaterThan(0, (int) $totals['marked']);

        $markedRows = collect($res->viewData('rows'))->filter(function ($row) {
            return (int) $row['marked'] > 0;
        });
        $this->assertGreaterThan(0, $markedRows->count());
    }

    public function test_print_view_carries_the_pagination_rules()
    {
        $res = $this->actingAs($this->admin())->get(route('attendance.report.print', [
            'class_id' => 13,
            'section_id' => 64,
            'month' => '2026-10',
            'months' => 2,
        ]));
        $res->assertOk();

        // The long table fragments freely and repeats its head;
        // the summary panels and signature block never split.
        $res->assertSee('.mar-table{width:100%;border:1px solid #b9c6d4;table-layout:fixed;break-inside:auto;page-break-inside:auto;}', false);
        $res->assertSee('.mar-table thead{display:table-header-group;}', false);
        $res->assertSee('.mar-panels{width:100%;margin-top:6px;break-inside:avoid;page-break-inside:avoid;}', false);
        $res->assertSee('.mar-sign{break-inside:avoid;page-break-inside:avoid;}', false);

        // Empty top margin boxes suppress the browser's own
        // date/URL header strip on continuation pages.
        $res->assertSee('@top-left{content:"";}', false);
        $res->assertSee('@top-center{content:"";}', false);
        $res->assertSee('@top-right{content:"";}', false);
    }
}
