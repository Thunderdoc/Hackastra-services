<?php

namespace Tests\Feature;

use App\Models\State;
use App\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: Hackstra serves Indian schools —
 * nationality defaults to Indian on the staff forms, and the
 * State dropdown lists INDIAN states (the legacy Nigerian list,
 * Abia/Lagos/…, is gone from the seed and from live data via
 * migration 2026_10_30_000020).
 */
class OwnerIndianStatesTest extends TestCase
{
    public function test_states_lookup_is_indian()
    {
        $names = State::orderBy('name')->pluck('name')->all();
        $this->assertContains('Tamil Nadu', $names);
        $this->assertContains('Maharashtra', $names);
        $this->assertContains('Delhi', $names);
        $this->assertNotContains('Lagos', $names);
        $this->assertNotContains('Abia', $names);
        $this->assertSame(36, State::count());
        // The Nigerian LGA lookup no longer shadows Indian states.
        $this->assertSame(0, DB::table('lgas')->count());
    }

    public function test_create_user_form_defaults_nationality_to_indian_and_lists_indian_states()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $page = $this->actingAs($admin)->get(route('users.create'));
        $page->assertOk()->assertSee('Tamil Nadu')->assertDontSee('Abia');
        // The Indian option is the selected one in the rendered form.
        $indian = DB::table('nationalities')->where('name', 'Indian')->first();
        $this->assertNotNull($indian);
        $page->assertSee('selected value="' . $indian->id . '"', false);
    }

    public function test_edit_form_for_teacher_without_nationality_defaults_to_indian()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $teacher->update(['nal_id' => null]);
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $indian = DB::table('nationalities')->where('name', 'Indian')->first();
        $this->actingAs($admin)->get(route('users.edit', \App\Helpers\Qs::hash($teacher->id)))
            ->assertOk()
            ->assertSee('selected value="' . $indian->id . '"', false)
            ->assertSee('Tamil Nadu');
    }
}
