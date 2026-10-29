<?php

namespace Tests\Feature;

use App\Models\PtmSlot;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Overnight menus stream (S6-MENUS): menu-link hiding + role gates.
 *
 * Items covered here:
 *  - 16: admin menu removals (Documents Vault, Alumni Directory,
 *    Elective Choices, Role Permissions, Transport, Inventory,
 *    Discipline Records, Parent Messages).
 *  - 7 (companion): Reports & PDF Builder out of the ADMIN menu
 *    only; teacher and principal keep their builder links. The
 *    server gate (teacher+principal-only) is owned elsewhere.
 *  - 17: teacher-side removals (Question Bank, PTM Meetings,
 *    Lesson Plans, Homework Tracker, Substitutions links) and the
 *    image-33 full removal (Lesson Plans: link + teacher gate).
 *  - 18: PTM scheduling admin-only (teacher keeps view).
 *  - 19: Substitutions decided by admin only (image-31 screen).
 *  - 20: Hostel out of admin; warden login path + gate.
 *  - 23: global Back button goes straight to Dashboard.
 *
 * The live sidebar is partials/menu (principal: pages/principal/
 * menu). pages/admin/menu is not included by any layout; its
 * listed lines are removed too and asserted by file content.
 */
class OwnerOvernightMenusTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function warden()
    {
        // A warden is a plain users row with user_type 'hostel'
        // (string column); no user_types seed row is needed for
        // login or for these gates.
        if (!User::where('email', 'warden@hackstra.app')->exists()) {
            DB::table('users')->insert([
                'name' => 'Warden Test',
                'email' => 'warden@hackstra.app',
                'code' => 'WARDEN1',
                'username' => 'warden',
                'password' => bcrypt('hackstra'),
                'user_type' => 'hostel',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return User::where('email', 'warden@hackstra.app')->firstOrFail();
    }

    protected function adminMenuBlade()
    {
        return file_get_contents(resource_path('views/pages/admin/menu.blade.php'));
    }

    public function test_admin_menu_hides_item16_and_item7_links()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();
        // Live admin sidebar: no Hostel, Transport or Inventory
        // link remains. The Reports & PDF Builder link was out in
        // the item-7 pass and is BACK on the owner's correction
        // (2026-10-29 evening — he uses the builder as Admin).
        $response->assertDontSee(route('hostel.rooms.index'), false);
        $response->assertDontSee(route('transport.index'), false);
        $response->assertDontSee(route('inventory.index'), false);
        $response->assertSee(route('reports.builder'), false);

        // pages/admin/menu blade: the listed LINKS are gone
        // (asserted by route name — the removal comments may still
        // name the items for history); the kept items stay.
        $blade = $this->adminMenuBlade();
        foreach (['documents.index', 'alumni.index', 'electives.index', 'role_permissions.index', 'discipline_records.index', 'parent_messages.index'] as $gone) {
            $this->assertStringNotContainsString($gone, $blade, $gone . ' must be out of the admin menu');
        }
        $this->assertStringContainsString('reports.builder', $blade, 'reports.builder is back in the admin menu (owner correction)');
        foreach (['Settings', 'Substitutions', 'PTM Meetings'] as $kept) {
            $this->assertStringContainsString($kept, $blade, $kept . ' stays in the admin menu');
        }
    }

    public function test_builder_link_removed_for_accountant_and_super_admin()
    {
        // AUD-ROLES F2 (item 7): the accountant-only block and the
        // super-admin menu linked a builder their roles cannot
        // open (teacher+principal-only gate) — clean-403 links.
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        $response = $this->actingAs($accountant)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee(route('reports.builder'), false);

        $saBlade = file_get_contents(resource_path('views/pages/super_admin/menu.blade.php'));
        $this->assertStringNotContainsString('reports.builder', $saBlade);
    }

    public function test_teacher_menu_hides_listed_links_keeps_others()
    {
        $response = $this->actingAs($this->teacher())->get(route('dashboard'));
        $response->assertOk();
        foreach (['Lesson Plans', 'Homework Tracker', 'Substitutions', 'Question Bank', 'PTM Meetings'] as $gone) {
            $response->assertDontSee($gone);
        }
        foreach (['Parent Messages', 'Discipline Records'] as $kept) {
            $response->assertSee($kept);
        }
        // Raw ampersand in the blade: assertSee would escape the
        // needle to &amp; and miss.
        $response->assertSee('Reports & PDF Builder', false);
    }

    public function test_principal_keeps_builder_link()
    {
        $response = $this->actingAs($this->principal())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Reports & Analytics');
        $response->assertSee(route('reports.builder'), false);
    }

    public function test_ptm_scheduling_is_admin_only_teacher_keeps_view()
    {
        $slot = [
            'date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
        ];

        $this->actingAs($this->teacher())->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($this->teacher())->post(route('ptm.store'), $slot)->assertForbidden();

        $view = $this->actingAs($this->teacher())->get(route('ptm.index'));
        $view->assertOk();
        $view->assertDontSee('New Slot');

        $this->actingAs($this->admin())->get(route('ptm.create'))->assertOk();
        $created = $this->actingAs($this->admin())->post(route('ptm.store'), $slot);
        $created->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', [
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
        ]);
        PtmSlot::where('teacher_id', $this->teacher()->id)->where('my_class_id', 1)->delete();
    }

    public function test_substitution_decisions_are_admin_only()
    {
        $this->actingAs($this->teacher())->get(route('substitutions.index'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('substitutions.create'))->assertForbidden();
        $this->actingAs($this->teacher())->post(route('substitutions.store'), [])->assertForbidden();

        $adminView = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $adminView->assertOk();
        // Owner order 2026-10-29 (night): the board is a record —
        // NOBODY gets a create action there any more; cover is
        // arranged from the leave flow.
        $adminView->assertDontSee('Arrange Substitution');

        $principalView = $this->actingAs($this->principal())->get(route('substitutions.index'));
        $principalView->assertOk();
        $principalView->assertDontSee('Arrange Substitution');
    }

    public function test_lesson_plans_fully_removed_for_teachers()
    {
        $this->actingAs($this->teacher())->get(route('lesson_plans.index'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('lesson_plans.create'))->assertForbidden();
        $this->actingAs($this->teacher())->post(route('lesson_plans.store'), [])->assertForbidden();

        // Admin and principal reads are untouched by the
        // teacher-side full removal.
        $this->actingAs($this->admin())->get(route('lesson_plans.index'))->assertOk();
        $this->actingAs($this->principal())->get(route('lesson_plans.index'))->assertOk();
    }

    public function test_global_back_goes_straight_to_dashboard()
    {
        $response = $this->actingAs($this->admin())->get(route('exam_dates.manage'));
        $response->assertOk();
        $response->assertDontSee('window.history.back', false);
        $response->assertSee('Back to dashboard', false);
        $response->assertSee(route('dashboard'), false);

        // Error pages keep their own Go-back links (out of scope).
        $this->assertStringContainsString(
            'history.back',
            file_get_contents(resource_path('views/errors/403.blade.php'))
        );
    }

    public function test_hostel_removed_from_admin_and_run_by_warden()
    {
        $this->actingAs($this->admin())->get(route('hostel.rooms.index'))->assertForbidden();
        $this->actingAs($this->admin())->post(route('hostel.dorms.store'), ['name' => 'Nope'])->assertForbidden();

        $warden = $this->warden();
        $this->actingAs($warden)->get(route('hostel.rooms.index'))->assertOk();
        $this->actingAs($warden)->get(route('hostel.dorms.index'))->assertOk();

        $created = $this->actingAs($warden)->post(route('hostel.dorms.store'), ['name' => 'Warden Test Dorm']);
        $created->assertRedirect();
        $this->assertDatabaseHas('dorms', ['name' => 'Warden Test Dorm']);

        // The warden's sidebar carries the hostel links.
        $rooms = $this->actingAs($warden)->get(route('hostel.rooms.index'));
        $rooms->assertSee(route('hostel.dorms.index'), false);
        $rooms->assertSee(route('hostel.allocations.index'), false);

        // Student/parent self-view is untouched.
        $this->actingAs($this->student())->get(route('hostel.my'))->assertOk();
    }
}
