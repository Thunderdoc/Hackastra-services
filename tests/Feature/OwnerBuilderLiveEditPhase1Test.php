<?php

namespace Tests\Feature;

use App\Support\Reports\ReportEngine;
use App\Support\Reports\ReportSources;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Report Builder live-edit fix, Round 3 Phase 1 (2026-11-07):
 *
 *  (a) preview-html carries freshness headers — X-RB-Generated-At
 *      and X-RB-Records matching the resolved data_count — so the
 *      statusbar can prove the document is from *now*.
 *  (b) Blade pins for the response discipline: AbortController,
 *      cache: 'no-store', non-200 responses never reach srcdoc,
 *      localStorage draft autosave/restore, visibilitychange
 *      auto-refresh, and the statusbar Refresh button.
 *  (c) status_options are declared per source (additive — the
 *      filters map is untouched), the builder payload carries
 *      them, and a defaulter preview with the select's stored
 *      value 'partial' returns rows (the old free-text trap,
 *      where the displayed word "Partial" silently returned 0,
 *      is gone from the UI contract).
 */
class OwnerBuilderLiveEditPhase1Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function defaultersConfig(array $filters): array
    {
        return [
            'title' => 'Fee Defaulters',
            'source' => 'defaulters',
            'filters' => $filters,
            'columns' => [
                ['key' => 'student', 'label' => 'Student', 'type' => 'text', 'width' => 30],
                ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 12],
            ],
            'options' => [],
        ];
    }

    // (a) ---------------------------------------------------------

    public function test_preview_html_carries_freshness_headers_matching_data_count()
    {
        $admin = $this->admin();
        $config = $this->defaultersConfig([]);
        $doc = ReportEngine::resolve(ReportEngine::validateConfig($config, $admin), $admin);

        $response = $this->actingAs($admin)
            ->post(route('reports.preview_html'), ['config' => json_encode($config)]);
        $response->assertOk();
        $this->assertNotNull($response->headers->get('X-RB-Generated-At'), 'preview must stamp when it was generated');
        $this->assertSame((string) $doc['data_count'], $response->headers->get('X-RB-Records'), 'X-RB-Records must equal the resolved data_count');
    }

    // (b) ---------------------------------------------------------

    public function test_blade_has_abort_nostore_and_status_discipline()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('AbortController', $html);
        $this->assertStringContainsString("cache: 'no-store'", $html);
        // The status-check branch: 401/419 names the expired
        // session and only 200 reaches the frame swap.
        $this->assertStringContainsString('Session expired — your draft is saved on this device; reload to sign in again', $html);
        $this->assertStringContainsString('res.status === 401', $html);
        $this->assertStringContainsString('res.status === 422', $html);
        $this->assertStringContainsString('if (!res.ok)', $html);
        // The success-path swap (the LAST srcdoc assignment — the
        // 422 error-document swap is the first) happens only after
        // the discipline branches have run.
        $this->assertLessThan(
            strrpos($html, "el('previewFrame').srcdoc = res.text"),
            strpos($html, 'if (!res.ok)'),
            'the !ok branch must be checked before the success-path srcdoc assignment'
        );
        // Single scheduler with the honest states.
        $this->assertStringContainsString('function markDirty(source)', $html);
        $this->assertStringContainsString('Editing…', $html);
        $this->assertStringContainsString('X-RB-Generated-At', $html);
        $this->assertStringContainsString('X-RB-Records', $html);
    }

    public function test_blade_has_draft_autosave_restore_and_auto_refresh()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString("'rb5-draft-' + RB_USER_ID", $html);
        $this->assertStringContainsString('localStorage.setItem(DRAFT_KEY', $html);
        $this->assertStringContainsString('localStorage.getItem(DRAFT_KEY)', $html);
        $this->assertStringContainsString('Restored your unsaved draft', $html);
        $this->assertStringContainsString('id="rbStartFresh"', $html);
        $this->assertStringContainsString('visibilitychange', $html);
        $this->assertStringContainsString("window.addEventListener('focus'", $html);
        // The Refresh control lives in the statusbar row, and the
        // docbar keeps its settled shape (summary + two downloads).
        $this->assertStringContainsString('id="rbRefreshData"', $html);
        $this->assertStringContainsString('↻ Refresh data', $html);
    }

    // (c) ---------------------------------------------------------

    public function test_status_options_declared_for_the_six_sources()
    {
        $defs = ReportSources::definitions();

        $this->assertSame(['unpaid' => 'Unpaid', 'partial' => 'Partial'], $defs['defaulters']['status_options']);
        $this->assertSame(['issued' => 'Issued', 'returned' => 'Returned', 'overdue' => 'Overdue'], $defs['issues']['status_options']);
        $this->assertSame(['pending' => 'Pending', 'paid' => 'Paid', 'waived' => 'Waived'], $defs['fines']['status_options']);
        $this->assertSame(['bonafide' => 'Bonafide', 'transfer' => 'Transfer'], $defs['certificates']['status_options']);
        $this->assertSame(\App\Models\Circular::audiences(), $defs['circulars']['status_options']);
        $this->assertSame(\App\Models\Admission::STATUS_LABELS, $defs['admissions']['status_options']);

        // Additive only: sources without a status vocabulary
        // declare nothing, and the filters map is untouched.
        $this->assertArrayNotHasKey('status_options', $defs['students']);
        $this->assertSame(['class_id', 'section_id', 'status', 'year'], $defs['defaulters']['filters']);
    }

    public function test_builder_payload_carries_status_options()
    {
        $response = $this->actingAs($this->admin())->get(route('reports.builder'));
        $response->assertOk();
        $response->assertSee('"status_options":{"unpaid":"Unpaid","partial":"Partial"}', false);
        $response->assertSee('status_options', false);
        // The builder renders the select from those options.
        $response->assertSee('renderStatusControl', false);
    }

    public function test_defaulter_preview_with_select_value_partial_returns_rows()
    {
        $admin = $this->admin();

        $all = ReportEngine::resolve(
            ReportEngine::validateConfig($this->defaultersConfig([]), $admin), $admin);
        $this->assertGreaterThan(0, $all['data_count'], 'fixture: seeded defaulters must exist');

        $partial = ReportEngine::resolve(
            ReportEngine::validateConfig($this->defaultersConfig(['status' => 'partial']), $admin), $admin);
        $this->assertGreaterThan(0, $partial['data_count'], 'the stored key the select sends must return rows');

        $response = $this->actingAs($admin)
            ->post(route('reports.preview_html'), ['config' => json_encode($this->defaultersConfig(['status' => 'partial']))]);
        $response->assertOk();
        $this->assertSame((string) $partial['data_count'], $response->headers->get('X-RB-Records'));
        $response->assertSee('Partial', false);
    }
}
