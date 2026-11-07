<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\ExamDuty;
use App\Models\InventoryItem;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 Slice 3, P5 — empty-state + loading-state rollout.
 *
 *  - partials/patterns/empty_state gains an additive 'compact'
 *    prop (.pat-empty-compact: 18px padding, 40px medallion,
 *    .85rem message; CSS ships as the css-p5 fragment the
 *    coordinator appends to qs.css). The default form is pinned
 *    byte-for-byte by the pre-existing slices and must not move.
 *  - The sweep converts hand-rolled empties to the shared
 *    component in its three forms (panel / table-row colspan /
 *    filter-no-match with icon-search4 + Clear filters), in the
 *    audit's traffic order; all 23 .dashboard-empty role-home
 *    sites ride the compact form.
 *  - Rival idioms retired at the caller level: .lav-empty,
 *    .ttx-empty-state, .p96-empty, .au-empty, .l106-empty (plus
 *    .l103-empty / .fb-empty) have ZERO blade callers left; their
 *    qs.css rules are deleted by the coordinator from the spec
 *    in this build's report, never from this tree mid-wave.
 *  - Loading: marks' private l84-loading spinner is replaced by
 *    the shared .btn.is-loading; the ONE region standard stays
 *    Slice 1's skeleton kit (documented in its partial header);
 *    there is deliberately NO page-level loader (SSR).
 *
 * Users resolve by email, never hardcoded ids. Pages whose empty
 * state must be deterministic clear their own rows inside the
 * test transaction first (the shared seed baseline is not empty
 * for every module, and the transaction rolls the clears back).
 */
