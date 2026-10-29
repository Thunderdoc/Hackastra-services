<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 29 — PTM MEETINGS after override removal.
 *
 * CODE READING FIRST (PtmController, controller-guarded, no team
 * middleware — parents must reach it):
 *   - index(): admin/teacher/parent only (else 403), but UNSCOPED:
 *     every viewer gets ALL slots paginated; staff_view shows the
 *     booking parent + student names for every slot, parents see
 *     'Booked by you' for their own booking and a bare 'Booked'
 *     for other families'. There is NO show route — the index list
 *     IS the view.
 *   - create()/store(): teacher slots only for classes in
 *     TeacherScope::classIds() (direct subjects + led sections +
 *     overrides) and only in their own name — CURRENT-TEACHING
 *     gated, like parent-message compose (round 28).
 *   - book(): parent only, own child only, child must match the
 *     slot's class (and section, when set), one booking per slot
 *     (UNIQUE ptm_slot_id). It NEVER checks the slot teacher's
 *     current teaching — a slot stays bookable after its teacher
 *     stops teaching the class.
 *   - cancel(): booking parent, admin, or the SLOT's teacher
 *     (slot.teacher_id == caller) — slot identity, not current
 *     teaching. Cancel deletes the booking row AND the slot
 *     teacher's ptm_booked bell (round-4 orphan-bell fix). There
 *     is NO slot-delete route: a stale slot can only be emptied
 *     booking by booking, never removed via the UI.
 *
 * EXPECTATION, therefore a THIRD shape beside rounds 18-27
 * (content rows: ownership-persistence) and round 28 (messaging:
 * current teaching closes the channel): PTM bookings follow the
 * SLOT, not current teaching. Removal closes NEW slot creation
 * for the class, but an already-booked meeting stays visible to
 * the slot teacher (unscoped index) and to the parent, stays
 * cancellable by the slot teacher (with bell cleanup), and a
 * freed slot stays re-bookable with the same teacher. If any of
 * that broke — a booking vanishing from either party's view, or
 * the slot teacher losing cancel — a parent would be stranded
 * with an appointment nobody honours, and that would be a REAL
 * finding. No fix is expected: the slot-identity rules already
 * produce the coherent outcome.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions):
 * class K (section S led by D, subject direct-taught by D, O's
 * override on S/subject) + class K2 (section led by O — O's
 * still-taught control) + parents P1/P2 with children in S and
 * P4 with a child in K2's section. O teaches K ONLY via the
 * override. Distinct display names throughout so index
 * assertions can tell O from D and P1 from P2.
 */
