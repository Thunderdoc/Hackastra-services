<?php

namespace Tests\Feature;

use App\Models\ParentMessage;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Parent Messaging (class-wise, IN-APP ONLY).
 *
 * Users are resolved by email, never hardcoded ids. Seed facts used:
 * teacher@hackstra.app teaches class 1 (Prep, via a subject and via
 * class-teacher sections) but not class 2 (Class 1); parent@hackstra.app
 * has children in class 1, section 1 (A); parent1@parent.com has no
 * student records, so it is a foreign parent for class-1 messages.
 * DatabaseTransactions rolls every row created here back.
 *
 * Run ONLY this file (never the full suite — sibling workers share
 * the sqlite DB; on "database is locked" rerun on an isolated
 * snapshot: DB_DATABASE=/tmp/pm.sqlite php artisan test
 * --filter=OwnerParentMessageTest).
 */
class OwnerParentMessageTest extends TestCase
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

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function foreignParent()
    {
        return User::where('email', 'parent1@parent.com')->firstOrFail();
    }

    protected function uniq($prefix)
    {
        return $prefix . ' ' . uniqid();
    }

    public function test_admin_store_notifies_parents_of_the_class()
    {
        $title = $this->uniq('PM admin class1');

        $this->actingAs($this->admin())
            ->post(route('parent_messages.store'), [
                'my_class_id' => 1,
                'section_id' => null,
                'title' => $title,
                'body' => 'Whole class notice.',
            ])
            ->assertRedirect(route('parent_messages.index'));

        $message = ParentMessage::where('title', $title)->firstOrFail();
        $this->assertEquals(1, $message->my_class_id);
        $this->assertNull($message->section_id);

        // The seeded parent of class-1 children got an in-app bell
        // notification pointing at this message; no SMS/WhatsApp
        // channel exists for this feature.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->parent()->id,
            'type' => 'parent_message',
            'title' => $title,
            'link' => route('parent_messages.show', $message->id),
        ]);
    }

    public function test_teacher_can_message_a_class_they_teach()
    {
        $title = $this->uniq('PM teacher taught');

        $this->actingAs($this->teacher())
            ->post(route('parent_messages.store'), [
                'my_class_id' => 1,
                'section_id' => null,
                'title' => $title,
                'body' => 'From your teacher.',
            ])
            ->assertRedirect(route('parent_messages.index'));

        $this->assertDatabaseHas('parent_messages', [
            'title' => $title, 'sender_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);
    }

    public function test_teacher_cannot_message_a_class_they_do_not_teach()
    {
        $title = $this->uniq('PM teacher untaught');

        $this->actingAs($this->teacher())
            ->post(route('parent_messages.store'), [
                'my_class_id' => 2,
                'section_id' => null,
                'title' => $title,
                'body' => 'Should be refused.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('parent_messages', ['title' => $title]);
    }

    public function test_section_must_belong_to_the_chosen_class()
    {
        // Section 4 is Class 2's A section, not Class 1's.
        $this->actingAs($this->admin())
            ->post(route('parent_messages.store'), [
                'my_class_id' => 1,
                'section_id' => 4,
                'title' => $this->uniq('PM bad section'),
                'body' => 'Should fail validation.',
            ])
            ->assertSessionHasErrors('section_id');
    }

    public function test_parent_index_shows_addressed_messages_only()
    {
        $wholeClass = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $this->uniq('PM parent whole'), 'body' => 'For all of Prep.',
        ]);
        $otherSection = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => 2,
            'title' => $this->uniq('PM parent othersec'), 'body' => 'For Prep B only.',
        ]);
        $otherClass = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 2, 'section_id' => null,
            'title' => $this->uniq('PM parent otherclass'), 'body' => 'For Class 1.',
        ]);

        $response = $this->actingAs($this->parent())->get(route('parent_messages.index'));
        $response->assertOk();
        $response->assertSee($wholeClass->title);
        $response->assertDontSee($otherSection->title);
        $response->assertDontSee($otherClass->title);
    }

    public function test_parent_index_shows_section_message_for_their_section()
    {
        $sectionMsg = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => 1,
            'title' => $this->uniq('PM parent ownsec'), 'body' => 'For Prep A only.',
        ]);

        $this->actingAs($this->parent())->get(route('parent_messages.index'))
            ->assertOk()
            ->assertSee($sectionMsg->title);
    }

    public function test_show_allows_sender_and_addressed_parent_only()
    {
        $message = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $this->uniq('PM show gate'), 'body' => 'Gated body text.',
        ]);

        // Sender opens it.
        $this->actingAs($this->admin())->get(route('parent_messages.show', $message->id))
            ->assertOk()->assertSee('Gated body text.');
        // Addressed parent opens it.
        $this->actingAs($this->parent())->get(route('parent_messages.show', $message->id))
            ->assertOk()->assertSee('Gated body text.');
        // Foreign parent: 403.
        $this->actingAs($this->foreignParent())->get(route('parent_messages.show', $message->id))
            ->assertForbidden();
        // Non-sending teacher: 403 even though they teach the class.
        $this->actingAs($this->teacher())->get(route('parent_messages.show', $message->id))
            ->assertForbidden();
    }

    public function test_parent_cannot_compose()
    {
        $this->actingAs($this->parent())->get(route('parent_messages.create'))
            ->assertForbidden();
        $this->actingAs($this->parent())->post(route('parent_messages.store'), [
            'my_class_id' => 1, 'title' => 'Nope', 'body' => 'Nope.',
        ])->assertForbidden();
    }

    public function test_sender_index_lists_only_their_own_messages()
    {
        $mine = ParentMessage::create([
            'sender_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $this->uniq('PM sender mine'), 'body' => 'Mine.',
        ]);
        $theirs = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $this->uniq('PM sender theirs'), 'body' => 'Theirs.',
        ]);

        $response = $this->actingAs($this->teacher())->get(route('parent_messages.index'));
        $response->assertOk();
        $response->assertSee($mine->title);
        $response->assertDontSee($theirs->title);
    }
}