class OwnerRound5EmptyStatesTest extends TestCase
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

    protected function bladeSrc(string $rel): string
    {
        return file_get_contents(resource_path('views/' . $rel));
    }

    protected function fragment(): string
    {
        $path = base_path('../hk-tmp/round5/build/css-p5.fragment.css');
        if (!file_exists($path)) {
            $path = '/home/hatch/workspace/hk-tmp/round5/build/css-p5.fragment.css';
        }

        return file_get_contents($path);
    }

    /** Every blade source in the tree, concatenated once. */
    protected function allBlades(): string
    {
        $out = '';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -10) === '.blade.php') {
                $out .= file_get_contents($f->getPathname()) . "\n";
            }
        }

        return $out;
    }

    /* ---------------- the compact variant ---------------- */

    public function test_compact_variant_renders_compact_class_and_structure()
    {
        $html = view('partials.patterns.empty_state', [
            'icon' => 'icon-books',
            'message' => 'Compact probe.',
            'compact' => true,
        ])->render();

        $this->assertStringContainsString('<div class="pat-empty pat-empty-compact">', $html);
        $this->assertStringContainsString('pat-empty-icon', $html);
        $this->assertStringContainsString('Compact probe.', $html);
    }

    public function test_default_form_is_unchanged_without_the_compact_prop()
    {
        // The needle earlier slices pin: a bare .pat-empty div, the
        // icon medallion, and no compact modifier anywhere.
        $html = view('partials.patterns.empty_state', [
            'icon' => 'icon-books',
            'message' => 'Default probe.',
        ])->render();

        $this->assertStringContainsString('<div class="pat-empty">', $html);
        $this->assertStringContainsString('pat-empty-icon', $html);
        $this->assertStringNotContainsString('pat-empty-compact', $html);

        // The opt-in illustration plate still composes with compact.
        $art = view('partials.patterns.empty_state', [
            'message' => 'Art probe.',
            'hkEmptyArt' => true,
            'compact' => true,
        ])->render();
        $this->assertStringContainsString('pat-empty-art', $art);
        $this->assertStringContainsString('pat-empty-compact', $art);
    }

    public function test_compact_css_fragment_carries_the_design_rules()
    {
        $css = $this->fragment();
        $this->assertStringContainsString('.pat-empty-compact{padding:18px 12px}', $css);
        $this->assertStringContainsString('.pat-empty-compact .pat-empty-icon{', $css);
        $this->assertStringContainsString('height:40px', $css);
        $this->assertStringContainsString('width:40px', $css);
        $this->assertStringContainsString('.pat-empty-compact .pat-empty-msg{font-size:.85rem}', $css);
    }

    /* ---------------- rendered pages: the sweep ---------------- */

    public function test_notifications_page_uses_shared_empty_state_when_inbox_is_empty()
    {
        $teacher = $this->teacher();
        UserNotification::where('user_id', $teacher->id)->delete();

        $response = $this->actingAs($teacher)->get(route('notifications.index'));
        $response->assertOk();
        $response->assertSee('pat-empty', false);
        $response->assertSee('No notifications yet. When something happens for you, it will appear here.');
    }

    public function test_transport_page_uses_shared_empty_state_in_all_three_tables()
    {
        TransportAssignment::query()->delete();
        TransportVehicle::query()->delete();
        TransportStop::query()->delete();
        TransportRoute::query()->delete();

        $response = $this->actingAs($this->admin())->get(route('transport.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($html, 'pat-empty'));
        $this->assertStringContainsString('No routes yet.', $html);
        $this->assertStringContainsString('No vehicles yet.', $html);
        $this->assertStringContainsString('No assignments yet.', $html);
        // Table-row form: the shared block sits inside colspan cells.
        $this->assertStringContainsString('<td colspan="6">', $html);
        $this->assertStringContainsString('<td colspan="5">', $html);
        $this->assertStringContainsString('<td colspan="7">', $html);
    }

    public function test_my_duties_uses_shared_empty_state_table_row()
    {
        $teacher = $this->teacher();
        ExamDuty::where('teacher_id', $teacher->id)->delete();

        $response = $this->actingAs($teacher)->get(route('exam_duties.my'));
        $response->assertOk();
        $response->assertSee('pat-empty', false);
        $response->assertSee('No invigilation duties assigned yet.');
        $response->assertSee('<td colspan="5">', false);
    }

    public function test_admissions_board_uses_shared_empty_state_table_row()
    {
        Admission::query()->delete();

        $response = $this->actingAs($this->admin())->get(route('admissions.index'));
        $response->assertOk();
        $response->assertSee('pat-empty', false);
        $response->assertSee('No enquiries yet');
        $response->assertSee('<td colspan="8">', false);
    }

    public function test_inventory_uses_shared_empty_state_and_filter_no_match_form()
    {
        InventoryItem::query()->delete();
        $admin = $this->admin();

        $plain = $this->actingAs($admin)->get(route('inventory.index'));
        $plain->assertOk();
        $plain->assertSee('pat-empty', false);
        $plain->assertSee('No inventory items yet.');

        // With a search term the same cell switches to the
        // filter-no-match form: search icon + a Clear filters action.
        $filtered = $this->actingAs($admin)->get(route('inventory.index', ['q' => 'zzz-no-such-item']));
        $filtered->assertOk();
        $filtered->assertSee('No inventory items match these filters.');
        $filtered->assertSee('icon-search4', false);
        $filtered->assertSee('Clear filters');
    }

    /* ---------------- static retirement checks ---------------- */

    public function test_role_home_blades_no_longer_use_dashboard_empty()
    {
        foreach ([
            'pages/accountant/home.blade.php',
            'pages/librarian/home.blade.php',
            'pages/student/home.blade.php',
            'pages/super_admin/platform_home.blade.php',
            'pages/support_team/search_results.blade.php',
            'pages/support_team/dashboard.blade.php',
        ] as $rel) {
            $src = $this->bladeSrc($rel);
            $this->assertStringNotContainsString('dashboard-empty', $src, $rel . ' still carries the retired idiom');
            $this->assertStringContainsString('partials.patterns.empty_state', $src, $rel . ' did not gain the shared component');
            $this->assertStringContainsString("'compact' => true", $src, $rel . ' does not pass the compact prop');
        }
    }

    public function test_rival_empty_classes_have_no_blade_callers_left()
    {
        $all = $this->allBlades();
        foreach (['lav-empty', 'ttx-empty-state', 'p96-empty', 'au-empty', 'l106-empty', 'l103-empty', 'dashboard-empty'] as $class) {
            $this->assertStringNotContainsString($class, $all, $class . ' still has a blade caller');
        }
        // .fb-empty as a class of its own is gone too (the
        // l118-fb-empty-* table classes are a different idiom).
        $this->assertSame(0, preg_match_all('/["\s]fb-empty[\s"]/', $all), '.fb-empty still has a blade caller');
    }

    public function test_converted_pages_carry_the_shared_include()
    {
        foreach ([
            'pages/notifications/index.blade.php',
            'pages/support_team/admissions/index.blade.php',
            'pages/support_team/inventory/index.blade.php',
            'pages/support_team/transport/index.blade.php',
            'pages/support_team/transport/assignments.blade.php',
            'pages/support_team/transport/fees.blade.php',
            'pages/support_team/attendance/index.blade.php',
            'pages/support_team/attendance/my.blade.php',
            'pages/support_team/exams/admit_cards.blade.php',
            'pages/support_team/exams/admit_my.blade.php',
            'pages/support_team/exams/my_duties.blade.php',
            'pages/support_team/exams/date_sheet_approvals.blade.php',
            'pages/support_team/staff_attendance/index.blade.php',
            'pages/support_team/question_bank/index.blade.php',
            'pages/support_team/health_records/index.blade.php',
            'pages/support_team/discipline_records/index.blade.php',
            'pages/support_team/hostel/rooms/index.blade.php',
            'pages/support_team/hostel/allocations/index.blade.php',
            'pages/support_team/hostel/dorms/index.blade.php',
            'pages/support_team/lesson_plans/index.blade.php',
            'pages/parent/home.blade.php',
            'pages/parent/progress.blade.php',
            'pages/support_team/timetables/index.blade.php',
            'pages/support_team/payments/invoice.blade.php',
            'pages/support_team/payments/index.blade.php',
        ] as $rel) {
            $this->assertStringContainsString(
                'partials.patterns.empty_state',
                $this->bladeSrc($rel),
                $rel . ' was not converted to the shared component'
            );
        }
    }

    /* ---------------- loading standard ---------------- */

    public function test_marks_blades_use_shared_btn_is_loading_not_l84_loading()
    {
        $marks = $this->bladeSrc('pages/support_team/marks/index.blade.php');
        $this->assertStringContainsString("addClass('is-loading')", $marks);
        $this->assertStringNotContainsString('l84-loading', $marks);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/pages/support_team/marks'))
        );
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -10) === '.blade.php') {
                $this->assertStringNotContainsString(
                    'l84-loading',
                    file_get_contents($f->getPathname()),
                    $f->getFilename() . ' still uses the duplicated spinner'
                );
            }
        }
    }

    public function test_skeleton_partial_documents_the_one_region_standard()
    {
        $skel = $this->bladeSrc('partials/patterns/skeleton.blade.php');
        $this->assertStringContainsString('ONE REGION LOADING STANDARD', $skel);
        $this->assertStringContainsString('NO page-level loader', $skel);
    }
}
