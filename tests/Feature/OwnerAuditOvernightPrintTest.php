<?php

namespace Tests\Feature;

use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Reports\ReportEngine;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AUD-PRINT Audit Round 1 (overnight batch, verification only).
 * Item 11 (S5-Print): tabulation watermark must be transform-free.
 * Items 5/6 (S3-Builder): preview == download, >800 cap flash, honest empty.
 * Item 12 (S5-Print): timetable View/Print/Validate parity after index redesign.
 * No Chrome/CDP proof is claimed anywhere here (CSS/byte parity only).
 */
class OwnerAuditOvernightPrintTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function config(string $source, array $columns, array $filters = [], array $overrides = []): array
    {
        return [
            'title' => 'Audit Print Report',
            'subtitle' => '',
            'source' => $source,
            'filters' => $filters,
            'columns' => $columns,
            'options' => array_merge([
                'orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
                'show_header' => true, 'show_meta' => true, 'show_footer' => true,
                'show_signatures' => false, 'blank_rows' => 0, 'totals' => [], 'sort' => null,
            ], $overrides),
            'instructions' => '',
        ];
    }

    /** Extract a top-level CSS rule block for a selector (first occurrence). */
    protected function cssRule(string $css, string $selector): string
    {
        $pos = strpos($css, $selector);
        $this->assertNotFalse($pos, "selector {$selector} missing from print_tabulation.css");
        $open = strpos($css, '{', $pos);
        $close = strpos($css, '}', $open);
        $this->assertNotFalse($open, "rule block for {$selector} not closed");
        $this->assertNotFalse($close, "rule block for {$selector} not closed");
        return substr($css, $open + 1, $close - $open - 1);
    }

    // ---- Item 11: tabulation watermark, transform-free centering ----

    public function test_item11_watermark_selectors_have_no_transform_and_use_inset_zero_margin_auto()
    {
        $css = file_get_contents(public_path('assets/css/print_tabulation.css'));
        $this->assertNotFalse($css);
        foreach (['.tab-watermark', '.tab-watermark-mono'] as $sel) {
            $rule = $this->cssRule($css, $sel);
            $this->assertStringNotContainsStringIgnoringCase('transform', $rule, "{$sel} must not use transform (Chrome prints transformed fixed elements on page 1 only)");
            // Transform-free centering contract from the batch brief
            // (regexes, so compact `top:0` and spaced `top: 0` both pass):
            foreach (['top', 'left', 'right', 'bottom'] as $edge) {
                $this->assertMatchesRegularExpression('/' . $edge . '\s*:\s*0\b/', $rule, "{$sel} must centre with {$edge}: 0");
            }
            $this->assertMatchesRegularExpression('/margin\s*:\s*auto\b/', $rule, "{$sel} must centre with margin: auto");
            $this->assertMatchesRegularExpression('/position\s*:\s*fixed\b/', $rule, "{$sel} must stay position:fixed so it repeats per page");
        }
    }

    public function test_item11_tabulation_print_blade_repeats_header_per_page_by_construction()
    {
        $blade = file_get_contents(resource_path('views/pages/support_team/marks/tabulation/print.blade.php'));
        $this->assertStringContainsString('tab-hd-school', $blade);
        $this->assertStringContainsString('tab-hd-title', $blade);
        $this->assertStringContainsString('tab-hd-ctx', $blade);
        $this->assertStringContainsString('page-break-after: always', $blade);
        $css = file_get_contents(public_path('assets/css/print_tabulation.css'));
        $this->assertStringContainsString('display: table-header-group', $css);
    }

    // ---- Items 5/6: builder preview == download ----

    protected function normalizePdf(string $bytes): string
    {
        // dompdf embeds volatile metadata; parity is about the document,
        // not the timestamp. Strip only volatile fields, nothing else.
        $out = preg_replace('/\/CreationDate\s*\(D:[^)]*\)/', '/CreationDate ()', $bytes);
        $out = preg_replace('/\/ModDate\s*\(D:[^)]*\)/', '/ModDate ()', $out);
        $out = preg_replace('/\/ID\s*\[[^\]]*\]/', '/ID []', $out);
        return $out ?? $bytes;
    }

    public function test_items56_preview_bytes_match_download_bytes_for_same_config()
    {
        $teacher = $this->teacher();
        $cfg = $this->config('blank', [['key' => 'custom_1', 'label' => 'Note', 'type' => 'text', 'width' => 50]]);
        $preview = $this->actingAs($teacher)->post(route('reports.preview'), ['config' => json_encode($cfg)]);
        $preview->assertOk();
        $this->assertStringContainsString('application/pdf', $preview->headers->get('Content-Type', ''));
        $this->assertStringContainsString('inline', $preview->headers->get('Content-Disposition', ''));
        $this->assertStringStartsWith('%PDF', $preview->getContent());
        $download = $this->actingAs($teacher)->post(route('reports.pdf'), ['config' => json_encode($cfg)]);
        $download->assertOk();
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition', ''));
        $this->assertStringStartsWith('%PDF', $download->getContent());
        $this->assertSame(
            $this->normalizePdf($preview->getContent()),
            $this->normalizePdf($download->getContent()),
            'preview and download must be the same document (modulo PDF timestamps/IDs)'
        );
    }

    public function test_items56_over_800_rows_refuses_with_exact_cap_flash_on_both_paths()
    {
        $teacher = $this->teacher();
        $huge = $this->config('students', [['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30]], []);
        $pdf = $this->actingAs($teacher)->from(route('reports.builder'))->post(route('reports.pdf'), ['config' => json_encode($huge)]);
        $pdf->assertRedirect();
        $flash = session('flash_danger') ?? '';
        $this->assertMatchesRegularExpression('/^Too many rows for one PDF \(\d+\)\. Filter by class or section first, then generate\.$/', $flash);
        $preview = $this->actingAs($teacher)->post(route('reports.preview'), ['config' => json_encode($huge), 'preview_frame' => '1']);
        $preview->assertStatus(422);
        $this->assertStringContainsString('Too many rows for one PDF', $preview->getContent());
        $this->assertStringContainsString('Filter by class or section first, then generate.', $preview->getContent());
    }

    public function test_items56_empty_result_is_honest_empty_state_never_invented_rows()
    {
        $teacher = $this->teacher();
        $scoped = TeacherScope::classIds($teacher);
        $this->assertNotEmpty($scoped);
        $config = ReportEngine::validateConfig(
            $this->config('students', [['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30]], ['class_id' => $scoped[0], 'section_id' => 999999]),
            $teacher
        );
        $doc = ReportEngine::resolve($config, $teacher);
        $this->assertSame([], $doc['rows']);
        $this->assertSame(0, (int) $doc['data_count']);
        $html = view('pages.support_team.reports.print', ['doc' => $doc, 's' => ['system_name' => 'Hackstra Academy']])->render();
        $this->assertStringContainsString('No records found for the selected filters.', $html);
        // Honest empty at HTTP level too: preview returns a PDF, not a redirect or invented rows.
        $preview = $this->actingAs($teacher)->post(route('reports.preview'), ['config' => json_encode($this->config('students', [['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30]], ['class_id' => $scoped[0], 'section_id' => 999999]))]);
        $preview->assertOk();
        $this->assertStringContainsString('application/pdf', $preview->headers->get('Content-Type', ''));
    }

    // ---- Item 12: timetable View/Print/Validate parity ----

    public function test_item12_timetable_view_print_validate_parity()
    {
        $record = TimeTableRecord::orderBy('id')->first();
        $this->assertNotNull($record, 'snapshot must contain at least one timetable record');
        $admin = $this->admin();
        $index = $this->actingAs($admin)->get(route('tt.index'))->assertOk();
        $indexHtml = $index->getContent();
        $this->assertStringContainsString(route('ttr.show', $record->id), $indexHtml);
        $this->assertStringContainsString(route('ttr.print', $record->id), $indexHtml);
        $this->assertStringContainsString(route('ttr.validate', $record->id), $indexHtml);
        $show = $this->actingAs($admin)->get(route('ttr.show', $record->id))->assertOk();
        $print = $this->actingAs($admin)->get(route('ttr.print', $record->id))->assertOk();
        $validate = $this->actingAs($admin)->get(route('ttr.validate', $record->id))->assertOk();
        $className = optional($record->my_class)->name;
        $this->assertNotEmpty($className);
        foreach ([$show->getContent(), $print->getContent()] as $html) {
            $this->assertStringContainsString($className, $html);
            $this->assertStringContainsString((string) $record->year, $html);
        }
        if ($record->section_id) {
            $sectionName = optional($record->section)->name;
            if ($sectionName) {
                $this->assertStringContainsString($sectionName, $show->getContent());
                $this->assertStringContainsString($sectionName, $print->getContent());
            }
        }
        // Grid parity: every non-empty subject cell in the DB for this
        // record must appear in BOTH the view and the print document.
        $subjects = TimeTable::where('ttr_id', $record->id)->whereNotNull('subject_id')->with('subject')->get()
            ->map(function ($c) { return optional($c->subject)->name; })->filter()->unique()->values()->all();
        foreach ($subjects as $subjectName) {
            $this->assertStringContainsString($subjectName, $show->getContent(), "subject {$subjectName} missing from View");
            $this->assertStringContainsString($subjectName, $print->getContent(), "subject {$subjectName} missing from Print");
        }
        // Validate page must reference the same record/class.
        $this->assertStringContainsString($record->name, $validate->getContent());
    }
}
