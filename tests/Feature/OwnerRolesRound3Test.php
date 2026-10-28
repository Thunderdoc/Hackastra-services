<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Book;
use App\Models\BookRequest;
use App\Models\DisciplineRecord;
use App\Models\HealthRecord;
use App\Models\LibraryFine;
use App\Models\LibraryReservation;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Payroll;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Quiz;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles agent C round 3: deep surfaces round 2 did not cover —
 * accountant fees/payroll, librarian books/fines/reservations,
 * student portal, parent portal, teacher scoped indexes, and the
 * per-role notification bell. Pattern under test throughout:
 * every listed row's detail link must open for its rightful
 * viewer (list-without-detail is a bug), and foreign access
 * must 302/403, never leak data.
 */
class OwnerRolesRound3Test extends TestCase
{
    use DatabaseTransactions;

    protected function byEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin() { return $this->byEmail('admin@hackstra.app'); }
    protected function teacher() { return $this->byEmail('teacher@hackstra.app'); }
    protected function teacher2() { return $this->byEmail('teacher2@teacher.com'); }
    protected function principal() { return $this->byEmail('principal@hackstra.app'); }
    protected function student() { return $this->byEmail('student@hackstra.app'); }
    protected function parent() { return $this->byEmail('parent@hackstra.app'); }
    protected function accountant() { return $this->byEmail('accountant@hackstra.app'); }
    protected function librarian() { return $this->byEmail('librarian@hackstra.app'); }

    protected function otherStudent()
    {
        return User::where('user_type', 'student')
            ->where('email', '!=', 'student@hackstra.app')->firstOrFail();
    }

