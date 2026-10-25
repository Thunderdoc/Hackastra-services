<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 6: Library
 * removed from the Admin panel completely (owner order), the
 * Librarian keeps it; plus green timetable validation.
 */
class OwnerWave6Test extends TestCase
{
    use DatabaseTransactions;

    public function test_library_is_gone_for_admin_but_kept_for_librarian()
    {
        $admin = User::where('email', 'admin@hackstra.app')->first();
        $librarian = User::where('email', 'librarian@hackstra.app')->first();
        $this->assertNotNull($admin, 'seed must hold the admin demo user');
        $this->assertNotNull($librarian, 'seed must hold the librarian demo user');

        // Route gate: admin is out of the library team entirely.
        $this->actingAs($admin)->get(route('books.index'))->assertRedirect(route('login'));
        $this->actingAs($librarian)->get(route('books.index'))->assertStatus(200);

        // Menu: the admin sidebar no longer carries the Library item.
        // (assertSee escapes its needle by default — pass false so the
        // literal <span> markup is matched, not its escaped form.)
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('<span>Library</span>', false);
        $this->actingAs($librarian)->get(route('dashboard'))->assertSee('<span>Books</span>', false);
    }
}
