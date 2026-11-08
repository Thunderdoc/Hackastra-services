<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Sweep find (2026-11-07 late): the sidebar user chip printed the
 * role as "Principal@elsePrincipal" for principals — the blade
 * wrote the directive glued to a word character
 * ("Principal@else{{ ... }}"), which Blade does not compile, so
 * the true branch printed the literal text plus the echo. Every
 * other role sits in the false branch and never saw it. The chip
 * is now a single ternary echo; these pins hold the rendered role
 * line for the principal (the only affected role) and a teacher
 * (the false-branch control).
 */
class OwnerSidebarUserChipTest extends TestCase
{
    use DatabaseTransactions;

    public function test_principal_chip_shows_a_clean_role_line()
    {
        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();

        $resp = $this->actingAs($principal)->get(route('dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('@else');
        $resp->assertDontSee('Principal@elsePrincipal');
        $resp->assertSee('Rajesh Iyer');
    }

    public function test_teacher_chip_shows_a_clean_role_line()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();

        $resp = $this->actingAs($teacher)->get(route('dashboard'));
        $resp->assertOk();
        $resp->assertDontSee('@else');
    }

    public function test_menu_blade_has_no_glued_directive()
    {
        $blade = file_get_contents(base_path('resources/views/partials/menu.blade.php'));

        $this->assertStringNotContainsString('Principal@else', $blade);
    }
}
