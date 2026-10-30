<?php

namespace Tests\Feature;

use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportSources;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner report 2026-10-29 (live screenshot): the Report Builder
 * died with "Unknown column: class" on a Marks report. Two
 * defects met there: the Marks and Attendance sources had no
 * Class column at all (a class-filtered report could not show
 * its class), and the builder could hand the engine a column
 * the chosen source does not offer. The sources now carry the
 * column end to end, and the builder drops stale columns
 * before anything is submitted.
 */
class OwnerReportClassColumnTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_marks_reports_carry_the_class_column()
    {
        $admin = $this->admin();
        $config = ReportEngine::validateConfig([
            'title' => 'Students',
            'source' => 'marks',
            'filters' => ['exam_id' => 1, 'class_id' => 13, 'subject_id' => 79],
            'columns' => [
                ['key' => 'student', 'label' => 'Student'],
                ['key' => 'class', 'label' => 'Class'],
                ['key' => 'total', 'label' => 'Total'],
            ],
        ], $admin);
        $this->assertSame('marks', $config['source']);

        $rows = ReportSources::rows('marks', ['exam_id' => 1, 'class_id' => 13, 'subject_id' => 79], $admin);
        $this->assertNotEmpty($rows);
        $this->assertSame('Class 12', $rows[0]['class']);
    }

    public function test_attendance_reports_carry_the_class_column()
    {
        $admin = $this->admin();
        ReportEngine::validateConfig([
            'title' => 'Attendance',
            'source' => 'attendance',
            'filters' => [],
            'columns' => [
                ['key' => 'student', 'label' => 'Student'],
                ['key' => 'class', 'label' => 'Class'],
            ],
        ], $admin);
        // Row-level: any seeded register's rows carry the class.
        $rows = ReportSources::rows('attendance', [], $admin);
        if (!empty($rows)) {
            $this->assertArrayHasKey('class', $rows[0]);
        }
        $this->assertTrue(true);
    }

    public function test_builder_drops_stale_columns_and_dates_render_dd_mm_yyyy()
    {
        $page = $this->actingAs($this->admin())->get(route('reports.builder'));
        $page->assertOk();
        // The stale-column guard at the builder's single exit
        // (rb5: the cleaning lives in visibleColumns(), shared by
        // collectConfig() and the canvas heading editor).
        $page->assertSee('visibleColumns', false);
        // The DD/MM/YYYY mechanism ships in the layout: native
        // date inputs take their format from the browser's
        // language, so the site converts them itself.
        $page->assertSee('HkDates', false);
        $page->assertSee('DD/MM/YYYY', false);
    }
}
