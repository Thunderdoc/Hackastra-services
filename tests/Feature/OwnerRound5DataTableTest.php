<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Round 5 Slice 3, P11 — the reusable data_table pattern
 * (partials/patterns/data_table) + migration batch 1.
 *
 *  - The component emits table + colgroup + thead itself, carries
 *    hk-std + the variant class, wraps in exactly ONE
 *    .table-responsive, renders sortable header links that toggle
 *    sort/dir while preserving the query string, and renders the
 *    empty_state pattern inside a single colspan cell when empty.
 *  - list_page gains pat_no_wrap so component users are not
 *    double-wrapped; default behaviour is unchanged.
 *  - Batch 1 migrations: payments _table / manage / collection /
 *    gateway_transactions, payroll index, library fines, book
 *    requests — every migrated table's column widths sum to 100
 *    and the private skins (l108-stack on these lists) are gone.
 *  - The substitutions board tables gain hk-std (their colgroups
 *    already complied) and the empty board uses the pat-empty
 *    table-row form.
 *  - Variant min-width rules ship as the P11 CSS fragment the
 *    coordinator appends to qs.css (same discipline as Round 3).
 */
class OwnerRound5DataTableTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function bladeSrc(string $rel): string
    {
        return file_get_contents(resource_path('views/' . $rel));
    }

    protected function fragment(): string
    {
        $path = base_path('../hk-tmp/round5/build/css-p11.fragment.css');
        if (!file_exists($path)) {
            $path = '/home/hatch/workspace/hk-tmp/round5/build/css-p11.fragment.css';
        }

        return file_get_contents($path);
    }

    /** Widths of the first colgroup in a rendered page. */
    protected function firstColgroupWidths(string $html): array
    {
        $this->assertSame(1, preg_match('/<colgroup>(.*?)<\/colgroup>/s', $html, $m), 'no colgroup found');
        preg_match_all('/width:(\d+)%/', $m[1], $w);

        return array_map('intval', $w[1]);
    }

    /* ---------------- (a) the component itself ---------------- */

    public function test_component_renders_colgroup_hkstd_single_wrap_and_rows()
    {
        $html = view('partials.patterns.data_table', [
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'width' => 50, 'sortable' => 'name'],
                ['key' => 'amount', 'label' => 'Amount', 'width' => 30, 'class' => 'hk-nw', 'align' => 'right', 'sortable' => 'amount'],
                ['key' => 'status', 'label' => 'Status', 'width' => 20, 'class' => 'hk-nw'],
            ],
            'variant' => 'testprobe',
            'min_width' => 700,
            'slot' => '<tr><td>Alpha</td><td class="text-right">10</td><td>Live</td></tr>',
        ])->render();

        // Colgroup carries the declared widths, contiguously.
        $this->assertStringContainsString(
            '<colgroup><col style="width:50%"><col style="width:30%"><col style="width:20%"></colgroup>',
            $html
        );
        // The standard + variant classes ride the table.
        $this->assertStringContainsString('table hk-std hk-std-testprobe', $html);
        // Exactly one responsive wrapper.
        $this->assertSame(1, substr_count($html, 'class="table-responsive"'));
        // The caller's rows are the body, untouched.
        $this->assertStringContainsString('<td>Alpha</td>', $html);
        // Sortable headers link into sort/dir; an inactive column
        // sorts ascending first, with no direction glyph yet.
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('dir=asc', $html);
        $this->assertStringNotContainsString('▲', $html);
        $this->assertStringNotContainsString('▼', $html);
    }

    public function test_sortable_link_toggles_dir_and_preserves_query()
    {
        $this->app->instance('request', Request::create('http://localhost/probe?sort=name&dir=asc&filter=live', 'GET'));

        $html = view('partials.patterns.data_table', [
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'width' => 60, 'sortable' => 'name'],
                ['key' => 'amount', 'label' => 'Amount', 'width' => 40, 'sortable' => 'amount'],
            ],
            'variant' => 'testprobe',
            'min_width' => 700,
            'slot' => '<tr><td>Alpha</td><td>10</td></tr>',
        ])->render();

        // The active column toggles to desc, keeps the rest of the
        // query string, and shows its current direction.
        $this->assertStringContainsString('sort=name', $html);
        $this->assertStringContainsString('dir=desc', $html);
        $this->assertStringContainsString('filter=live', $html);
        $this->assertStringContainsString('▲', $html);
        // The inactive column still offers its first (asc) sort.
        $this->assertStringContainsString('sort=amount', $html);
    }

    /* ---------------- (b) the empty form ---------------- */

    public function test_empty_config_renders_empty_state_inside_a_td()
    {
        $html = view('partials.patterns.data_table', [
            'columns' => [
                ['key' => 'a', 'label' => 'A', 'width' => 40],
                ['key' => 'b', 'label' => 'B', 'width' => 35],
                ['key' => 'c', 'label' => 'C', 'width' => 25],
            ],
            'variant' => 'testprobe',
            'min_width' => 700,
            'is_empty' => true,
            'empty' => ['icon' => 'icon-books', 'message' => 'Nothing here probe.'],
            'slot' => '<tr><td>must not render</td></tr>',
        ])->render();

        $this->assertStringContainsString('<td colspan="3">', $html);
        $this->assertStringContainsString('pat-empty', $html);
        $this->assertStringContainsString('Nothing here probe.', $html);
        $this->assertStringContainsString('icon-books', $html);
        $this->assertStringNotContainsString('must not render', $html);
        $this->assertSame(1, substr_count($html, 'class="table-responsive"'));
    }

    /* ---------------- list_page flag ---------------- */

    public function test_list_page_wraps_by_default_and_not_with_pat_no_wrap()
    {
        $wrapped = view('partials.patterns.list_page', ['slot' => '<p>probe</p>'])->render();
        $this->assertStringContainsString('<div class="table-responsive">', $wrapped);

        $bare = view('partials.patterns.list_page', [
            'pat_no_wrap' => true,
            'slot' => '<p>probe</p>',
        ])->render();
        $this->assertStringNotContainsString('table-responsive', $bare);
        $this->assertStringContainsString('<p>probe</p>', $bare);
    }

    /* ---------------- widths sum to 100 ---------------- */

    public function test_migrated_static_column_widths_sum_to_100()
    {
        $static = [
            'pages/support_team/payments/_table.blade.php',
            'pages/support_team/payments/collection.blade.php',
            'pages/support_team/payments/gateway_transactions.blade.php',
            'pages/support_team/payroll/index.blade.php',
            'pages/support_team/book_requests/index.blade.php',
        ];
        foreach ($static as $rel) {
            $blade = $this->bladeSrc($rel);
            $this->assertStringContainsString('partials.patterns.data_table', $blade, $rel . ' not migrated');
            preg_match_all("/'width' => (\d+)/", $blade, $m);
            $this->assertNotEmpty($m[1], $rel . ' declares no widths');
            $this->assertSame(100, array_sum(array_map('intval', $m[1])), $rel . ' widths must sum to 100');
        }
    }

    public function test_migrated_conditional_column_widths_sum_to_100_in_both_shapes()
    {
        // payments/manage: ternaries on $canFees + the appended
        // Payments column (28) in the fees shape only.
        $manage = $this->bladeSrc('pages/support_team/payments/manage.blade.php');
        preg_match_all("/'width' => \\\$canFees \? (\d+) : (\d+)/", $manage, $m);
        $this->assertNotEmpty($m[1], 'manage ternaries not found');
        $this->assertSame(100, array_sum(array_map('intval', $m[1])) + 28, 'manage with-fees widths');
        $this->assertSame(100, array_sum(array_map('intval', $m[2])), 'manage without-fees widths');

        // library_fines: ternaries on $lfShowAction, plus the fixed
        // columns (# 5, Days Late 8, Status 8, In Fees 8 = 29) in
        // both shapes and the appended Action column (6) in the
        // action shape only.
        $fines = $this->bladeSrc('pages/support_team/library_fines/index.blade.php');
        preg_match_all("/'width' => \\\$lfShowAction \? (\d+) : (\d+)/", $fines, $m);
        $this->assertNotEmpty($m[1], 'fines ternaries not found');
        $this->assertSame(100, array_sum(array_map('intval', $m[1])) + 29 + 6, 'fines with-action widths');
        $this->assertSame(100, array_sum(array_map('intval', $m[2])) + 29, 'fines without-action widths');
    }

    public function test_substitutions_colgroups_sum_to_100()
    {
        $blade = $this->bladeSrc('pages/support_team/substitutions/index.blade.php');
        preg_match_all('/<colgroup>(.*?)<\/colgroup>/s', $blade, $groups);
        $this->assertNotEmpty($groups[1]);
        foreach ($groups[1] as $group) {
            preg_match_all('/width:(\d+)%/', $group, $w);
            $this->assertSame(100, array_sum(array_map('intval', $w[1])), 'substitutions colgroup must sum to 100');
        }
    }

    public function test_migrated_lists_dropped_the_stack_skin()
    {
        foreach ([
            'pages/support_team/payments/manage.blade.php',
            'pages/support_team/book_requests/index.blade.php',
        ] as $rel) {
            $this->assertStringNotContainsString('l108-stack', $this->bladeSrc($rel), $rel . ' still carries the retired skin');
        }
    }

    /* ---------------- (c) rendered pages: single wrap ---------------- */

    public function test_payroll_index_renders_component_with_single_wrap()
    {
        $response = $this->actingAs($this->admin())->get(route('payroll.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('hk-std hk-std-payroll', $html);
        $this->assertSame(100, array_sum($this->firstColgroupWidths($html)), 'payroll colgroup must sum to 100');
        $normalized = preg_replace('/>\s+</', '><', $html);
        $this->assertStringNotContainsString('table-responsive"><div class="table-responsive', $normalized);
    }

    public function test_payments_manage_renders_component_with_single_wrap()
    {
        $response = $this->actingAs($this->admin())->get(route('payments.manage', 1));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('hk-std hk-std-paymanage', $html);
        $this->assertSame(100, array_sum($this->firstColgroupWidths($html)), 'manage colgroup must sum to 100');
        $normalized = preg_replace('/>\s+</', '><', $html);
        $this->assertStringNotContainsString('table-responsive"><div class="table-responsive', $normalized);
    }

    public function test_payments_table_partial_renders_through_the_component()
    {
        $row = new Payment([
            'title' => 'Probe Fee',
            'amount' => 1500,
            'ref_no' => 'PROBE-1',
            'method' => 'cash',
            'description' => 'Probe description',
        ]);
        $row->id = 1;

        $html = view('pages.support_team.payments._table', [
            'rows' => collect([$row]),
            'total_label' => 'Total (1 payments)',
        ])->render();

        $this->assertStringContainsString('hk-std hk-std-paydefs', $html);
        $this->assertStringContainsString('Probe Fee', $html);
        $this->assertStringContainsString('Total (1 payments)', $html);
        $this->assertSame(1, substr_count($html, 'class="table-responsive"'));
        $this->assertSame(100, array_sum($this->firstColgroupWidths($html)), '_table colgroup must sum to 100');
    }

    /* ---------------- (d) substitutions board ---------------- */

    public function test_substitutions_board_carries_hkstd_and_pat_empty_row()
    {
        $response = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $response->assertOk();
        $html = $response->getContent();

        // The test baseline clears substitutions, so the board is
        // empty: the pat-empty table-row form carries the standard.
        $this->assertStringContainsString('hk-std hk-std-subsheet', $html);
        $this->assertStringContainsString('pat-empty', $html);
        $this->assertStringContainsString('<td colspan="8">', $html);

        $blade = $this->bladeSrc('pages/support_team/substitutions/index.blade.php');
        $this->assertStringContainsString('sub-plan-table hk-std hk-std-subplan', $blade);
        $this->assertStringContainsString('sub-sheet-table hk-std hk-std-subsheet', $blade);
    }

    /* ---------------- the CSS fragment ---------------- */

    public function test_fragment_carries_every_introduced_variant()
    {
        $css = $this->fragment();
        foreach ([
            '@media screen{.table-responsive>.hk-std-paydefs{min-width:880px}}',
            '@media screen{.table-responsive>.hk-std-paymanage{min-width:880px}}',
            '@media screen{.table-responsive>.hk-std-collection{min-width:880px}}',
            '@media screen{.table-responsive>.hk-std-gateway{min-width:1080px}}',
            '@media screen{.table-responsive>.hk-std-fines{min-width:960px}}',
            '@media screen{.table-responsive>.hk-std-loans{min-width:820px}}',
            '@media screen{.table-responsive>.hk-std-subplan{min-width:640px}}',
            '@media screen{.table-responsive>.hk-std-subsheet{min-width:920px}}',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
    }
}
