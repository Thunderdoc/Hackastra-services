<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\MarkScheme;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent C round 24 — TEACHER PORTAL DEPTH (marks entry + period
 * attendance take/store role gates). Tabulation was locked in
 * round 23; this is the entry/store side, which had never been
 * probed at the role level.
 *
 * Gates read first (routes/web.php + controller guards):
 * - marks.manage / marks.update / marks.selector live in the
 *   teamSATWrite group (admin + teacher at the route gate);
 *   MarkController + MarkComponentUpdate::authorize() then gate
 *   teachers by TeacherScope::canTeachClass — CLASS-level only.
 * - attendance.periods take/store carry NO team middleware;
 *   PeriodAttendanceController::guardCanTake() is the whole gate:
 *   non-teachers get a redirect to periods.index with a
 *   read-only flash (admin included — oversight only, admin does
 *   NOT submit registers); a teacher whose effective teacher id
 *   (SectionTeacher::teacherIdFor, override-aware) is not the
 *   caller gets 403. Date must match the period weekday, future
 *   dates are refused, submitted ids must equal the roster.
 * - There is NO results_published guard on marks entry (Wave 32
 *   design: entry stays enabled) — locked as behaviour, not
 *   invented as a guard.
 *
 * Fixture anchors (seeded, resolved by email / ids, never
 * hardcoded student ids):
 * - Ritu Sharma (teacher20@teacher.com, id 44): override-only
 *   teacher — Class 11-A (section 55) English (67) + Class 12-B
 *   (section 65) English (79) via overrides, no direct subjects.
 * - Ananya Sharma (teacher@hackstra.app, id 4): direct teacher of
 *   Class 12 English (79) and Prep English (1); LEADS Class 8
 *   sections 31–36 but teaches NO Class 8 subject and holds NO
 *   Class 8 override — the clean led-only anchor.
 * - Period tt 788: Monday, Class 12-E (section 68), English (79),
 *   effective teacher Ananya, zero seeded sessions, roster 40.
 * - Past Monday 2026-10-19 (no session for tt 788), Tuesday
 *   2026-10-20 (wrong weekday), future Monday computed from today.
 */
class OwnerTeacherPortalRound24Test extends TestCase
{
    use DatabaseTransactions;

    private const PAST_MONDAY = '2026-10-19';

    private const TUESDAY = '2026-10-20';

    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        $user = User::where('email', 'principal@hackstra.app')->firstOrFail();
        if ($user->user_type !== 'principal') {
            $user->user_type = 'principal';
            $user->save();
        }

        return $user;
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    /** Non-entry roles: every role that must never reach marks entry. */
    protected function nonEntryRoles(): array
    {
        return [$this->student(), $this->parent(), $this->accountant(), $this->librarian(), $this->principal()];
    }

    protected function comboRow(array $combo): array
    {
        return ['exam_id' => $combo[0], 'my_class_id' => $combo[1], 'section_id' => $combo[2], 'subject_id' => $combo[3]];
    }

    /** Current scores as the entry grid renders them (configured or legacy). */
    protected function payloadFor(array $combo): array
    {
        [$exam, $class, $section, $subject] = $combo;
        $data = [];
        foreach (Mark::where($this->comboRow($combo))->get() as $mk) {
            foreach (MarkScheme::components($exam, $subject) as $comp) {
                $v = MarkScheme::scoreFor($mk, $comp);
                $key = $comp['field'] ? $comp['field'].'_'.$mk->id : 'comp_'.$mk->id.'_'.$comp['id'];
                $data[$key] = $v === null ? '' : (string) $v;
            }
        }

        return $data;
    }

    /** Payload with exactly one cell changed within its max, plus its DB locator. */
    protected function payloadWithOneChange(array $combo): array
    {
        [$exam, $class, $section, $subject] = $combo;
        $data = $this->payloadFor($combo);
        $marks = Mark::where($this->comboRow($combo))->get();
        $this->assertNotEmpty($marks, 'Combo must have mark rows for a write test');
        $first = $marks->first();
        $comp = MarkScheme::components($exam, $subject)[0];
        $key = $comp['field'] ? $comp['field'].'_'.$first->id : 'comp_'.$first->id.'_'.$comp['id'];
        $current = $data[$key] === '' ? null : (int) $data[$key];
        $max = (int) $comp['max_marks'];
        $new = $current === null ? 10 : ($current < $max ? $current + 1 : $current - 1);
        $this->assertTrue($new >= 0 && $new <= $max, 'Changed value must stay within the component max');
        $data[$key] = (string) $new;

        return [$data, $first->id, $key, $new, $comp];
    }

