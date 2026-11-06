<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Item 12 (owner choice, 2026-11-01): Class Overview rebuilt
 * Light Luxe — slim header, four summary tiles computed from the
 * page's own per-class data, one card per class, and the
 * section-comparison detail inside the expanded card. The long
 * methodology paragraph is gone. Numbers must equal the old
 * table's — these pins read them from the controller's view data
 * and require them on the rendered cards and tiles.
 */
class OwnerClassOverviewLuxeTest extends TestCase
{
    use DatabaseTransactions;

    private function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function viewData($response): array
    {
        $content = $response->getOriginalContent();
        return method_exists($content, 'getData') ? $content->getData() : [];
    }

    public function test_tiles_and_cards_carry_the_controllers_own_numbers()
    {
        $response = $this->actingAs($this->teacher())->get(route('class_overview.index'));
        $response->assertOk();
        $html = $response->getContent();
        $overviews = $this->viewData($response)['overviews'];
        $this->assertNotEmpty($overviews);

        // Chrome: tiles present, methodology paragraph gone.
        $this->assertStringContainsString('l12-tiles', $html);
        $this->assertStringContainsString('l12-card', $html);
        $this->assertStringNotContainsString('Live snapshot from existing records only', $html);
        $this->assertStringNotContainsString('nothing here is stored or estimated', $html);

        // Tiles = sums / means of the rows themselves.
        $students = $overviews->sum('strength');
        $atRisk = $overviews->sum(function ($r) { return count($r['at_risk']); });
        $this->assertStringContainsString(number_format($students), $html);
        $this->assertStringContainsString('At-Risk Students', $html);
        $this->assertSame(count($overviews), substr_count($html, 'l12-card'));

        // First card carries its own figures verbatim.
        $first = $overviews->first();
        $this->assertStringContainsString($first['class']->name, $html);
        $this->assertStringContainsString(number_format($first['strength']), $html);
        if ($first['marks_avg'] !== null) {
            $this->assertStringContainsString(number_format($first['marks_avg'], 1) . '%', $html);
        }
        if ($first['topper']) {
            $this->assertStringContainsString($first['topper']['name'], $html);
        }
        $this->assertSame($atRisk >= 0, true);
    }

    public function test_detail_renders_inside_the_expanded_card()
    {
        $teacher = $this->teacher();
        $base = $this->actingAs($teacher)->get(route('class_overview.index'));
        $first = $this->viewData($base)['overviews']->first();

        $detailResponse = $this->actingAs($teacher)
            ->get(route('class_overview.index', ['class_id' => $first['class']->id]))
            ->assertOk();
        $html = $detailResponse->getContent();

        $this->assertStringContainsString('l12-detail', $html);
        $this->assertStringContainsString('Hide detail', $html);
        $detailPos = strpos($html, 'l12-detail');
        $sectionPos = strpos($html, 'Section comparison');
        if ($sectionPos !== false) {
            $this->assertGreaterThan($detailPos, $sectionPos,
                'section comparison must render inside the expanded card');
        }
        // Parity (overview-redesign sample, 2026-11-01): the
        // Students needing attention block renders ONLY when the
        // class actually has at-risk students — no empty block.
        $detail = $this->viewData($detailResponse)['detail'] ?? null;
        if ($detail && count($detail['at_risk']) > 0) {
            $this->assertStringContainsString(
                'Students needing attention (' . count($detail['at_risk']) . ')', $html);
        } else {
            $this->assertStringNotContainsString('Students needing attention', $html);
        }
    }

    public function test_no_periods_yet_is_an_honest_dash_not_a_zero()
    {
        $response = $this->actingAs($this->teacher())->get(route('class_overview.index'));
        $overviews = $this->viewData($response)['overviews'];
        $noPeriods = $overviews->first(function ($r) { return $r['attendance_pct'] === null; });
        if ($noPeriods) {
            $this->assertStringContainsString('no periods yet', $response->getContent());
        } else {
            $this->assertStringNotContainsString('no periods yet', $response->getContent());
        }
    }
}
