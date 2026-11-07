<?php

namespace Tests\Feature;

use App\Helpers\Mk;
use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Report card COMPLETE REDESIGN (owner order 2026-11-06: remove the
 * old design, build a new one — not a patch). Pins for the owner's
 * layout law, rendered through the real routes:
 *
 *  (a) ONE A4 page per student in the section bulk PDF — the Class
 *      12 stress case (12 subject rows incl. non-offered stream
 *      subjects, traits annex, comments) and the small Prep case
 *      both render exactly roster-size page counts. Page counting
 *      uses the PDF page-object markers (the Kids /Count in these
 *      streams is unreliable in-process).
 *  (b) The rendered card carries the FULL grading-system legend
 *      (the configured bands for the student's class type), the
 *      Settings school name, and — in the annual PDF — at least one
 *      embedded raster image (the principal signature / school
 *      stamp from Settings).
 *  (c) Header structure: in the shared partial's rendered markup
 *      the school logo precedes the school name, and the name sits
 *      inside the centered header block — the logo never shares a
 *      line with anything.
 *  (d) The term and annual adapters both render through the ONE
 *      shared document partial, so the surfaces cannot drift.
 */
class OwnerReportCardRedesignTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function pdfPageCount(string $bytes): int
    {
        return substr_count($bytes, '/Type /Page') - substr_count($bytes, '/Type /Pages');
    }

    public function test_bulk_pdf_class_12_section_is_exactly_one_page_per_student()
    {
        $students = StudentRecord::where('section_id', 68)->count();
        $this->assertGreaterThan(0, $students);

        $res = $this->actingAs($this->admin())
            ->get(route('marks.bulk_download', [
                'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 68,
            ]))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(
            $students,
            $this->pdfPageCount($bytes),
            'Class 12 bulk report cards must fit exactly one A4 page per student'
        );
    }

    public function test_bulk_pdf_prep_section_is_exactly_one_page_per_student()
    {
        $students = StudentRecord::where('section_id', 1)->count();
        $this->assertGreaterThan(0, $students);

        $res = $this->actingAs($this->admin())
            ->get(route('marks.bulk_download', [
                'exam_id' => 1, 'my_class_id' => 1, 'section_id' => 1,
            ]))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(
            $students,
            $this->pdfPageCount($bytes),
            'Prep bulk report cards must fit exactly one A4 page per student'
        );
    }

    public function test_annual_pdf_is_one_page_and_embeds_settings_brand_images()
    {
        $sr = StudentRecord::where('section_id', 68)->firstOrFail();

        $res = $this->actingAs($this->admin())
            ->get(route('marks.pdf_annual', [Qs::hash($sr->user_id), '2026-2027']))
            ->assertOk();
        $bytes = $res->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(1, $this->pdfPageCount($bytes), 'annual report card must fit one A4 page');
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($bytes, '/Subtype /Image'),
            'annual report PDF must embed the Settings signature/stamp images'
        );
    }

    public function test_card_html_carries_full_grading_legend_and_school_name()
    {
        $sr = StudentRecord::where('section_id', 68)->firstOrFail();
        $classTypeId = MyClass::findOrFail($sr->my_class_id)->class_type_id;
        $bands = Mk::getGradeList($classTypeId);
        $this->assertNotEmpty($bands, 'fixture: the class type must have configured grade bands');

        $html = $this->actingAs($this->admin())
            ->get(route('marks.print_annual', [Qs::hash($sr->user_id), '2026-2027']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Grading System', $html);
        foreach ($bands as $band) {
            $this->assertStringContainsString(
                $band->mark_from.' - '.$band->mark_to,
                $html,
                'grading legend must print band '.$band->name.' in full'
            );
        }
        $this->assertStringContainsString(Qs::getSetting('system_name'), $html);
        $this->assertStringContainsString('CLASS TEACHER', $html);
        $this->assertStringContainsString('PRINCIPAL', $html);
    }

    public function test_header_logo_precedes_centered_school_name()
    {
        $sr = StudentRecord::where('section_id', 68)->firstOrFail();

        $html = $this->actingAs($this->admin())
            ->get(route('marks.print', [
                'id' => Qs::hash($sr->user_id), 'exam_id' => 1, 'year' => '2026-2027',
            ]))
            ->assertOk()
            ->getContent();

        // Markup positions (the class= forms occur only in the
        // document markup, never in the stylesheet).
        $logoPos = strpos($html, 'class="rcd-logo-img"');
        if ($logoPos === false) {
            $logoPos = strpos($html, 'rc-logo-tile');
        }
        $this->assertNotFalse($logoPos, 'header must carry the school logo (image or monogram tile)');
        $headPos = strpos($html, 'class="rcd-head"');
        $this->assertNotFalse($headPos, 'header must be the centered rcd-head block');
        $namePos = strpos($html, 'class="rcd-school"');
        $this->assertNotFalse($namePos);
        $this->assertLessThan($namePos, $logoPos, 'the logo prints before the school name');
        $this->assertLessThan($logoPos, $headPos, 'the logo sits inside the centered header block');
        // The header block is centered by the shared stylesheet.
        $this->assertStringContainsString('.rcd-head{text-align:center;}', $html);
    }

    public function test_term_and_annual_adapters_share_one_document_partial()
    {
        $term = file_get_contents(resource_path('views/pages/support_team/marks/partials/report_card.blade.php'));
        $annual = file_get_contents(resource_path('views/pages/support_team/marks/partials/annual_report_card.blade.php'));

        $this->assertStringContainsString('partials.report_card_document', $term);
        $this->assertStringContainsString("'rcd_mode' => 'term'", $term);
        $this->assertStringContainsString('partials.report_card_document', $annual);
        $this->assertStringContainsString("'rcd_mode' => 'annual'", $annual);
    }
}
