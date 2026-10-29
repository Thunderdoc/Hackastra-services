<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\LeaveRequest;
use App\Models\MyClass;
use App\Models\PtmSlot;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * AUDIT stream AUD-ROLES, Audit Round 1 (2026-10-29 overnight batch).
 * Verification ONLY — these probes assert the batch's stated gates:
 *  (7)  Reports & PDF Builder: teacher + principal only (S3-BUILDER).
 *  (16) Admin menu removals: Documents Vault, Alumni, Elective
 *       Choices, Role Permissions, Transport, Inventory, Discipline
 *       Records, Parent Messages (S6-MENUS).
 *  (17) Teacher menu removals: Question Bank, PTM links (S6-MENUS).
 *  (18) PTM scheduling admin-only (S6-MENUS).
 *  (19) Decisions gate: teacher cannot decide; admin manual decide,
 *       emergency auto-approved (S6-MENUS). NOTE: the teacher
 *       student-leave probe below CONFLICTS with the settled
 *       OwnerStudentLeaveTest rule (section teacher decides student
 *       leaves) — its failure is reported with that conflict flagged
 *       for the coordinator to adjudicate scope, not as a clean break.
 *  (20) Hostel: hidden from admin; warden (user_type 'hostel') login
 *       path (S6-MENUS).
 *  (22) Date-sheet approval gates: students/parents/teachers see
 *       PUBLISHED sheets only (S6-MENUS).
 *
 * Snapshot DB only (DB_DATABASE=/tmp/ovn-a-roles.sqlite). The
 * exam_date_sheet_states table is created inside the test
 * transaction when the snapshot predates the migration, so the
 * item-22 probes test gate logic rather than schema presence;
 * schema presence itself is reported separately.
 */
class OwnerAuditOvernightRolesTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function blankConfig(): array
    {
        return [
            'title' => 'Audit Probe',
            'subtitle' => '',
            'source' => 'blank',
            'filters' => [],
            'columns' => [['key' => 'custom_1', 'label' => 'Note', 'type' => 'text', 'width' => 50]],
            'options' => [
                'orientation' => 'portrait', 'margin_mm' => 10, 'font_size' => 11,
                'show_header' => true, 'show_meta' => true, 'show_footer' => true,
                'show_signatures' => false, 'blank_rows' => 0, 'totals' => [], 'sort' => null,
            ],
            'instructions' => '',
        ];
    }

    protected function ensureSheetStatesTable(): void
    {
        if (Schema::hasTable('exam_date_sheet_states')) {
            return;
        }
        Schema::create('exam_date_sheet_states', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('exam_id');
            $table->unsignedInteger('my_class_id');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['exam_id', 'my_class_id']);
        });
    }

    /* ---------------- Item 7: builder gate ---------------- */

    public function test_item7_admin_allowed_on_every_builder_route()
    {
        // Owner correction 2026-10-29 evening: the overnight
        // teacher+principal-only gate is reversed for admin — he
        // works in the builder as Admin himself.
        $admin = $this->userByEmail('admin@hackstra.app');
        $this->actingAs($admin)->get(route('reports.builder'))->assertOk();
        $this->actingAs($admin)->post(route('reports.preview'), ['config' => $this->blankConfig()])->assertOk();
        $response = $this->actingAs($admin)->post(route('reports.pdf'), ['config' => $this->blankConfig()]);
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->actingAs($admin)->from(route('reports.builder'))
            ->post(route('reports.templates.store'), ['name' => 'Audit Admin Tpl', 'config' => $this->blankConfig()])
            ->assertRedirect();
    }

    public function test_item7_teacher_and_principal_allowed_on_builder()
    {
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->get(route('reports.builder'))->assertOk();
        $this->actingAs($this->userByEmail('principal@hackstra.app'))
            ->post(route('reports.preview'), ['config' => $this->blankConfig()])->assertOk();
    }

    public function test_item7_other_roles_refused_on_builder()
    {
        foreach (['accountant@hackstra.app', 'librarian@hackstra.app', 'superadmin@hackstra.app', 'student@hackstra.app', 'parent@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))->get(route('reports.builder'))->assertForbidden();
        }
    }

    public function test_item7_admin_menu_has_builder_link_and_accountant_link_removed()
    {
        // Admin menu: builder link restored (owner correction —
        // see test_item7_admin_allowed_on_every_builder_route).
        // The needle carries a raw & — assertSee must not escape
        // it, or the check passes/fails vacuously.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('dashboard'))
            ->assertOk()->assertSee('Reports & PDF Builder', false);
        // Accountant menu no longer links the builder at all (overnight
        // menus stream removed the dead clean-403 link; better than stale).
        // The link text is written with a raw & in partials/menu, so the
        // assertion must not escape the needle (assertSee escapes by default).
        $this->actingAs($this->userByEmail('accountant@hackstra.app'))->get(route('dashboard'))
            ->assertOk()->assertDontSee('Reports & PDF Builder', false);
        // Principal keeps its builder link (different label).
        $this->actingAs($this->userByEmail('principal@hackstra.app'))->get(route('dashboard'))
            ->assertOk()->assertSee('Reports & Analytics');
    }

    /* ---------------- Item 16: admin menu removals ---------------- */

    public function test_item16_admin_menu_removals()
    {
        $response = $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('dashboard'));
        $response->assertOk();
        // Render control: admin menu still has Settings + kept items.
        $response->assertSee('Settings')->assertSee('PTM Meetings')->assertSee('Substitutions');
        // Removed items must be gone (link text, plus route URLs for the two
        // whose link text could plausibly appear in dashboard content).
        $response->assertDontSee('Documents Vault');
        $response->assertDontSee('Alumni Directory');
        $response->assertDontSee('Elective Choices');
        $response->assertDontSee('Role Permissions');
        $response->assertDontSee('Parent Messages');
        $response->assertDontSee('Discipline Records');
        $response->assertDontSee(route('transport.index'));
        $response->assertDontSee(route('inventory.index'));
    }

    /* ---------------- Item 17: teacher menu removals ---------------- */

    public function test_item17_teacher_menu_removals()
    {
        $response = $this->actingAs($this->userByEmail('teacher@hackstra.app'))->get(route('dashboard'));
        $response->assertOk();
        // Render controls: kept teacher items still present.
        $response->assertSee('Parent Messages')->assertSee('Student Leave Requests')->assertSee('My Leave');
        // Removed links must be gone.
        $response->assertDontSee('Question Bank');
        $response->assertDontSee('PTM Meetings');
    }

    /* ---------------- Item 18: PTM admin-only ---------------- */

    public function test_item18_teacher_scheduling_refused_admin_allowed()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $admin = $this->userByEmail('admin@hackstra.app');
        $slot = [
            'date' => now()->addDays(30)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '10:30',
            'teacher_id' => $teacher->id,
            'my_class_id' => 1,
            'section_id' => 1,
        ];

        $before = PtmSlot::count();
        $this->actingAs($teacher)->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($teacher)->post(route('ptm.store'), $slot)->assertForbidden();
        // Count-based: ptm_slots.date is stored as datetime, so a
        // date-string assertDatabaseHas would false-fail on format.
        $this->assertSame($before, PtmSlot::count());

        $this->actingAs($admin)->get(route('ptm.create'))->assertOk();
        $this->actingAs($admin)->post(route('ptm.store'), $slot)->assertRedirect(route('ptm.index'));
        $this->assertSame($before + 1, PtmSlot::count());
        $created = PtmSlot::orderByDesc('id')->firstOrFail();
        $this->assertSame($teacher->id, (int) $created->teacher_id);
        $this->assertSame(1, (int) $created->my_class_id);
        $this->assertStringStartsWith($slot['date'], (string) $created->date);
    }

    /* ---------------- Item 19: decisions gate ---------------- */

    protected function pendingStudentLeave(): LeaveRequest
    {
        $student = $this->userByEmail('student@hackstra.app');
        return LeaveRequest::create([
            'user_id' => $student->id,
            'start_date' => now()->addDays(10)->format('Y-m-d'),
            'end_date' => now()->addDays(11)->format('Y-m-d'),
            'reason' => 'Audit probe leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
    }

    public function test_item19_admin_manual_decide_and_emergency_auto_approve()
    {
        $leave = $this->pendingStudentLeave();
        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->post(route('leave.decide', $leave->id), ['status' => 'approved'])
            ->assertRedirect();
        $this->assertSame('approved', $leave->fresh()->status);

        // Emergency: auto-approved at submit, no human decider.
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->post(route('leave.store'), [
                'start_date' => now()->addDays(12)->format('Y-m-d'),
                'end_date' => now()->addDays(12)->format('Y-m-d'),
                'reason' => 'Audit probe emergency',
                'leave_type' => 'emergency',
            ])->assertRedirect();
        $auto = LeaveRequest::where('user_id', $this->userByEmail('teacher@hackstra.app')->id)
            ->where('reason', 'Audit probe emergency')->latest('id')->firstOrFail();
        $this->assertSame('approved', $auto->status);
        $this->assertSame('Auto-approved (emergency).', $auto->decision_note);
    }

    public function test_item19_teacher_cannot_decide_staff_leave()
    {
        $otherTeacher = $this->userByEmail('teacher1@teacher.com');
        $leave = LeaveRequest::create([
            'user_id' => $otherTeacher->id,
            'start_date' => now()->addDays(13)->format('Y-m-d'),
            'end_date' => now()->addDays(13)->format('Y-m-d'),
            'reason' => 'Audit probe staff leave',
            'leave_type' => 'normal',
            'status' => 'pending',
        ]);
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->post(route('leave.decide', $leave->id), ['status' => 'approved'])
            ->assertRedirect(route('dashboard'));
        $this->assertSame('pending', $leave->fresh()->status);
    }

    /* ---------------- Item 20: hostel ---------------- */

    public function test_item20_admin_hostel_hidden_and_warden_path()
    {
        // Admin sidebar: no hostel links (menu half of item 20).
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('hostel.rooms.index'))
            ->assertDontSee(route('hostel.dorms.index'))
            ->assertDontSee(route('hostel.allocations.index'));

        // Admin direct route access: closed if item 20 is more than menu-only.
        $adminRooms = $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->get(route('hostel.rooms.index'));
        $this->assertNotEquals(200, $adminRooms->status(),
            'Admin still reaches hostel.rooms.index directly (teamSA route gate).');
    }

    public function test_item20_warden_can_open_hostel_pages()
    {
        $warden = User::create([
            'name' => 'Audit Warden',
            'email' => 'warden-probe@hackstra.app',
            'code' => 'WARDEN-PROBE',
            'user_type' => 'hostel',
            'password' => bcrypt('secret123'),
        ]);
        $this->actingAs($warden)->get(route('hostel.rooms.index'))->assertOk();
        $this->actingAs($warden)->get(route('hostel.dorms.index'))->assertOk();
        $this->actingAs($warden)->get(route('hostel.allocations.index'))->assertOk();
        $this->actingAs($warden)->get(route('dashboard'))->assertOk()->assertSee('Dorms');
    }

    /* ---------------- Item 22: date-sheet approval gates ---------------- */

    protected function sheetFixture(): array
    {
        $this->ensureSheetStatesTable();
        $student = $this->userByEmail('student@hackstra.app');
        $parent = $this->userByEmail('parent@hackstra.app');
        $sr = StudentRecord::where('user_id', $student->id)->orderByDesc('id')->firstOrFail();
        $classId = (int) $sr->my_class_id;
        $subject = Subject::where('my_class_id', $classId)->orderBy('id')->firstOrFail();
        $exam = Exam::create([
            'name' => 'Audit Probe Exam',
            'term' => 'Term 1',
            'year' => Qs::getCurrentSession(),
            'my_class_id' => 0,
        ]);
        $date = now()->addDays(60)->format('Y-m-d');
        ExamDate::create([
            'exam_id' => $exam->id,
            'my_class_id' => $classId,
            'subject_id' => $subject->id,
            'exam_date' => $date,
            'start_time' => '09:00:00',
            'note' => null,
        ]);
        return [
            'exam' => $exam,
            'class_id' => $classId,
            'parent' => $parent,
            'student' => $student,
            'date_label' => Carbon::parse($date)->format('D, d M Y'),
            'time_label' => '09:00 AM',
        ];
    }

    public function test_item22_manage_refused_for_families_and_teachers()
    {
        foreach (['student@hackstra.app', 'parent@hackstra.app', 'teacher@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))->get(route('exam_dates.manage'))
                ->assertRedirect(route('login'));
        }
        $this->actingAs($this->userByEmail('admin@hackstra.app'))->get(route('exam_dates.manage'))->assertOk();
    }

    public function test_item22_unpublished_sheet_hidden_from_student_parent_teacher()
    {
        $f = $this->sheetFixture();
        // Fixture sanity: admin preview shows the date.
        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->get(route('exam_dates.index', ['exam_id' => $f['exam']->id, 'class_id' => $f['class_id']]))
            ->assertOk()->assertSee($f['date_label']);

        // No state row = draft: families + teacher must NOT see the date.
        $this->actingAs($f['student'])
            ->get(route('exam_dates.index', ['exam_id' => $f['exam']->id]))
            ->assertOk()->assertDontSee($f['date_label'])->assertSee('Dates not announced yet');
        $childId = StudentRecord::where('my_parent_id', $f['parent']->id)->orderBy('user_id')->value('user_id');
        $this->actingAs($f['parent'])
            ->get(route('exam_dates.index', ['exam_id' => $f['exam']->id, 'student_id' => $childId]))
            ->assertOk()->assertDontSee($f['date_label']);
        $this->actingAs($this->userByEmail('teacher@hackstra.app'))
            ->get(route('exam_dates.index', ['exam_id' => $f['exam']->id, 'class_id' => $f['class_id']]))
            ->assertOk()->assertDontSee($f['date_label']);

        // Print + PDF of an unpublished sheet are already gated (controls).
        $this->actingAs($f['student'])
            ->get(route('exam_dates.print', ['exam_id' => $f['exam']->id]))
            ->assertRedirect(route('exam_dates.index'));
        $this->actingAs($f['student'])
            ->get(route('exam_dates.pdf', ['exam_id' => $f['exam']->id]))
            ->assertRedirect(route('exam_dates.index'));
    }

    public function test_item22_submit_approve_publishes_sheet_to_families()
    {
        $f = $this->sheetFixture();
        $admin = $this->userByEmail('admin@hackstra.app');
        $principal = $this->userByEmail('principal@hackstra.app');

        // Admin submits; admin cannot approve (principal-only queue).
        $this->actingAs($admin)->post(route('exam_dates.submit'), [
            'exam_id' => $f['exam']->id, 'class_id' => $f['class_id'],
        ])->assertRedirect();
        $this->assertSame(ExamDateSheetState::SUBMITTED,
            ExamDateSheetState::statusFor($f['exam']->id, $f['class_id']));
        $this->actingAs($admin)->post(route('exam_dates.approve'), [
            'exam_id' => $f['exam']->id, 'class_id' => $f['class_id'],
        ])->assertRedirect(route('dashboard'));
        $this->assertSame(ExamDateSheetState::SUBMITTED,
            ExamDateSheetState::statusFor($f['exam']->id, $f['class_id']));

        // Principal queue + approve = published.
        $this->actingAs($principal)->get(route('exam_dates.approvals'))
            ->assertOk()->assertSee('Audit Probe Exam');
        $this->actingAs($principal)->post(route('exam_dates.approve'), [
            'exam_id' => $f['exam']->id, 'class_id' => $f['class_id'],
        ])->assertRedirect();
        $this->assertSame(ExamDateSheetState::PUBLISHED,
            ExamDateSheetState::statusFor($f['exam']->id, $f['class_id']));

        // Published sheet is visible to the student, with its date.
        $this->actingAs($f['student'])
            ->get(route('exam_dates.index', ['exam_id' => $f['exam']->id]))
            ->assertOk()->assertSee($f['date_label'])->assertSee($f['time_label']);
    }
}
