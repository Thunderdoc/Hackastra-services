<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 7, family version (owner, 2026-11-01): the attendance
 * reports — Monthly Report AND Low Attendance — refresh their
 * results region via AJAX (no Filter button, no page reload),
 * start EMPTY (the list loads only once a class AND a section
 * are chosen), and offer a teacher only her own sections.
 * Fixtures: Prep A (class 1, section 1) in September 2026 has a
 * full roster and 41 students below 75% in the seeded registers.
 */
class OwnerAttendanceReportsFamilyTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ---------------- Monthly report ---------------- */

    public function test_monthly_report_starts_empty_with_ajax_wiring_and_no_filter_button()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.report'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'Select a class and section to see the monthly attendance report.', $html);
        $this->assertSame(0, substr_count($html, 'marx-ava'), 'no student rows before a selection');
        $this->assertStringNotContainsString('>Filter</button>', $html);
        $this->assertStringContainsString('report/results', $html);
        $this->assertStringContainsString('att-doc-btn', $html);
        $this->assertStringContainsString('Updating', $html);
    }

    public function test_monthly_report_loads_rows_only_with_class_and_section()
    {
        $admin = $this->user('admin@hackstra.app');

        $classOnly = $this->actingAs($admin)
            ->get(route('attendance.report', ['class_id' => 1, 'month' => '2026-09']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Select a class and section', $classOnly);
        $this->assertSame(0, substr_count($classOnly, 'marx-ava'));

        $full = $this->actingAs($admin)
            ->get(route('attendance.report', ['class_id' => 1, 'section_id' => 1, 'month' => '2026-09']))
            ->assertOk()->getContent();
        $this->assertSame(41, substr_count($full, 'marx-ava'));
        $this->assertStringContainsString('Total Present', $full);
    }

    public function test_monthly_results_endpoint_swaps_between_empty_and_rows()
    {
        $admin = $this->user('admin@hackstra.app');

        $empty = $this->actingAs($admin)
            ->getJson(route('attendance.report.results'))->assertOk()->json('results');
        $this->assertStringContainsString('Select a class and section', $empty);
        $this->assertSame(0, substr_count($empty, 'marx-ava'));

        $rows = $this->actingAs($admin)
            ->getJson(route('attendance.report.results', ['class_id' => 1, 'section_id' => 1, 'month' => '2026-09']))
            ->assertOk()->json('results');
        $this->assertSame(41, substr_count($rows, 'marx-ava'));
    }

    public function test_report_sections_feed_and_page_offer_a_teacher_only_her_sections()
    {
        $teacher = $this->user('teacher@hackstra.app');

        $feed = $this->actingAs($teacher)
            ->getJson(route('attendance.report.sections', ['class_id' => 13]))->assertOk()->json();
        $this->assertSame([76], array_map(function ($r) { return (int) $r['id']; }, $feed));

        // A class outside her scope answers empty, never the list.
        $foreign = $this->actingAs($teacher)
            ->getJson(route('attendance.report.sections', ['class_id' => 2]))->assertOk()->json();
        $this->assertSame([], $foreign);

        $adminFeed = $this->actingAs($this->user('admin@hackstra.app'))
            ->getJson(route('attendance.report.sections', ['class_id' => 13]))->assertOk()->json();
        $this->assertCount(13, $adminFeed);

        $html = $this->actingAs($teacher)
            ->get(route('attendance.report', ['class_id' => 13]))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="76"', $html);
        $this->assertStringNotContainsString('<option value="64"', $html);
    }

    /* ---------------- Low Attendance ---------------- */

    public function test_defaulters_starts_empty_with_ajax_wiring_and_no_filter_button()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.defaulters'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'Select a class and section to see the low attendance list.', $html);
        $this->assertSame(0, substr_count($html, 'att-low-initials'), 'no student rows before a selection');
        $this->assertStringNotContainsString('>Filter</button>', $html);
        $this->assertStringContainsString('defaulters/results', $html);
        $this->assertStringContainsString('id="ad-reset"', $html);
    }

    public function test_defaulters_loads_the_seeded_below_75_set_for_class12_m_october()
    {
        // Real seed fixture (probed through the endpoint): Class 12, section 70,
        // October 2026, threshold 75 -> 2 students below.
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.defaulters', ['class_id' => 13, 'section_id' => 70, 'month' => '2026-10']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('of 2 students', $html);
        $this->assertSame(2, substr_count($html, 'att-low-initials'), 'both below-75 rows render');
        $this->assertStringContainsString('<strong>2</strong>', $html);
    }

    public function test_defaulters_results_endpoint_reports_rows_and_fragments()
    {
        $admin = $this->user('admin@hackstra.app');

        $empty = $this->actingAs($admin)
            ->getJson(route('attendance.defaulters.results'))->assertOk();
        $empty->assertJson(['has_rows' => false]);
        $this->assertStringContainsString('Select a class and section', $empty->json('results'));

        $full = $this->actingAs($admin)
            ->getJson(route('attendance.defaulters.results', ['class_id' => 13, 'section_id' => 70, 'month' => '2026-10']))
            ->assertOk();
        $full->assertJson(['has_rows' => true]);
        $this->assertStringContainsString('att-low-table', $full->json('results'));
        $this->assertStringContainsString('<strong>2</strong>', $full->json('stats'));
        $this->assertStringContainsString('att-low-print-table', $full->json('print'));
    }

    public function test_defaulters_page_offers_a_teacher_only_her_sections()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('attendance.defaulters', ['class_id' => 13]))->assertOk()->getContent();

        $this->assertStringContainsString('<option value="76"', $html);
        $this->assertStringNotContainsString('<option value="64"', $html);
    }
}
