<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent B round 2 — orphan/wasted-page + print-UI guards.
 *
 * Every test here pins a behaviour that was PROVEN over real HTTP with
 * curl/pdfinfo (page counts live in the Agent B report, not in these
 * assertions — binary PDF page counts are unreliable in-process, so
 * these tests assert status + content-type + size, and the HTML views
 * assert the closing blocks that used to spill alone).
 *
 * Seed facts used (same seed as the local server): Class 10 A =
 * my_class_id 11 / section 48; Class 12-E = my_class_id 13 / section
 * 68; attendance session 11 is a Class 11-A section period (40
 * students); exam 1 = Term 1 Examination 2026-2027.
 */
class OwnerPrintOrphanTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        // Reports & PDF Builder gate (overnight order 2026-10-29):
        // teacher + principal ONLY. Principal maps to admin sources,
        // so it reproduces the same students config as admin did.
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function assertPdfResponse($response)
    {
        $response->assertStatus(200);
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type', ''));
        $this->assertGreaterThan(1000, strlen($response->getContent()));
    }

    public function test_monthly_report_whole_class_12_pdf_returns_pdf()
    {
        // The 500 case: 520 rows exhausted dompdf at the stock 128M
        // before chunking + lean rows + the request-scoped backstop.
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.report.pdf', ['class_id' => 13]));
        $this->assertPdfResponse($response);
        $this->assertGreaterThan(100000, strlen($response->getContent()));
    }

    public function test_monthly_report_section_pdf_returns_pdf()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.report.pdf', ['class_id' => 13, 'section_id' => 68]));
        $this->assertPdfResponse($response);
    }

    public function test_register_pdf_returns_pdf_for_section_session()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.periods.pdf', ['session_id' => 11]));
        $this->assertPdfResponse($response);
    }

    public function test_register_print_view_contains_signatures()
    {
        // Browser print of the same register: the signature block used
        // to spill alone onto page 3; it must render on the register.
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.periods.print', ['session_id' => 11]));
        $response->assertStatus(200);
        $response->assertSee('Subject Teacher', false);
    }

    public function test_bulk_marksheet_class_10_section_returns_pdf()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('marks.bulk_download', [
                'exam_id' => 1, 'my_class_id' => 11, 'section_id' => 48,
            ]));
        $this->assertPdfResponse($response);
        $this->assertGreaterThan(100000, strlen($response->getContent()));
    }

    public function test_bulk_marksheet_class_12_section_returns_pdf()
    {
        // Class 12 cards carry 12 subject rows (whole-class subject
        // list) and spilled every card's signatures onto a second page
        // (80 pages for 40 students) until the report-card tightening.
        $response = $this->actingAs($this->admin())
            ->get(route('marks.bulk_download', [
                'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
            ]));
        $this->assertPdfResponse($response);
        $this->assertGreaterThan(100000, strlen($response->getContent()));
    }

    public function test_single_marksheet_print_contains_signatures()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $response = $this->actingAs($this->admin())
            ->get(route('marks.print', [
                'id' => Qs::hash($student->id), 'exam_id' => 1, 'year' => '2026-2027',
            ]));
        $response->assertStatus(200);
        $response->assertSee('CLASS TEACHER', false);
        $response->assertSee('PRINCIPAL', false);
    }

    public function test_builder_landscape_with_signatures_returns_pdf()
    {
        // Landscape + signature lines: the signature block used to sit
        // alone on the last page (11 pages, p.11 = signatures only).
        // Caller is principal, not admin: the builder gate is now
        // teacher + principal only, so admin gets 403 (stale caller,
        // not a parity regression). Config/assertions unchanged.
        $response = $this->actingAs($this->principal())->post(route('reports.pdf'), [
            'config' => [
                'title' => 'Landscape Signature Test',
                'source' => 'students',
                'filters' => ['class_id' => 2],
                'columns' => [
                    ['key' => 'student', 'label' => 'Student'],
                    ['key' => 'adm_no', 'label' => 'Admission No'],
                ],
                'options' => [
                    'orientation' => 'landscape',
                    'margin_mm' => 10,
                    'font_size' => 11,
                    'show_header' => true,
                    'show_meta' => true,
                    'show_footer' => true,
                    'show_signatures' => true,
                    'blank_rows' => 0,
                ],
            ],
        ]);
        $this->assertPdfResponse($response);
    }

    public function test_id_card_bulk_html_has_initials_tiles_and_photo_column_rule()
    {
        // The bulk card's initials used to overflow into the student
        // name ("JSJayson"): the body table had no fixed layout and the
        // outer grid's `td { width: 50% }` beat the photo width. The
        // fix is a fixed-layout table with a percent photo column
        // (dompdf drops absolute widths in fixed layout) + initials in
        // their own tile block.
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('my_class_id', 11)
            ->where('section_id', 48)
            ->where('grad', 0)
            ->orderBy('adm_no')
            ->get();
        $this->assertNotEmpty($students);

        $html = view('pages.support_team.students.id_card_bulk_pdf', [
            'students' => $students,
            'my_class' => $students->first()->my_class,
            'section' => $students->first()->section,
            'school' => 'Hackstra Academy',
            'session' => '2026-2027',
        ])->render();

        // Count the tile DIVS, not the class name: the CSS rule itself
        // contains 'bdc-initials' once, which threw this count off by
        // one (41 vs 40) on the first run.
        $this->assertSame(
            $students->count(),
            substr_count($html, '<div class="bdc-initials">'),
            'One initials tile per card, no photo overlap path'
        );
        $this->assertStringContainsString('td.bdc-photo { width: 24%', $html);
    }

    /**
     * Agent B round 3 — payslip-all rupee fix: the "all" shell used
     * Arial/Helvetica, which dompdf maps to core Helvetica — no
     * U+20B9, so every ₹ printed as "?". The shell must lead with
     * DejaVu Sans, exactly like the single-payslip shell and the
     * receipt/builder shells. (Glyph rendering itself is proven by
     * curl + pdftotext in the round-3 report, not in-process.)
     */
    public function test_payslip_all_shell_uses_rupee_capable_font()
    {
        $css = file_get_contents(resource_path(
            'views/pages/support_team/payroll/partials/payslip_pdf_styles.blade.php'
        ));
        $this->assertStringContainsString('"DejaVu Sans"', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/body\s*\{\s*font-family:\s*Arial/',
            $css,
            'The all-payslips shell must not lead with Arial/Helvetica'
        );

        $response = $this->actingAs(User::where('email', 'teacher@hackstra.app')->firstOrFail())
            ->get(route('my_payslips.pdf_all'));
        $this->assertPdfResponse($response);
    }

    /**
     * Agent B round 3 — builder totals on the FIRST column: the
     * totals row printed the "Total" label in column 0
     * unconditionally, so a first-column aggregate was computed
     * but never displayed. The engine value must exist AND the
     * blade must append it to the label cell.
     */
    public function test_builder_first_column_total_is_kept_and_rendered()
    {
        $config = \App\Support\Reports\ReportEngine::validateConfig([
            'title' => 'R3 Totals Guard',
            'source' => 'students',
            'columns' => [
                ['key' => 'roll_no', 'label' => 'Roll No'],
                ['key' => 'student', 'label' => 'Student'],
            ],
            'filters' => ['class_id' => 1],
            'options' => ['totals' => ['roll_no' => 'count']],
        ], $this->admin());
        $doc = \App\Support\Reports\ReportEngine::resolve($config, $this->admin());
        $this->assertSame(121, $doc['data_count']);
        $this->assertArrayHasKey('roll_no', $doc['totals_formatted']);
        $this->assertSame('121', $doc['totals_formatted']['roll_no']);

        $blade = file_get_contents(resource_path(
            'views/pages/support_team/reports/print.blade.php'
        ));
        $this->assertStringContainsString(
            "totals_formatted'][\$col['key']]",
            $blade,
            'The first totals cell must append its own aggregate'
        );
    }

    /**
     * Agent B round 3 — tabulation phone preview: at 360/390px the
     * sheet measured 532px wide (right columns cut off). The
     * screen-only block tightens fonts/padding and contains the
     * overflow in .tab-body; print pagination (2 pages) is proven
     * by curl in the round-3 report, not here.
     */
    public function test_tabulation_phone_screen_block_and_brace_balance()
    {
        $css = file_get_contents(public_path('assets/css/print_tabulation.css'));
        $this->assertStringContainsString('@media screen and (max-width: 480px)', $css);
        $this->assertStringContainsString('.tab-body { overflow-x: auto; }', $css);
        $stripped = preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertSame(
            substr_count($stripped, '{'),
            substr_count($stripped, '}'),
            'print_tabulation.css braces must balance after any edit'
        );
    }

    /**
     * Agent B round 6 — timetable print pages phone fit: the teacher
     * week print and the class print pages wrapped their wide grids
     * in .tt-wrap with position:relative only, so at 360px the whole
     * document scrolled sideways (~787px; class grid ~957px wide).
     * Both blades now give .tt-wrap an inner horizontal scroll on
     * screens (the accepted .l7-table-scroll pattern); the rule is
     * screen-only so landscape print pagination is untouched
     * (week print + class print both still 1 page).
     */
    public function test_timetable_print_wrap_scrolls_on_screen_only()
    {
        foreach (['mine_print', 'print'] as $blade) {
            $content = file_get_contents(resource_path(
                'views/pages/support_team/timetables/' . $blade . '.blade.php'
            ));
            $this->assertStringContainsString('@media screen', $content);
            $this->assertStringContainsString(
                '.tt-wrap { overflow-x: auto;',
                $content,
                $blade . ' .tt-wrap must scroll its grid internally on screens'
            );
            // The print block must not gain an overflow rule: print
            // geometry (1 landscape page) is proven by CDP, not by
            // clipping the grid inside a scroll container.
            $printBlock = substr($content, (int) strpos($content, '@media print'));
            $this->assertStringNotContainsString('overflow', $printBlock);
        }
    }

    /**
     * Agent B round 5 — certificate signature cap: the signature img
     * carried max-height only, so an extreme-aspect upload (10:1
     * rendered 600x60, still inside the 760px card; wider would
     * overflow it) had no bound. Both cert blades now cap width
     * at 320px; normal signatures (200x60) are unaffected.
     */
    /**
     * Agent B round 7 — phone-fit sweep: the two marksheet print
     * blades (term + annual) are standalone pages that do not load
     * qs.css, so they missed the house rule
     * (@media screen and (max-width: 991px) { .report-card {
     * overflow-x: auto } }) the on-screen marksheet already enjoys.
     * At 360px the term sheet pushed the document to 681px and the
     * annual sheet to 463px. Both blades now carry the same
     * screen-only scroll rule on .report-card; print geometry is
     * untouched and the dompdf blades (bulk_pdf, annual_pdf) never
     * include these blades, so no PDF surface moves.
     */
    public function test_marksheet_print_pages_scroll_on_screen_only()
    {
        foreach (['index', 'annual'] as $blade) {
            $content = file_get_contents(resource_path(
                'views/pages/support_team/marks/print/' . $blade . '.blade.php'
            ));
            $this->assertStringContainsString('@media screen', $content);
            $this->assertStringContainsString(
                '.report-card { overflow-x: auto;',
                $content,
                $blade . ' .report-card must scroll internally on screens'
            );
            // No @media print block exists in these blades; the only
            // print rule is @page — the fix cannot leak into print.
            $this->assertStringNotContainsString('@media print', $content);
        }
    }

    /**
     * Agent B round 7 — register print clip: the register table
     * (413px) was cut off by .doc's overflow:hidden on phone
     * screens — 124px of columns unreachable, no swipe, while docW
     * still read 360 (the clip hides the overflow instead of
     * scrolling the page). The blade is shared with the dompdf PDF,
     * so the fix is screen-only: at PDF width the table fits inside
     * .doc, so auto creates no scroll there either way, and print
     * keeps overflow:hidden.
     */
    public function test_register_print_doc_scrolls_on_screen_only()
    {
        $content = file_get_contents(resource_path(
            'views/pages/support_team/attendance_periods/print.blade.php'
        ));
        $this->assertStringContainsString('@media screen', $content);
        $this->assertStringContainsString(
            '.doc { overflow-x: auto;',
            $content,
            'register .doc must scroll internally on screens'
        );
        $printBlock = substr($content, (int) strpos($content, '@media print'));
        $this->assertStringNotContainsString('overflow', $printBlock);
    }

    public function test_certificate_signature_has_max_width()
    {
        foreach (['print_bonafide', 'print_transfer'] as $blade) {
            $content = file_get_contents(resource_path(
                'views/pages/support_team/certificates/' . $blade . '.blade.php'
            ));
            $this->assertStringContainsString(
                'alt="Principal signature" style="max-height: 60px; max-width: 320px;"',
                $content,
                $blade . ' signature img must carry the 320px width cap'
            );
        }
    }

    /**
     * Agent B round 8 — monthly Status pill + % bar clip on phones:
     * the fixed table squeezes the Status column to ~38px at 360px
     * while the pill needs 60-100px ("No Records" 72px, "Needs
     * Attention" wider) and the bar+pct needs ~106px, so both clip
     * inside their overflow:hidden cells on screen (print is fine —
     * A4 columns are wide). Screen-only fix: auto layout on phones
     * lets columns size to content, and the doc scrolls internally.
     * Print keeps the fixed layout (2 pages, proven by CDP + dompdf).
     */
    public function test_monthly_print_table_sizes_to_content_on_phones_only()
    {
        $content = file_get_contents(resource_path(
            'views/pages/support_team/attendance/partials/monthly_report_document.blade.php'
        ));
        $screenBlock = substr($content, (int) strpos($content, '@media screen'));
        $this->assertStringContainsString('@media screen', $content);
        $this->assertStringContainsString(
            '.mar-table { table-layout: auto;',
            $screenBlock,
            'monthly table must drop fixed layout on phone screens so the pill and % bar fit their columns'
        );
        $this->assertStringContainsString(
            '.mar-doc { overflow-x: auto;',
            $screenBlock,
            'monthly doc must scroll the widened table internally on phone screens'
        );
        // The print block must keep the fixed layout and gain no
        // overflow rule: print geometry (2 pages) is proven by CDP.
        // (The screen block follows the print block in this blade,
        // so bound the print slice at '@media screen'.)
        $printStart = (int) strpos($content, '@media print');
        $screenStart = (int) strpos($content, '@media screen');
        $printBlock = substr($content, $printStart, $screenStart - $printStart);
        $this->assertStringNotContainsString('table-layout: auto', $printBlock);
        $this->assertStringNotContainsString('overflow', $printBlock);
    }

    public function test_monthly_print_table_sizes_to_content_on_tablet_widths_too()
    {
        // B round 9: the round-8 screen block stopped at 480px, but the
        // clip window runs to ~615px — at 481px the "No Records" pill
        // (72px) overhung its fixed-layout cell by 21px and clipped
        // inside td overflow:hidden; at 600px still by 2px; at 620px
        // clean. The breakpoint must therefore exceed 480 — AND stay
        // under dompdf's ~595pt A4-paper-width evaluation threshold:
        // 767px leaked into dompdf and ballooned the whole-class PDF
        // from 12 to 20 pages with wrapped pills. 594px satisfies both.
        $content = file_get_contents(resource_path(
            'views/pages/support_team/attendance/partials/monthly_report_document.blade.php'
        ));
        $screenBlock = substr($content, (int) strpos($content, '@media screen'));
        $this->assertMatchesRegularExpression(
            '/max-width:\s*(\d+)px/',
            $screenBlock,
            'monthly screen block must carry a max-width breakpoint'
        );
        preg_match('/max-width:\s*(\d+)px/', $screenBlock, $m);
        $breakpoint = (int) $m[1];
        $this->assertGreaterThan(
            480,
            $breakpoint,
            'monthly screen block must reach past the 480px phone edge into the tablet clip window'
        );
        $this->assertLessThan(
            595,
            $breakpoint,
            'monthly screen block must stay under dompdf\'s ~595pt paper-width threshold or it leaks into PDFs'
        );
        $this->assertStringContainsString(
            '.mar-table { table-layout: auto;',
            $screenBlock
        );
        $this->assertStringContainsString(
            '.mar-doc { overflow-x: auto;',
            $screenBlock
        );
    }
}