    protected function period()
    {
        $tt = TimeTable::findOrFail(788);
        $this->assertSame('Monday', $tt->day, 'tt 788 must be a Monday period');
        $this->assertSame(0, AttendanceSession::whereDate('date', self::PAST_MONDAY)->where('time_table_id', 788)->count(), 'tt 788 must have no session on the probe date');

        return $tt;
    }

    protected function rosterIds(): array
    {
        $ids = StudentRecord::where('grad', 0)->where('my_class_id', 13)->where('section_id', 68)->orderBy('id')->pluck('id')->all();
        $this->assertSame(40, count($ids), 'Class 12-E roster must be exactly 40');

        return $ids;
    }

    protected function statuses(array $ids = null): array
    {
        $statuses = [];
        foreach ($ids ?? $this->rosterIds() as $id) {
            $statuses[$id] = 'present';
        }

        return $statuses;
    }

    protected function storePayload(string $date, array $statuses = null): array
    {
        return ['topic' => 'English revision', 'date' => $date, 'statuses' => $statuses ?? $this->statuses()];
    }

    protected function sessionCount(string $date = self::PAST_MONDAY): int
    {
        return AttendanceSession::whereDate('date', $date)->where('time_table_id', 788)->count();
    }

    protected function entryCount(string $date = self::PAST_MONDAY): int
    {
        return DB::table('attendance_session_entries')->whereIn('session_id', function ($q) use ($date) {
            $q->select('id')->from('attendance_sessions')->whereDate('date', $date)->where('time_table_id', 788);
        })->count();
    }

    // ---------- MARKS ENTRY: own subject works ----------

    public function test_ritu_own_override_subject_manage_and_update_work()
    {
        $combo = [1, 12, 55, 67];
        $this->actingAs($this->ritu())->get(route('marks.manage', $combo))->assertOk();

        [$payload, $markId, $key, $new, $comp] = $this->payloadWithOneChange($combo);
        $this->actingAs($this->ritu())->put(route('marks.update', $combo), $payload)->assertOk();
        $this->assertSame($new, (int) DB::table('mark_components')->where('mark_id', $markId)->where('component_id', $comp['id'])->value('score'), 'Own-subject write must land');
    }

    // ---------- MARKS ENTRY: foreign subject / led-only refused ----------

    public function test_ritu_foreign_subject_in_own_class_manage_is_forbidden()
    {
        // Class 11 Maths (69) is taught by teacher 24; Ritu's Class 11
        // override covers English (67) only. Class scope alone passes.
        $this->actingAs($this->ritu())->get(route('marks.manage', [1, 12, 55, 69]))->assertForbidden();
    }

    public function test_ritu_foreign_subject_in_own_class_update_is_forbidden_and_writes_nothing()
    {
        $combo = [1, 12, 55, 69];
        $first = Mark::where($this->comboRow($combo))->orderBy('id')->firstOrFail();
        $before = $first->t1;
        $this->actingAs($this->ritu())->put(route('marks.update', $combo), $this->payloadFor($combo))->assertForbidden();
        $this->assertSame($before, Mark::find($first->id)->t1, 'A refused save must not change a cell');
    }

    public function test_ritu_foreign_subject_selector_is_forbidden_and_creates_nothing()
    {
        // Class 12-B Maths (81) has ZERO mark rows for (1, 13, 65, 81):
        // a selector success would CREATE 40 rows — the write itself.
        $combo = ['exam_id' => 1, 'my_class_id' => 13, 'section_id' => 65, 'subject_id' => 81];
        $this->assertSame(0, Mark::where($combo)->count(), 'Selector probe must start with zero rows');
        $this->actingAs($this->ritu())->post(route('marks.selector'), $combo)->assertForbidden();
        $this->assertSame(0, Mark::where($combo)->count(), 'A refused selector must create no mark rows');
    }

    public function test_ananya_led_only_class_manage_is_forbidden()
    {
        // Ananya LEADS Class 8 (sections 31–36) but teaches no Class 8
        // subject and holds no Class 8 override. Class scope passes.
        $this->actingAs($this->teacher())->get(route('marks.manage', [1, 8, 31, 43]))->assertForbidden();
    }

