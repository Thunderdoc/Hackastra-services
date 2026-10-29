<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Book;
use App\Models\Circular;
use App\Models\ElectiveChoice;
use App\Models\Exam;
use App\Models\FeedbackItem;
use App\Models\LeaveRequest;
use App\Models\LibraryReservation;
use App\Models\ParentMessage;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Models\TransportAssignment;
use App\Models\TransportRoute;
use App\Models\TransportStop;
use App\Models\TransportVehicle;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Roles agent C round 7: student/parent portal deep audit — the one
 * role area not re-audited since round 3. Every surface gets both
 * sides: the rightful viewer (200, own data only) and the foreign
 * viewer (403/redirect, never the other family's data). Surfaces:
 * student timetable + print + PDF, marksheet print + annual,
 * leave.my scoping, feedback reporter privacy, admit cards,
 * document downloads, electives, library reservations, portal
 * basics; parent transport mine, parent messages, PTM book/cancel
 * gates, parent admit cards + timetable fallback, parent leave
 * refusal. Findings in forbidden files are reported, not edited.
 */
class OwnerPortalDeepAuditRound7Test extends TestCase
{
    use DatabaseTransactions;

    protected function byEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin() { return $this->byEmail('admin@hackstra.app'); }
    protected function teacher() { return $this->byEmail('teacher@hackstra.app'); }
    protected function student() { return $this->byEmail('student@hackstra.app'); }
    protected function parent() { return $this->byEmail('parent@hackstra.app'); }

    protected function otherStudent()
    {
        return User::where('user_type', 'student')
            ->where('email', '!=', 'student@hackstra.app')->firstOrFail();
    }

    protected function foreignRecord()
    {
        // A record with NO parent link: parent@ is foreign to him.
        return StudentRecord::whereNull('my_parent_id')
            ->where('user_id', '!=', $this->student()->id)->firstOrFail();
    }

    protected function prepRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->firstOrFail();
    }

    protected function uniq($prefix)
    {
        return $prefix . ' ' . uniqid();
    }

    protected function freshClass11Student()
    {
        $user = User::create([
            'name' => 'Round7 Class11',
            'email' => 'probe-student-c7-' . uniqid() . '@example.com',
            'password' => Hash::make('probe'),
            'user_type' => 'student',
            'code' => 'PRB-C7-' . uniqid(),
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $user->id,
            'my_class_id' => 12, 'section_id' => 55, 'grad' => 0,
            'adm_no' => 'R7/' . uniqid(),
        ]);
        return $user;
    }

    // ---------------- Student timetable ----------------

    public function test_student_timetable_mine_print_pdf_and_parent_fallback()
    {
        $student = $this->student();
        $parent = $this->parent();
        $foreign = $this->foreignRecord();
        $foreignUser = User::findOrFail($foreign->user_id);

        $this->actingAs($student)->get(route('tt.mine'))->assertOk();
        $this->actingAs($student)->get(route('tt.mine_print'))->assertOk();
        $this->actingAs($student)->get(route('tt.mine_pdf'))->assertOk();

        // Parent week view: own children only; a foreign child id
        // falls back to the first own child, never the foreign data.
        $this->actingAs($parent)->get(route('tt.mine'))->assertOk()
            ->assertSee($student->name);
        $this->actingAs($parent)->get(route('tt.mine', ['child' => $foreign->id]))
            ->assertOk()->assertSee($student->name)->assertDontSee($foreignUser->name);
    }

    // ---------------- Student marksheet print ----------------

    public function test_student_marksheet_print_and_annual_gates()
    {
        $student = $this->student();
        $other = $this->otherStudent();
        $parent = $this->parent();
        $year = Qs::getCurrentSession();
        $exam = Exam::findOrFail(1);

        // Local Term 1 is unpublished: the student sees the
        // not-published view (200), never another student's card.
        $this->actingAs($student)
            ->get(route('marks.print', [Qs::hash($student->id), 1, $year]))->assertOk();
        $this->actingAs($student)
            ->get(route('marks.print', [Qs::hash($other->id), 1, $year]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($student)
            ->get(route('marks.print_annual', [Qs::hash($other->id), $year]))
            ->assertRedirect(route('dashboard'));

        // Published in-transaction: the real card renders for the
        // owner, and the parent's own-child card too.
        $exam->update(['results_published' => true]);
        $this->actingAs($student)
            ->get(route('marks.print', [Qs::hash($student->id), 1, $year]))
            ->assertOk()->assertSee($student->name);
        $this->actingAs($student)
            ->get(route('marks.print_annual', [Qs::hash($student->id), $year]))->assertOk();
        $this->actingAs($parent)
            ->get(route('marks.print', [Qs::hash($student->id), 1, $year]))
            ->assertOk()->assertSee($student->name);
        // Truly foreign for the parent: a NULL-my_parent_id record —
        // NOT user 58, who is parent@'s own second child (records 1+2
        // both link to parent 5; the first draft of this test used
        // otherStudent() here and "failed" on a legitimate 200).
        $foreignUser = User::findOrFail($this->foreignRecord()->user_id);
        $this->actingAs($parent)
            ->get(route('marks.print', [Qs::hash($foreignUser->id), 1, $year]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($parent)
            ->get(route('marks.print_annual', [Qs::hash($foreignUser->id), $year]))
            ->assertRedirect(route('dashboard'));
    }

    // ---------------- Student leave.my ----------------

    public function test_student_leave_my_lists_only_own_requests()
    {
        $student = $this->student();
        $other = $this->otherStudent();
        $ownReason = $this->uniq('Leave own c7');
        $foreignReason = $this->uniq('Leave foreign c7');
        LeaveRequest::create([
            'user_id' => $student->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-03',
            'reason' => $ownReason, 'leave_type' => 'normal', 'status' => 'pending',
        ]);
        LeaveRequest::create([
            'user_id' => $other->id, 'start_date' => '2026-11-02', 'end_date' => '2026-11-03',
            'reason' => $foreignReason, 'leave_type' => 'normal', 'status' => 'pending',
        ]);

        $this->actingAs($student)->get(route('leave.my'))
            ->assertOk()->assertSee($ownReason)->assertDontSee($foreignReason);
        // Parents cannot apply: the apply page bounces to dashboard.
        $this->actingAs($this->parent())->get(route('leave.my'))
            ->assertRedirect(route('dashboard'));
    }

    // ---------------- Student feedback privacy ----------------

    public function test_student_feedback_mine_privacy_and_forwarded_case()
    {
        $student = $this->student();
        $teacher = $this->teacher();
        $ownTitle = $this->uniq('Feedback own c7');
        $foreignTitle = $this->uniq('Feedback foreign c7');

        $this->actingAs($student)->from(route('feedback.create'))->post(route('feedback.store'), [
            'type' => 'problem', 'category' => 'facility', 'priority' => 'medium',
            'title' => $ownTitle, 'description' => 'Round7 reporter privacy probe text',
        ])->assertRedirect();
        $own = FeedbackItem::where('title', $ownTitle)->firstOrFail();
        $this->assertEquals($student->id, $own->reporter_id);

        $foreign = FeedbackItem::create([
            'reporter_id' => $teacher->id, 'reporter_name' => $teacher->name,
            'reporter_role' => 'teacher', 'type' => 'problem', 'category' => 'facility',
            'priority' => 'medium', 'title' => $foreignTitle,
            'description' => 'Round7 foreign reporter item', 'status' => 'new',
        ]);

        $this->actingAs($student)->get(route('feedback.mine'))
            ->assertOk()->assertSee($ownTitle)->assertDontSee($foreignTitle);
        $this->actingAs($student)->get(route('feedback.mine.show', $own->id))
            ->assertOk()->assertSee($ownTitle)->assertDontSee('Super Admin');
        $this->actingAs($student)->get(route('feedback.mine.show', $foreign->id))
            ->assertForbidden();

        // Forwarded + SA-fixed item: the reporter reads the school
        // status ("Resolved") with zero SA trace, never "Fixed" as
        // a Super Admin badge.
        $own->update([
            'forwarded_at' => now(), 'forwarded_by' => $this->admin()->id,
            'sa_status' => 'fixed', 'status' => 'resolved',
            'admin_note' => 'Round7 school response text',
        ]);
        $this->actingAs($student)->get(route('feedback.mine.show', $own->id))
            ->assertOk()->assertSee('Resolved')->assertSee('Round7 school response text')
            ->assertDontSee('Super Admin');
    }

    // ---------------- Admit cards ----------------

    public function test_student_and_parent_admit_cards()
    {
        $student = $this->student();
        $parent = $this->parent();
        $foreign = $this->foreignRecord();
        $foreignUser = User::findOrFail($foreign->user_id);

        $this->actingAs($student)->get(route('admit_cards.my'))->assertOk();
        $this->actingAs($student)->get(route('admit_cards.my_card', [1, $student->id]))->assertOk();
        $this->actingAs($student)->get(route('admit_cards.my_card', [1, $foreignUser->id]))
            ->assertForbidden();
        // Teachers have no self-service card surface (owner ruling).
        $this->actingAs($this->teacher())->get(route('admit_cards.my'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->teacher())->get(route('admit_cards.my_card', [1, $student->id]))
            ->assertForbidden();

        $this->actingAs($parent)->get(route('admit_cards.my'))->assertOk();
        $this->actingAs($parent)->get(route('admit_cards.my_card', [1, $student->id]))->assertOk();
        $this->actingAs($parent)->get(route('admit_cards.my_card', [1, $foreignUser->id]))
            ->assertForbidden();
    }

    // ---------------- Document downloads ----------------

    public function test_document_download_scoping_with_real_file()
    {
        $student = $this->student();
        $parent = $this->parent();
        $foreign = $this->foreignRecord();
        $ownPath = 'documents/r7-own-' . uniqid() . '.pdf';
        $foreignPath = 'documents/r7-foreign-' . uniqid() . '.pdf';
        Storage::disk('public')->put($ownPath, 'round7 own content');
        Storage::disk('public')->put($foreignPath, 'round7 foreign content');
        try {
            $ownDoc = StudentDocument::create([
                'student_record_id' => $this->prepRecord()->id, 'uploaded_by' => $this->admin()->id,
                'title' => $this->uniq('Doc dl own c7'), 'original_name' => 'own.pdf',
                'stored_path' => $ownPath, 'mime_type' => 'application/pdf', 'size_bytes' => 18,
            ]);
            $foreignDoc = StudentDocument::create([
                'student_record_id' => $foreign->id, 'uploaded_by' => $this->admin()->id,
                'title' => $this->uniq('Doc dl foreign c7'), 'original_name' => 'foreign.pdf',
                'stored_path' => $foreignPath, 'mime_type' => 'application/pdf', 'size_bytes' => 22,
            ]);
            $this->actingAs($student)->get(route('documents.download', $ownDoc->id))->assertOk();
            $this->actingAs($student)->get(route('documents.download', $foreignDoc->id))
                ->assertForbidden();
            $this->actingAs($parent)->get(route('documents.download', $ownDoc->id))->assertOk();
            $this->actingAs($parent)->get(route('documents.download', $foreignDoc->id))
                ->assertForbidden();
            $this->actingAs($this->teacher())->get(route('documents.download', $ownDoc->id))
                ->assertForbidden();
            $this->actingAs($this->admin())->get(route('documents.download', $ownDoc->id))
                ->assertOk();
        } finally {
            Storage::disk('public')->delete([$ownPath, $foreignPath]);
        }
    }

    // ---------------- Electives ----------------

    public function test_electives_eligibility_stream_and_duplicates()
    {
        $prep = $this->student();
        $c11 = $this->freshClass11Student();

        // Prep is not elective-eligible: explanatory page, and the
        // store refuses instead of minting a row.
        $this->actingAs($prep)->get(route('electives.index'))->assertOk();
        $this->actingAs($prep)->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 69])->assertForbidden();
        $this->assertEquals(0, ElectiveChoice::where('subject_id', 69)
            ->whereHas('student_record', function ($q) use ($prep) {
                $q->where('user_id', $prep->id);
            })->count());

        // Class 11 pcm student (section 55): offered = common + pcm.
        $this->actingAs($c11)->get(route('electives.index'))
            ->assertOk()->assertSee('Mathematics')->assertDontSee('Accountancy');

        $this->actingAs($c11)->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 69])->assertRedirect();
        $this->assertEquals(1, ElectiveChoice::where('subject_id', 69)
            ->whereHas('student_record', function ($q) use ($c11) {
                $q->where('user_id', $c11->id);
            })->count());
        // Duplicate pick: refused with no second row.
        $this->actingAs($c11)->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 69])->assertRedirect();
        $this->assertEquals(1, ElectiveChoice::where('subject_id', 69)
            ->whereHas('student_record', function ($q) use ($c11) {
                $q->where('user_id', $c11->id);
            })->count());
        // Wrong stream (commerce) and wrong class (Class 1) refuse.
        $this->actingAs($c11)->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 73])->assertForbidden();
        $this->actingAs($c11)->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 7])->assertForbidden();

        // Admin sees the choice queue; a student may not approve.
        $this->actingAs($this->admin())->get(route('electives.index'))->assertOk();
        $choice = ElectiveChoice::where('subject_id', 69)->firstOrFail();
        $this->actingAs($c11)->post(route('electives.approve', $choice->id))->assertForbidden();
        $this->actingAs($this->admin())->post(route('electives.approve', $choice->id))->assertRedirect();
        $this->assertEquals(ElectiveChoice::STATUS_APPROVED, $choice->fresh()->status);
    }

    // ---------------- Library reservations (student side) ----------------

    public function test_student_library_reservation_own_and_foreign_cancel()
    {
        $student = $this->student();
        $other = $this->otherStudent();
        $book = Book::create([
            'name' => $this->uniq('Round7 reserve book'), 'author' => 'R7',
            'book_type' => 'Book', 'total_copies' => 2, 'issued_copies' => 0,
        ]);

        $this->actingAs($student)->from(route('student.library'))
            ->post(route('library_reservations.store'), ['book_id' => $book->id])
            ->assertRedirect();
        $own = LibraryReservation::where('book_id', $book->id)
            ->where('user_id', $student->id)->firstOrFail();
        $this->assertEquals(LibraryReservation::STATUS_RESERVED, $own->status);

        $foreignRes = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $other->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($student)->delete(route('library_reservations.cancel', $foreignRes->id))
            ->assertForbidden();
        $this->assertEquals(
            LibraryReservation::STATUS_RESERVED, $foreignRes->fresh()->status
        );

        $this->actingAs($student)->delete(route('library_reservations.cancel', $own->id));
        $this->assertEquals(
            LibraryReservation::STATUS_CANCELLED, $own->fresh()->status
        );
    }

    // ---------------- Parent transport mine ----------------

    public function test_parent_transport_mine_scoping()
    {
        $parent = $this->parent();
        $student = $this->student();
        $foreign = $this->foreignRecord();
        $foreignUser = User::findOrFail($foreign->user_id);

        $route = TransportRoute::create(['name' => $this->uniq('Round7 route')]);
        $vehicle = TransportVehicle::create([
            'vehicle_no' => 'R7-' . uniqid(), 'capacity' => 40, 'transport_route_id' => $route->id,
        ]);
        $stop = TransportStop::create([
            'transport_route_id' => $route->id, 'name' => 'Round7 stop', 'sequence' => 1,
        ]);
        TransportAssignment::create([
            'student_record_id' => $this->prepRecord()->id,
            'transport_vehicle_id' => $vehicle->id, 'transport_stop_id' => $stop->id,
        ]);
        TransportAssignment::create([
            'student_record_id' => $foreign->id,
            'transport_vehicle_id' => $vehicle->id, 'transport_stop_id' => $stop->id,
        ]);

        // Parent-only surface; the list carries only own children.
        $this->actingAs($parent)->get(route('transport.mine'))
            ->assertOk()->assertSee($student->name)->assertDontSee($foreignUser->name);
        $this->actingAs($student)->get(route('transport.mine'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('transport.mine'))->assertForbidden();
    }

    // ---------------- Parent messages ----------------

    public function test_parent_messages_addressing_and_compose_refusal()
    {
        $parent = $this->parent();
        $teacher = $this->teacher();
        $ownTitle = $this->uniq('Message own c7');
        $foreignTitle = $this->uniq('Message foreign c7');

        // Class broadcast to Prep (parent@'s children are Prep A):
        // visible. Class 12-A message: not addressed to parent@.
        $ownMsg = ParentMessage::create([
            'sender_id' => $teacher->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $ownTitle, 'body' => 'Round7 prep broadcast',
        ]);
        $foreignMsg = ParentMessage::create([
            'sender_id' => $teacher->id, 'my_class_id' => 12, 'section_id' => 55,
            'title' => $foreignTitle, 'body' => 'Round7 class11 broadcast',
        ]);

        $this->actingAs($parent)->get(route('parent_messages.index'))
            ->assertOk()->assertSee($ownTitle)->assertDontSee($foreignTitle);
        $this->actingAs($parent)->get(route('parent_messages.show', $ownMsg->id))->assertOk();
        $this->actingAs($parent)->get(route('parent_messages.show', $foreignMsg->id))
            ->assertForbidden();
        // Parents cannot compose; teachers can open their own sent item.
        $this->actingAs($parent)->get(route('parent_messages.create'))->assertForbidden();
        $this->actingAs($parent)->post(route('parent_messages.store'), [
            'my_class_id' => 1, 'title' => 'x', 'body' => 'y',
        ])->assertForbidden();
        $this->actingAs($teacher)->get(route('parent_messages.show', $ownMsg->id))->assertOk();
    }

    // ---------------- Parent PTM book/cancel gates ----------------

    public function test_parent_ptm_book_and_cancel_gates()
    {
        $parent = $this->parent();
        $student = $this->student();
        $foreign = $this->foreignRecord();
        $foreignUser = User::findOrFail($foreign->user_id);

        $slot = PtmSlot::create([
            'date' => '2026-11-04', 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => 1,
            'created_by' => $this->admin()->id,
        ]);
        $slot2 = PtmSlot::create([
            'date' => '2026-11-04', 'start_time' => '10:00:00', 'end_time' => '10:30:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => 1,
            'created_by' => $this->admin()->id,
        ]);
        // Foreign-child booking is refused with no row minted.
        $this->actingAs($parent)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $foreignUser->id]);
        $this->assertEquals(0, PtmBooking::where('ptm_slot_id', $slot->id)->count());
        // Own-child booking works.
        $this->actingAs($parent)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $student->id]);
        $own = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $this->assertEquals($parent->id, $own->parent_id);

        // Another family's booking cannot be cancelled by parent@.
        $probeParent = User::create([
            'name' => 'Round7 Probe Parent',
            'email' => 'probe-parent-c7-' . uniqid() . '@example.com',
            'password' => Hash::make('probe'), 'user_type' => 'parent',
            'code' => 'PRB-C7-' . uniqid(),
        ]);
        StudentRecord::where('id', $foreign->id)->update(['my_parent_id' => $probeParent->id]);
        $this->actingAs($probeParent)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot2->id), ['student_user_id' => $foreignUser->id]);
        $foreignBooking = PtmBooking::where('ptm_slot_id', $slot2->id)->firstOrFail();
        $this->actingAs($parent)->delete(route('ptm.cancel', $foreignBooking->id))
            ->assertForbidden();
        $this->assertNotNull(PtmBooking::find($foreignBooking->id));

        // Own cancel works and frees the slot.
        $this->actingAs($parent)->delete(route('ptm.cancel', $own->id));
        $this->assertNull(PtmBooking::find($own->id));
    }

    // ---------------- Portal basics (re-prove after edits) ----------------

    public function test_student_portal_basics_still_hold()
    {
        $student = $this->student();
        $this->actingAs($student)->get(route('student.fees'))->assertOk();
        $this->actingAs($student)->get(route('student.library'))->assertOk();
        $this->actingAs($student)->get(route('student.events'))->assertOk();
        $this->actingAs($student)->get(route('my_children'))->assertRedirect(route('login'));
        $this->actingAs($this->parent())->get(route('my_children'))->assertOk();
    }

    // ---------------- Second pass: homework, materials, ID, circulars ----

    public function test_student_assignment_submit_scoping()
    {
        $student = $this->student();
        // Seeded: #1 Prep whole-class (in scope), #2 Class 4 (foreign).
        $own = Assignment::findOrFail(1);
        $foreign = Assignment::findOrFail(2);
        $this->assertEquals(1, $own->my_class_id);
        $this->assertEquals(4, $foreign->my_class_id);

        $this->actingAs($student)->from(route('assignments.index'))
            ->post(route('assignments.submit', $own->id), [
                'text_answer' => 'Round7 own submission text',
            ])->assertRedirect();
        $this->assertDatabaseHas('assignment_submissions', [
            'assignment_id' => $own->id, 'student_id' => $student->id,
        ]);
        // Foreign-class submission is refused, no row minted; and
        // parents cannot submit at all (student-only endpoint).
        $this->actingAs($student)->from(route('assignments.index'))
            ->post(route('assignments.submit', $foreign->id), [
                'text_answer' => 'Round7 foreign submission text',
            ])->assertForbidden();
        $this->assertDatabaseMissing('assignment_submissions', [
            'assignment_id' => $foreign->id, 'student_id' => $student->id,
        ]);
        $this->actingAs($this->parent())->from(route('assignments.index'))
            ->post(route('assignments.submit', $own->id), [
                'text_answer' => 'Round7 parent submission text',
            ])->assertForbidden();
    }

    public function test_student_and_parent_materials_index_scoping()
    {
        $student = $this->student();
        $parent = $this->parent();
        $ownTitle = $this->uniq('Material own c7');
        $foreignTitle = $this->uniq('Material foreign c7');
        // Prep material (student@'s class) vs Class 4 material.
        StudyMaterial::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'title' => $ownTitle, 'link_url' => 'https://example.com/r7',
        ]);
        StudyMaterial::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 4, 'section_id' => null,
            'subject_id' => 19, 'title' => $foreignTitle, 'link_url' => 'https://example.com/r7f',
        ]);

        $this->actingAs($student)->get(route('materials.index'))
            ->assertOk()->assertSee($ownTitle)->assertDontSee($foreignTitle);
        // parent@'s children are both Prep: same scope as the student.
        $this->actingAs($parent)->get(route('materials.index'))
            ->assertOk()->assertSee($ownTitle)->assertDontSee($foreignTitle);
    }

    public function test_student_and_parent_id_card_mine()
    {
        $student = $this->student();
        $parent = $this->parent();
        $foreign = $this->foreignRecord();
        $foreignUser = User::findOrFail($foreign->user_id);

        $this->actingAs($student)->get(route('id_card.mine'))
            ->assertOk()->assertSee($student->name);
        $this->actingAs($parent)->get(route('id_card.mine'))
            ->assertOk()->assertSee($student->name);
        // Foreign child id falls back to the first own child.
        $this->actingAs($parent)->get(route('id_card.mine', ['child' => $foreign->id]))
            ->assertOk()->assertSee($student->name)->assertDontSee($foreignUser->name);
        // Teachers have no self-service card: back to dashboard.
        $this->actingAs($this->teacher())->get(route('id_card.mine'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_circular_audience_scoping_and_lesson_plan_student_refusal()
    {
        $student = $this->student();
        $parent = $this->parent();
        $draftTitle = $this->uniq('Circular draft c7');
        Circular::create([
            'title' => $draftTitle, 'body' => 'Round7 draft body', 'audience' => 'all',
            'priority' => 'normal', 'published_by' => $this->admin()->id,
            'is_published' => false,
        ]);

        // Seeded: #1 'all' published (visible to all), #2 'teachers'
        // published (never a student's), #3 'parents' published.
        $this->actingAs($student)->get(route('circulars.index'))
            ->assertOk()->assertSee('School closed for the Diwali break')
            ->assertDontSee('Term 1 marks entry deadline')
            ->assertDontSee($draftTitle);
        $this->actingAs($student)->get(route('circulars.show', 2))->assertNotFound();
        $this->actingAs($student)->get(route('circulars.show', 1))->assertOk();
        $this->actingAs($parent)->get(route('circulars.index'))
            ->assertOk()->assertSee('Parent-Teacher Meeting this Saturday')
            ->assertDontSee('Term 1 marks entry deadline');

        // Lesson plans have no student/parent surface by design
        // (admin/principal tool since overnight menus 2026-10-29,
        // item 17): direct URLs refuse — teachers included now.
        $this->actingAs($student)->get(route('lesson_plans.index'))->assertForbidden();
        $this->actingAs($parent)->get(route('lesson_plans.index'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('lesson_plans.index'))->assertForbidden();
        $this->actingAs($this->admin())->get(route('lesson_plans.index'))->assertOk();
    }
}
