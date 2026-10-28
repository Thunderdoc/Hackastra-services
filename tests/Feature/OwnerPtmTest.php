<?php

namespace Tests\Feature;

use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * PTM Scheduling (Batch 101).
 *
 * Decisions under test (see PtmController docblock): teacher scope
 * is TeacherScope::canTeachClass; a slot takes at most one booking
 * (exists-check + UNIQUE on ptm_bookings.ptm_slot_id); cancel
 * deletes the booking row so the slot is immediately re-bookable;
 * a parent books only for their own child, in the slot's class.
 * Users are found by email, never by hardcoded ids.
 */
class OwnerPtmTest extends TestCase
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

    protected function otherTeacher()
    {
        // teacher1@teacher.com teaches classes 2,5,8,9,11 — not class 1.
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function parentChildId()
    {
        // parent@hackstra.app has children in class 1, section 1.
        return StudentRecord::where('my_parent_id', $this->parent()->id)
            ->orderBy('user_id')->value('user_id');
    }

    protected function slotData(array $overrides = [])
    {
        return array_merge([
            'date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '10:30',
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => 1,
        ], $overrides);
    }

    protected function makeSlot(array $overrides = [])
    {
        $data = $this->slotData($overrides);
        $data['created_by'] = $this->admin()->id;

        return PtmSlot::create($data);
    }

    public function test_admin_creates_slot_and_index_shows_it()
    {
        $response = $this->actingAs($this->admin())->post(route('ptm.store'), $this->slotData());
        $response->assertRedirect(route('ptm.index'));

        $slot = PtmSlot::where('teacher_id', $this->teacher()->id)
            ->where('my_class_id', 1)->latest('id')->firstOrFail();
        $this->assertEquals('10:00', substr($slot->start_time, 0, 5));

        $this->actingAs($this->admin())->get(route('ptm.index'))
            ->assertOk()->assertSee('Available')->assertSee($this->teacher()->name);
        $this->actingAs($this->teacher())->get(route('ptm.create'))->assertOk();
    }

    public function test_teacher_creates_only_for_classes_they_teach()
    {
        // Class 1 is in teacher@hackstra.app's scope (subject + section).
        $this->actingAs($this->teacher())->post(route('ptm.store'), $this->slotData())
            ->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', [
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);

        // Class 1 is NOT in teacher1@teacher.com's scope.
        $this->actingAs($this->otherTeacher())->post(route('ptm.store'), $this->slotData([
            'teacher_id' => $this->otherTeacher()->id, 'my_class_id' => 1,
        ]))->assertForbidden();
        $this->assertDatabaseMissing('ptm_slots', [
            'teacher_id' => $this->otherTeacher()->id, 'my_class_id' => 1,
        ]);

        // A teacher cannot create a slot in another teacher's name.
        $this->actingAs($this->teacher())->post(route('ptm.store'), $this->slotData([
            'teacher_id' => $this->otherTeacher()->id,
        ]))->assertForbidden();
    }

    public function test_parent_books_own_child_and_teacher_is_notified()
    {
        $slot = $this->makeSlot();

        $response = $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->parentChildId(),
        ]);
        $response->assertRedirect(route('ptm.index'));

        $this->assertDatabaseHas('ptm_bookings', [
            'ptm_slot_id' => $slot->id,
            'parent_id' => $this->parent()->id,
            'student_user_id' => $this->parentChildId(),
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->teacher()->id, 'type' => 'ptm_booked',
        ]);

        // Staff index names the booker; parent index shows it booked.
        $this->actingAs($this->admin())->get(route('ptm.index'))
            ->assertOk()->assertSee('Booked')->assertSee($this->parent()->name);
        $this->actingAs($this->parent())->get(route('ptm.index'))
            ->assertOk()->assertSee('Booked by you');
    }

    public function test_double_booking_refused_and_unique_constraint_holds()
    {
        $slot = $this->makeSlot();
        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->parentChildId(),
        ])->assertRedirect();

        // A second parent with a child in the same class/section.
        // Seed links children only to parent@hackstra.app, so a
        // genuine second family is built here (same pattern as
        // OwnerTransportTest: users.code + username are NOT NULL).
        $secondParent = User::create([
            'name' => 'PTM Second Parent', 'email' => 'ptm-second-parent@test.local',
            'password' => bcrypt('secret'), 'user_type' => 'parent',
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'username' => 'TEST/PTM/PARENT',
        ]);
        $secondChild = User::create([
            'name' => 'PTM Second Child', 'email' => 'ptm-second-child@test.local',
            'password' => bcrypt('secret'), 'user_type' => 'student',
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'username' => 'TEST/PTM/CHILD',
        ]);
        StudentRecord::create([
            'session' => date('Y'), 'user_id' => $secondChild->id,
            'my_class_id' => 1, 'section_id' => 1, 'my_parent_id' => $secondParent->id,
        ]);

        $response = $this->actingAs($secondParent)->post(route('ptm.book', $slot->id), [
            'student_user_id' => $secondChild->id,
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('flash_danger');
        $this->assertEquals(1, PtmBooking::where('ptm_slot_id', $slot->id)->count());

        // The DB constraint itself refuses a duplicate row.
        $failed = false;
        try {
            PtmBooking::create([
                'ptm_slot_id' => $slot->id, 'parent_id' => $secondParent->id,
                'student_user_id' => $secondChild->id,
            ]);
        } catch (QueryException $e) {
            $failed = true;
        }
        $this->assertTrue($failed, 'Unique constraint on ptm_slot_id must refuse a second booking');
        $this->assertEquals(1, PtmBooking::where('ptm_slot_id', $slot->id)->count());
    }

    public function test_parent_cannot_book_for_foreign_child()
    {
        $slot = $this->makeSlot();
        // Seed links children only to parent@hackstra.app, so build
        // a foreign family in-test (see double-booking test).
        $foreignParent = User::create([
            'name' => 'PTM Foreign Parent', 'email' => 'ptm-foreign-parent@test.local',
            'password' => bcrypt('secret'), 'user_type' => 'parent',
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'username' => 'TEST/PTM/FPARENT',
        ]);
        $foreignChild = User::create([
            'name' => 'PTM Foreign Child', 'email' => 'ptm-foreign-child@test.local',
            'password' => bcrypt('secret'), 'user_type' => 'student',
            'code' => strtoupper(\Illuminate\Support\Str::random(10)),
            'username' => 'TEST/PTM/FCHILD',
        ]);
        StudentRecord::create([
            'session' => date('Y'), 'user_id' => $foreignChild->id,
            'my_class_id' => 1, 'section_id' => 1, 'my_parent_id' => $foreignParent->id,
        ]);
        $foreignChildId = $foreignChild->id;

        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $foreignChildId,
        ])->assertRedirect()->assertSessionHas('flash_danger');
        $this->assertEquals(0, PtmBooking::where('ptm_slot_id', $slot->id)->count());
    }

    public function test_cancel_frees_slot_for_rebooking()
    {
        $slot = $this->makeSlot();
        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->parentChildId(),
        ])->assertRedirect();
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();

        $this->actingAs($this->parent())->delete(route('ptm.cancel', $booking->id))
            ->assertRedirect(route('ptm.index'));
        $this->assertDatabaseMissing('ptm_bookings', ['id' => $booking->id]);

        // Re-bookable immediately after cancel.
        $this->actingAs($this->parent())->post(route('ptm.book', $slot->id), [
            'student_user_id' => $this->parentChildId(),
        ])->assertRedirect();
        $this->assertEquals(1, PtmBooking::where('ptm_slot_id', $slot->id)->count());
    }

    public function test_parent_cannot_create_slot_and_student_cannot_view()
    {
        $this->actingAs($this->parent())->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($this->parent())->post(route('ptm.store'), $this->slotData())
            ->assertForbidden();

        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $this->actingAs($student)->get(route('ptm.index'))->assertForbidden();
    }
}
