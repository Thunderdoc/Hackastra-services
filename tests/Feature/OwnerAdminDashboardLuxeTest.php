<?php

namespace Tests\Feature;

use App\Models\LeaveRequest;
use App\Models\PaymentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Item 17 (2026-11-01): Admin dashboard redesign (admin-dash
 * reference, site style). Same sections, data and links as the
 * dashboard has always computed — KPI cards with an accent strip
 * (no sparkline filler), Quick Action tiles, equal-height
 * Attendance/Fee duo with the fee card filled (rate + defaulter
 * rows + pending alert), Enrollment Trend bars, Recent rows, and
 * the rail's NEW Needs Your Attention card fed by real counts:
 * a row renders only when its count is positive.
 */
class OwnerAdminDashboardLuxeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_dashboard_renders_the_l17_chrome_with_real_figures()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();
        $html = $response->getContent();

        // KPI cards: accent strip, no sparkline filler anywhere.
        $this->assertSame(4, substr_count($html, 'class="l17-kpi dashboard-stat'));
        $this->assertStringContainsString('--acc:#17507f', $html);
        $this->assertStringNotContainsString('spark', $html);

        // Quick Actions are tiles carrying the same four links.
        $this->assertSame(4, substr_count($html, 'class="l17-qatile"'));
        foreach ([route('students.create'), route('payments.create'), route('tt.index'), route('admissions.index')] as $href) {
            $this->assertStringContainsString('href="' . $href . '"', $html);
        }

        // Fee card fill rows + the enrollment panel. Beauty
        // Batch 6 (audit E9) re-expresses the trend pin: the
        // 6-column chart renders only once enrolments span three
        // or more of the six months; with fewer, the panel states
        // the "Enrolment so far" total instead of one stray bar.
        $this->assertStringContainsString('Collection rate', $html);
        $this->assertStringContainsString('Students with fees pending', $html);
        $trendColumns = substr_count($html, 'l17-tcol');
        if ($trendColumns === 0) {
            $this->assertStringContainsString('Enrolment so far:', $html);
        } else {
            $this->assertSame(6, $trendColumns);
        }

        // Recent lists are rows now, payments carry receipt numbers.
        $this->assertStringContainsString('l17-rrow', $html);
        $withRef = PaymentRecord::whereNotNull('ref_no')->first();
        if ($withRef) {
            $this->assertStringContainsString('Receipt', $html);
        }
    }

    public function test_attention_card_counts_are_real_and_rows_follow_them()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard.school'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Needs Your Attention', $html);

        $year = (int) now()->format('Y');
        $expectedDefaulters = (int) DB::table('payment_records')
            ->join('payments', 'payments.id', '=', 'payment_records.payment_id')
            ->whereYear('payment_records.updated_at', $year)
            ->whereRaw('payment_records.amt_paid < payments.amount')
            ->pluck('payment_records.student_id')->unique()->count();
        $this->assertSame($expectedDefaulters, (int) $response->viewData('fee_defaulter_count'));

        $pendingLeaves = LeaveRequest::where('status', 'pending')->count();
        $this->assertSame($pendingLeaves, (int) $response->viewData('pending_leave_count'));

        if ($expectedDefaulters > 0) {
            $this->assertStringContainsString('Fee defaulters', $html);
            $this->assertStringContainsString('students have fees pending', $html);
            $this->assertStringContainsString('href="' . route('payments.manage') . '"', $html);
        } else {
            $this->assertStringNotContainsString('Fee defaulters', $html);
        }
        if ($pendingLeaves > 0) {
            $this->assertStringContainsString('Leave requests pending', $html);
            $this->assertStringContainsString('href="' . route('leave.index') . '"', $html);
        } else {
            $this->assertStringNotContainsString('Leave requests pending', $html);
        }
    }

    public function test_attention_card_shows_all_caught_up_when_nothing_waits()
    {
        LeaveRequest::where('status', 'pending')->update(['status' => 'approved']);
        \App\Models\Admission::where('status', 'new')->update(['status' => 'contacted']);
        // Settle every payment record of the current year.
        PaymentRecord::with('payment')->whereYear('updated_at', (int) now()->format('Y'))
            ->get()->each(function ($r) {
                $r->update(['amt_paid' => optional($r->payment)->amount ?? $r->amt_paid, 'paid' => 1]);
            });

        $html = $this->actingAs($this->admin())->get(route('dashboard.school'))->assertOk()->getContent();
        $this->assertStringContainsString('All caught up', $html);
        $this->assertStringNotContainsString('Leave requests pending', $html);
        $this->assertStringNotContainsString('Fee defaulters', $html);
    }
}
