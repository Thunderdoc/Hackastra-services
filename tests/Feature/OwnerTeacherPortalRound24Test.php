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
 *   teacher — one English override in each of classes 2/5/8/11/13
 *   (sections 4/19/34/49/64) since the section segment
 *   (2026-10-30), no direct subjects. Her combos below are
 *   resolved from that live map.
 * - Ananya Sharma (teacher@hackstra.app, id 4): direct teacher of
 *   Class 12 English (79) and Prep English (1); LEADS Class 7
 *   (my_class_id 8) sections 31–36 but teaches NO my_class_id 8
 *   subject — she holds an override only in section 31, so
 *   section 32 is the clean led-only anchor.
 * - Probe period: a Monday cell of Class 12 section 76 /
 *   English (79), effective teacher Ananya via her (76, 79)
 *   override, zero seeded sessions, roster 40 (resolved by
 *   period() — the pre-segment seeded id tt 788 is stale).
 * - Past Monday 2026-10-19 (no session for the probe cell),
 *   Tuesday 2026-10-20 (wrong weekday), future Monday computed
 *   from today.
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

    protected $probeTtId;

    /** Resolve (once per test) the probe cell id WITHOUT preconditions. */
    protected function resolveProbeTtId(): int
    {
        if ($this->probeTtId === null) {
            // Section segment (2026-10-30): the old seeded cell id
            // (tt 788) now points at an unrelated period. The probe
            // cell is resolved by its anchors instead: a Monday cell
            // of Class 12 section 76 / English (79), whose effective
            // teacher is Ananya via her (76, 79) override, roster 40.
            $tt = TimeTable::where('day', 'Monday')
                ->where('subject_id', 79)
                ->whereHas('tt_record', function ($q) {
                    $q->where('section_id', 76);
                })
                ->orderBy('id')
                ->first();
            $this->assertNotNull($tt, 'Probe Monday cell (section 76, English) must exist');
            $this->probeTtId = (int) $tt->id;
        }

        return $this->probeTtId;
    }

    protected function period()
    {
        $tt = TimeTable::findOrFail($this->resolveProbeTtId());
        $this->assertSame('Monday', $tt->day, 'Probe cell must be a Monday period');
        $this->assertSame(
            $this->teacher()->id,
            \App\Support\SectionTeacher::teacherIdFor(76, \App\Models\Subject::findOrFail(79)),
            'Probe cell effective teacher must be Ananya'
        );
        $this->assertSame(0, AttendanceSession::whereDate('date', self::PAST_MONDAY)->where('time_table_id', $tt->id)->count(), 'Probe cell must have no session on the probe date');

        return $tt;
    }

    protected function ttId(): int
    {
        return $this->resolveProbeTtId();
    }

    protected function rosterIds(): array
    {
        $ids = StudentRecord::where('grad', 0)->where('my_class_id', 13)->where('section_id', 76)->orderBy('id')->pluck('id')->all();
        $this->assertSame(40, count($ids), 'Probe section roster must be exactly 40');

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
        return AttendanceSession::whereDate('date', $date)->where('time_table_id', $this->ttId())->count();
    }

    protected function entryCount(string $date = self::PAST_MONDAY): int
    {
        return DB::table('attendance_session_entries')->whereIn('session_id', function ($q) use ($date) {
            $q->select('id')->from('attendance_sessions')->whereDate('date', $date)->where('time_table_id', $this->ttId());
        })->count();
    }

    // ---------- MARKS ENTRY: own subject works ----------

    /** One of Ritu's live override combos: [exam, class, section, subject]. */
    protected function rituCombo(): array
    {
        $row = DB::table('section_subject_teachers')
            ->where('teacher_id', $this->ritu()->id)
            ->orderBy('section_id')->orderBy('subject_id')->first();
        $this->assertNotNull($row, 'Ritu must hold an override in the seed');
        $section = \App\Models\Section::findOrFail($row->section_id);
        return [1, (int) $section->my_class_id, (int) $section->id, (int) $row->subject_id];
    }

    /** A combo in Ritu's own override class+section for a subject she does NOT teach. */
    protected function rituForeignCombo(): array
    {
        [, $classId, $sectionId, $ownSubjectId] = $this->rituCombo();
        $subject = \App\Models\Subject::where('my_class_id', $classId)
            ->where('id', '!=', $ownSubjectId)
            ->whereExists(function ($q) use ($classId, $sectionId) {
                $q->select(DB::raw(1))->from('marks')
                    ->where('marks.subject_id', DB::raw('subjects.id'))
                    ->where('marks.exam_id', 1)
                    ->where('marks.my_class_id', $classId)
                    ->where('marks.section_id', $sectionId);
            })
            ->orderBy('id')->first();
        $this->assertNotNull($subject, 'A foreign subject with marks must exist in her section');
        $this->assertNotSame(
            (int) $this->ritu()->id,
            \App\Support\SectionTeacher::teacherIdFor($sectionId, $subject),
            'Foreign subject must not resolve to Ritu'
        );
        return [1, $classId, $sectionId, (int) $subject->id];
    }

    public function test_ritu_own_override_subject_manage_and_update_work()
    {
        $combo = $this->rituCombo();
        $this->actingAs($this->ritu())->get(route('marks.manage', $combo))->assertOk();

        [$payload, $markId, $key, $new, $comp] = $this->payloadWithOneChange($combo);
        $this->actingAs($this->ritu())->put(route('marks.update', $combo), $payload)->assertOk();
        $this->assertSame($new, (int) DB::table('mark_components')->where('mark_id', $markId)->where('component_id', $comp['id'])->value('score'), 'Own-subject write must land');
    }

    // ---------- MARKS ENTRY: foreign subject / led-only refused ----------

    public function test_ritu_foreign_subject_in_own_class_manage_is_forbidden()
    {
        // A subject in her own override section that she does not
        // teach: class scope alone passes, the subject gate refuses.
        $this->actingAs($this->ritu())->get(route('marks.manage', $this->rituForeignCombo()))->assertForbidden();
    }

    public function test_ritu_foreign_subject_in_own_class_update_is_forbidden_and_writes_nothing()
    {
        $combo = $this->rituForeignCombo();
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
        // Ananya LEADS my_class_id 8 sections 31–36 but teaches no
        // subject of that class. Since the section segment she
        // holds an override in section 31 only — section 32 stays
        // led-only: she leads it, yet the subject gate refuses her.
        $this->actingAs($this->teacher())->get(route('marks.manage', [1, 8, 32, 43]))->assertForbidden();
    }

    public function test_ananya_led_only_class_update_is_forbidden_and_writes_nothing()
    {
        $combo = [1, 8, 32, 43];
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
        // Ananya effectively teaches section 76 English (override
        // 76/79); section 68 English belongs to override teacher 63
        // and is correctly refused to her since round 24's gate.
        $combo = [1, 13, 76, 79];
        $this->actingAs($this->teacher())->put(route('marks.update', $combo), $this->payloadFor($combo))->assertOk();
    }

    // ---------- ATTENDANCE: own period works ----------

    public function test_ananya_own_period_take_and_store_work()
    {
        $this->period();
        $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]))->assertOk();

        $response = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY));
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
        $take = $this->actingAs($this->admin())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]));
        $take->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $take->assertSessionHas('flash_danger', 'Period attendance is submitted by the subject teacher — this view is read-only for administrators.');

        $store = $this->actingAs($this->admin())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY));
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
            $this->actingAs($user)->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]))->assertRedirect(route('login'));
            $this->actingAs($user)->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY))->assertRedirect(route('login'));
            $this->assertSame(0, $this->sessionCount(), 'A refused submit by '.$user->email.' must write no session');
            $this->assertSame(0, $this->entryCount(), 'A refused submit by '.$user->email.' must write no entries');
        }
    }

    public function test_attendance_take_and_store_refused_for_super_admin()
    {
        $this->period();
        $this->actingAs($this->superAdmin())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]))->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY))->assertForbidden();
        $this->assertSame(0, $this->sessionCount());
    }

    // ---------- ATTENDANCE: foreign teacher refused; named substitute allowed (owner order 2026-10-29) ----------

    public function test_attendance_foreign_teacher_take_and_store_are_forbidden()
    {
        $this->period();
        $this->actingAs($this->ritu())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]))->assertForbidden();
        $this->actingAs($this->ritu())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY))->assertForbidden();
        $this->assertSame(0, $this->sessionCount(), 'A refused take/store must write no session');
        $this->assertSame(0, $this->entryCount(), 'A refused take/store must write no entries');
    }

    public function test_attendance_substitute_take_and_store_are_allowed_under_2026_10_29_order()
    {
        $this->period();
        Substitution::create([
            'time_table_id' => $this->ttId(),
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
        $this->actingAs($this->ritu())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]))->assertOk();
        $this->actingAs($this->ritu())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY))->assertRedirect();
        $this->assertSame(1, $this->sessionCount());
        $this->assertGreaterThan(0, $this->entryCount());
    }

    // ---------- ATTENDANCE: date + roster violations ----------

    public function test_attendance_wrong_weekday_is_refused()
    {
        $this->period();
        $take = $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::TUESDAY]));
        $take->assertSessionHasErrors('date');
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::TUESDAY));
        $store->assertSessionHasErrors('date');
        $this->assertSame(0, $this->sessionCount(self::TUESDAY));
    }

    public function test_attendance_future_date_is_refused()
    {
        $this->period();
        $futureMonday = Carbon::today()->next(Carbon::MONDAY)->format('Y-m-d');
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload($futureMonday));
        $store->assertSessionHasErrors('date');
        $this->assertSame(0, $this->sessionCount($futureMonday));
    }

    public function test_attendance_roster_omission_is_refused()
    {
        $this->period();
        $ids = $this->rosterIds();
        array_pop($ids);
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY, $this->statuses($ids)));
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
        $store = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY, $statuses));
        $store->assertSessionHasErrors('statuses');
        $this->assertSame(0, $this->sessionCount());
        $this->assertSame(0, $this->entryCount());
    }

    // ---------- ATTENDANCE: submitted register is locked ----------

    public function test_attendance_resubmit_is_locked_and_teacher_view_back_is_forbidden()
    {
        $this->period();
        $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY))->assertRedirect();
        $this->assertSame(1, $this->sessionCount());

        $again = $this->actingAs($this->teacher())->post(route('attendance.periods.store', $this->ttId()), $this->storePayload(self::PAST_MONDAY));
        $again->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $again->assertSessionHas('flash_danger', 'Attendance for this period was already submitted and is locked — it cannot be taken again.');
        $this->assertSame(1, $this->sessionCount(), 'A locked re-submit must not duplicate the session');

        $take = $this->actingAs($this->teacher())->get(route('attendance.periods.take', ['tt_id' => $this->ttId(), 'date' => self::PAST_MONDAY]));
        $take->assertRedirect(route('attendance.periods.index', ['date' => self::PAST_MONDAY]));
        $take->assertSessionHas('flash_success', 'Attendance for this period was already submitted.');

        $session = AttendanceSession::whereDate('date', self::PAST_MONDAY)->where('time_table_id', $this->ttId())->firstOrFail();
        $this->actingAs($this->teacher())->get(route('attendance.periods.show', $session->id))->assertForbidden();
        $this->actingAs($this->admin())->get(route('attendance.periods.show', $session->id))->assertOk();
    }
}
