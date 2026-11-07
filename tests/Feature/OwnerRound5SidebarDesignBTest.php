<?php

namespace Tests\Feature;

use App\Models\UserPreference;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 5 FINAL SLICE — sidebar rebuild to "Design B — Soft
 * Cards" (owner pick 2026-11-07; the Premium Tree design was
 * rejected outright) + P4 sidebar memory.
 *
 * B structure, as rendered: the rail root carries .hk-sb-b; menu
 * groups are card surfaces formed on the existing flat list
 * (header + following items — pinned in the appended qs.css
 * fragment); the user chip is .hk-sb-userchip; every submenu
 * parent carries a stable data-group key. Menu names, links and
 * order are UNTOUCHED — the href-sequence pins in the older
 * sidebar suites stay as they are and keep covering that.
 *
 * P4: sidebar_collapsed + sidebar_open_groups live in
 * user_preferences, mirroring the theme store exactly
 * (UserPreference keys/defaults + a MyAccountController AJAX
 * endpoint, route name my_account.sidebar_state — applied by the
 * coordinator with the fragment + tree deletion spec, so the
 * stylesheet pins below only go green once those land).
 */
class OwnerRound5SidebarDesignBTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function dashboardHtml(string $email): string
    {
        return $this->actingAs($this->user($email))
            ->get(route('dashboard'))->assertOk()->getContent();
    }

    /** The full opening tag of the <li> carrying $needle. */
    private function liTagFor(string $html, string $needle): string
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, 'needle not rendered: ' . $needle);
        $start = strrpos(substr($html, 0, $pos), '<li');
        $end = strpos($html, '>', $pos);

        return substr($html, $start, $end - $start + 1);
    }

    private function bodyTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<body class="[^"]*"/', $html, $m));

        return $m[0];
    }

    /* ---------------- Design B structure ---------------- */

    public function test_admin_sidebar_renders_the_design_b_structure()
    {
        $html = $this->dashboardHtml('admin@hackstra.app');

        // Rail root + user chip card.
        $this->assertStringContainsString('sidebar-expand-md hk-sb-b', $html);
        $this->assertStringContainsString('hk-sb-userchip', $html);
        // Group labels still render inside the rail (they head the
        // card surfaces the fragment forms).
        foreach (['Attendance', 'Communication', 'Management', 'Account'] as $group) {
            $this->assertStringContainsString('>' . $group . '</div>', $html, 'group label ' . $group);
        }
        // Every submenu parent carries its stable data-group key.
        foreach (['academics', 'administrative', 'payments', 'students', 'student-promotion', 'exams'] as $key) {
            $this->assertStringContainsString('data-group="' . $key . '"', $html, 'data-group ' . $key);
        }
        // The long label renders in full (the fragment wraps it —
        // it is never clipped to fit the rail).
        $this->assertStringContainsString('<span>Low Attendance (Below 75%)</span>', $html);
    }

    public function test_teacher_sidebar_renders_the_design_b_structure()
    {
        $html = $this->dashboardHtml('teacher@hackstra.app');

        $this->assertStringContainsString('sidebar-expand-md hk-sb-b', $html);
        // Teachers get the B user chip too (their branch never
        // rendered .sidebar-user before this slice).
        $this->assertStringContainsString('hk-sb-userchip', $html);
        foreach (['academics', 'students', 'exams'] as $key) {
            $this->assertStringContainsString('data-group="' . $key . '"', $html, 'data-group ' . $key);
        }
        $this->assertStringContainsString('<span>Low Attendance (Below 75%)</span>', $html);
    }

    public function test_active_item_is_a_plain_active_link_the_fragment_tints()
    {
        // The fragment paints .nav-link.active as tint + accent
        // bar; the markup contract is the same active class the
        // menu has always emitted.
        $teacher = $this->user('teacher@hackstra.app');
        $html = $this->actingAs($teacher)->get('/home')->assertOk()->getContent();
        $this->assertStringContainsString(
            'href="' . route('dashboard') . '" class="nav-link active"', $html);
    }

    public function test_stylesheet_is_design_b_and_the_tree_is_gone()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));

        // Design B fragment (appended by the coordinator).
        $this->assertStringContainsString('.hk-sb-b', $css);
        $this->assertStringContainsString('background:rgba(47,111,237,.17)', $css);
        $this->assertStringContainsString('width:3.5px', $css);
        $this->assertStringContainsString('border-radius:14px', $css);

        // Tree wholesale removal (deletion spec): connectors,
        // node dots and the tree motion keyframes must not survive.
        foreach (['hkTreeDraw', 'hkTreeItemIn', 'hkNodePulse',
                     'border:2px solid #33597d', '#59a9ff',
                     'linear-gradient(180deg, rgba(94,168,255,.55)',
                     'rgba(148,180,220', 'Sidebar Premium Tree',
                     'Sidebar tree motion', 'Sidebar submenu tree flow'] as $needle) {
            $this->assertStringNotContainsString($needle, $css, 'tree residue: ' . $needle);
        }
    }

    /* ---------------- P4: sidebar memory ---------------- */

    public function test_no_preference_user_gets_the_default_expanded_state()
    {
        $teacher = $this->user('teacher@hackstra.app');
        UserPreference::where('user_id', $teacher->id)->delete();

        $html = $this->dashboardHtml('teacher@hackstra.app');
        $this->assertStringNotContainsString('sidebar-xs', $this->bodyTag($html));
        $this->assertStringNotContainsString('hk-sb-remembered', $html);
        $this->assertSame([], UserPreference::sidebarOpenGroups($teacher->id));
        $this->assertFalse(UserPreference::sidebarCollapsed($teacher->id));
    }

    public function test_collapsed_preference_renders_the_rail_collapsed()
    {
        $admin = $this->user('admin@hackstra.app');
        UserPreference::set($admin->id, 'sidebar_collapsed', '1');

        $html = $this->dashboardHtml('admin@hackstra.app');
        $this->assertStringContainsString('sidebar-xs', $this->bodyTag($html));
    }

    public function test_remembered_groups_render_open_and_active_group_always_opens()
    {
        $admin = $this->user('admin@hackstra.app');
        UserPreference::set($admin->id, 'sidebar_open_groups', json_encode(['students']));

        // On the dashboard nothing is route-open: the remembered
        // Students group renders open, Exams stays closed.
        $html = $this->dashboardHtml('admin@hackstra.app');
        $this->assertStringContainsString('hk-sb-remembered',
            $this->liTagFor($html, 'data-group="students"'));
        $this->assertStringNotContainsString('nav-item-open',
            $this->liTagFor($html, 'data-group="exams"'));

        // On a Marks page the Exams group is route-open WITHOUT
        // the remembered marker, and the stored set is untouched.
        $html = $this->actingAs($admin)->get(route('marks.index'))->assertOk()->getContent();
        $exams = $this->liTagFor($html, 'data-group="exams"');
        $this->assertStringContainsString('nav-item-open', $exams);
        $this->assertStringNotContainsString('hk-sb-remembered', $exams);
        $this->assertSame(['students'], UserPreference::sidebarOpenGroups($admin->id));
    }

    public function test_sidebar_state_endpoint_round_trips()
    {
        $admin = $this->user('admin@hackstra.app');

        $this->actingAs($admin)
            ->post(route('my_account.sidebar_state'), [
                'sidebar_collapsed' => '1',
                'sidebar_open_groups' => ['exams', 'academics'],
            ])
            ->assertOk()
            ->assertJson([
                'sidebar_collapsed' => true,
                'sidebar_open_groups' => ['exams', 'academics'],
            ]);

        $this->assertTrue(UserPreference::sidebarCollapsed($admin->id));
        $this->assertSame(['exams', 'academics'], UserPreference::sidebarOpenGroups($admin->id));

        // The next render arrives in the remembered state.
        $html = $this->dashboardHtml('admin@hackstra.app');
        $this->assertStringContainsString('sidebar-xs', $this->bodyTag($html));
        $this->assertStringContainsString('hk-sb-remembered',
            $this->liTagFor($html, 'data-group="exams"'));

        // Either field may be saved alone.
        $this->actingAs($admin)
            ->post(route('my_account.sidebar_state'), ['sidebar_collapsed' => '0'])
            ->assertOk();
        $this->assertFalse(UserPreference::sidebarCollapsed($admin->id));
        $this->assertSame(['exams', 'academics'], UserPreference::sidebarOpenGroups($admin->id));
    }

    public function test_sidebar_state_endpoint_filters_bad_keys_and_stores_an_empty_set()
    {
        $admin = $this->user('admin@hackstra.app');

        $this->actingAs($admin)
            ->post(route('my_account.sidebar_state'), [
                'sidebar_open_groups' => ['exams', 'Bad Key!', 'exams', 'ok-key'],
            ])
            ->assertOk()
            ->assertJson(['sidebar_open_groups' => ['exams', 'ok-key']]);

        // The *_present marker stores "every group closed".
        $this->actingAs($admin)
            ->post(route('my_account.sidebar_state'), [
                'sidebar_open_groups_present' => '1',
                'sidebar_open_groups' => [],
            ])
            ->assertOk()
            ->assertJson(['sidebar_open_groups' => []]);
        $this->assertSame([], UserPreference::sidebarOpenGroups($admin->id));
    }
}