    public function test_ananya_led_only_class_update_is_forbidden_and_writes_nothing()
    {
        $combo = [1, 8, 31, 43];
        $first = Mark::where($this->comboRow($combo))->orderBy('id')->firstOrFail();
        $before = $first->t1;
        $this->actingAs($this->teacher())->put(route('marks.update', $combo), $this->payloadFor($combo))->assertForbidden();
        $this->assertSame($before, Mark::find($first->id)->t1, 'A refused save must not change a cell');
    }

    public function test_ritu_foreign_class_manage_and_update_are_forbidden()
    {
        $this->actingAs($this->ritu())->get(route('marks.manage', [1, 1, 1, 1]))->assertForbidden();
        $this->actingAs($this->ritu())->put(route('marks.update', [1, 1, 1, 1]), [])->assertForbidden();
    }

    // ---------- MARKS ENTRY: roles + published exam ----------

    public function test_marks_manage_refused_for_non_entry_roles_and_super_admin()
    {
        foreach ($this->nonEntryRoles() as $user) {
            $this->actingAs($user)->get(route('marks.manage', [1, 12, 55, 67]))->assertRedirect(route('login'));
        }
        // PlatformOnly: SA GETs on school routes bounce to /dashboard.
        $this->actingAs($this->superAdmin())->get(route('marks.manage', [1, 12, 55, 67]))->assertRedirect(route('dashboard'));
    }

    public function test_marks_update_refused_for_non_entry_roles_and_super_admin()
    {
        foreach ($this->nonEntryRoles() as $user) {
            $this->actingAs($user)->put(route('marks.update', [1, 12, 55, 67]), [])->assertRedirect(route('login'));
        }
        $this->actingAs($this->superAdmin())->put(route('marks.update', [1, 12, 55, 67]), [])->assertForbidden();
    }

    public function test_published_exam_does_not_block_marks_entry()
    {
        // No results_published guard exists on entry (Wave 32 design:
        // entry stays enabled). Publishing must not change the save.
        $exam = Exam::findOrFail(1);
        $exam->results_published = true;
        $exam->save();
        // Ananya effectively teaches section 68 English (override
        // 68/79); section 66 English belongs to override teacher 45
        // and is correctly refused to her since round 24's gate.
        $combo = [1, 13, 68, 79];
        $this->actingAs($this->teacher())->put(route('marks.update', $combo), $this->payloadFor($combo))->assertOk();
    }

    // ---------- ATTENDANCE: own period works ----------

