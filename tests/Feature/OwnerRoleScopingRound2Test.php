<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\DisciplineRecord;
use App\Models\ElectiveChoice;
use App\Models\HealthRecord;
use App\Models\LessonPlan;
use App\Models\LibraryReservation;
use App\Models\ParentMessage;
use App\Models\QuestionBankQuestion;
use App\Models\Quiz;
use App\Models\StudentDocument;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Roles agent C round 2: deep per-role scoping on Wave 1/2/3
 * surfaces + principal read-list coherence.
 *
 * The four principal-read fixes (parent-message show, lesson-plan
 * show, discipline show, substitutions index) close a real pattern
 * found in this pass: the principal's index listed rows with View
 * links whose detail action still 403'd the principal. Each fix is
 * read-only monitoring; every write stays closed (asserted below).
 */
class OwnerRoleScopingRound2Test extends TestCase
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
        return User::where('user_type', 'teacher')
            ->where('email', '!=', 'teacher@hackstra.app')
            ->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function otherStudent()
    {
        return User::where('user_type', 'student')
            ->where('email', '!=', 'student@hackstra.app')
            ->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function foreignParent()
    {
        return User::create([
            'name' => 'Round2 Foreign Parent',
            'email' => 'probe-parent-c2-' . uniqid() . '@example.com',
            'password' => Hash::make('probe'),
            'user_type' => 'parent',
            'code' => 'PRB-C2-' . uniqid(),
        ]);
    }

    protected function uniq($prefix)
    {
        return $prefix . ' ' . uniqid();
    }

    public function test_principal_opens_parent_message_from_monitoring_board()
    {
        $message = ParentMessage::create([
            'sender_id' => $this->admin()->id, 'my_class_id' => 1, 'section_id' => null,
            'title' => $this->uniq('PM principal'), 'body' => 'Principal readable body.',
        ]);

        $this->actingAs($this->principal())->get(route('parent_messages.index'))->assertOk();
        $this->actingAs($this->principal())->get(route('parent_messages.show', $message->id))
            ->assertOk()->assertSee('Principal readable body.');
    }

    public function test_principal_opens_lesson_plan_from_board()
    {
        $plan = LessonPlan::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'week_start' => '2026-10-26',
            'topic' => $this->uniq('Plan principal'),
        ]);

        $this->actingAs($this->principal())->get(route('lesson_plans.index'))->assertOk();
        $this->actingAs($this->principal())->get(route('lesson_plans.show', $plan->id))->assertOk();
    }

    public function test_principal_opens_discipline_record_from_board()
    {
        $record = DisciplineRecord::create([
            'student_id' => $this->student()->id, 'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-26', 'type' => array_key_first(DisciplineRecord::TYPES),
            'description' => $this->uniq('Discipline principal'),
        ]);

        $this->actingAs($this->principal())->get(route('discipline_records.index'))->assertOk();
        $this->actingAs($this->principal())->get(route('discipline_records.show', $record->id))
            ->assertOk()->assertSee($record->description);
    }

    public function test_principal_opens_substitutions_board_from_leave_badge()
    {
        $this->actingAs($this->principal())->get(route('substitutions.index'))->assertOk();
    }

    public function test_principal_writes_still_closed()
    {
        $this->actingAs($this->principal())->get(route('parent_messages.create'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('lesson_plans.create'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('discipline_records.create'))->assertForbidden();
        $this->actingAs($this->principal())->get('/substitutions/create')->assertForbidden();
        $this->actingAs($this->principal())->post('/substitutions', [
            'date' => '2026-10-26', 'time_table_id' => TimeTable::firstOrFail()->id,
            'substitute_teacher_id' => $this->otherTeacher()->id,
        ])->assertForbidden();
    }

    public function test_foreign_teacher_cannot_open_or_edit_others_work()
    {
        $plan = LessonPlan::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'week_start' => '2026-10-26',
            'topic' => $this->uniq('Plan foreign'),
        ]);
        $question = QuestionBankQuestion::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'question' => $this->uniq('QB foreign'),
            'options' => ['A', 'B'], 'correct_index' => 0, 'marks' => 1,
        ]);
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => null,
            'subject_id' => 1, 'title' => $this->uniq('Quiz foreign teacher'),
            'time_limit_minutes' => 30, 'status' => 'published',
            'available_from' => '2026-01-01 00:00:00', 'available_until' => '2027-01-01 00:00:00',
        ]);

        $this->actingAs($this->otherTeacher())->get(route('lesson_plans.show', $plan->id))->assertForbidden();
        $this->actingAs($this->otherTeacher())->get(route('question_bank.edit', $question->id))->assertForbidden();
        $this->actingAs($this->otherTeacher())->get(route('quizzes.show', $quiz->id))->assertForbidden();
    }

    public function test_student_and_parent_health_scoping()
    {
        $record = HealthRecord::create([
            'student_id' => $this->student()->id, 'visit_date' => '2026-10-26',
            'complaint' => 'Round2 probe complaint', 'recorded_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->student())->get(route('health_records.show', $record->id))->assertOk();
        $this->actingAs($this->otherStudent())->get(route('health_records.show', $record->id))->assertForbidden();
        $this->actingAs($this->foreignParent())->get(route('health_records.show', $record->id))->assertForbidden();
        // parent@ is the seeded parent of every seeded student.
        $this->actingAs($this->parent())->get(route('health_records.show', $record->id))->assertOk();
    }

    public function test_documents_download_scoping()
    {
        $doc = StudentDocument::create([
            'student_record_id' => 1, 'uploaded_by' => $this->admin()->id,
            'title' => $this->uniq('Doc scope'), 'original_name' => 'probe.pdf',
            'stored_path' => 'documents/probe-c2.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);

        // Guard precedes the file-exists check, so foreign/teacher
        // callers get 403 even though the file is absent.
        $this->actingAs($this->otherStudent())->get(route('documents.download', $doc->id))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('documents.download', $doc->id))->assertForbidden();
    }

    public function test_non_admin_cannot_open_alumni_or_role_permissions()
    {
        $this->actingAs($this->teacher())->get(route('alumni.index'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('alumni.index'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('role_permissions.index'))->assertForbidden();
    }

    public function test_teacher_cannot_approve_elective_without_grant()
    {
        $choice = ElectiveChoice::create([
            'student_record_id' => 1, 'subject_id' => 79, 'status' => 'pending',
        ]);

        $this->actingAs($this->teacher())->post(route('electives.approve', $choice->id))->assertForbidden();
        $this->assertEquals('pending', $choice->fresh()->status);
    }

    public function test_student_quiz_show_foreign_class_denied()
    {
        $quiz = Quiz::create([
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 2, 'section_id' => null,
            'subject_id' => 7, 'title' => $this->uniq('Quiz foreign class'),
            'time_limit_minutes' => 30, 'status' => 'published',
            'available_from' => '2026-01-01 00:00:00', 'available_until' => '2027-01-01 00:00:00',
        ]);

        // student@ is Prep (class 1); this quiz is Class 1.
        $this->actingAs($this->student())->get(route('quizzes.show', $quiz->id))->assertForbidden();
    }

    public function test_parent_ptm_book_foreign_child_refused()
    {
        $slot = \App\Models\PtmSlot::create([
            'date' => '2026-11-02', 'start_time' => '09:00:00', 'end_time' => '09:30:00',
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1, 'section_id' => 1,
            'created_by' => $this->admin()->id,
        ]);

        $response = $this->actingAs($this->foreignParent())
            ->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $this->student()->id]);
        $response->assertRedirect();
        $this->assertEquals(0, \App\Models\PtmBooking::where('slot_id', $slot->id)->count());
    }

    public function test_library_reservation_cancel_foreign_denied()
    {
        $book = Book::create([
            'name' => $this->uniq('Scope book'), 'author' => 'Scope Author',
            'book_type' => 'Book', 'total_copies' => 2,
        ]);
        $reservation = LibraryReservation::create([
            'book_id' => $book->id, 'user_id' => $this->parent()->id,
            'student_record_id' => null, 'status' => 'reserved',
        ]);

        $this->actingAs($this->student())->delete(route('library_reservations.cancel', $reservation->id))
            ->assertForbidden();
        $this->assertEquals('reserved', $reservation->fresh()->status);
    }

    public function test_substitution_delete_by_non_creator_denied()
    {
        $sub = Substitution::create([
            'time_table_id' => TimeTable::firstOrFail()->id, 'date' => '2026-10-26',
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $this->otherTeacher()->id,
            'created_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->otherTeacher())->delete('/substitutions/' . $sub->id)->assertForbidden();
        $this->assertNotNull($sub->fresh());
    }

    public function test_no_link_surfaces_stay_closed_for_principal()
    {
        // No principal menu link points at these; the closure is the
        // intended behaviour (health data is sensitive, quiz/class
        // overview/homework are teacher/admin tools).
        $this->actingAs($this->principal())->get(route('homework_tracker.index'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('class_overview.index'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('health_records.index'))->assertForbidden();
        $this->actingAs($this->principal())->get(route('quizzes.index'))->assertForbidden();
    }

    protected function apiToken(User $user)
    {
        return $user->createToken('mobile-round2')->plainTextToken;
    }

    protected function apiCall(string $method, string $uri, User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->apiToken($user)])
            ->json($method, $uri);
    }

    public function test_principal_api_token_but_data_endpoints_closed()
    {
        // Principal login is NOT refused (only SA/suspended are), so a
        // token + /me succeed — but every data endpoint refuses the
        // account type, so no school data leaks through the API.
        $principal = $this->principal();
        $login = $this->postJson('/api/login', [
            'identity' => 'principal@hackstra.app', 'password' => 'hackstra',
        ]);
        $login->assertOk()->assertJsonPath('user.user_type', 'principal');

        $this->apiCall('GET', '/api/me', $principal)->assertOk();
        $this->apiCall('GET', '/api/timetable/mine', $principal)->assertStatus(403);
        $this->apiCall('GET', '/api/marks/mine', $principal)->assertStatus(403);
        $this->apiCall('GET', '/api/attendance/mine', $principal)->assertStatus(403);
        $this->apiCall('GET', '/api/messages', $principal)->assertStatus(403);
        // Notifications are the caller's own bell items only.
        $this->apiCall('GET', '/api/notifications', $principal)->assertOk();
    }

    public function test_parent_foreign_child_attendance_is_403()
    {
        $parentA = User::create([
            'name' => 'Probe Parent A2', 'username' => 'probe-parent-a2',
            'email' => 'probe-parent-a2@example.com', 'code' => 'PPA-2',
            'password' => Hash::make('hackstra'), 'user_type' => 'parent',
        ]);
        $parentB = User::create([
            'name' => 'Probe Parent B2', 'username' => 'probe-parent-b2',
            'email' => 'probe-parent-b2@example.com', 'code' => 'PPB-2',
            'password' => Hash::make('hackstra'), 'user_type' => 'parent',
        ]);
        $records = \App\Models\StudentRecord::orderBy('id')->take(2)->get();
        \App\Models\StudentRecord::where('id', $records[0]->id)->update(['my_parent_id' => $parentA->id]);
        \App\Models\StudentRecord::where('id', $records[1]->id)->update(['my_parent_id' => $parentB->id]);

        // resolveChild is shared by timetable/marks/attendance, so the
        // foreign-child refusal must hold on attendance too.
        $this->apiCall('GET', '/api/attendance/mine?child=' . $records[1]->id, $parentA)
            ->assertStatus(403);
    }

    public function test_principal_sidebar_links_all_200_and_dashboard_school_redirects()
    {
        $principal = $this->principal();
        $classId = \App\Models\MyClass::firstOrFail()->id;
        $links = [
            route('dashboard'), route('classes.index'), route('tt.index'),
            route('students.list', $classId), route('users.index'),
            route('attendance.periods.index'), route('exams.index'), route('leave.index'),
            route('payments.manage'), route('parent_messages.index'), route('transport.index'),
            route('discipline_records.index'), route('reports.builder'), route('audit_logs.index'),
            route('my_account'), route('leave.my'), route('ask.index'),
            route('feedback.create'), route('feedback.mine'),
        ];
        $this->assertCount(19, $links, 'Principal sidebar must carry exactly 19 links');
        foreach ($links as $url) {
            $this->actingAs($principal)->get($url)->assertOk($url);
        }

        // The admin board stays closed: school dashboard bounces to home.
        $this->actingAs($principal)->get(route('dashboard.school'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_principal_writes_still_closed_after_all_edits()
    {
        $principal = $this->principal();
        $this->actingAs($principal)->get('/super_admin/settings')->assertRedirect('/login');
        $this->actingAs($principal)->get(route('users.create'))->assertRedirect('/login');
        $this->actingAs($principal)->get(route('tt.generate.form'))->assertRedirect('/login');
        $this->actingAs($principal)->get(route('exams.create'))->assertRedirect('/login');
        $this->actingAs($principal)->get(route('payments.create'))->assertRedirect('/login');
    }
}
