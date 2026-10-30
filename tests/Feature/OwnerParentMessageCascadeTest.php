<?php

namespace Tests\Feature;

use App\Models\ParentMessage;
use App\Models\Section;
use App\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner bug report 2026-10-30: the New Parent Message form let
 * Class 12 pair with a Class 6 section, and the send died on
 * "The selected section id is invalid." The section list now
 * cascades from the chosen class (data-class options + JS), the
 * server pair-guard stays, and the parent fan-out is one bulk
 * insert (P27) instead of one query per parent.
 */
class OwnerParentMessageCascadeTest extends TestCase
{
    public function test_create_form_cascades_sections_by_class()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $page = $this->actingAs($admin)->get(route('parent_messages.create'));
        $page->assertOk();
        $page->assertSee('data-class="13"', false); // Class 12 sections carry their class
        $page->assertSee('data-class="7"', false);  // Class 6 sections too
        $page->assertSee('cascade', false);
    }

    public function test_matching_pair_sends_and_bells_parents_in_bulk()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        // Prep A: the seed's one guaranteed parent link (Aarav's
        // parent) lives there — most demo students have no linked
        // parent, so a random section could legitimately bell 0.
        $section = Section::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $before = DB::table('user_notifications')->count();
        $messagesBefore = ParentMessage::where('title', 'Please pay the fee')->count();

        $response = $this->actingAs($admin)->post(route('parent_messages.store'), [
            'my_class_id' => 1,
            'section_id' => $section->id,
            'title' => 'Please pay the fee',
            'body' => 'Hello all',
        ]);
        $response->assertRedirect(route('parent_messages.index'));
        $this->assertSame($messagesBefore + 1, ParentMessage::where('title', 'Please pay the fee')->count());
        // Parents of that section's students were belled.
        $this->assertGreaterThan($before, DB::table('user_notifications')->count());
    }

    public function test_mismatched_pair_is_still_refused_by_the_server()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $class6Section = Section::where('my_class_id', 7)->orderBy('id')->firstOrFail();
        $response = $this->actingAs($admin)->post(route('parent_messages.store'), [
            'my_class_id' => 13, // Class 12
            'section_id' => $class6Section->id, // but a Class 6 section
            'title' => 'Mismatch',
            'body' => 'Should never send',
        ]);
        $response->assertSessionHasErrors('section_id');
        $this->assertSame(0, ParentMessage::where('title', 'Mismatch')->count());
    }
}