    public function test_ananya_own_period_take_and_store_work()
    {
        $this->period();
        $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]))->assertOk();

        $response = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY));
        $response->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $response->assertSessionHas('flash_success');
        $this->assertSame(1, $this->sessionCount(), 'Own-period submit must create exactly one session');
        $this->assertSame(40, $this->entryCount(), 'Submit must write exactly the 40 roster entries');
    }

    // ---------- ATTENDANCE: non-teachers are read-only ----------

    public function test_attendance_admin_take_and_store_redirect_read_only_with_no_rows()
    {
        // Admin passes the teamSAT constructor middleware, so the
        // controller guard is the refusal: redirect to the index
        // with the read-only flash. Admin never submits registers.
        $this->period();
        $take = $this->actingAs($this->admin())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]));
        $take->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $take->assertSessionHas('flash_danger', 'Period attendance is submitted by the subject teacher — this view is read-only for administrators.');

        $store = $this->actingAs($this->admin())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY));
        $store->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $store->assertSessionHas('flash_danger', 'Period attendance is submitted by the subject teacher — this view is read-only for administrators.');
        $this->assertSame(0, $this->sessionCount(), 'An admin submit must write no session');
        $this->assertSame(0, $this->entryCount(), 'An admin submit must write no entries');
    }

    public function test_attendance_take_and_store_bounce_non_staff_roles_to_login()
    {
        // Test-craft correction (not an app bug): take/store carry
        // the teamSAT constructor middleware, so principal, student,
        // parent, accountant and librarian bounce to LOGIN before
        // ever reaching guardCanTake — only admin reaches the guard.
        $this->period();
        foreach ([$this->principal(), $this->student(), $this->parent(), $this->accountant(), $this->librarian()] as $user) {
            $this->actingAs($user)->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]))->assertRedirect(route('login'));
            $this->actingAs($user)->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY))->assertRedirect(route('login'));
            $this->assertSame(0, $this->sessionCount(), 'A refused submit by '.$user->email.' must write no session');
            $this->assertSame(0, $this->entryCount(), 'A refused submit by '.$user->email.' must write no entries');
        }
    }

    public function test_attendance_take_and_store_refused_for_super_admin()
    {
        $this->period();
        $this->actingAs($this->superAdmin())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]))->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY))->assertForbidden();
        $this->assertSame(0, $this->sessionCount());
    }

    // ---------- ATTENDANCE: foreign teacher refused; named substitute allowed (owner order 2026-10-29) ----------

    public function test_attendance_foreign_teacher_take_and_store_are_forbidden()
    {
        $this->period();
        $this->actingAs($this->ritu())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]))->assertForbidden();
        $this->actingAs($this->ritu())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY))->assertForbidden();
        $this->assertSame(0, $this->sessionCount(), 'A refused take/store must write no session');
        $this->assertSame(0, $this->entryCount(), 'A refused take/store must write no entries');
    }

    public function test_attendance_substitute_take_and_store_are_allowed_under_2026_10_29_order()
    {
        $this->period();
        Substitution::create([
            'time_table_id' => 788,
            'date' => self::PAST_MONDAY,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $this->ritu()->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);
        // SUPERSEDED PREMISE (owner order 2026-10-29): naming Ritu
        // as substitute for (period, date) DOES open the register
        // to her for that window — guardCanTake honours the
        // Substitution row alongside teacherIdFor. The no-row case
        // in the neighbouring test above stays forbidden.
        $this->actingAs($this->ritu())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]))->assertOk();
        $this->actingAs($this->ritu())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY))->assertRedirect();
        $this->assertSame(1, $this->sessionCount());
        $this->assertGreaterThan(0, $this->entryCount());
    }

    // ---------- ATTENDANCE: date + roster violations ----------

    public function test_attendance_wrong_weekday_is_refused()
    {
        $this->period();
        $take = $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::TUESDAY]));
        $take->assertSessionHasErrors('date');
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::TUESDAY));
        $store->assertSessionHasErrors('date');
        $this->assertSame(0, $this->sessionCount(self::TUESDAY));
    }

    public function test_attendance_future_date_is_refused()
    {
        $this->period();
        $futureMonday = Carbon::today()->next(Carbon::MONDAY)->format('Y-m-d');
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload($futureMonday));
        $store->assertSessionHasErrors('date');
        $this->assertSame(0, $this->sessionCount($futureMonday));
    }

    public function test_attendance_roster_omission_is_refused()
    {
        $this->period();
        $ids = $this->rosterIds();
        array_pop($ids);
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY, $this->statuses($ids)));
        $store->assertSessionHasErrors('statuses');
        $this->assertSame(0, $this->sessionCount());
        $this->assertSame(0, $this->entryCount());
    }

    public function test_attendance_foreign_roster_id_is_refused()
    {
        $this->period();
        $foreign = StudentRecord::where('grad', 0)->where('my_class_id', 13)->where('section_id', 65)->orderBy('id')->firstOrFail();
        $statuses = $this->statuses();
        $statuses[$foreign->id] = 'present';
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY, $statuses));
        $store->assertSessionHasErrors('statuses');
        $this->assertSame(0, $this->sessionCount());
        $this->assertSame(0, $this->entryCount());
    }

    // ---------- ATTENDANCE: submitted register is locked ----------

    public function test_attendance_resubmit_is_locked_and_teacher_view_back_is_forbidden()
    {
        $this->period();
        $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY))->assertRedirect();
        $this->assertSame(1, $this->sessionCount());

        $again = $this->actingAs($this->teacher())->post(route('attendance.periods.store', 788), $this->storePayload(self::PAST_MONDAY));
        $again->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $again->assertSessionHas('flash_danger', 'Attendance for this period was already submitted and is locked — it cannot be taken again.');
        $this->assertSame(1, $this->sessionCount(), 'A locked re-submit must not duplicate the session');

        $take = $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => 788, 'date' => self::PAST_MONDAY]));
        $take->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $take->assertSessionHas('flash_success', 'Attendance for this period was already submitted.');

        $session = AttendanceSession::whereDate('date', self::PAST_MONDAY)->where('time_table_id', 788)->firstOrFail();
        $this->actingAs($this->teacher())->get(route('attendance.periods.show', $session->id))->assertForbidden();
        $this->actingAs($this->admin())->get(route('attendance.periods.show', $session->id))->assertOk();
    }
}
