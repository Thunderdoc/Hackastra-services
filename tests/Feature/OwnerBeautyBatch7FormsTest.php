<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Beauty Batch 7 (2026-11-07, audit group C: Forms C1-C7) plus the
 * fit defect Batch 6 flagged on the principal home.
 *
 * Presentation only: no field name, value, validation rule or POST
 * contract changed. C3 converts the two Settings term dates from
 * free-text US-format fields to the app's native date fields — the
 * POST still carries term_ends / term_begins, hk_date shows them
 * DD/MM/YYYY, and the one reader (report-card comments print,
 * strtotime) reads the saved ISO value as the same calendar date it
 * read the legacy text as. Legacy stored text still renders: the
 * blade normalises it to ISO for the date input (strtotime first,
 * then a strict d/m/Y read), pinned below.
 *
 * C5 note: the audit described the Bulk ID Cards / Substitutions
 * labels as a different STYLE (small, grey). Measured on the current
 * tree they already compute identically to the app standard label
 * (13px / 600 / same colour — normalised by earlier batches); the
 * only live drift was textual — trailing colons. The colon removals
 * are pinned here; the substitutions "Date" label already conforms.
 */
class OwnerBeautyBatch7FormsTest extends TestCase
{
    use DatabaseTransactions;

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    public function test_c1_leave_filter_labels_are_left_aligned()
    {
        $css = $this->css();
        $this->assertStringContainsString('.lv-fld{align-items:flex-start}', $css);
        $this->assertStringContainsString('.lv-fld label{text-align:left}', $css);

        $response = $this->actingAs($this->user('admin@hackstra.app'))->get('/leave');
        $response->assertOk();
        $response->assertSee('lv-filters', false);
    }

    public function test_c2_filter_bar_controls_share_one_height()
    {
        $css = $this->css();
        $this->assertStringContainsString(
            '.pat-filter-form .select2-container .select2-selection--single{height:40px}', $css);
        $this->assertStringContainsString(
            '.pat-filter-form .select2-container .select2-selection--single .select2-selection__rendered{line-height:38px}', $css);
        $this->assertStringContainsString(
            '.pat-filter-form .pat-filter-field input.form-control{height:40px}', $css);
    }

    public function test_c3_settings_term_dates_are_native_date_fields()
    {
        $this->setSetting('term_ends', '31/3/2027');
        $this->setSetting('term_begins', '1/6/2026');

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('settings'))->assertOk()->getContent();

        // Legacy d/m/Y text normalises to the same calendar date.
        $this->assertStringContainsString(
            'name="term_ends" id="term_ends" value="2027-03-31" type="date"', $html);
        // Legacy m/d/Y text keeps the meaning its reader already uses.
        $this->assertStringContainsString(
            'name="term_begins" id="term_begins" value="2026-01-06" type="date"', $html);
        // The US-format helper copy is gone, placeholders survive
        // (OwnerSettingsWorkflowRound1Test pins their counts).
        $this->assertStringNotContainsString('M-D-Y or M/D/Y format.', $html);
        $this->assertStringContainsString('placeholder="Date Term Ends"', $html);
        $this->assertStringContainsString('placeholder="Date Next Term Begins"', $html);
    }

    public function test_c3_report_card_comments_still_read_term_begins_the_same()
    {
        $this->setSetting('term_begins', '1/6/2026');
        $hash = Qs::hash($this->user('student@hackstra.app')->id);

        $response = $this->actingAs($this->user('admin@hackstra.app'))
            ->get("/marks/print/{$hash}/1/2026-2027");

        $response->assertOk();
        $response->assertSee('6th January, 2026', false);
    }

    public function test_c4_marks_import_source_select_has_the_wide_column()
    {
        $response = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get('/marks/manage/1/13/76/79');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression(
            '#<div class="col-md-4">\s*<label>Source</label>#', $html);
        $this->assertStringContainsString(
            '<div class="col-md-3" style="display:flex; align-items:flex-end;">', $html);
    }

    public function test_c5_filter_labels_carry_no_trailing_colons()
    {
        $admin = $this->user('admin@hackstra.app');

        $bulk = $this->actingAs($admin)->get('/students/id_cards/bulk');
        $bulk->assertOk();
        $bulk->assertSee('<label for="bulk_class">Class <span class="text-danger">*</span></label>', false);
        $bulk->assertSee('<label for="bulk_section">Section</label>', false);
        $bulk->assertDontSee('Class:', false);
        $bulk->assertDontSee('Section:', false);

        $payments = $this->actingAs($admin)->get('/payments/manage');
        $payments->assertOk();
        $payments->assertSee('<label for="my_class_id">Class</label>', false);
        $payments->assertDontSee('<label for="my_class_id">Class:</label>', false);
    }

    public function test_c6_users_filter_groups_are_flush_left()
    {
        $this->assertStringContainsString(
            '.l106-users-filter-group + .l106-users-filter-group{padding-left:0;border-left:none}',
            $this->css());

        $response = $this->actingAs($this->user('admin@hackstra.app'))->get('/users');
        $response->assertOk();
    }

    public function test_c7_guest_panel_fields_share_the_login_radius()
    {
        $this->assertStringContainsString(
            '.login-a-panel .form-control{border-radius:9px}', $this->css());

        $this->get('/admission-enquiry')->assertOk();
        $this->get('/password/reset')->assertOk();
    }

    public function test_fit_principal_home_cards_can_shrink_below_min_content()
    {
        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('.pr-cols>*{min-width:0}', false);
        $response->assertSee('repeat(3,minmax(0,1fr))', false);
        /* The finance money tiles retired with the owner-approved
           principal home sample v2 (2026-11-08): the figures now
           sit in the fees donut card. The fit promise is unchanged
           — every new grid's children can still shrink below
           min-content, and the fee figures keep an explicit size
           rule with its laptop step-down. */
        $response->assertSee('.pr2-kpis>*{min-width:0}', false);
        $response->assertSee('.pr2-row2>*{min-width:0}', false);
        $response->assertSee('.pr2-fv{margin-left:auto;font-size:16.5px;font-weight:800;white-space:nowrap;color:#1c2f47}', false);
        $response->assertSee('@media(max-width:1500px){.pr2-row2{grid-template-columns:1fr 1fr}}', false);
        $response->assertSee('@media(max-width:480px){.pr2-fee-flex{flex-direction:column;align-items:stretch}', false);
    }
}
