<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Round 5 Slice 4 (P3) — command palette.
 *
 * The palette endpoint (GET /dashboard/search.json, route name
 * dashboard.search_json — added from the worker's route snippet)
 * and the HTML search page now read ONE shared query
 * (App\Support\DashboardSearch), so this suite pins both
 * surfaces at once:
 *
 *   - the endpoint answers 200 with the six-group shape for a
 *     data role (admin), catalogue included;
 *   - HOLE 1 CLOSED: a teacher's class results — palette AND
 *     page — contain only TeacherScope classes;
 *   - HOLE 2 CLOSED: the payments group is empty for a teacher
 *     on both surfaces, while admin and principal still get it;
 *   - roles the page 403s (student, accountant, librarian) get
 *     {pages, actions} ONLY from the endpoint — the data gate is
 *     not widened, no other student's record can surface;
 *   - the Super Admin stays platform-only: platform staff in
 *     the staff group, platform navigation, no actions, and no
 *     school entity anywhere;
 *   - the palette partial renders in the master layout and the
 *     Slice-1 Ctrl/Cmd+K registry entry now opens the palette
 *     (source pins, in the Slice-1 test's style).
 */
class OwnerRound5CommandPaletteTest extends TestCase
{
    use DatabaseTransactions;

    protected function file($rel)
    {
        return file_get_contents(base_path($rel));
    }

    protected function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    /** Aarav Sharma — Prep (class 1), inside the teacher's scope. */
    protected function ownStudent()
    {
        return $this->student();
    }

    /** A real student in a class the teacher does NOT teach (class 2). */
    protected function foreignStudent()
    {
        $record = StudentRecord::where('my_class_id', 2)->firstOrFail();

        return User::findOrFail($record->user_id);
    }

    protected function labels($response, $group)
    {
        return array_column($response->json($group) ?: [], 'label');
    }

    public function test_route_snippet_is_registered_in_the_auth_group()
    {
        $this->assertTrue(Route::has('dashboard.search_json'));
        $route = Route::getRoutes()->getByName('dashboard.search_json');
        $this->assertSame('dashboard/search.json', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    public function test_guest_palette_is_refused()
    {
        $this->getJson('/dashboard/search.json?q=Aarav')->assertUnauthorized();
    }

    public function test_admin_palette_shape_catalogue_and_data()
    {
        $response = $this->actingAs($this->admin())
            ->getJson('/dashboard/search.json?q=Aarav');

        $response->assertOk();
        $response->assertJsonStructure([
            'pages' => [['label', 'url', 'icon']],
            'actions' => [['label', 'url', 'icon']],
            'students', 'staff', 'classes', 'payments',
        ]);
        $this->assertContains('Dashboard', $this->labels($response, 'pages'));
        $this->assertContains('Admit Student', $this->labels($response, 'actions'));
        $this->assertContains('Aarav Sharma', $this->labels($response, 'students'));

        // Payments still flow for admin (the domain owner of the group).
        $payments = $this->actingAs($this->admin())
            ->getJson('/dashboard/search.json?q=Tuition');
        $payments->assertOk();
        $this->assertContains('Term 1 Tuition Fee', $this->labels($payments, 'payments'));
    }

    public function test_principal_palette_keeps_payments_and_approval_actions()
    {
        $response = $this->actingAs($this->principal())
            ->getJson('/dashboard/search.json?q=Tuition');

        $response->assertOk();
        $this->assertContains('Term 1 Tuition Fee', $this->labels($response, 'payments'));
        $this->assertContains('Date Sheet Approvals', $this->labels($response, 'actions'));
        // No admin write actions leak into the principal's catalogue.
        $this->assertNotContains('Admit Student', $this->labels($response, 'actions'));
    }

    public function test_teacher_palette_classes_scoped_and_payments_empty()
    {
        $teacher = $this->teacher();
        $scopeIds = TeacherScope::classIds($teacher);
        $this->assertNotEmpty($scopeIds, 'fixture teacher must have a scope');

        $ownClass = MyClass::findOrFail($scopeIds[0]);
        $foreignClass = MyClass::whereNotIn('id', $scopeIds)->firstOrFail();

        $own = $this->actingAs($teacher)
            ->getJson('/dashboard/search.json?q=' . urlencode($ownClass->name));
        $own->assertOk();
        $this->assertContains($ownClass->name, $this->labels($own, 'classes'));
        // Every class row returned sits inside the teacher's scope.
        foreach ($own->json('classes') as $row) {
            $class = MyClass::where('name', $row['label'])->first();
            $this->assertContains($class->id, $scopeIds);
        }

        $foreign = $this->actingAs($teacher)
            ->getJson('/dashboard/search.json?q=' . urlencode($foreignClass->name));
        $foreign->assertOk();
        $this->assertNotContains($foreignClass->name, $this->labels($foreign, 'classes'));

        // Payments: excluded for teachers even though the fee exists.
        $payments = $this->actingAs($teacher)
            ->getJson('/dashboard/search.json?q=Tuition');
        $payments->assertOk();
        $this->assertSame([], $payments->json('payments'));

        // Students stay TeacherScope-scoped on the palette too.
        $foreignStudent = $this->foreignStudent();
        $studentSearch = $this->actingAs($teacher)
            ->getJson('/dashboard/search.json?q=' . urlencode($foreignStudent->name));
        $studentSearch->assertOk();
        $this->assertNotContains($foreignStudent->name, $this->labels($studentSearch, 'students'));

        $ownSearch = $this->actingAs($teacher)
            ->getJson('/dashboard/search.json?q=Aarav');
        $ownSearch->assertOk();
        $this->assertContains('Aarav Sharma', $this->labels($ownSearch, 'students'));
    }

    public function test_html_page_holes_are_closed_for_a_teacher_too()
    {
        // The shared query means the PAGE inherits the fixes —
        // pin them there, not just on the JSON surface. (Round 30
        // asserted the old unscoped behaviour as "as-coded"; the
        // P3 design closes those holes deliberately.)
        $teacher = $this->teacher();
        $scopeIds = TeacherScope::classIds($teacher);
        $foreignClass = MyClass::whereNotIn('id', $scopeIds)->firstOrFail();

        $classResponse = $this->actingAs($teacher)
            ->get(route('dashboard.search', ['q' => $foreignClass->name]));
        $classResponse->assertOk();
        $classResponse->assertViewHas('classes', function ($classes) use ($foreignClass) {
            return ! $classes->contains('id', $foreignClass->id);
        });

        $paymentResponse = $this->actingAs($teacher)
            ->get(route('dashboard.search', ['q' => 'Tuition']));
        $paymentResponse->assertOk();
        $paymentResponse->assertViewHas('payments', function ($payments) {
            return $payments->isEmpty();
        });
    }

    public function test_student_palette_is_navigation_only()
    {
        $response = $this->actingAs($this->student())
            ->getJson('/dashboard/search.json?q=Aarav');

        $response->assertOk();
        // No data groups at all — not even the student's own
        // record, and certainly nobody else's.
        $this->assertSame([], $response->json('students'));
        $this->assertSame([], $response->json('staff'));
        $this->assertSame([], $response->json('classes'));
        $this->assertSame([], $response->json('payments'));
        // Its own navigation is there; no create actions.
        // Re-expressed 2026-11-08 (LMS fold-in): the student's
        // Learning entry is "My Courses" now — the standalone
        // My Assignments / Study Materials pages retired into
        // courses (their routes redirect there).
        $this->assertContains('My Courses', $this->labels($response, 'pages'));
        $this->assertNotContains('My Assignments', $this->labels($response, 'pages'));
        $this->assertContains('My Fees', $this->labels($response, 'pages'));
        $this->assertSame([], $response->json('actions'));
        $this->assertNotContains('Admit Student', $this->labels($response, 'pages'));
    }

    public function test_accountant_and_librarian_palettes_are_navigation_only()
    {
        $accountant = $this->actingAs($this->accountant())
            ->getJson('/dashboard/search.json?q=Tuition');
        $accountant->assertOk();
        $this->assertSame([], $accountant->json('payments'));
        $this->assertSame([], $accountant->json('students'));
        $this->assertContains('Manage Payments', $this->labels($accountant, 'pages'));
        $this->assertContains('Record Payment', $this->labels($accountant, 'actions'));

        $librarian = $this->actingAs($this->librarian())
            ->getJson('/dashboard/search.json?q=Aarav');
        $librarian->assertOk();
        $this->assertSame([], $librarian->json('students'));
        $this->assertSame([], $librarian->json('classes'));
        $this->assertContains('Books', $this->labels($librarian, 'pages'));
        $this->assertContains('Add Book', $this->labels($librarian, 'actions'));
    }

    public function test_sa_palette_is_platform_only()
    {
        $response = $this->actingAs($this->sa())
            ->getJson('/dashboard/search.json?q=Ankit');

        $response->assertOk();
        $this->assertSame([], $response->json('students'));
        $this->assertSame([], $response->json('classes'));
        $this->assertSame([], $response->json('payments'));
        $this->assertSame([], $response->json('actions'));
        $this->assertContains('Platform Overview', $this->labels($response, 'pages'));
        // Platform staff surface (the SA's own account matches).
        $metas = array_column($response->json('staff') ?: [], 'meta');
        $this->assertNotEmpty(array_filter($metas, function ($meta) {
            return strpos($meta, 'superadmin@hackstra.app') !== false;
        }));

        // A student name finds nothing anywhere for the SA.
        $studentSearch = $this->actingAs($this->sa())
            ->getJson('/dashboard/search.json?q=Aarav');
        $studentSearch->assertOk();
        $this->assertSame([], $studentSearch->json('staff'));
        $this->assertSame([], $studentSearch->json('students'));
    }

    public function test_html_search_page_still_renders_for_admin()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $response->assertOk();
        $response->assertViewHas('students', function ($students) {
            return $students->contains('id', $this->ownStudent()->id);
        });
    }

    public function test_palette_partial_renders_and_ctrl_k_opens_it()
    {
        $response = $this->actingAs($this->admin())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('hk-palette-modal');
        $response->assertSee('hk-palette-input');
        $response->assertSee('hkOpenPalette');

        // The Slice-1 registry entry: palette first, topbar-search
        // focus as the fallback, and the label follows.
        $ux = $this->file('public/assets/js/hackstra-ux.js');
        $this->assertStringContainsString('window.hkOpenPalette', $ux);
        $this->assertStringContainsString("label: 'Open command palette'", $ux);
        $this->assertStringContainsString("document.getElementById('lav-global-search')", $ux);

        // The partial itself owns open/close + the endpoint.
        $partial = $this->file('resources/views/partials/command_palette.blade.php');
        $this->assertStringContainsString('dashboard/search.json', $partial);
        $this->assertStringContainsString('role="listbox"', $partial);
        $this->assertStringContainsString('partials.patterns.empty_state', $partial);

        // The librarian placeholder promise is gone; the honest
        // per-role text is in its place.
        $menu = $this->file('resources/views/partials/top_menu.blade.php');
        $this->assertStringNotContainsString('books, teachers', $menu);
        $this->assertStringContainsString('Search pages and actions', $menu);
        $this->assertStringContainsString('data-hk-palette-trigger', $menu);
    }
}
