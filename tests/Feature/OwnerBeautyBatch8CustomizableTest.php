<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use ZipArchive;

/**
 * Beauty Batch 8 (2026-11-07, audit group F: Customizable) plus the
 * principal-home bar defect Batch 7 flagged.
 *
 * F1 — Report documents (builder print/PDF blade, builder preview JS
 * name, and the Word export the audit missed) fell back to the vendor
 * demo school's name when the Settings name was blank. All now fall
 * back to the neutral placeholder "School"; a set name renders
 * exactly as before (control test below).
 *
 * F2 — NOT BUILT: the audit itself tags the brand-colour setting
 * "(Owner decision.)". No setting key, field or CSS consumption was
 * added; the decision is the owner's.
 *
 * F3 — The guest legal pill could only break at its separator dots:
 * each item is wrapped in a .login-a-legal-item span and one appended
 * qs.css rule makes those spans unbreakable. Root-cause bonus: the
 * login page carried its own INLINE copy of the pill instead of the
 * shared guest_shell_close partial the other four guest pages use —
 * it now includes the partial, so the five pages cannot drift again.
 *
 * Principal bars — Batch 7 flagged "navy fill on dark track". Measured
 * root cause is different: the dark fill colour already existed and
 * computed correctly (#6ea8ff), but the fill is an EMPTY span rendered
 * as an inline box, so its width/height never applied and the fill
 * painted 0x0 in BOTH themes. Fix: display:block on .pr-bar-fill in
 * the page-private style block; colours untouched in both themes.
 */
class OwnerBeautyBatch8CustomizableTest extends TestCase
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

    private function blankConfig(): array
    {
        return [
            'title' => 'Batch 8 Report',
            'subtitle' => '',
            'source' => 'blank',
            'filters' => [],
            'columns' => [
                ['key' => 'custom_1', 'label' => 'Note', 'type' => 'text', 'width' => 50],
            ],
            'options' => [
                'orientation' => 'portrait',
                'margin_mm' => 10,
                'font_size' => 11,
                'show_header' => true,
                'show_meta' => true,
                'show_footer' => true,
                'show_signatures' => false,
                'blank_rows' => 0,
                'totals' => [],
                'sort' => null,
            ],
            'instructions' => '',
        ];
    }

    // ---- F1 ----

    public function test_f1_no_document_surface_falls_back_to_the_demo_name()
    {
        $print = file_get_contents(resource_path('views/pages/support_team/reports/print.blade.php'));
        $builder = file_get_contents(resource_path('views/pages/support_team/reports/builder.blade.php'));
        $word = file_get_contents(app_path('Support/Reports/WordExporter.php'));

        $this->assertStringNotContainsString('Hackstra Academy', $print);
        $this->assertStringNotContainsString('Hackstra Academy', $word);
        $this->assertStringNotContainsString("?: 'Hackstra Academy'", $builder);
        $this->assertStringContainsString("?: 'School'", $builder);
        $this->assertStringContainsString("\$rpSchoolName = trim((string) (\$s['system_name'] ?? '')) ?: 'School';", $print);
    }

    public function test_f1_print_document_with_blank_school_name_uses_the_neutral_placeholder()
    {
        $this->setSetting('system_name', '');

        $response = $this->actingAs($this->user('admin@hackstra.app'))
            ->post(route('reports.preview_html'), ['config' => json_encode($this->blankConfig())]);

        $response->assertOk();
        $response->assertDontSee('Hackstra Academy', false);
        $response->assertSee('<div class="school">School</div>', false);
    }

    public function test_f1_print_document_with_a_set_school_name_is_unchanged()
    {
        // Control: the seeded demo name is a SET name, so it renders
        // exactly as before — the placeholder fires only when blank.
        $response = $this->actingAs($this->user('admin@hackstra.app'))
            ->post(route('reports.preview_html'), ['config' => json_encode($this->blankConfig())]);

        $response->assertOk();
        $response->assertSee('<div class="school">Hackstra Academy</div>', false);
    }

    public function test_f1_word_export_with_blank_school_name_uses_the_neutral_placeholder()
    {
        $this->setSetting('system_name', '');

        $response = $this->actingAs($this->user('admin@hackstra.app'))
            ->post(route('reports.word'), ['config' => json_encode($this->blankConfig())]);

        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'b8word') . '.docx';
        file_put_contents($tmp, $response->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true);
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($tmp);

        $this->assertIsString($xml);
        $this->assertStringNotContainsString('Hackstra Academy', $xml);
        $this->assertStringContainsString('School', $xml);
    }

    public function test_f1_builder_preview_name_falls_back_to_the_neutral_placeholder()
    {
        $this->setSetting('system_name', '');

        $response = $this->actingAs($this->user('admin@hackstra.app'))->get(route('reports.builder'));

        $response->assertOk();
        $response->assertSee('var SCHOOL_NAME = "School"', false);
    }

    // ---- F3 ----

    public function test_f3_legal_pill_items_are_unbreakable_spans()
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('<span class="login-a-legal-item">Current session:', false);
        $response->assertSee('<span class="login-a-legal-item"><a href=', false);
        $this->assertSame(4, substr_count($response->getContent(), 'login-a-legal-item'));

        $css = $this->css();
        $this->assertStringContainsString('.login-a-legal-item{white-space:nowrap}', $css);
        // Append-only discipline: the Batch 8 rule lands after the
        // Batch 7 guest rule it builds on.
        $this->assertGreaterThan(
            strpos($css, '.login-a-panel .form-control{border-radius:9px}'),
            strpos($css, '.login-a-legal-item{white-space:nowrap}')
        );
    }

    // ---- Principal bars (Batch 7 flag, root-caused) ----

    public function test_principal_bar_fill_is_a_block_box_with_its_dark_colour_kept()
    {
        $blade = file_get_contents(resource_path('views/pages/principal/home.blade.php'));

        $this->assertStringContainsString('.pr-bar-fill{display:block;', $blade);
        $this->assertStringContainsString('html.hk-dark .pr-bar-fill{background:#6ea8ff}', $blade);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('pr-bar-fill', false);
    }
}
