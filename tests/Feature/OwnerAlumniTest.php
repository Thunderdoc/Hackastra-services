<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\AlumniController;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Alumni Directory (READ-ONLY): StudentRecord rows with grad = 1.
 *
 * There is no dedicated graduation-year column: session is the
 * academic session string and grad_date is a free nullable string,
 * so the directory shows both and filters by class + session, with a
 * name/admission-no search. Seeded data contains ZERO graduated
 * students, so every test creates its own graduated rows inside the
 * DatabaseTransactions transaction (rolled back after).
 *
 * Guards: ADMIN ONLY. Teacher, student, parent and Super Admin are
 * denied. At HTTP level the Super Admin is turned away by the
 * PlatformOnly middleware (redirect to the platform dashboard);
 * the controller guard itself is asserted directly for the Super
 * Admin below, so the 403 wall holds even without that middleware.
 */
class OwnerAlumniTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin()
    {
        return $this->userByEmail('admin@hackstra.app');
    }

    protected function studentUser()
    {
        return $this->userByEmail('student@hackstra.app');
    }

    protected function makeAlumnus(array $overrides = [])
    {
        return StudentRecord::create(array_merge([
            'user_id' => $this->studentUser()->id,
            'my_class_id' => 1,
            'section_id' => 1,
            'session' => '2026-2027',
            'adm_no' => 'ALUMNI-PROBE-001',
            'grad' => 1,
            'grad_date' => '2026-03-31',
            'year_admitted' => '2020',
        ], $overrides));
    }

    public function test_admin_index_shows_graduated_row_with_name_class_section_year_and_adm_no()
    {
        $alumnus = $this->makeAlumnus();
        $class = MyClass::findOrFail($alumnus->my_class_id);

        $response = $this->actingAs($this->admin())->get(route('alumni.index'));
        $response->assertOk();
        $response->assertSee($this->studentUser()->name);
        $response->assertSee($class->name);
        $response->assertSee('ALUMNI-PROBE-001');
        $response->assertSee('2026-2027');
        $response->assertSee('2026-03-31');
    }

    public function test_admin_index_excludes_non_graduated_records()
    {
        $this->makeAlumnus();
        $nonGrad = $this->makeAlumnus([
            'adm_no' => 'ALUMNI-PROBE-NONGRAD',
            'grad' => 0,
            'grad_date' => null,
        ]);

        $response = $this->actingAs($this->admin())->get(route('alumni.index'));
        $response->assertOk();
        $response->assertSee('ALUMNI-PROBE-001');
        $response->assertDontSee('ALUMNI-PROBE-NONGRAD');

        $this->actingAs($this->admin())->get(route('alumni.show', $nonGrad->id))
            ->assertNotFound();
    }

    public function test_admin_show_renders_alumnus_details()
    {
        $alumnus = $this->makeAlumnus();

        $response = $this->actingAs($this->admin())->get(route('alumni.show', $alumnus->id));
        $response->assertOk();
        $response->assertSee($this->studentUser()->name);
        $response->assertSee('ALUMNI-PROBE-001');
        $response->assertSee('2026-03-31');
        $response->assertSee('2020');
    }

    public function test_class_and_session_filters_narrow_the_directory()
    {
        $this->makeAlumnus();

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['class_id' => 2]))
            ->assertOk()
            ->assertDontSee('ALUMNI-PROBE-001');

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['class_id' => 1]))
            ->assertOk()
            ->assertSee('ALUMNI-PROBE-001');

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['session' => '1999-2000']))
            ->assertOk()
            ->assertDontSee('ALUMNI-PROBE-001');

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['session' => '2026-2027']))
            ->assertOk()
            ->assertSee('ALUMNI-PROBE-001');
    }

    public function test_name_search_finds_alumnus_and_unrelated_term_does_not()
    {
        $this->makeAlumnus();

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['q' => 'Aarav']))
            ->assertOk()
            ->assertSee('ALUMNI-PROBE-001');

        $this->actingAs($this->admin())
            ->get(route('alumni.index', ['q' => 'ZZZNOPEZZZ']))
            ->assertOk()
            ->assertDontSee('ALUMNI-PROBE-001');
    }

    public function test_teacher_student_and_parent_are_forbidden()
    {
        $alumnus = $this->makeAlumnus();

        foreach (['teacher@hackstra.app', 'student@hackstra.app', 'parent@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))
                ->get(route('alumni.index'))
                ->assertForbidden();
            $this->actingAs($this->userByEmail($email))
                ->get(route('alumni.show', $alumnus->id))
                ->assertForbidden();
        }
    }

    public function test_super_admin_is_denied_at_http_level_and_by_the_controller_guard()
    {
        $alumnus = $this->makeAlumnus();

        // PlatformOnly turns school GETs for the Super Admin into a
        // redirect to the platform dashboard — never alumni data.
        $this->actingAs($this->userByEmail('superadmin@hackstra.app'))
            ->get(route('alumni.index'))
            ->assertRedirect(route('dashboard'));

        // The controller guard itself is a 403 for the Super Admin.
        $this->actingAs($this->userByEmail('superadmin@hackstra.app'));
        try {
            (new AlumniController())->index(new Request());
            $this->fail('Super Admin must be forbidden by the AlumniController guard.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            (new AlumniController())->show($alumnus->id);
            $this->fail('Super Admin must be forbidden by the AlumniController guard.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_no_create_edit_or_delete_surface_exists()
    {
        $this->actingAs($this->admin())
            ->post(route('alumni.index'), [])
            ->assertStatus(405);

        $this->actingAs($this->admin())
            ->get('/alumni/create')
            ->assertNotFound();
    }
}
