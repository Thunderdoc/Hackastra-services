<?php

namespace Tests\Feature;

use App\Support\Reports\ReportEngine;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sweep fix: one giant table blows dompdf past 512M (unfiltered
 * whole-school roster 500s with an empty body). resolve() must
 * refuse over-cap reports with a plain message, never a 500 —
 * and a whole class (520) must still resolve fine.
 */
class OwnerReportRowCapTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function baseConfig(array $filters): array
    {
        return [
            'title' => 'Sweep Roster',
            'source' => 'students',
            'filters' => $filters,
            'columns' => [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 18],
            ],
            'options' => [
                'orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
                'show_header' => true, 'show_meta' => true, 'show_footer' => true,
                'show_signatures' => true, 'blank_rows' => 0, 'totals' => [], 'sort' => null,
            ],
        ];
    }

    public function test_unfiltered_teacher_roster_is_refused_not_rendered()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Too many rows/');
        $config = ReportEngine::validateConfig($this->baseConfig([]), $this->teacher());
        ReportEngine::resolve($config, $this->teacher());
    }

    public function test_whole_class_roster_still_resolves()
    {
        $config = ReportEngine::validateConfig($this->baseConfig(['class_id' => 13]), $this->teacher());
        $doc = ReportEngine::resolve($config, $this->teacher());
        $this->assertNotEmpty($doc['rows']);
        $this->assertLessThanOrEqual(800, count($doc['rows']));
    }

    public function test_unfiltered_pdf_post_redirects_with_plain_message()
    {
        $config = $this->baseConfig([]);
        $response = $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => json_encode($config),
        ]);
        $response->assertRedirect();
        $this->assertStringContainsString('Too many rows', session('flash_danger') ?? '');
    }
}
