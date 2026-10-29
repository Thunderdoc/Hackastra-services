<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Tabulation print pagination (owner screenshot 2026-10-29):
 * Prep A has 41 students, and the greedy split printed them as
 * 21 + 19 + 1 — the last page carried a single student, the
 * summary and the Examination Officer / Principal signatures
 * on a near-empty page. The split now rebalances a stub last
 * page against its predecessor, so the signatures close a
 * well-filled final page.
 */
class OwnerTabulationPrintBalanceTest extends TestCase
{
    public function test_prep_a_print_has_no_orphan_last_page()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $html = $this->actingAs($admin)
            ->get(route('marks.print_tabulation', [1, 1, 1]))
            ->assertOk()
            ->getContent();

        // One marks table per printed page; count body rows in each.
        $chunks = preg_split('/<table class="tab-marks"/', $html);
        array_shift($chunks); // preamble before the first table
        $this->assertGreaterThanOrEqual(2, count($chunks), 'expected a multi-page sheet');

        $rowCounts = [];
        foreach ($chunks as $chunk) {
            $rowCounts[] = substr_count($chunk, '<td class="tab-sn">');
        }
        $this->assertSame(41, array_sum($rowCounts), 'every student prints exactly once: ' . implode('+', $rowCounts));
        $last = end($rowCounts);
        $this->assertGreaterThanOrEqual(8, $last, 'last page must not be a stub: ' . implode('+', $rowCounts));
        foreach ($rowCounts as $i => $n) {
            $this->assertLessThanOrEqual($i === count($rowCounts) - 1 ? 19 : 21, $n);
        }
        // Signatures close the document, after the summary.
        $this->assertStringContainsString('EXAMINATION OFFICER', $html);
        $this->assertStringContainsString('PRINCIPAL', $html);
    }
}
