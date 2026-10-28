<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\BookRequest;
use App\Models\DisciplineRecord;
use App\Models\ElectiveChoice;
use App\Models\Exam;
use App\Models\HealthRecord;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Agent C round 25 — student/parent portal RE-SWEEP after the
 * round-21/22/23/24 landscape (librarian + accountant + principal
 * portals locked, marks entry re-gated to the effective teacher
 * by F-R24-1, which this round's parent decision KEEPS).
 *
 * Gates read first (routes/web.php + controller constructors +
 * controller guards) before writing any test:
 *  - marks.show / marks.print: NO team middleware; the shared
 *    canAccessStudentMarks() gate decides — self, teamSA, own
 *    child, or a teacher scoped to the student's class pass;
 *    everyone else is redirected to /dashboard with pop_error
 *    (NOT a 403). feeDefaulterBlock() then serves the blocked
 *    view to owing students/parents (teamSA + teachers exempt);
 *    unpublished exams render the honest not_published view
 *    for students/parents.
 *  - /fees/mine + /library/mine: `student` middleware — parents
 *    bounce to login; the parent fee surface is /my_fees under
 *    `my_parent` middleware (students bounce to login there).
 *  - /my_fees shows exactly the parent's linked children
 *    (my_parent_id = parent id); /my_fees/{student_id} aborts
 *    403 unless the id is the parent's own child.
 *  - documents: scopedQuery() — admin all, student own record
 *    only, parent own children's records only; download() uses
 *    the same canAccess() (admin/student-self/parent-of-child).
 *  - electives: admin all; student own choices only, eligible
 *    classes Class 11/12 only (others get an explanatory page,
 *    not an error); store refuses foreign subjects (403) and
 *    ineligible students (403); parents 403.
 *  - ptm.index: canView = admin/teacher/parent — students 403.
 *    Parents see "Booked by you" (+ own child name) for their
 *    own booking and a bare "Booked" badge for other families
 *    — never another family's names. book() refuses foreign
 *    children with flash_danger.
 *  - health: index scopes by role and NEVER renders complaint/
 *    treatment text (record page only); show() enforces the
 *    same scope per record (403 otherwise).
 *  - discipline: index/show scope via visibleStudentIds() /
 *    mayAccessStudent() (admin + principal all; teacher scoped;
 *    parent own children; student self).
 *  - transport.mine: parent-only (403 for student AND admin);
 *    children = findMyChildren(), assignments whereIn child ids.
 *  - staff negatives: users.index teamOrPrincipal:SA,
 *    payments.manage teamOrPrincipal:Account (controller),
 *    marks.manage teamSATWrite, attendance take teamSAT,
 *    leave.index teamOrPrincipal:SAWrite — team middleware
 *    bounces to LOGIN on failure.
 *
 * Brief-premise CORRECTIONS (proven from code, not findings):
 *  1. "/fees/mine for student and parent" is wrong as written:
 *     /fees/mine is student-middleware-only, so a parent GET
 *     bounces to login; the parent surface is /my_fees. Both
 *     are locked here under their real routes.
 *  2. "PTM (own bookings only)" for students is wrong: students
 *     cannot open ptm.index at all (403 — canView excludes
 *     them). Parents get the masked list + own bookings.
 *  3. Transport assignment-row filtering is code-read, not
 *     fixture-proven: the transport tables seed ZERO routes,
 *     vehicles and stops, so no assignment row can be built
 *     without fabricating the whole transport graph. mine()
 *     gating + children scoping ARE fixture-proven below.
 */
class OwnerStudentParentResweepRound25Test extends TestCase
{
    use DatabaseTransactions;

    private const YEAR = '2026-2027';

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

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

    /** Aarav Sharma (user 57, student_record 1). */
    protected function ownRecord()
    {
        return StudentRecord::where('user_id', $this->student()->id)->firstOrFail();
    }

    /** Dariana Heaney — parent@'s second linked child. */
    protected function siblingRecord()
    {
        return StudentRecord::where('my_parent_id', $this->parent()->id)
            ->where('user_id', '!=', $this->student()->id)
            ->firstOrFail();
    }

    /** A truly foreign student: no parent link, not Aarav/Dariana. */
    protected function foreignRecord()
    {
        return StudentRecord::whereNull('my_parent_id')
            ->whereNotIn('user_id', [$this->student()->id, $this->siblingRecord()->user_id])
            ->orderBy('user_id')
            ->firstOrFail();
    }

    /** A foreign student in Class 1 — outside Ananya's scope. */
    protected function foreignClass1Record()
    {
        return StudentRecord::where('my_class_id', 2)
            ->whereNull('my_parent_id')
            ->orderBy('user_id')
            ->firstOrFail();
    }

    /** A Class 12 student (elective-eligible) + a second one. */
    protected function class12Records()
    {
        return StudentRecord::where('my_class_id', 13)->where('grad', 0)
            ->orderBy('id')->limit(2)->get();
    }

    protected function hashId($userId)
    {
        return Qs::hash($userId);
    }

    protected function viewData($response, $key)
    {
        return $response->getOriginalContent()->getData()[$key];
    }

    protected function publishTerm1()
    {
        $exam = Exam::findOrFail(1);
        $exam->results_published = true;
        $exam->save();
    }

    protected function unpublishTerm1()
    {
        $exam = Exam::findOrFail(1);
        $exam->results_published = false;
        $exam->save();
    }

    // ---------------- (a) MARKS ----------------

    public function test_student_own_marksheet_published()
    {
        $this->publishTerm1();

        $show = $this->actingAs($this->student())
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $show->assertOk();
        $show->assertSee('Aarav Sharma');

        $print = $this->actingAs($this->student())
            ->get(route('marks.print', [$this->hashId(57), 1, self::YEAR]));
        $print->assertOk();
        $print->assertSee('Aarav Sharma');
    }

    public function test_student_foreign_marksheet_refused()
    {
        $this->publishTerm1();
        $foreign = $this->foreignRecord();

        $show = $this->actingAs($this->student())
            ->get(route('marks.show', [$this->hashId($foreign->user_id), self::YEAR]));
        $show->assertRedirect(route('dashboard'));

        $print = $this->actingAs($this->student())
            ->get(route('marks.print', [$this->hashId($foreign->user_id), 1, self::YEAR]));
        $print->assertRedirect(route('dashboard'));
    }

    public function test_parent_own_child_marksheet_published()
    {
        $this->publishTerm1();

        $show = $this->actingAs($this->parent())
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $show->assertOk();
        $show->assertSee('Aarav Sharma');

        $print = $this->actingAs($this->parent())
            ->get(route('marks.print', [$this->hashId(57), 1, self::YEAR]));
        $print->assertOk();
        $print->assertSee('Aarav Sharma');
    }

    public function test_parent_foreign_child_marksheet_refused()
    {
        $this->publishTerm1();
        $foreign = $this->foreignRecord();

        $show = $this->actingAs($this->parent())
            ->get(route('marks.show', [$this->hashId($foreign->user_id), self::YEAR]));
        $show->assertRedirect(route('dashboard'));

        $print = $this->actingAs($this->parent())
            ->get(route('marks.print', [$this->hashId($foreign->user_id), 1, self::YEAR]));
        $print->assertRedirect(route('dashboard'));
    }

    public function test_unpublished_exam_honest_empty_state()
    {
        $this->unpublishTerm1();

        $studentShow = $this->actingAs($this->student())
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $studentShow->assertOk();
        $studentShow->assertSee('Results Not Published Yet');

        $parentShow = $this->actingAs($this->parent())
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $parentShow->assertOk();
        $parentShow->assertSee('Results Not Published Yet');

        $parentPrint = $this->actingAs($this->parent())
            ->get(route('marks.print', [$this->hashId(57), 1, self::YEAR]));
        $parentPrint->assertOk();
        $parentPrint->assertSee('Results Not Published Yet');
    }

    public function test_teacher_and_accountant_marksheet_scope()
    {
        $this->publishTerm1();
        $foreign = $this->foreignClass1Record();

        // Ananya teaches Prep: Aarav's sheet opens.
        $own = $this->actingAs($this->teacher())
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $own->assertOk();
        $own->assertSee('Aarav Sharma');

        // Ananya does NOT teach Class 1: foreign sheet bounces.
        $away = $this->actingAs($this->teacher())
            ->get(route('marks.show', [$this->hashId($foreign->user_id), self::YEAR]));
        $away->assertRedirect(route('dashboard'));

        // Accountant is neither teamSA, teacher, parent nor self.
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        $acct = $this->actingAs($accountant)
            ->get(route('marks.show', [$this->hashId(57), self::YEAR]));
        $acct->assertRedirect(route('dashboard'));
    }

    // ---------------- (b) FEES + MY CARD ----------------

    public function test_student_fees_mine_own_only()
    {
        $response = $this->actingAs($this->student())->get(route('student.fees'));
        $response->assertOk();

        $ids = collect($this->viewData($response, 'uncleared'))
            ->merge($this->viewData($response, 'cleared'))
            ->map(function ($presented) {
                return (int) $presented['record']->student_id;
            })->unique()->values()->all();
        $this->assertSame([57], $ids);
    }

    public function test_parent_fees_my_children_only()
    {
        $foreign = $this->foreignRecord();
        $foreignName = User::findOrFail($foreign->user_id)->name;

        $response = $this->actingAs($this->parent())->get(route('my_fees'));
        $response->assertOk();
        $response->assertSee('Aarav Sharma');
        $response->assertSee($this->siblingRecord()->user->name);
        $response->assertDontSee($foreignName);

        $childUserIds = $this->viewData($response, 'children')
            ->map(function ($child) {
                return (int) $child['sr']->user_id;
            })->sort()->values()->all();
        $this->assertSame([57, $this->siblingRecord()->user_id], $childUserIds);
    }

    public function test_parent_fees_show_own_vs_foreign()
    {
        $foreign = $this->foreignRecord();

        $own = $this->actingAs($this->parent())
            ->get(route('my_fees.show', 57));
        $own->assertOk();

        $away = $this->actingAs($this->parent())
            ->get(route('my_fees.show', $foreign->user_id));
        $away->assertForbidden();
    }

    public function test_my_card_defaulter_block_vs_office()
    {
        $sibling = $this->siblingRecord();

        // Owing student 58, self: blocked view with the real total.
        $blocked = $this->actingAs(User::findOrFail($sibling->user_id))
            ->get(route('admit_cards.my_card', [1, $sibling->user_id]));
        $blocked->assertOk();
        $blocked->assertViewIs('pages.support_team.fee_blocked');
        $blocked->assertSee('14,400');

        // Parent of 58: blocked view with the Pay the Fee action.
        $parentBlocked = $this->actingAs($this->parent())
            ->get(route('admit_cards.my_card', [1, $sibling->user_id]));
        $parentBlocked->assertOk();
        $parentBlocked->assertViewIs('pages.support_team.fee_blocked');
        $parentBlocked->assertSee('Pay the Fee');

        // Admin office generation is never blocked.
        $office = $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', [1, $sibling->user_id]));
        $office->assertOk();
        $office->assertSee($sibling->user->name);
        $office->assertDontSee('Cannot Be Generated');
    }

    // ---------------- (c) OWN-ONLY SURFACES ----------------

    public function test_library_mine_own_only_and_parent_bounces()
    {
        $foreign = $this->foreignRecord();
        BookRequest::create([
            'book_id' => 1, 'user_id' => 57,
            'start_date' => '2026-10-20', 'end_date' => '2026-11-20',
            'returned' => false, 'status' => BookRequest::STATUS_ISSUED,
        ]);
        BookRequest::create([
            'book_id' => 1, 'user_id' => $foreign->user_id,
            'start_date' => '2026-10-20', 'end_date' => '2026-11-20',
            'returned' => false, 'status' => BookRequest::STATUS_ISSUED,
        ]);

        $response = $this->actingAs($this->student())->get(route('student.library'));
        $response->assertOk();
        $loanUserIds = $this->viewData($response, 'loans')
            ->map(function ($loan) {
                return (int) $loan->user_id;
            })->unique()->values()->all();
        $this->assertSame([57], $loanUserIds);

        // /library/mine is student-middleware-only: parent bounces.
        $this->actingAs($this->parent())->get(route('student.library'))
            ->assertRedirect(route('login'));
    }

    public function test_documents_index_and_download_own_only()
    {
        Storage::fake('public');
        $own = $this->ownRecord();
        $sibling = $this->siblingRecord();
        $foreign = $this->foreignRecord();

        $mk = function ($recordId, $title) {
            Storage::disk('public')->put('documents/' . $title . '.pdf', 'R25 fixture');
            return StudentDocument::create([
                'student_record_id' => $recordId,
                'uploaded_by' => User::where('email', 'admin@hackstra.app')->firstOrFail()->id,
                'title' => $title,
                'original_name' => $title . '.pdf',
                'stored_path' => 'documents/' . $title . '.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 11,
            ]);
        };
        $ownDoc = $mk($own->id, 'R25-OWN-DOC');
        $childDoc = $mk($sibling->id, 'R25-CHILD58-DOC');
        $foreignDoc = $mk($foreign->id, 'R25-FOR-DOC');

        $studentIndex = $this->actingAs($this->student())->get(route('documents.index'));
        $studentIndex->assertOk();
        $studentIndex->assertSee('R25-OWN-DOC');
        $studentIndex->assertDontSee('R25-FOR-DOC');
        $studentIndex->assertDontSee('R25-CHILD58-DOC');

        $parentIndex = $this->actingAs($this->parent())->get(route('documents.index'));
        $parentIndex->assertOk();
        $parentIndex->assertSee('R25-OWN-DOC');
        $parentIndex->assertSee('R25-CHILD58-DOC');
        $parentIndex->assertDontSee('R25-FOR-DOC');

        $this->actingAs($this->student())
            ->get(route('documents.download', $ownDoc->id))->assertOk();
        $this->actingAs($this->parent())
            ->get(route('documents.download', $childDoc->id))->assertOk();
        $this->actingAs($this->student())
            ->get(route('documents.download', $foreignDoc->id))->assertForbidden();
        $this->actingAs($this->parent())
            ->get(route('documents.download', $foreignDoc->id))->assertForbidden();

        $adminIndex = $this->actingAs($this->admin())->get(route('documents.index'));
        $adminIndex->assertOk();
        $adminIndex->assertSee('R25-FOR-DOC');
    }

    public function test_electives_student_own_only_and_store()
    {
        [$first, $second] = $this->class12Records()->all();
        $subject = Subject::findOrFail(79); // Class 12 English, untagged

        $index = $this->actingAs(User::findOrFail($first->user_id))
            ->get(route('electives.index'));
        $index->assertOk();
        $this->assertTrue($this->viewData($index, 'eligible'));

        $stored = $this->actingAs(User::findOrFail($first->user_id))
            ->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 79]);
        $stored->assertRedirect(route('electives.index'));
        $stored->assertSessionHas('flash_success');
        $this->assertDatabaseHas('elective_choices', [
            'student_record_id' => $first->id,
            'subject_id' => 79,
            'status' => ElectiveChoice::STATUS_PENDING,
        ]);

        // The second Class 12 student's page shows none of it.
        $other = $this->actingAs(User::findOrFail($second->user_id))
            ->get(route('electives.index'));
        $other->assertOk();
        $otherChoiceIds = $this->viewData($other, 'choices')
            ->map(function ($choice) {
                return (int) $choice->student_record_id;
            })->unique()->values()->all();
        $this->assertNotContains($first->id, $otherChoiceIds);

        // Foreign subject (Class 11) refused for a Class 12 student.
        $this->actingAs(User::findOrFail($first->user_id))
            ->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 67])
            ->assertForbidden();

        // Prep student: explanatory page, store refused.
        $prep = $this->actingAs($this->student())->get(route('electives.index'));
        $prep->assertOk();
        $this->assertFalse($this->viewData($prep, 'eligible'));
        $this->actingAs($this->student())
            ->from(route('electives.index'))
            ->post(route('electives.store'), ['subject_id' => 79])
            ->assertForbidden();

        // Parent: no elective surface at all.
        $this->actingAs($this->parent())->get(route('electives.index'))
            ->assertForbidden();
    }

    public function test_ptm_parent_masking_and_booking_rules()
    {
        $foreign = $this->foreignRecord();
        $foreignName = User::findOrFail($foreign->user_id)->name;
        $otherParent = User::where('user_type', 'parent')
            ->where('id', '!=', $this->parent()->id)->firstOrFail();

        $slotOwn = PtmSlot::create([
            'date' => '2026-11-10', 'start_time' => '10:00', 'end_time' => '10:30',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
            'section_id' => null, 'created_by' => $this->admin()->id,
        ]);
        PtmBooking::create([
            'ptm_slot_id' => $slotOwn->id,
            'parent_id' => $this->parent()->id,
            'student_user_id' => 57,
        ]);
        $slotOther = PtmSlot::create([
            'date' => '2026-11-11', 'start_time' => '10:00', 'end_time' => '10:30',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
            'section_id' => null, 'created_by' => $this->admin()->id,
        ]);
        PtmBooking::create([
            'ptm_slot_id' => $slotOther->id,
            'parent_id' => $otherParent->id,
            'student_user_id' => $foreign->user_id,
        ]);

        $index = $this->actingAs($this->parent())->get(route('ptm.index'));
        $index->assertOk();
        $index->assertSee('Booked by you');
        $index->assertSee('Aarav Sharma');
        $index->assertSee('Booked');
        $index->assertDontSee($foreignName);
        $index->assertDontSee($otherParent->name);

        // Students get no PTM index at all.
        $this->actingAs($this->student())->get(route('ptm.index'))
            ->assertForbidden();

        // Booking own child works; booking a foreign child is refused.
        $slotFree = PtmSlot::create([
            'date' => '2026-11-12', 'start_time' => '10:00', 'end_time' => '10:30',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
            'section_id' => null, 'created_by' => $this->admin()->id,
        ]);
        $booked = $this->actingAs($this->parent())
            ->post(route('ptm.book', $slotFree->id), ['student_user_id' => 57]);
        $booked->assertRedirect(route('ptm.index'));
        $booked->assertSessionHas('flash_success', 'Slot booked.');
        $this->assertDatabaseHas('ptm_bookings', [
            'ptm_slot_id' => $slotFree->id,
            'parent_id' => $this->parent()->id,
            'student_user_id' => 57,
        ]);

        $slotFree2 = PtmSlot::create([
            'date' => '2026-11-13', 'start_time' => '10:00', 'end_time' => '10:30',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
            'section_id' => null, 'created_by' => $this->admin()->id,
        ]);
        $refused = $this->actingAs($this->parent())
            ->from(route('ptm.index'))
            ->post(route('ptm.book', $slotFree2->id), ['student_user_id' => $foreign->user_id]);
        $refused->assertRedirect(route('ptm.index'));
        $refused->assertSessionHas('flash_danger', 'You can only book a slot for your own child.');
        $this->assertDatabaseMissing('ptm_bookings', ['ptm_slot_id' => $slotFree2->id]);
    }

    public function test_health_index_hides_complaint_show_reveals()
    {
        $sibling = $this->siblingRecord();
        $foreign = $this->foreignRecord();
        $adminId = $this->admin()->id;

        $own = HealthRecord::create([
            'student_id' => 57, 'visit_date' => '2026-10-20',
            'complaint' => 'R25-COMP-A', 'treatment' => 'R25-TREAT-A',
            'recorded_by' => $adminId,
        ]);
        $child = HealthRecord::create([
            'student_id' => $sibling->user_id, 'visit_date' => '2026-10-20',
            'complaint' => 'R25-COMP-C', 'treatment' => 'R25-TREAT-C',
            'recorded_by' => $adminId,
        ]);
        $away = HealthRecord::create([
            'student_id' => $foreign->user_id, 'visit_date' => '2026-10-20',
            'complaint' => 'R25-COMP-F', 'treatment' => 'R25-TREAT-F',
            'recorded_by' => $adminId,
        ]);

        $studentIndex = $this->actingAs($this->student())->get(route('health_records.index'));
        $studentIndex->assertOk();
        $studentIndex->assertDontSee('R25-COMP-A');
        $studentIndex->assertDontSee('R25-COMP-F');
        $studentIds = $this->viewData($studentIndex, 'records')
            ->map(function ($record) {
                return (int) $record->student_id;
            })->unique()->values()->all();
        $this->assertSame([57], $studentIds);

        $ownShow = $this->actingAs($this->student())
            ->get(route('health_records.show', $own->id));
        $ownShow->assertOk();
        $ownShow->assertSee('R25-COMP-A');

        $this->actingAs($this->student())
            ->get(route('health_records.show', $away->id))->assertForbidden();

        $parentIndex = $this->actingAs($this->parent())->get(route('health_records.index'));
        $parentIndex->assertOk();
        $parentIndex->assertDontSee('R25-COMP-A');
        $parentIndex->assertDontSee('R25-COMP-C');
        $parentIndex->assertDontSee('R25-COMP-F');
        $parentIds = $this->viewData($parentIndex, 'records')
            ->map(function ($record) {
                return (int) $record->student_id;
            })->unique()->values()->sort()->values()->all();
        $this->assertSame([57, $sibling->user_id], $parentIds);

        $childShow = $this->actingAs($this->parent())
            ->get(route('health_records.show', $child->id));
        $childShow->assertOk();
        $childShow->assertSee('R25-COMP-C');

        $this->actingAs($this->parent())
            ->get(route('health_records.show', $away->id))->assertForbidden();
    }

    public function test_discipline_index_and_show_scoping()
    {
        $sibling = $this->siblingRecord();
        $foreign = $this->foreignRecord();
        $adminId = $this->admin()->id;

        $own = DisciplineRecord::create([
            'student_id' => 57, 'recorded_by' => $adminId,
            'incident_date' => '2026-10-20', 'type' => 'late',
            'description' => 'R25-DISC-A', 'action_taken' => 'Warned',
        ]);
        $child = DisciplineRecord::create([
            'student_id' => $sibling->user_id, 'recorded_by' => $adminId,
            'incident_date' => '2026-10-20', 'type' => 'late',
            'description' => 'R25-DISC-C', 'action_taken' => 'Warned',
        ]);
        $away = DisciplineRecord::create([
            'student_id' => $foreign->user_id, 'recorded_by' => $adminId,
            'incident_date' => '2026-10-20', 'type' => 'late',
            'description' => 'R25-DISC-F', 'action_taken' => 'Warned',
        ]);

        $studentIndex = $this->actingAs($this->student())->get(route('discipline_records.index'));
        $studentIndex->assertOk();
        $studentIds = collect($this->viewData($studentIndex, 'records')->items())
            ->map(function ($record) {
                return (int) $record->student_id;
            })->unique()->values()->all();
        $this->assertSame([57], $studentIds);

        $ownShow = $this->actingAs($this->student())
            ->get(route('discipline_records.show', $own->id));
        $ownShow->assertOk();
        $ownShow->assertSee('R25-DISC-A');

        $this->actingAs($this->student())
            ->get(route('discipline_records.show', $away->id))->assertForbidden();

        $parentIndex = $this->actingAs($this->parent())->get(route('discipline_records.index'));
        $parentIndex->assertOk();
        $parentIndex->assertDontSee('R25-DISC-F');

        $childShow = $this->actingAs($this->parent())
            ->get(route('discipline_records.show', $child->id));
        $childShow->assertOk();
        $childShow->assertSee('R25-DISC-C');

        $this->actingAs($this->parent())
            ->get(route('discipline_records.show', $away->id))->assertForbidden();
    }

    public function test_transport_mine_parent_only_and_children_scoped()
    {
        // The blade renders assignments only (child names never
        // appear); with zero seeded assignments it shows the empty
        // state. Scoping is proven by the view data below.
        $mine = $this->actingAs($this->parent())->get(route('transport.mine'));
        $mine->assertOk();
        $mine->assertSee('None of your children has a transport assignment yet.');
        $childUserIds = $this->viewData($mine, 'children')
            ->map(function ($child) {
                return (int) $child->user_id;
            })->sort()->values()->all();
        $this->assertSame([57, $this->siblingRecord()->user_id], $childUserIds);

        $this->actingAs($this->student())->get(route('transport.mine'))
            ->assertForbidden();
        $this->actingAs($this->admin())->get(route('transport.mine'))
            ->assertForbidden();
    }

    // ---------------- (d) NEGATIVE CONTROLS ----------------

    public function test_staff_surfaces_refused_for_student_and_parent()
    {
        $surfaces = [
            route('users.index'),
            route('payments.manage'),
            route('marks.manage', [1, 1, 1, 1]),
            route('attendance.periods.take', ['tt_id' => 788, 'date' => '2026-10-19']),
            route('leave.index'),
        ];

        foreach ([$this->student(), $this->parent()] as $user) {
            foreach ($surfaces as $url) {
                $this->actingAs($user)->get($url)->assertRedirect(route('login'));
            }
        }
    }

    public function test_parent_student_middleware_surfaces()
    {
        // /fees/mine is student-only; /my_fees is parent-only.
        $this->actingAs($this->parent())->get(route('student.fees'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->student())->get(route('my_fees'))
            ->assertRedirect(route('login'));

        // Student's own events surface still opens for students.
        $this->actingAs($this->student())->get(route('student.events'))
            ->assertOk();
    }
}
