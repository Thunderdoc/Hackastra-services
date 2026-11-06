<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportPresets;
use App\Support\Reports\ReportSources;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use ZipArchive;

/**
 * Owner simplify pass, 2026-11-01 ("we are making this for normal
 * use"): the Report Builder tells the truth everywhere —
 *
 *  1. Filters are declared ONCE per source in
 *     ReportSources::definitions() (the keys that source's row
 *     builder actually reads) and the builder shows exactly those.
 *  2. The browser-Print button is gone (Chrome printed the
 *     PDF-only letterhead layout broken); the docbar carries the
 *     two downloads — PDF (pakka final) and Word (worksheet).
 *  3. Students gain a real "Parent / Father No." column — the
 *     parent's phone from the record, not the student's own.
 *  4. Hand-typed custom columns can no longer be created; old
 *     templates carrying custom_N columns still render.
 *  6/7. The Word download is a real .docx of the same document;
 *     the Marks Entry Sheet preset renders every mark cell blank
 *     (blank_marks) even though marks exist.
 *  8. The summary line is computed from collectConfig() on every
 *     change; a blocked/failed render says "Document not updated"
 *     instead of describing the stale document. Empty filter
 *     options say what empty means ("All classes", …).
 *  9. The caption line under the docbar is deleted.
 * 10. Text typed into the document (title, subtitle, footer,
 *     column heading) lands in the canvas AND the PDF.
 * 11. The generated PDF repeats the school band on later pages
 *     and keeps rows flowing to the last page.
 */
class OwnerBuilderSimplifyTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** A seeded student whose parent carries a real phone number. */
    protected function studentWithParentPhone(): StudentRecord
    {
        return StudentRecord::where('grad', 0)
            ->whereHas('my_parent', function ($q) {
                $q->whereNotNull('phone')->where('phone', '!=', '');
            })
            ->with(['user', 'my_parent'])
            ->firstOrFail();
    }

    protected function studentsConfig(array $filters, array $columns, array $options = []): array
    {
        return [
            'title' => 'Students',
            'source' => 'students',
            'filters' => $filters,
            'columns' => $columns,
            'options' => $options,
        ];
    }

    // ---------------------------------------------------------------
    // Item 1 — one filter declaration per source, matching the resolver
    // ---------------------------------------------------------------

    public function test_every_source_declares_exactly_the_filters_its_resolver_reads()
    {
        $expected = [
            'students' => ['class_id', 'section_id'],
            'marks' => ['class_id', 'section_id', 'subject_id', 'exam_id'],
            'attendance' => ['class_id', 'section_id', 'subject_id', 'date_from', 'date_to'],
            'assignments' => ['class_id', 'section_id', 'subject_id', 'date_from', 'date_to'],
            'fees' => ['class_id', 'section_id', 'year', 'date_from', 'date_to'],
            'defaulters' => ['class_id', 'section_id', 'status', 'year'],
            'fines' => ['status'],
            'books' => ['class_id'],
            'issues' => ['status'],
            'staff' => [],
            // admissions' resolver reads a 'session' key nothing can
            // send — it is NOT declared, so the builder never offers
            // a session box that would do nothing.
            'admissions' => ['class_id', 'status'],
            'certificates' => ['status'],
            'classes' => [],
            'circulars' => ['status'],
            'timetables' => ['class_id', 'section_id'],
            'fee_summary' => ['class_id', 'year'],
            'attendance_summary' => ['class_id', 'subject_id', 'date_from', 'date_to'],
            'results_summary' => ['class_id', 'exam_id'],
            'library_summary' => [],
            'platform' => [],
            'blank' => [],
        ];
        $defs = ReportSources::definitions();
        $this->assertSame(array_keys($expected), array_keys($defs), 'source list changed — update the declaration map deliberately');
        foreach ($expected as $key => $filters) {
            $this->assertArrayHasKey('filters', $defs[$key], $key . ' must declare its filters');
            $this->assertSame($filters, $defs[$key]['filters'], $key . ' filter declaration drifted from its resolver');
        }
    }

    public function test_builder_page_carries_the_declarations_and_derives_its_map()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        // The declarations ride in the SOURCES payload…
        $response->assertSee('"filters":["class_id","section_id"]', false);
        $response->assertSee('"filters":["status"]', false);
        $response->assertSee('"filters":[]', false);
        // …and the visibility map is DERIVED from them, not a
        // second hand-maintained list that can drift.
        $response->assertSee('FILTER_KEY_GROUPS', false);
        $response->assertSee('SOURCES[srcKey].filters', false);
        // collectConfig() sends only the declared filters.
        $response->assertSee("groups.indexOf('class') !== -1", false);
    }

    // ---------------------------------------------------------------
    // Items 2, 6, 9 — docbar surface: two downloads, no Print, no caption
    // ---------------------------------------------------------------

    public function test_docbar_carries_two_downloads_and_no_print_button_or_caption()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $response->assertDontSee('id="rbDocPrint"', false);
        $response->assertSee('Download PDF', false);
        $response->assertSee('Download Word', false);
        $response->assertSee('id="rbWordForm"', false);
        $response->assertSee('rb-word-btn', false);
        // The caption segment under the docbar is deleted completely.
        $response->assertDontSee('The document is live: click its title', false);
        $response->assertDontSee('Print prints only this document', false);
    }

    // ---------------------------------------------------------------
    // Item 4 — no hand-typed custom columns; old templates still work
    // ---------------------------------------------------------------

    public function test_custom_column_creator_is_gone_but_old_templates_still_resolve()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $response->assertDontSee('__custom', false);
        $response->assertDontSee('Custom column', false);

        // Engine compatibility: a saved config carrying custom_N
        // columns (an old template) still validates and resolves.
        $config = ReportEngine::validateConfig([
            'title' => 'Old Template',
            'source' => 'blank',
            'filters' => [],
            'columns' => [
                ['key' => 'custom_1', 'label' => 'Handwritten', 'type' => 'text', 'width' => 40],
                ['key' => 'custom_2', 'label' => 'Done', 'type' => 'checkbox', 'width' => 10],
            ],
            'options' => [],
        ], $this->admin());
        $doc = ReportEngine::resolve($config, $this->admin());
        $this->assertSame(['custom_1', 'custom_2'], array_column($doc['columns'], 'key'));
        $this->assertNotEmpty($doc['rows']);
    }

    // ---------------------------------------------------------------
    // Item 8 — summary wiring + honest stale state + "All …" options
    // ---------------------------------------------------------------

    public function test_summary_is_computed_from_the_config_and_stale_renders_say_so()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        // updateSummary() reads the same collectConfig() object the
        // preview and downloads use — it cannot describe another
        // report than the settings on the left.
        $response->assertSee('var cfg = collectConfig();', false);
        // Blocked and failed renders name the stale document.
        $response->assertSee('Document not updated', false);
        // Empty means ALL — and the option says so.
        $response->assertSee('<option value="">All classes</option>', false);
        $response->assertSee('<option value="">All sections</option>', false);
        $response->assertSee('<option value="">All subjects</option>', false);
        $response->assertSee('<option value="">All exams</option>', false);
    }

    // ---------------------------------------------------------------
    // Item 3 — the real Parent / Father No.
    // ---------------------------------------------------------------

    public function test_parent_phone_column_resolves_the_parents_real_number()
    {
        $sr = $this->studentWithParentPhone();
        $defs = ReportSources::definition('students');
        $this->assertSame('Parent / Father No.', $defs['columns']['parent_phone'][0]);

        $config = $this->studentsConfig(
            ['class_id' => $sr->my_class_id, 'section_id' => $sr->section_id],
            [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'parent_phone', 'label' => 'Parent / Father No.', 'type' => 'text', 'width' => 20],
            ]
        );
        $response = $this->actingAs($this->admin())
            ->post(route('reports.preview_html'), ['config' => json_encode($config)]);
        $response->assertOk();
        $response->assertSee('Parent / Father No.', false);
        $response->assertSee($sr->my_parent->phone, false);
        $response->assertSee($sr->user->name, false);
    }

    // ---------------------------------------------------------------
    // Items 6 + 3 — the Word download is a real .docx of this document
    // ---------------------------------------------------------------

    public function test_word_download_is_a_real_docx_carrying_the_same_document()
    {
        $sr = $this->studentWithParentPhone();
        $config = $this->studentsConfig(
            ['class_id' => $sr->my_class_id, 'section_id' => $sr->section_id],
            [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'parent_phone', 'label' => 'Parent / Father No.', 'type' => 'text', 'width' => 20],
            ]
        );
        $response = $this->actingAs($this->admin())
            ->post(route('reports.word'), ['config' => json_encode($config)]);
        $response->assertOk();
        $this->assertStringContainsString('wordprocessingml', $response->headers->get('Content-Type'));
        $bytes = $response->getContent();
        $this->assertStringStartsWith('PK', $bytes, 'a .docx is a zip package');

        $tmp = tempnam(sys_get_temp_dir(), 'hkdocx') . '.docx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'the download must open as a zip');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertIsString($xml);
        $this->assertStringContainsString('Hackstra Academy', $xml);
        $this->assertStringContainsString($sr->user->name, $xml);
        $this->assertStringContainsString($sr->my_parent->phone, $xml);
        $this->assertStringContainsString('Parent / Father No.', $xml);
        // The document carries data, never the posted configuration.
        $this->assertStringNotContainsString('"source"', $xml);
        $this->assertStringNotContainsString('parent_phone', $xml);
    }

    // ---------------------------------------------------------------
    // Item 7 — Marks Entry Sheet: blank_marks + the teacher preset
    // ---------------------------------------------------------------

    protected function marksConfig(bool $blankMarks): array
    {
        return [
            'title' => 'Marks Entry Sheet',
            'source' => 'marks',
            'filters' => ['exam_id' => 1, 'class_id' => 13, 'section_id' => 64, 'subject_id' => 79],
            'columns' => [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'total', 'label' => 'Total', 'type' => 'number', 'width' => 10],
            ],
            'options' => ['totals' => ['total' => 'sum'], 'blank_marks' => $blankMarks],
        ];
    }

    public function test_blank_marks_renders_empty_mark_cells_while_marks_exist()
    {
        $admin = $this->admin();

        $filled = ReportEngine::resolve(
            ReportEngine::validateConfig($this->marksConfig(false), $admin), $admin);
        $this->assertGreaterThan(0, $filled['data_count']);
        $filledTotals = array_filter(array_column($filled['rows'], 'cells'), function ($cells) {
            return ($cells['total'] ?? '') !== '';
        });
        $this->assertNotEmpty($filledTotals, 'fixture: the unit must carry real totals without blank_marks');
        $compKeys = array_values(array_filter(array_column($filled['columns'], 'key'), function ($k) {
            return strpos($k, 'comp_') === 0;
        }));
        $this->assertNotEmpty($compKeys, 'fixture: English carries a configured scheme');
        $this->assertArrayHasKey('total', $filled['totals_formatted']);

        $blank = ReportEngine::resolve(
            ReportEngine::validateConfig($this->marksConfig(true), $admin), $admin);
        $this->assertSame($filled['data_count'], $blank['data_count'], 'the student list itself must not change');
        foreach ($blank['rows'] as $row) {
            $this->assertSame('', $row['cells']['total'], 'Total must render blank on the entry sheet');
            foreach ($compKeys as $compKey) {
                $this->assertSame('', $row['cells'][$compKey], $compKey . ' must render blank on the entry sheet');
            }
        }
        $this->assertArrayNotHasKey('total', $blank['totals_formatted'], 'a handwriting sheet must not print the real sums');
    }

    public function test_marks_entry_sheet_preset_is_offered_to_teachers_with_blank_marks()
    {
        $keys = array_column(ReportPresets::forRole('teacher'), 'key');
        $this->assertContains('teacher_marks_entry_sheet', $keys);
        $preset = ReportPresets::find('teacher_marks_entry_sheet');
        $this->assertSame('marks', $preset['config']['source']);
        $this->assertTrue($preset['config']['options']['blank_marks']);
    }

    // ---------------------------------------------------------------
    // Item 10 — writing in the document lands in canvas AND PDF
    // ---------------------------------------------------------------

    protected function typedConfig(): array
    {
        return [
            'title' => 'Typed Title Qzx',
            'subtitle' => 'Typed Subtitle Qzx',
            'source' => 'students',
            'filters' => ['class_id' => 1, 'section_id' => 1],
            'columns' => [
                ['key' => 'student', 'label' => 'Typed Heading Qzx', 'type' => 'text', 'width' => 30],
                ['key' => 'adm_no', 'label' => 'Admission No', 'type' => 'text', 'width' => 20],
            ],
            'options' => ['footer_text' => 'Typed Footer Qzx'],
        ];
    }

    public function test_typed_text_lands_in_the_canvas_document()
    {
        $response = $this->actingAs($this->admin())
            ->post(route('reports.preview_html'), ['config' => json_encode($this->typedConfig())]);
        $response->assertOk();
        $response->assertSee('TYPED TITLE QZX', false);
        $response->assertSee('Typed Subtitle Qzx', false);
        $response->assertSee('Typed Footer Qzx', false);
        $response->assertSee('Typed Heading Qzx', false);
    }

    /** Per-page text of a generated PDF (poppler), or skip. */
    protected function pdfPages(string $bytes): array
    {
        $bin = trim((string) shell_exec('command -v pdftotext'));
        if ($bin === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not available in this environment.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hkpdf') . '.pdf';
        file_put_contents($tmp, $bytes);
        $out = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' -');
        @unlink($tmp);
        return explode("\f", (string) $out);
    }

    public function test_typed_text_lands_in_the_pdf()
    {
        $response = $this->actingAs($this->admin())
            ->post(route('reports.pdf'), ['config' => json_encode($this->typedConfig())]);
        $response->assertOk();
        $pages = $this->pdfPages($response->getContent());
        $page1 = $pages[0] ?? '';
        $this->assertStringContainsString('TYPED TITLE QZX', $page1);
        $this->assertStringContainsString('Typed Subtitle Qzx', $page1);
        $this->assertStringContainsString('Typed Footer Qzx', $page1);
        $this->assertStringContainsString('Typed Heading Qzx', $page1);
    }

    // ---------------------------------------------------------------
    // Item 11 — the PDF repeats the band and fills its pages
    // ---------------------------------------------------------------

    public function test_pdf_repeats_the_school_band_and_rows_reach_the_last_page()
    {
        $config = $this->studentsConfig([], [
            ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
            ['key' => 'class', 'label' => 'Class', 'type' => 'text', 'width' => 16],
            ['key' => 'section', 'label' => 'Section', 'type' => 'text', 'width' => 12],
        ]);
        $response = $this->actingAs($this->admin())
            ->post(route('reports.pdf'), ['config' => json_encode($config)]);
        $response->assertOk();
        $pages = array_values(array_filter($this->pdfPages($response->getContent()), function ($p) {
            return trim($p) !== '';
        }));
        $this->assertGreaterThanOrEqual(3, count($pages), 'an 800-row report must span several pages');

        // The fixed letterhead repeats: page 2 carries the school
        // band and the record count, not a giant blank top.
        $this->assertStringContainsString('Hackstra Academy', $pages[1]);
        $this->assertStringContainsString('record(s)', $pages[1]);

        // Rows flow to the very end: the truncation note (printed
        // after the table) sits on the last page. The total is
        // computed from the same query the resolver runs — never a
        // hardcoded seed aggregate.
        $total = StudentRecord::where('grad', 0)->count();
        $last = $pages[count($pages) - 1];
        $this->assertStringContainsString('Showing first 800 of ' . $total . ' rows', $last);
    }
}
