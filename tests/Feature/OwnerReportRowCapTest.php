<?php

namespace Tests\Feature;

use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportSources;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Row cap (re-expressed 2026-10-30, owner order): one giant table
 * blows dompdf past 512M (unfiltered whole-school roster 500s with
 * an empty body), so resolve() still caps the rendered rows at
 * 800 — but it no longer REFUSES. An over-cap report renders its
 * first 800 rows with a truncation note inside the document, so
 * the builder never shows a blank page for Students + All
 * classes. A whole class (520) still resolves in full, no note.
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

    public function test_unfiltered_teacher_roster_truncates_with_note_not_refused()
    {
        $teacher = $this->teacher();
        // The teacher's scoped roster, read live — it must exceed
        // the cap for this test to mean anything.
        $total = count(ReportSources::rows('students', [], $teacher));
        $this->assertGreaterThan(ReportEngine::MAX_ROWS, $total);

        $config = ReportEngine::validateConfig($this->baseConfig([]), $teacher);
        $doc = ReportEngine::resolve($config, $teacher);
        $this->assertCount(ReportEngine::MAX_ROWS, $doc['rows']);
        $this->assertSame(ReportEngine::MAX_ROWS, $doc['data_count']);
        $this->assertSame(
            'Showing first ' . ReportEngine::MAX_ROWS . ' of ' . $total . ' rows — narrow by class or section for the complete list.',
            $doc['truncation_note']
        );
    }

    public function test_whole_class_roster_still_resolves()
    {
        $config = ReportEngine::validateConfig($this->baseConfig(['class_id' => 13]), $this->teacher());
        $doc = ReportEngine::resolve($config, $this->teacher());
        $this->assertNotEmpty($doc['rows']);
        $this->assertLessThanOrEqual(800, count($doc['rows']));
        $this->assertNull($doc['truncation_note']);
    }

    public function test_unfiltered_pdf_post_returns_the_truncated_document()
    {
        $config = $this->baseConfig([]);
        $response = $this->actingAs($this->teacher())->post(route('reports.pdf'), [
            'config' => json_encode($config),
        ]);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