class OwnerAllotmentRound29Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email, string $name): User
    {
        return User::create([
            'name' => $name,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function makeParent(string $code, string $email, string $name): User
    {
        return User::create([
            'name' => $name,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'parent',
        ]);
    }

    protected function makeStudent(string $code, string $email, string $name, MyClass $class, Section $section, ?User $parent = null): User
    {
        $student = User::create([
            'name' => $name,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'student',
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'my_parent_id' => $parent ? $parent->id : null,
            'adm_no' => 'R29-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /**
     * Returns [O, D, K, S, subject, K2, SK2, P1, P2, P4, S1, S2, S4].
     * O teaches K ONLY via the override on (S, subject). O leads a
     * section of K2 (still-taught control). P1/P2 have children in
     * S; P4's child is in K2's section.
     */
    protected function fixture(string $suffix): array
    {
        $o = $this->makeTeacher('PROBE-R29-O' . $suffix, 'probe-r29-o' . strtolower($suffix) . '@example.com', 'Probe Teacher O R29' . $suffix);
        $d = $this->makeTeacher('PROBE-R29-D' . $suffix, 'probe-r29-d' . strtolower($suffix) . '@example.com', 'Probe Teacher D R29' . $suffix);

        $k = MyClass::create(['name' => 'ProbeR29Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R29 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $subject = Subject::create([
            'name' => 'Probe Ptm Subject R29' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r29-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $s->id,
            'subject_id' => $subject->id,
            'teacher_id' => $o->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $k2 = MyClass::create(['name' => 'ProbeR29Class2 ' . $suffix, 'class_type_id' => 4]);
        $sk2 = Section::create(['name' => 'R29 K2 ' . $suffix, 'my_class_id' => $k2->id, 'teacher_id' => $o->id]);

        $p1 = $this->makeParent('PROBE-R29-P1' . $suffix, 'probe-r29-p1' . strtolower($suffix) . '@example.com', 'Probe Parent One R29' . $suffix);
        $p2 = $this->makeParent('PROBE-R29-P2' . $suffix, 'probe-r29-p2' . strtolower($suffix) . '@example.com', 'Probe Parent Two R29' . $suffix);
        $p4 = $this->makeParent('PROBE-R29-P4' . $suffix, 'probe-r29-p4' . strtolower($suffix) . '@example.com', 'Probe Parent Four R29' . $suffix);
        $s1 = $this->makeStudent('PROBE-R29-S1' . $suffix, 'probe-r29-s1' . strtolower($suffix) . '@example.com', 'Probe Student One R29' . $suffix, $k, $s, $p1);
        $s2 = $this->makeStudent('PROBE-R29-S2' . $suffix, 'probe-r29-s2' . strtolower($suffix) . '@example.com', 'Probe Student Two R29' . $suffix, $k, $s, $p2);
        $s4 = $this->makeStudent('PROBE-R29-S4' . $suffix, 'probe-r29-s4' . strtolower($suffix) . '@example.com', 'Probe Student Four R29' . $suffix, $k2, $sk2, $p4);

        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'Fixture must start with O as the effective teacher via the override.');
        $this->assertContains($k->id, TeacherScope::classIds($o),
            'Fixture must start with K inside O\'s teacher scope.');

        return [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4];
    }

    protected function createSlotAs(User $teacher, int $classId, ?int $sectionId)
    {
        $payload = [
            'date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '10:30',
            'teacher_id' => $teacher->id,
            'my_class_id' => $classId,
        ];
        if ($sectionId !== null) {
            $payload['section_id'] = $sectionId;
        }

        // Overnight menus 2026-10-29, item 18: PTM scheduling is
        // admin-only — the SLOT TEACHER stays $teacher, but the
        // creator is the admin. Teacher view/book/cancel
        // assertions in this file are unaffected.
        return $this->actingAs($this->admin())->post(route('ptm.store'), $payload);
    }

    protected function slotFor(User $teacher, int $classId, ?int $sectionId): PtmSlot
    {
        return PtmSlot::where('teacher_id', $teacher->id)
            ->where('my_class_id', $classId)
            ->when($sectionId !== null, fn ($q) => $q->where('section_id', $sectionId))
            ->latest('id')->firstOrFail();
    }

    protected function bookAs(User $parent, PtmSlot $slot, User $child)
    {
        return $this->actingAs($parent)->post(route('ptm.book', $slot->id), [
            'student_user_id' => $child->id,
        ]);
    }

    protected function bellCount(User $teacher): int
    {
        return (int) UserNotification::where('user_id', $teacher->id)
            ->where('type', 'ptm_booked')->count();
    }

    protected function removeOverrideViaMatrix(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertFalse(DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $subject->id)->exists(),
            'The matrix save must actually remove the override.');
    }

    protected function bookedFixture(string $suffix): array
    {
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4] = $this->fixture($suffix);
        $this->createSlotAs($o, $k->id, $s->id)->assertRedirect(route('ptm.index'));
        $slot = $this->slotFor($o, $k->id, $s->id);
        $this->bookAs($p1, $slot, $s1)->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Slot booked.');
        $this->assertDatabaseHas('ptm_bookings', ['ptm_slot_id' => $slot->id, 'parent_id' => $p1->id, 'student_user_id' => $s1->id]);
        $this->assertSame(1, $this->bellCount($o), 'Booking must bell the slot teacher.');

        return [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4, $slot];
    }

    public function test_booking_visible_to_teacher_and_parent_with_override_intact()
    {
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4, $slot] = $this->bookedFixture('A');

        // Staff view is unscoped: the slot teacher sees the booking
        // with the booking family's names.
        $this->actingAs($o)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked')->assertSee($p1->name)->assertSee($s1->name);

        // Booking parent sees 'Booked by you' + own child, nothing
        // of any other family (only one slot exists, so no book
        // form renders for anyone).
        $this->actingAs($p1)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked by you')->assertSee($s1->name);

        // Another family sees a bare 'Booked' badge and neither the
        // booking parent's nor the booked student's name.
        $this->actingAs($p2)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked')->assertDontSee($p1->name)->assertDontSee($s1->name);
    }

    public function test_booking_stays_visible_and_cancellable_by_slot_teacher_after_removal()
    {
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4, $slot] = $this->bookedFixture('B');
        $this->removeOverrideViaMatrix($s, $subject);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'Removal must flip the effective teacher to D.');
        $this->assertNotContains($k->id, TeacherScope::classIds($o),
            'Removal must drop K from O\'s teacher scope.');

        // The unscoped index still shows the booking to the slot
        // teacher, with the family's names — removal strands nobody.
        $this->actingAs($o)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked')->assertSee($p1->name)->assertSee($s1->name);

        // And the parent still sees her own booking unchanged.
        $this->actingAs($p1)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked by you')->assertSee($s1->name);

        // Cancel follows slot identity, not current teaching: the
        // removed teacher can still cancel, the booking row goes,
        // and the round-4 bell cleanup fires.
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $this->actingAs($o)->delete(route('ptm.cancel', $booking->id))
            ->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Booking cancelled. The slot is free again.');
        $this->assertDatabaseMissing('ptm_bookings', ['id' => $booking->id]);
        $this->assertSame(0, $this->bellCount($o), 'Cancel must clean the slot teacher\'s bell.');

        // The freed slot shows Available to the parent again.
        $this->actingAs($p1)->get(route('ptm.index'))->assertOk()
            ->assertSee('Available')->assertDontSee('Booked by you');
    }

    public function test_booking_does_not_transfer_to_new_teacher()
    {
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4, $slot] = $this->bookedFixture('C');
        $this->removeOverrideViaMatrix($s, $subject);
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();

        // Visibility is unscoped, so D also sees the booking — but
        // visibility is not ownership: D cannot cancel O's booking.
        $this->actingAs($d)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked')->assertSee($p1->name)->assertSee($s1->name);
        $this->actingAs($d)->delete(route('ptm.cancel', $booking->id))->assertForbidden();
        $this->assertDatabaseHas('ptm_bookings', ['id' => $booking->id]);
        $this->assertSame(1, $this->bellCount($o), 'A refused cancel must not touch the bell.');
    }

    public function test_slot_creation_is_admin_only_regardless_of_override_removal()
    {
        // Overnight menus 2026-10-29, item 18: the old
        // "creation closes for the removed class but stays open
        // for the still-taught class" shape is retired — teacher
        // scheduling is closed for EVERY class now, before and
        // after override removal alike. The admin still schedules
        // in the teacher's name for either class (admin store
        // carries no class-scope check).
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4] = $this->fixture('D');

        $slotPayload = function (int $classId, ?int $sectionId) use ($o): array {
            $payload = [
                'date' => now()->addDays(7)->format('Y-m-d'),
                'start_time' => '10:00',
                'end_time' => '10:30',
                'teacher_id' => $o->id,
                'my_class_id' => $classId,
            ];
            if ($sectionId !== null) {
                $payload['section_id'] = $sectionId;
            }

            return $payload;
        };

        // Before removal: teacher form + store refused for both classes.
        $this->actingAs($o)->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($o)->post(route('ptm.store'), $slotPayload($k->id, $s->id))->assertForbidden();
        $this->actingAs($o)->post(route('ptm.store'), $slotPayload($k2->id, $sk2->id))->assertForbidden();
        $this->assertSame(0, (int) PtmSlot::where('teacher_id', $o->id)->count());

        $this->removeOverrideViaMatrix($s, $subject);

        // After removal: still refused for the teacher — removal
        // changes nothing about the admin-only gate.
        $this->actingAs($o)->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($o)->post(route('ptm.store'), $slotPayload($k->id, $s->id))->assertForbidden();
        $this->actingAs($o)->post(route('ptm.store'), $slotPayload($k2->id, $sk2->id))->assertForbidden();
        $this->assertSame(0, (int) PtmSlot::where('teacher_id', $o->id)->count());

        // Admin scheduling in O's name still works for both classes.
        $this->createSlotAs($o, $k->id, $s->id)->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', ['teacher_id' => $o->id, 'my_class_id' => $k->id]);
        $this->createSlotAs($o, $k2->id, $sk2->id)->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', ['teacher_id' => $o->id, 'my_class_id' => $k2->id]);
    }

    public function test_freed_slot_stays_rebookable_with_same_teacher_after_removal()
    {
        [$o, $d, $k, $s, $subject, $k2, $sk2, $p1, $p2, $p4, $s1, $s2, $s4, $slot] = $this->bookedFixture('E');
        $this->removeOverrideViaMatrix($s, $subject);

        // The removed teacher cancels her own booking (slot identity).
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $this->actingAs($o)->delete(route('ptm.cancel', $booking->id))->assertRedirect(route('ptm.index'));
        $this->assertSame(0, $this->bellCount($o));

        // book() never checks the slot teacher's current teaching,
        // so the same parent re-books the same slot with the same
        // (removed) teacher — the appointment channel follows the
        // slot, not the timetable. This is the observation the
        // brief asked to surface, not a gate to add unilaterally:
        // slots have no delete route, so a removed teacher's old
        // slots stay bookable until an admin-side slot-management
        // decision is made.
        $this->bookAs($p1, $slot, $s1)->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Slot booked.');
        $this->assertDatabaseHas('ptm_bookings', ['ptm_slot_id' => $slot->id, 'parent_id' => $p1->id, 'student_user_id' => $s1->id]);
        $this->assertSame(1, $this->bellCount($o), 'Re-booking bells the slot teacher again.');
        $this->actingAs($p1)->get(route('ptm.index'))->assertOk()
            ->assertSee('Booked by you')->assertSee($s1->name);
    }
}