    protected function prepRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->firstOrFail();
    }

    protected function class2Record()
    {
        // my_class_id 3 = Class 2, teacher2's class-teacher scope.
        return StudentRecord::where('my_class_id', 3)->where('grad', 0)->firstOrFail();
    }

    protected function uniq($prefix)
    {
        return $prefix . ' ' . uniqid();
    }

    protected function probeParent()
    {
        return User::create([
            'name' => 'Round3 Probe Parent',
            'email' => 'probe-parent-c3-' . uniqid() . '@example.com',
            'password' => Hash::make('probe'),
            'user_type' => 'parent',
            'code' => 'PRB-C3-' . uniqid(),
        ]);
    }

    // ---------------- Accountant ----------------

    public function test_accountant_fee_surface_and_payroll_boundary()
    {
        $accountant = $this->accountant();
        $payment = Payment::firstOrFail();
        $payroll = Payroll::firstOrFail();

        $this->actingAs($accountant)->get(route('payments.index'))->assertOk();
        $this->actingAs($accountant)->get(route('payments.manage'))->assertOk();
        $this->actingAs($accountant)->get(route('payments.collection'))->assertOk();
        $this->actingAs($accountant)->get(route('payments.defaulters'))->assertOk();
        $this->actingAs($accountant)->get(route('payments.create'))->assertOk();
        // List-without-detail: the fee list's detail links open.
        // payments.show is the session-year page (payments/{year}),
        // and the per-student detail is the invoice (hashed user id).
        $this->actingAs($accountant)->get(route('payments.show', Qs::getCurrentSession()))->assertOk();
        $this->actingAs($accountant)
            ->get(route('payments.invoice', Qs::hash($this->student()->id)))->assertOk();
        // Payroll administration is Admin-team only by design;
        // the accountant keeps only their own payslips.
        $this->actingAs($accountant)->get(route('payroll.index'))->assertRedirect();
        $this->actingAs($accountant)->get(route('payroll.payslip', $payroll->id))->assertRedirect();
        $this->actingAs($accountant)->get(route('my_payslips.index'))->assertOk();
        $this->actingAs($this->teacher())->get(route('payroll.index'))->assertRedirect();
    }

    public function test_accountant_fee_write_and_pay_now_policy()
    {
        $accountant = $this->accountant();

        $this->actingAs($accountant)->post(route('payments.store'), [
            'title' => $this->uniq('Round3 fee'), 'amount' => 1000, 'my_class_id' => 1,
        ])->assertRedirect(route('payments.index'));
        $this->assertDatabaseHas('payments', ['amount' => 1000, 'my_class_id' => 1]);

        $owing = PaymentRecord::where('paid', 0)->firstOrFail();
        $before = (int) $owing->amt_paid;
        $this->actingAs($accountant)->from(route('payments.manage'))
            ->post(route('payments.pay_now', Qs::hash($owing->id)), ['amt_paid' => 100]);
        $this->assertEquals($before + 100, (int) $owing->fresh()->amt_paid);

        // A student may never record a payment on a record: the
        // teamAccount middleware refuses them before any policy
        // runs, and the amount is untouched.
        $this->actingAs($this->student())
            ->post(route('payments.pay_now', Qs::hash($owing->id)), ['amt_paid' => 100])
            ->assertRedirect('/login');
        $this->assertEquals($before + 100, (int) $owing->fresh()->amt_paid);
    }

    public function test_fee_reminder_gate_accountant_yes_teacher_no()
    {
        $this->actingAs($this->teacher())->from(route('payments.defaulters'))
            ->post(route('payments.defaulters.remind', ['class_id' => 1]), [])
            ->assertRedirect('/login');
        $this->actingAs($this->accountant())->from(route('payments.defaulters'))
            ->post(route('payments.defaulters.remind', ['class_id' => 1]), [])
            ->assertRedirect(route('payments.defaulters'));
    }

    // ---------------- Librarian ----------------

    public function test_librarian_book_and_fine_flows()
    {
        $librarian = $this->librarian();

        $this->actingAs($librarian)->get(route('books.index'))->assertOk();
        $this->actingAs($librarian)->get(route('books.create'))->assertOk();
        // Library removal order: the Admin menu carries no library.
        $this->actingAs($this->admin())->get(route('books.index'))->assertRedirect();
        $this->actingAs($librarian)->post(route('books.store'), [
            'name' => $this->uniq('Round3 book'), 'author' => 'Round3 Author',
            'total_copies' => 3,
        ])->assertRedirect(route('books.index'));
        $this->assertDatabaseHas('books', ['author' => 'Round3 Author']);

        $this->actingAs($librarian)->get(route('library_fines.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('library_fines.index'))->assertRedirect();

        $fine = LibraryFine::create([
            'user_id' => $this->student()->id, 'amount' => 50, 'days_overdue' => 5,
            'reason' => 'Round3 overdue', 'status' => 'pending',
            'imposed_by' => $librarian->id,
        ]);
        $this->actingAs($librarian)->from(route('library_fines.index'))
            ->post(route('library_fines.waive', Qs::hash($fine->id)));
        $this->assertEquals('waived', $fine->fresh()->status);
    }

    public function test_librarian_loan_return_and_reservation_collect()
    {
        $librarian = $this->librarian();
        $book = Book::create([
            'name' => $this->uniq('Round3 loan book'), 'author' => 'L',
            'book_type' => 'Book', 'total_copies' => 2, 'issued_copies' => 1,
        ]);

        $this->actingAs($librarian)->get(route('book_requests.index'))->assertOk();
        $loan = BookRequest::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-11-02',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        // List-without-detail: the listed loan's return works.
        $this->actingAs($librarian)->from(route('book_requests.index'))
            ->patch(route('book_requests.return', Qs::hash($loan->id)));
        $this->assertEquals(1, (int) $loan->fresh()->returned);
        $this->assertEquals(BookRequest::STATUS_RETURNED, $loan->fresh()->status);
        // Return is librarian-only: a student borrower cannot do it.
        $loan2 = BookRequest::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'start_date' => '2026-10-26', 'end_date' => '2026-11-02',
            'returned' => 0, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        $this->actingAs($this->student())
            ->patch(route('book_requests.return', Qs::hash($loan2->id)))
            ->assertRedirect('/login');
        $this->assertEquals(0, (int) $loan2->fresh()->returned);

        $reservation = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($librarian)->get(route('library_reservations.index'))->assertOk();
        $this->actingAs($librarian)
            ->post(route('library_reservations.collect', $reservation->id));
        $this->assertEquals(
            LibraryReservation::STATUS_COLLECTED, $reservation->fresh()->status
        );
        // A collected reservation cannot be collected again, and a
        // cancelled one cannot be collected either.
        $reservation2 = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->student()->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->student())
            ->delete(route('library_reservations.cancel', $reservation2->id));
        $this->assertEquals(
            LibraryReservation::STATUS_CANCELLED, $reservation2->fresh()->status
        );
        $this->actingAs($librarian)
            ->post(route('library_reservations.collect', $reservation2->id));
        $this->assertEquals(
            LibraryReservation::STATUS_CANCELLED, $reservation2->fresh()->status
        );
        // Foreign cancel stays refused.
        $reservation3 = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->parent()->id,
            'student_record_id' => null, 'status' => LibraryReservation::STATUS_RESERVED,
        ]);
        $this->actingAs($this->student())
            ->delete(route('library_reservations.cancel', $reservation3->id))
            ->assertForbidden();
        $this->assertEquals(
            LibraryReservation::STATUS_RESERVED, $reservation3->fresh()->status
        );
    }

    // ---------------- Student portal ----------------

    public function test_student_portal_scoping()
    {
        $student = $this->student();
        $other = $this->otherStudent();
        $ownRecord = $this->prepRecord();
        $otherRecord = StudentRecord::where('user_id', $other->id)->firstOrFail();

        $this->actingAs($student)->get(route('student.fees'))->assertOk();
        $this->actingAs($student)->get(route('student.library'))->assertOk();
        $this->actingAs($student)->get(route('student.events'))->assertOk();
        // Prep students are not elective-eligible: an explanatory
        // page, not an error and not another class's form.
        $this->actingAs($student)->get(route('electives.index'))->assertOk();

        $ownPr = PaymentRecord::where('student_id', $student->id)->firstOrFail();
        $foreignPr = PaymentRecord::where('student_id', '!=', $student->id)->firstOrFail();
        $this->actingAs($student)->get(route('student.fees.receipt', $ownPr->id))->assertOk();
        $this->actingAs($student)->get(route('student.fees.receipt', $foreignPr->id))->assertForbidden();

        $ownDoc = StudentDocument::create([
            'student_record_id' => $ownRecord->id, 'uploaded_by' => $this->admin()->id,
            'title' => $this->uniq('Doc own c3'), 'original_name' => 'own.pdf',
            'stored_path' => 'documents/own-c3.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $foreignDoc = StudentDocument::create([
            'student_record_id' => $otherRecord->id, 'uploaded_by' => $this->admin()->id,
            'title' => $this->uniq('Doc foreign c3'), 'original_name' => 'foreign.pdf',
            'stored_path' => 'documents/foreign-c3.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $this->actingAs($student)->get(route('documents.index'))
            ->assertOk()->assertSee($ownDoc->title)->assertDontSee($foreignDoc->title);

        // Attendance: own page, and a foreign student_id falls back
        // to the caller's own record — never the foreign data.
        $this->actingAs($student)->get(route('attendance.my'))
            ->assertOk()->assertSee($student->name);
        $this->actingAs($student)->get(route('attendance.my', ['student_id' => $otherRecord->id]))
            ->assertOk()->assertSee($student->name)->assertDontSee($other->name);

        // Marks: own marksheet opens; foreign ones bounce.
        $year = Qs::getCurrentSession();
        $this->actingAs($student)
            ->get(route('marks.show', [Qs::hash($student->id), $year]))->assertOk();
        $this->actingAs($student)
            ->get(route('marks.show', [Qs::hash($other->id), $year]))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->teacher2())
            ->get(route('marks.show', [Qs::hash($student->id), $year]))
            ->assertRedirect(route('dashboard'));

        // Quizzes: own-class list opens; a foreign-class quiz's
        // attempt page refuses.
        $this->actingAs($student)->get(route('quizzes.index'))->assertOk();
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 2, 'section_id' => null,
            'subject_id' => 7, 'title' => $this->uniq('Quiz foreign c3'),
            'time_limit_minutes' => 30, 'status' => 'published',
            'available_from' => '2026-01-01 00:00:00', 'available_until' => '2027-01-01 00:00:00',
        ]);
        $this->actingAs($student)->get(route('quizzes.attempt', $quiz->id))->assertForbidden();
    }

    // ---------------- Parent portal ----------------

    public function test_parent_portal_scoping()
    {
        $parent = $this->parent();
        $student = $this->student();
        // Foreign child: a record with NO parent link on the seed
        // (my_parent_id is NULL for 3039 of 3041 records; only
        // records 1+2 link to parent@). parent@ is foreign to him.
        $foreignRecord = StudentRecord::whereNull('my_parent_id')
            ->where('user_id', '!=', $student->id)->firstOrFail();
        $foreign = User::findOrFail($foreignRecord->user_id);

        $probeParent = $this->probeParent();
        // The probe parent adopts the foreign child, so the probe
        // can book a PTM slot for him below; parent@ stays foreign.
        StudentRecord::where('id', $foreignRecord->id)->update(['my_parent_id' => $probeParent->id]);

        $this->actingAs($parent)->get(route('my_children'))->assertOk()->assertSee($student->name);
        $this->actingAs($parent)->get(route('my_fees'))->assertOk();
        $this->actingAs($parent)->get(route('my_fees.show', $student->id))->assertOk();
        $this->actingAs($parent)->get(route('my_fees.show', $foreign->id))->assertForbidden();

        $ownPr = PaymentRecord::where('student_id', $student->id)->firstOrFail();
        $foreignPr = PaymentRecord::where('student_id', $foreign->id)->firstOrFail();
        $this->actingAs($parent)->get(route('my_fees.receipt', $ownPr->id))->assertOk();
        $this->actingAs($parent)->get(route('my_fees.receipt', $foreignPr->id))->assertForbidden();

        // Attendance: a foreign student_id falls back to the
        // parent's own first child — the child select lists the
        // parent's own children only, never the foreign name.
        $this->actingAs($parent)->get(route('attendance.my', ['student_id' => $foreignRecord->id]))
            ->assertOk()->assertSee($student->name)->assertDontSee($foreign->name);

        $ownComplaint = $this->uniq('Complaint own c3');
        $foreignComplaint = $this->uniq('Complaint foreign c3');
        $ownHealth = HealthRecord::create([
            'student_id' => $student->id, 'visit_date' => '2026-10-26',
            'complaint' => $ownComplaint, 'recorded_by' => $this->admin()->id,
        ]);
        $foreignHealth = HealthRecord::create([
            'student_id' => $foreign->id, 'visit_date' => '2026-10-26',
            'complaint' => $foreignComplaint, 'recorded_by' => $this->admin()->id,
        ]);
        // The index deliberately hides complaint text (record page
        // only), so index scoping is proven by the row's View link
        // and content scoping by the show page.
        $this->actingAs($parent)->get(route('health_records.index'))
            ->assertOk()
            ->assertSee(route('health_records.show', $ownHealth->id))
            ->assertDontSee(route('health_records.show', $foreignHealth->id));
        $this->actingAs($parent)->get(route('health_records.show', $ownHealth->id))
            ->assertOk()->assertSee($ownComplaint);
        $this->actingAs($parent)->get(route('health_records.show', $foreignHealth->id))->assertForbidden();

        $ownIncident = $this->uniq('Incident own c3');
        $foreignIncident = $this->uniq('Incident foreign c3');
        $ownDiscipline = DisciplineRecord::create([
            'student_id' => $student->id, 'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-26', 'type' => array_key_first(DisciplineRecord::TYPES),
            'description' => $ownIncident,
        ]);
        $foreignDiscipline = DisciplineRecord::create([
            'student_id' => $foreign->id, 'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-26', 'type' => array_key_first(DisciplineRecord::TYPES),
            'description' => $foreignIncident,
        ]);
        $this->actingAs($parent)->get(route('discipline_records.index'))
            ->assertOk()
            ->assertSee(route('discipline_records.show', $ownDiscipline->id))
            ->assertDontSee(route('discipline_records.show', $foreignDiscipline->id));
        $this->actingAs($parent)->get(route('discipline_records.show', $ownDiscipline->id))
            ->assertOk()->assertSee($ownIncident);
        $this->actingAs($parent)->get(route('discipline_records.show', $foreignDiscipline->id))->assertForbidden();

        $ownDoc = StudentDocument::create([
            'student_record_id' => $this->prepRecord()->id, 'uploaded_by' => $this->admin()->id,
            'title' => $this->uniq('Doc parent own c3'), 'original_name' => 'p-own.pdf',
            'stored_path' => 'documents/p-own-c3.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $foreignDoc = StudentDocument::create([
            'student_record_id' => $foreignRecord->id, 'uploaded_by' => $this->admin()->id,
            'title' => $this->uniq('Doc parent foreign c3'), 'original_name' => 'p-foreign.pdf',
            'stored_path' => 'documents/p-foreign-c3.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);
        $this->actingAs($parent)->get(route('documents.index'))
            ->assertOk()->assertSee($ownDoc->title)->assertDontSee($foreignDoc->title);

        // PTM masking: a booking by another family shows as
        // "Booked" to a parent — never the other child's name.
        $slot = PtmSlot::create([
            'date' => '2026-11-03', 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => 1,
            'created_by' => $this->admin()->id,
        ]);
        $this->actingAs($probeParent)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $foreign->id]);
        $this->assertEquals(1, PtmBooking::where('ptm_slot_id', $slot->id)->count());
        $this->actingAs($parent)->get(route('ptm.index'))
            ->assertOk()->assertDontSee($foreign->name);
    }

    // ---------------- Teacher scoped indexes ----------------

    public function test_teacher_scoped_indexes_and_health_show()
    {
        $teacher2 = $this->teacher2();
        $prepStudent = $this->student();
        $class2Record = $this->class2Record();
        $class2Student = User::findOrFail($class2Record->user_id);

        $foreignComplaint = $this->uniq('Complaint teacher foreign c3');
        $ownComplaint = $this->uniq('Complaint teacher own c3');
        $foreignHealth = HealthRecord::create([
            'student_id' => $prepStudent->id, 'visit_date' => '2026-10-26',
            'complaint' => $foreignComplaint, 'recorded_by' => $this->admin()->id,
        ]);
        $ownHealth = HealthRecord::create([
            'student_id' => $class2Student->id, 'visit_date' => '2026-10-26',
            'complaint' => $ownComplaint, 'recorded_by' => $this->admin()->id,
        ]);
        // Health index hides complaint text by design (record page
        // only): index scoping is proven by the row's View link.
        $this->actingAs($teacher2)->get(route('health_records.index'))
            ->assertOk()
            ->assertSee(route('health_records.show', $ownHealth->id))
            ->assertDontSee(route('health_records.show', $foreignHealth->id));
        $this->actingAs($teacher2)->get(route('health_records.show', $ownHealth->id))
            ->assertOk()->assertSee($ownComplaint);
        $this->actingAs($teacher2)->get(route('health_records.show', $foreignHealth->id))->assertForbidden();

        $foreignIncident = $this->uniq('Incident teacher foreign c3');
        $ownIncident = $this->uniq('Incident teacher own c3');
        $foreignDiscipline = DisciplineRecord::create([
            'student_id' => $prepStudent->id, 'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-26', 'type' => array_key_first(DisciplineRecord::TYPES),
            'description' => $foreignIncident,
        ]);
        $ownDiscipline = DisciplineRecord::create([
            'student_id' => $class2Student->id, 'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-26', 'type' => array_key_first(DisciplineRecord::TYPES),
            'description' => $ownIncident,
        ]);
        $this->actingAs($teacher2)->get(route('discipline_records.index'))
            ->assertOk()
            ->assertSee(route('discipline_records.show', $ownDiscipline->id))
            ->assertDontSee(route('discipline_records.show', $foreignDiscipline->id));
        $this->actingAs($teacher2)->get(route('discipline_records.show', $ownDiscipline->id))
            ->assertOk()->assertSee($ownIncident);
        $this->actingAs($teacher2)->get(route('discipline_records.show', $foreignDiscipline->id))->assertForbidden();

        $this->actingAs($teacher2)->get(route('ptm.index'))->assertOk();
    }

    // ---------------- Notification bell ----------------

    public function test_notification_api_teacher_sees_only_own()
    {
        // One API call per test: within one in-process test the
        // Sanctum guard retains the first caller (known artifact),
        // so teacher and student calls live in separate methods.
        $teacher = $this->teacher();
        UserNotification::create([
            'user_id' => $teacher->id, 'type' => 'round3', 'title' => $this->uniq('Bell teacher c3'),
        ]);
        UserNotification::create([
            'user_id' => $this->student()->id, 'type' => 'round3', 'title' => $this->uniq('Bell student c3'),
        ]);
        $teacherNote = UserNotification::where('user_id', $teacher->id)->latest()->firstOrFail();
        $studentNote = UserNotification::where('user_id', $this->student()->id)->latest()->firstOrFail();

        $token = $teacher->createToken('round3')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->json('GET', '/api/notifications')
            ->assertOk()->assertSee($teacherNote->title)->assertDontSee($studentNote->title);
    }

    public function test_notification_api_student_sees_only_own()
    {
        $student = $this->student();
        UserNotification::create([
            'user_id' => $this->teacher()->id, 'type' => 'round3', 'title' => $this->uniq('Bell teacher c3'),
        ]);
        UserNotification::create([
            'user_id' => $student->id, 'type' => 'round3', 'title' => $this->uniq('Bell student c3'),
        ]);
        $teacherNote = UserNotification::where('user_id', $this->teacher()->id)->latest()->firstOrFail();
        $studentNote = UserNotification::where('user_id', $student->id)->latest()->firstOrFail();

        $token = $student->createToken('round3')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->json('GET', '/api/notifications')
            ->assertOk()->assertSee($studentNote->title)->assertDontSee($teacherNote->title);
    }

    public function test_notification_mark_read_is_per_user()
    {
        $teacher = $this->teacher();
        $student = $this->student();
        UserNotification::create([
            'user_id' => $teacher->id, 'type' => 'round3', 'title' => $this->uniq('Bell teacher c3'),
        ]);
        UserNotification::create([
            'user_id' => $student->id, 'type' => 'round3', 'title' => $this->uniq('Bell student c3'),
        ]);

        // Opening the bell marks only the caller's items read.
        $this->actingAs($teacher)->post(route('notifications.read'))->assertOk();
        $this->assertEquals(0, UserNotification::where('user_id', $teacher->id)->unread()->count());
        $this->assertEquals(1, UserNotification::where('user_id', $student->id)->unread()->count());
    }
}
