<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\Circular;
use App\Models\Exam;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Models\UserPreference;
use App\Models\WhatsAppMessage;
use App\Support\Notify;
use App\Support\ParentWhatsApp;
use App\Support\WhatsApp\WhatsAppProvider;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1 (Round 4) — Automated WhatsApp Parent Messaging, MOCK mode.
 * Covers design §12 groups 2–17: triggers + dedupe, fee cooldown,
 * results population, the three circular publish paths,
 * assignment scoping, recipient edges, the opt-out matrix,
 * settings, provider/mock transport, the sent-log UI and the
 * webhook. Everything runs with zero Meta credentials (mock +
 * Http::fake) inside DatabaseTransactions.
 */
class OwnerWhatsAppF1Test extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    /* ---------------- helpers ---------------- */

    protected function userByEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin() { return $this->userByEmail('admin@hackstra.app'); }
    protected function principal() { return $this->userByEmail('principal@hackstra.app'); }
    protected function superAdmin() { return $this->userByEmail('superadmin@hackstra.app'); }
    protected function teacher() { return $this->userByEmail('teacher@hackstra.app'); }
    protected function accountant() { return $this->userByEmail('accountant@hackstra.app'); }

    protected function setSetting($type, $value)
    {
        if ($value === null) {
            Setting::where('type', $type)->delete();
            return;
        }
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    protected function getSetting($type)
    {
        $s = Setting::where('type', $type)->first();

        return $s ? $s->description : null;
    }

    protected function enableWhatsApp(array $overrides = [])
    {
        $values = array_merge([
            'whatsapp_enabled' => '1',
            'whatsapp_absent_enabled' => '1',
            'whatsapp_fee_enabled' => '1',
            'whatsapp_result_enabled' => '1',
            'whatsapp_circular_enabled' => '1',
            'whatsapp_homework_enabled' => '1',
        ], $overrides);
        foreach ($values as $k => $v) {
            $this->setSetting($k, $v);
        }
        // Zero credentials unless a test sets them itself.
        foreach (['whatsapp_access_token', 'whatsapp_phone_number_id', 'whatsapp_business_id', 'whatsapp_app_secret', 'whatsapp_verify_token'] as $k) {
            if (!array_key_exists($k, $overrides)) {
                $this->setSetting($k, null);
            }
        }
    }

    protected function makeUser($type, $tag, $phone = null, $phone2 = null)
    {
        return User::create([
            'name' => 'F1 ' . ucfirst($tag),
            'email' => 'f1_' . $tag . '@example.test',
            'username' => 'f1_' . $tag,
            'password' => Hash::make('f1-pass'),
            'user_type' => $type,
            'code' => 'F1-' . strtoupper($tag),
            'phone' => $phone,
            'phone2' => $phone2,
        ]);
    }

    /** Fresh active student (+ optional linked parent). Returns [User, StudentRecord, ?User] */
    protected function makeStudent($tag, $parent = null, $classId = 1, $sectionId = 1, $grad = 0)
    {
        $student = $this->makeUser('student', $tag);
        $sr = StudentRecord::create([
            'user_id' => $student->id,
            'my_class_id' => $classId,
            'section_id' => $sectionId,
            'my_parent_id' => $parent ? $parent->id : null,
            'adm_no' => 'F1/' . strtoupper($tag),
            'session' => Qs::getCurrentSession(),
            'year_admitted' => '2026',
            'grad' => $grad,
        ]);

        return [$student, $sr, $parent];
    }

    protected function makeFee($studentUserId, $tag, $amount = 12000, $amtPaid = 0)
    {
        $payment = Payment::create([
            'title' => 'F1 Fee ' . $tag,
            'amount' => $amount,
            'my_class_id' => 1,
            'description' => 'F1 fixture',
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'F1-' . strtoupper($tag) . '-' . Str::random(6),
            'method' => 'Cash',
        ]);
        $record = PaymentRecord::create([
            'payment_id' => $payment->id,
            'student_id' => $studentUserId,
            'amt_paid' => $amtPaid,
            'balance' => $amtPaid >= $amount ? 0 : $amount - $amtPaid,
            'paid' => $amtPaid >= $amount ? 1 : 0,
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'F1R-' . strtoupper($tag) . '-' . Str::random(6),
        ]);

        return [$payment, $record];
    }

    protected function waFor($studentRecordId, $event = null)
    {
        $q = WhatsAppMessage::where('student_record_id', $studentRecordId);
        if ($event) {
            $q->where('event', $event);
        }

        return $q;
    }

    /* ---------------- §12.13 mock end-to-end ---------------- */

    public function test_zero_credentials_mock_end_to_end_no_http()
    {
        $this->enableWhatsApp();
        Http::fake();

        $parent = $this->makeUser('parent', 'mockp', '+91 98110 00011');
        [, $sr] = $this->makeStudent('mockc', $parent);

        ParentWhatsApp::absent($sr, null, '2026-11-02', 'English', $this->admin()->id);

        $row = $this->waFor($sr->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('mocked', $row->status);
        $this->assertStringStartsWith('wamid.MOCK', (string) $row->provider_message_id);
        $this->assertSame('919811000011', $row->recipient_phone);
        $this->assertSame('hackstra_attendance_absent', $row->template_name);
        Http::assertNothingSent();
    }

    public function test_disabled_master_queues_nothing()
    {
        $this->setSetting('whatsapp_enabled', '0');
        $parent = $this->makeUser('parent', 'offp', '9811000011');
        [, $sr] = $this->makeStudent('offc', $parent);

        ParentWhatsApp::absent($sr, null, '2026-11-02', 'English');

        $this->assertSame(0, $this->waFor($sr->id)->count());
    }

    /* ---------------- §12.2/3 attendance trigger ---------------- */

    /** The probe cell: Ananya's Monday Class-12 section-76 English period. */
    protected function probeCell()
    {
        return TimeTable::where('day', 'Monday')->where('subject_id', 79)
            ->whereHas('tt_record', function ($q) {
                $q->where('section_id', 76);
            })
            ->orderBy('id')->firstOrFail();
    }

    protected function freeMondayFor($ttId)
    {
        $cursor = Carbon::create(2026, 11, 2); // a Monday
        for ($i = 0; $i < 12; $i++) {
            $candidate = $cursor->copy()->subWeeks($i);
            if (!AttendanceSession::where('time_table_id', $ttId)->whereDate('date', $candidate->toDateString())->exists()) {
                return $candidate->toDateString();
            }
        }
        $this->fail('No free Monday for the probe cell');
    }

    protected function submitRegister($cell, $date, array $statusByRecordId)
    {
        return $this->actingAs($this->teacher())
            ->post(route('attendance.periods.store', $cell->id), [
                'topic' => 'F1 probe lesson',
                'date' => $date,
                'statuses' => $statusByRecordId,
            ]);
    }

    public function test_attendance_submit_queues_absent_only_and_dedupes_per_day()
    {
        $this->enableWhatsApp();
        $cell = $this->probeCell();
        $date = $this->freeMondayFor($cell->id);
        $classId = (int) $cell->tt_record->my_class_id;

        $roster = StudentRecord::where('my_class_id', $classId)
            ->where('section_id', 76)->where('grad', 0)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(3, $roster->count());

        $p1 = $this->makeUser('parent', 'attp1', '+91 98110 00011');
        $p2 = $this->makeUser('parent', 'attp2', '', '9811000022'); // phone2 only
        $roster[0]->update(['my_parent_id' => $p1->id]);
        $roster[1]->update(['my_parent_id' => $p2->id]);

        $statuses = [];
        foreach ($roster as $i => $rec) {
            $statuses[$rec->id] = $i === 0 || $i === 1 ? 'absent' : ($i === 2 ? 'late' : 'present');
        }

        $this->submitRegister($cell, $date, $statuses)
            ->assertRedirect(route('attendance.periods.index', ['date' => $date]));

        // Exactly 2 absence rows; the late child has none.
        $this->assertSame(2, WhatsAppMessage::where('event', 'attendance_absent')
            ->whereIn('student_record_id', [$roster[0]->id, $roster[1]->id, $roster[2]->id])->count());
        $r1 = $this->waFor($roster[0]->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('mocked', $r1->status);
        $this->assertSame('919811000011', $r1->recipient_phone);
        $r2 = $this->waFor($roster[1]->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('919811000022', $r2->recipient_phone, 'phone2 fallback must be used');
        $this->assertSame(0, $this->waFor($roster[2]->id, 'attendance_absent')->count(), 'late never triggers');

        // A second period the same day for the same child inserts nothing new.
        ParentWhatsApp::absent($roster[0]->fresh(), null, $date, 'Mathematics', $this->teacher()->id);
        $this->assertSame(1, $this->waFor($roster[0]->id, 'attendance_absent')->count(), 'daily dedupe: one absence message per child per day');

        // The next day is a new message.
        $nextDay = Carbon::parse($date)->addDay()->toDateString();
        ParentWhatsApp::absent($roster[0]->fresh(), null, $nextDay, 'English', $this->teacher()->id);
        $this->assertSame(2, $this->waFor($roster[0]->id, 'attendance_absent')->count());
    }

    public function test_attendance_submit_survives_throwing_provider()
    {
        $this->enableWhatsApp();
        $this->app->instance(WhatsAppProvider::class, new class implements WhatsAppProvider {
            public function sendTemplate(string $to, string $template, string $language, array $params): array
            {
                throw new \RuntimeException('provider exploded');
            }
        });

        $cell = $this->probeCell();
        $date = $this->freeMondayFor($cell->id);
        $classId = (int) $cell->tt_record->my_class_id;
        $roster = StudentRecord::where('my_class_id', $classId)
            ->where('section_id', 76)->where('grad', 0)->orderBy('id')->get();
        $p1 = $this->makeUser('parent', 'throwp', '9811000033');
        $roster[0]->update(['my_parent_id' => $p1->id]);

        $statuses = [];
        foreach ($roster as $i => $rec) {
            $statuses[$rec->id] = $i === 0 ? 'absent' : 'present';
        }

        $this->submitRegister($cell, $date, $statuses)
            ->assertRedirect(route('attendance.periods.index', ['date' => $date]));

        $this->assertTrue(AttendanceSession::where('time_table_id', $cell->id)->whereDate('date', $date)->exists(),
            'the register itself must land even when WhatsApp explodes');
        $row = $this->waFor($roster[0]->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('failed', $row->status);
    }

    /* ---------------- §12.4 fee reminders ---------------- */

    public function test_fee_reminder_rows_cooldown_and_non_defaulter()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'feep', '9811000044');
        [$student, $sr] = $this->makeStudent('feec', $parent);
        $this->makeFee($student->id, 'feec', 12000, 0);
        [, $srClean] = $this->makeStudent('clean', $this->makeUser('parent', 'cleanp', '9811000055'));

        $res = Notify::feeReminders(Qs::getCurrentSession());
        $this->assertGreaterThanOrEqual(1, $res['defaulters']);

        $row = $this->waFor($sr->id, 'fee_reminder')->firstOrFail();
        $this->assertSame('mocked', $row->status);
        $this->assertSame($parent->id, (int) $row->parent_user_id);
        $this->assertSame('12,000', $row->params[1], 'amount param is comma-grouped digits');
        $this->assertSame('hackstra_fee_reminder', $row->template_name);
        // The student is never a WhatsApp recipient; the bell still went to both.
        $this->assertSame(0, WhatsAppMessage::where('parent_user_id', $student->id)->count());
        $this->assertTrue(UserNotification::where('user_id', $parent->id)->where('type', 'fee_reminder')->exists());
        $this->assertTrue(UserNotification::where('user_id', $student->id)->where('type', 'fee_reminder')->exists());
        // Non-defaulter: nothing.
        $this->assertSame(0, $this->waFor($srClean->id, 'fee_reminder')->count());

        // Second click inside 7 days: suppressed_cooldown, no new send.
        Notify::feeReminders(Qs::getCurrentSession());
        $rows = $this->waFor($sr->id, 'fee_reminder')->orderBy('id')->get();
        $this->assertSame(2, $rows->count());
        $this->assertSame('suppressed_cooldown', $rows[1]->status);
        $this->assertSame(1, $this->waFor($sr->id, 'fee_reminder')->whereIn('status', ['sent', 'mocked'])->count());
    }

    /* ---------------- §12.5 results ---------------- */

    public function test_results_publish_population_republish_and_principal_gate()
    {
        $this->enableWhatsApp();
        $pA = $this->makeUser('parent', 'resp1', '9811000066');
        [, $srA] = $this->makeStudent('resc1', $pA, 1, 1);
        $pB = $this->makeUser('parent', 'resp2', ''); // no phone
        [, $srB] = $this->makeStudent('resc2', $pB, 1, 1);
        $pC = $this->makeUser('parent', 'resp3', '9811000077');
        $sectionClass2 = Section::where('my_class_id', 2)->orderBy('id')->firstOrFail();
        [, $srC] = $this->makeStudent('resc3', $pC, 2, $sectionClass2->id); // other class — out of scope

        $exam = Exam::create([
            'name' => 'F1 Result Exam', 'term' => 1,
            'year' => Qs::getCurrentSession(), 'my_class_id' => 1,
            'results_published' => false,
        ]);

        // Non-principal publish attempt: 403 and zero rows.
        $this->actingAs($this->admin())
            ->post(route('exams.publish_results', ['exam' => $exam->id]))
            ->assertForbidden();
        $this->assertSame(0, WhatsAppMessage::where('related_id', $exam->id)->count());

        $this->actingAs($this->principal())
            ->post(route('exams.publish_results', ['exam' => $exam->id]))
            ->assertRedirect();

        $rowA = WhatsAppMessage::where('related_id', $exam->id)->where('student_record_id', $srA->id)->firstOrFail();
        $this->assertContains($rowA->status, ['mocked', 'queued']);
        $this->assertSame('hackstra_result_published', $rowA->template_name);
        $rowB = WhatsAppMessage::where('related_id', $exam->id)->where('student_record_id', $srB->id)->firstOrFail();
        $this->assertSame('skipped_no_phone', $rowB->status);
        $this->assertSame(0, WhatsAppMessage::where('related_id', $exam->id)->where('student_record_id', $srC->id)->count(),
            'class-scoped exam must not message other classes');
        // Bell population matches: the fixture student + parent both got bells.
        $this->assertTrue(UserNotification::where('user_id', $pA->id)->where('type', 'result_published')->exists());

        $countAfterFirst = WhatsAppMessage::where('related_id', $exam->id)->count();
        $this->actingAs($this->principal())->post(route('exams.unpublish_results', ['exam' => $exam->id]));
        $this->actingAs($this->principal())->post(route('exams.publish_results', ['exam' => $exam->id]));
        $this->assertSame($countAfterFirst, WhatsAppMessage::where('related_id', $exam->id)->count(),
            're-publish never re-sends WhatsApp');
    }

    /* ---------------- §12.6 circulars (three paths) ---------------- */

    protected function circularPayload(array $overrides = [])
    {
        return array_merge([
            'title' => 'F1 Circular ' . Str::random(6),
            'body' => 'F1 circular body.',
            'audience' => 'all',
            'priority' => 'normal',
            'is_published' => 1,
        ], $overrides);
    }

    public function test_circular_store_publish_audience_gate_and_one_row_per_parent()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'circp', '9811000088');
        $this->makeStudent('circc1', $parent, 1, 1);
        $this->makeStudent('circc2', $parent, 1, 1);
        $sectionClass2 = Section::where('my_class_id', 2)->orderBy('id')->firstOrFail();
        $this->makeStudent('circc3', $parent, 2, $sectionClass2->id);

        // Draft: nothing.
        $this->actingAs($this->admin())
            ->post(route('circulars.store'), $this->circularPayload(['is_published' => 0]))
            ->assertRedirect(route('circulars.index'));
        $draft = Circular::latest('id')->firstOrFail();
        $this->assertSame(0, WhatsAppMessage::where('related_id', $draft->id)->where('event', 'circular_published')->count());

        // Published via store(), audience all: exactly ONE row for the 3-child parent.
        $this->actingAs($this->admin())
            ->post(route('circulars.store'), $this->circularPayload())
            ->assertRedirect(route('circulars.index'));
        $circ = Circular::latest('id')->firstOrFail();
        $rows = WhatsAppMessage::where('related_id', $circ->id)->where('event', 'circular_published')
            ->where('parent_user_id', $parent->id)->get();
        $this->assertSame(1, $rows->count(), 'one circular message per parent, not per child');
        $this->assertSame('hackstra_circular_published', $rows[0]->template_name);

        // Audience teachers: zero rows.
        $this->actingAs($this->admin())
            ->post(route('circulars.store'), $this->circularPayload(['audience' => 'teachers']))
            ->assertRedirect(route('circulars.index'));
        $staff = Circular::latest('id')->firstOrFail();
        $this->assertSame(0, WhatsAppMessage::where('related_id', $staff->id)->where('event', 'circular_published')->count());
    }

    public function test_circular_update_transition_publishes_once()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'circup', '9811000099');
        $this->makeStudent('circuc', $parent, 1, 1);

        $circ = Circular::create([
            'title' => 'F1 Update Path', 'body' => 'Body', 'audience' => 'parents',
            'priority' => 'normal', 'published_by' => $this->admin()->id, 'is_published' => false,
        ]);

        $this->actingAs($this->admin())
            ->put(route('circulars.update', $circ->id), $this->circularPayload(['title' => 'F1 Update Path']))
            ->assertRedirect(route('circulars.index'));
        $this->assertSame(1, WhatsAppMessage::where('related_id', $circ->id)
            ->where('parent_user_id', $parent->id)->count());

        // Editing while published: no re-send.
        $this->actingAs($this->admin())
            ->put(route('circulars.update', $circ->id), $this->circularPayload(['title' => 'F1 Update Path v2']))
            ->assertRedirect(route('circulars.index'));
        $this->assertSame(1, WhatsAppMessage::where('related_id', $circ->id)
            ->where('parent_user_id', $parent->id)->count());
    }

    public function test_circular_toggle_publish_no_duplicates()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'circtp', '9811000100');
        $this->makeStudent('circtc', $parent, 1, 1);

        $circ = Circular::create([
            'title' => 'F1 Toggle Path', 'body' => 'Body', 'audience' => 'all',
            'priority' => 'normal', 'published_by' => $this->admin()->id, 'is_published' => false,
        ]);

        $this->actingAs($this->admin())->patch(route('circulars.toggle_publish', $circ->id));
        $this->assertSame(1, WhatsAppMessage::where('related_id', $circ->id)
            ->where('parent_user_id', $parent->id)->count());

        $this->actingAs($this->admin())->patch(route('circulars.toggle_publish', $circ->id)); // off
        $this->actingAs($this->admin())->patch(route('circulars.toggle_publish', $circ->id)); // on again
        $this->assertSame(1, WhatsAppMessage::where('related_id', $circ->id)
            ->where('parent_user_id', $parent->id)->count(), 'toggle off/on never duplicates');
    }

    /* ---------------- §12.7 assignments ---------------- */

    public function test_assignment_store_scopes_to_section_then_class()
    {
        $this->enableWhatsApp();
        $subject = Subject::findOrFail(79); // Ananya's English (section 76 override)
        $classId = (int) $subject->my_class_id;
        $otherSection = Section::where('my_class_id', $classId)->where('id', '!=', 76)->orderBy('id')->firstOrFail();

        $pIn = $this->makeUser('parent', 'asnpin', '9811000111');
        $this->makeStudent('asnin', $pIn, $classId, 76);
        $pOut = $this->makeUser('parent', 'asnpout', '9811000122');
        $this->makeStudent('asnout', $pOut, $classId, $otherSection->id);

        $payload = [
            'title' => 'F1 Homework ' . Str::random(5),
            'description' => 'F1 homework body',
            'my_class_id' => $classId,
            'section_id' => 76,
            'subject_id' => $subject->id,
            'max_marks' => 20,
            'due_at' => '2030-01-05 10:00:00',
            'allow_late' => false,
        ];
        $this->actingAs($this->teacher())->post(route('assignments.store'), $payload)->assertRedirect();
        $assignment = Assignment::where('title', $payload['title'])->firstOrFail();
        $this->assertSame(1, WhatsAppMessage::where('related_id', $assignment->id)
            ->where('parent_user_id', $pIn->id)->count(), 'section parent gets the homework message');
        $this->assertSame(0, WhatsAppMessage::where('related_id', $assignment->id)
            ->where('parent_user_id', $pOut->id)->count(), 'other-section parent does not');

        // Class-wide (no section): both parents.
        $payload2 = $payload;
        $payload2['title'] = 'F1 Homework Wide ' . Str::random(5);
        unset($payload2['section_id']);
        $this->actingAs($this->teacher())->post(route('assignments.store'), $payload2)->assertRedirect();
        $wide = Assignment::where('title', $payload2['title'])->firstOrFail();
        $this->assertSame(1, WhatsAppMessage::where('related_id', $wide->id)->where('parent_user_id', $pIn->id)->count());
        $this->assertSame(1, WhatsAppMessage::where('related_id', $wide->id)->where('parent_user_id', $pOut->id)->count());
    }

    /* ---------------- §12.8 recipients ---------------- */

    public function test_recipient_edges_null_parent_and_graduated()
    {
        $this->enableWhatsApp();
        // No linked parent: skipped_no_phone audit row, no provider call (mocked count stays 0 for it).
        [, $srOrphan] = $this->makeStudent('orphan', null);
        ParentWhatsApp::absent($srOrphan, null, '2026-11-02', 'English');
        $row = $this->waFor($srOrphan->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('skipped_no_phone', $row->status);
        $this->assertNull($row->parent_user_id);

        // Graduated record: excluded from fan-outs.
        $pGrad = $this->makeUser('parent', 'gradp', '9811000133');
        [, $srGrad] = $this->makeStudent('gradc', $pGrad, 1, 1, 1);
        $exam = Exam::create([
            'name' => 'F1 Grad Exam', 'term' => 1,
            'year' => Qs::getCurrentSession(), 'my_class_id' => 1,
            'results_published' => false,
        ]);
        ParentWhatsApp::resultPublished($exam);
        $this->assertSame(0, WhatsAppMessage::where('related_id', $exam->id)
            ->where('student_record_id', $srGrad->id)->count());
    }

    /* ---------------- §12.9–11 opt-out matrix ---------------- */

    public function test_opt_out_matrix_and_muted_still_sends()
    {
        $this->enableWhatsApp();

        // Master opt-out: everything skipped_opt_out.
        $pOut = $this->makeUser('parent', 'optout', '9811000144');
        [, $srOut] = $this->makeStudent('optoutc', $pOut);
        UserPreference::set($pOut->id, 'notify_whatsapp', '0');
        ParentWhatsApp::absent($srOut, null, '2026-11-02', 'English');
        $this->assertSame('skipped_opt_out', $this->waFor($srOut->id, 'attendance_absent')->firstOrFail()->status);

        // Contract: notifications_muted does NOT suppress WhatsApp.
        $pMute = $this->makeUser('parent', 'muted', '9811000155');
        [, $srMute] = $this->makeStudent('mutedc', $pMute);
        UserPreference::set($pMute->id, 'notifications_muted', '1');
        ParentWhatsApp::absent($srMute, null, '2026-11-02', 'English');
        $this->assertSame('mocked', $this->waFor($srMute->id, 'attendance_absent')->firstOrFail()->status,
            'muting the bell must never mute WhatsApp');

        // Per-event: notify_attendance=0 suppresses absent only; a result still sends.
        $pAtt = $this->makeUser('parent', 'att0', '9811000166');
        [, $srAtt] = $this->makeStudent('att0c', $pAtt, 1, 1);
        UserPreference::set($pAtt->id, 'notify_attendance', '0');
        ParentWhatsApp::absent($srAtt, null, '2026-11-02', 'English');
        $this->assertSame('skipped_opt_out', $this->waFor($srAtt->id, 'attendance_absent')->firstOrFail()->status);
        $exam = Exam::create([
            'name' => 'F1 Pref Exam', 'term' => 1,
            'year' => Qs::getCurrentSession(), 'my_class_id' => 1,
            'results_published' => false,
        ]);
        ParentWhatsApp::resultPublished($exam);
        $this->assertContains(
            WhatsAppMessage::where('related_id', $exam->id)->where('student_record_id', $srAtt->id)->firstOrFail()->status,
            ['mocked', 'queued'],
            'result still sends when only attendance is off'
        );

        // Fee is essential: sends despite notify_exams / notify_announcements = 0.
        $pFee = $this->makeUser('parent', 'feepref', '9811000177');
        [$feeStudent, $srFee] = $this->makeStudent('feeprc', $pFee, 1, 1);
        UserPreference::set($pFee->id, 'notify_exams', '0');
        UserPreference::set($pFee->id, 'notify_announcements', '0');
        $this->makeFee($feeStudent->id, 'feeprc', 9000, 0);
        Notify::feeReminders(Qs::getCurrentSession());
        $this->assertSame('mocked', $this->waFor($srFee->id, 'fee_reminder')->firstOrFail()->status);
    }

    public function test_my_account_whatsapp_switch_persists()
    {
        $parent = $this->makeUser('parent', 'acctwa', '9811000188');
        $html = $this->actingAs($parent)->get(route('my_account'))->assertOk()->getContent();
        $this->assertStringContainsString('WhatsApp messages from school', $html);

        $this->actingAs($parent)->post(route('my_account.preferences'), [
            'tab' => 'notifications',
            'notify_whatsapp_present' => '1',
            // checkbox unchecked → key absent from the post
        ])->assertRedirect();
        $this->assertSame('0', UserPreference::allFor($parent->id)['notify_whatsapp']);
    }

    /* ---------------- §12.12 settings ---------------- */

    protected function settingsPayload(array $overrides = [])
    {
        return array_merge([
            'system_name' => $this->getSetting('system_name') ?: 'Hackstra Academy',
            'current_session' => Qs::getCurrentSession(),
            'address' => $this->getSetting('address') ?: '123 School Road, Madurai',
        ], $overrides);
    }

    public function test_settings_whatsapp_keys_save_blank_keeps_and_token_hidden()
    {
        $this->actingAs($this->admin())->put(route('settings.update'), $this->settingsPayload([
            'whatsapp_enabled' => '1',
            'whatsapp_absent_enabled' => '1',
            'whatsapp_fee_enabled' => '0',
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_business_id' => '987654321098765',
            'whatsapp_access_token' => 'test_token_value_123',
            'whatsapp_app_secret' => 'appsecret123',
            'whatsapp_verify_token' => 'verifytok123',
            'whatsapp_template_language' => 'en',
        ]))->assertSessionHas('flash_success');
        $this->assertSame('1', $this->getSetting('whatsapp_enabled'));
        $this->assertSame('0', $this->getSetting('whatsapp_fee_enabled'));
        $this->assertSame('test_token_value_123', $this->getSetting('whatsapp_access_token'));

        // Blank secret fields keep the saved values.
        $this->actingAs($this->admin())->put(route('settings.update'), $this->settingsPayload([
            'whatsapp_access_token' => '',
            'whatsapp_app_secret' => '',
            'whatsapp_verify_token' => '',
            'whatsapp_phone_number_id' => '',
        ]))->assertSessionHas('flash_success');
        $this->assertSame('test_token_value_123', $this->getSetting('whatsapp_access_token'));
        $this->assertSame('appsecret123', $this->getSetting('whatsapp_app_secret'));
        $this->assertSame('123456789012345', $this->getSetting('whatsapp_phone_number_id'));

        // The settings page never renders the secrets; test_ token → MOCK status line.
        $html = $this->actingAs($this->admin())->get(route('settings'))->assertOk()->getContent();
        $this->assertStringContainsString('id="set-whatsapp"', $html);
        $this->assertStringContainsString('MOCK mode', $html);
        $this->assertStringNotContainsString('test_token_value_123', $html);
        $this->assertStringNotContainsString('appsecret123', $html);
        $this->assertStringNotContainsString('verifytok123', $html);
    }

    public function test_settings_unknown_key_refused_and_sa_denied_and_lock()
    {
        // Unknown key: refused whole.
        $this->actingAs($this->admin())->put(route('settings.update'), $this->settingsPayload([
            'whatsapp_enabled' => '1',
            'whatsapp_evil_key' => 'x',
        ]))->assertSessionHas('flash_danger');
        $this->assertNull($this->getSetting('whatsapp_evil_key'));

        // Super Admin posting school (WhatsApp) keys: denied — the
        // platform boundary refuses the SA's school write outright
        // (403 before the controller's field-ownership refusal).
        $this->actingAs($this->superAdmin())->put(route('settings.update'), $this->settingsPayload([
            'whatsapp_enabled' => '1',
        ]))->assertForbidden();
        $this->assertNotSame('1', $this->getSetting('whatsapp_enabled'));

        // Settings lock blocks the Admin save.
        $this->setSetting('settings_locked', '1');
        $this->actingAs($this->admin())->put(route('settings.update'), $this->settingsPayload([
            'whatsapp_enabled' => '1',
        ]))->assertSessionHas('flash_danger');
        $this->assertNotSame('1', $this->getSetting('whatsapp_enabled'));
    }

    /* ---------------- §12.14 provider paths ---------------- */

    protected function liveCredentials()
    {
        $this->enableWhatsApp([
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_access_token' => 'EAA_real_looking_token',
        ]);
    }

    public function test_meta_200_marks_sent_with_wamid()
    {
        $this->liveCredentials();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REAL1']]], 200),
        ]);
        $parent = $this->makeUser('parent', 'livep', '9811000199');
        [, $sr] = $this->makeStudent('livec', $parent);

        ParentWhatsApp::absent($sr, null, '2026-11-02', 'English');

        $row = $this->waFor($sr->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('sent', $row->status);
        $this->assertSame('wamid.REAL1', $row->provider_message_id);
        Http::assertSentCount(1);
    }

    public function test_meta_400_marks_failed_with_error_code()
    {
        $this->liveCredentials();
        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 132001, 'message' => 'Template does not exist']], 400),
        ]);
        $parent = $this->makeUser('parent', 'errp', '9811000200');
        [, $sr] = $this->makeStudent('errc', $parent);

        ParentWhatsApp::absent($sr, null, '2026-11-02', 'English'); // must not throw

        $row = $this->waFor($sr->id, 'attendance_absent')->firstOrFail();
        $this->assertSame('failed', $row->status);
        $this->assertSame('132001', $row->error_code);
    }

    public function test_transport_errors_stop_inline_drain_after_two()
    {
        $this->liveCredentials();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
        });
        $parent = $this->makeUser('parent', 'top', '9811000211');
        $ids = [];
        for ($i = 1; $i <= 3; $i++) {
            $m = WhatsAppMessage::create([
                'parent_user_id' => $parent->id,
                'recipient_phone' => '919811000211',
                'event' => 'attendance_absent',
                'template_name' => 'hackstra_attendance_absent',
                'template_language' => 'en',
                'params' => ['A', '02 Nov 2026', 'English', 'School'],
                'body_preview' => 'probe',
                'dedupe_key' => 'transport-probe-' . $i,
                'status' => 'queued',
                'attempts' => 0,
            ]);
            $ids[] = $m->id;
        }

        $res = ParentWhatsApp::drain(25);
        $this->assertSame(2, $res['failed']);
        $this->assertSame('failed', WhatsAppMessage::findOrFail($ids[0])->status);
        $this->assertSame('failed', WhatsAppMessage::findOrFail($ids[1])->status);
        $third = WhatsAppMessage::findOrFail($ids[2]);
        $this->assertSame('queued', $third->status, 'remainder stays queued after 2 transport errors');
        $this->assertSame(0, (int) $third->attempts);
    }

    /* ---------------- §12.15/16 sent-log UI ---------------- */

    protected function seedLogRows()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'logp', '9811000011');
        [, $sr] = $this->makeStudent('logc', $parent);
        ParentWhatsApp::absent($sr, null, '2026-11-02', 'English'); // mocked row
        $failed = WhatsAppMessage::create([
            'parent_user_id' => $parent->id,
            'student_record_id' => $sr->id,
            'recipient_phone' => '919811000011',
            'event' => 'fee_reminder',
            'template_name' => 'hackstra_fee_reminder',
            'template_language' => 'en',
            'params' => ['Kid', '1,000', '2026-2027', 'School'],
            'body_preview' => 'Fee reminder probe row',
            'dedupe_key' => 'log-failed-probe',
            'status' => 'failed',
            'error_code' => '131030',
            'error_message' => 'Recipient not in allowed list',
            'attempts' => 1,
        ]);

        return [$parent, $sr, $failed];
    }

    public function test_sent_log_gates_masking_and_badges()
    {
        [$parent, $sr, $failed] = $this->seedLogRows();

        $html = $this->actingAs($this->admin())->get(route('whatsapp_log.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Mock — not sent', $html);
        $this->assertStringContainsString('+91 •••• ••0011', $html);
        $this->assertStringNotContainsString('919811000011', $html, 'the full phone number must never render');
        $this->assertStringContainsString('hk-std-whatsapp', $html);

        $this->actingAs($this->principal())->get(route('whatsapp_log.index'))->assertOk();
        $this->actingAs($this->superAdmin())->get(route('whatsapp_log.index'))->assertOk();

        $this->actingAs($this->teacher())->get(route('whatsapp_log.index'))->assertForbidden();
        $this->actingAs($this->accountant())->get(route('whatsapp_log.index'))->assertForbidden();
        $this->actingAs($parent)->get(route('whatsapp_log.index'))->assertForbidden();
        $studentUser = User::findOrFail($sr->user_id);
        $this->actingAs($studentUser)->get(route('whatsapp_log.index'))->assertForbidden();
    }

    public function test_sent_log_retry_rules()
    {
        [, , $failed] = $this->seedLogRows();

        // SA retry: denied (platform boundary, non-GET school write).
        $this->actingAs($this->superAdmin())
            ->post(route('whatsapp_log.retry', Qs::hash($failed->id)))
            ->assertForbidden();
        $this->assertSame('failed', $failed->fresh()->status);

        // Principal retry: denied by teamSAWrite.
        $resp = $this->actingAs($this->principal())
            ->post(route('whatsapp_log.retry', Qs::hash($failed->id)));
        $this->assertNotSame(200, $resp->getStatusCode());
        $this->assertSame('failed', $failed->fresh()->status);

        // Admin retry on the failed row: requeued → drained (mock) + audit.
        $this->actingAs($this->admin())
            ->post(route('whatsapp_log.retry', Qs::hash($failed->id)))
            ->assertRedirect();
        $this->assertSame('mocked', $failed->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'whatsapp.retried')->exists());

        // Retry on a non-failed row: refused.
        $this->actingAs($this->admin())
            ->post(route('whatsapp_log.retry', Qs::hash($failed->id)))
            ->assertSessionHas('flash_danger');
        $this->assertSame('mocked', $failed->fresh()->status);
    }

    public function test_sent_log_filters_narrow_and_pagination_keeps_query()
    {
        $this->seedLogRows();
        $html = $this->actingAs($this->admin())
            ->get(route('whatsapp_log.index', ['event' => 'fee_reminder']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Fee reminder probe row', $html);
        $this->assertStringNotContainsString('was marked absent', $html);
    }

    /* ---------------- §12.17 webhook ---------------- */

    protected function webhookSettings()
    {
        $this->enableWhatsApp([
            'whatsapp_phone_number_id' => '123456789012345',
            'whatsapp_access_token' => 'EAA_real_looking_token',
            'whatsapp_app_secret' => 'shh_secret',
            'whatsapp_verify_token' => 'vtok123',
        ]);
    }

    protected function signedPost($payload)
    {
        $raw = json_encode($payload);
        $sig = 'sha256=' . hash_hmac('sha256', $raw, 'shh_secret');

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function test_webhook_verify_token_gate()
    {
        $this->webhookSettings();
        $this->get('/webhooks/whatsapp?hub_verify_token=wrong&hub_challenge=CH1')
            ->assertForbidden()
            ->assertDontSee('CH1');
        $this->get('/webhooks/whatsapp?hub_verify_token=vtok123&hub_challenge=CH1')
            ->assertOk()
            ->assertSee('CH1', false);
    }

    public function test_webhook_status_updates_and_idempotency()
    {
        $this->webhookSettings();
        $row = WhatsAppMessage::create([
            'recipient_phone' => '919811000211',
            'event' => 'attendance_absent',
            'template_name' => 'hackstra_attendance_absent',
            'template_language' => 'en',
            'params' => ['A'],
            'dedupe_key' => 'webhook-probe-1',
            'status' => 'sent',
            'provider_message_id' => 'wamid.WH1',
            'attempts' => 1,
        ]);

        // Bad signature: 400, no change.
        $raw = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.WH1', 'status' => 'delivered']]]]]]]]);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(400);
        $this->assertSame('sent', $row->fresh()->status);

        $payload = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.WH1', 'status' => 'delivered']]]]]]]];
        $this->signedPost($payload)->assertOk();
        $this->assertSame('delivered', $row->fresh()->status);
        // Repeat: idempotent.
        $this->signedPost($payload)->assertOk();
        $this->assertSame('delivered', $row->fresh()->status);

        // Unknown wamid: ignored, still 200.
        $this->signedPost(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.NOPE', 'status' => 'read']]]]]]]])
            ->assertOk();
    }

    public function test_webhook_stop_opts_parent_out_and_mock_accepts_nothing()
    {
        $this->webhookSettings();
        $parent = $this->makeUser('parent', 'stopp', '9811000099');

        $payload = ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '919811000099', 'type' => 'text', 'text' => ['body' => 'stop'],
        ]]]]]]]];
        $this->signedPost($payload)->assertOk();
        $this->assertSame('0', UserPreference::allFor($parent->id)['notify_whatsapp']);

        // Mock mode (no app secret): POST accepts nothing.
        $this->setSetting('whatsapp_app_secret', null);
        $parent2 = $this->makeUser('parent', 'stopp2', '9811000098');
        $payload2 = ['entry' => [['changes' => [['value' => ['messages' => [[
            'from' => '919811000098', 'type' => 'text', 'text' => ['body' => 'STOP'],
        ]]]]]]]];
        $raw = json_encode($payload2);
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $raw, 'shh_secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk();
        $this->assertSame('1', UserPreference::allFor($parent2->id)['notify_whatsapp'],
            'with no app secret saved, the webhook writes nothing');
    }

    /* ---------------- caps (design §11.3) ---------------- */

    public function test_per_parent_daily_cap_holds_extra_rows_queued()
    {
        $this->enableWhatsApp();
        $parent = $this->makeUser('parent', 'capp', '9811000222');
        [, $sr] = $this->makeStudent('capc', $parent);
        // Five already sent today for this parent.
        for ($i = 1; $i <= 5; $i++) {
            WhatsAppMessage::create([
                'parent_user_id' => $parent->id,
                'student_record_id' => $sr->id,
                'recipient_phone' => '919811000222',
                'event' => 'circular_published',
                'template_name' => 'hackstra_circular_published',
                'template_language' => 'en',
                'params' => ['T', 'School'],
                'dedupe_key' => 'cap-seed-' . $i,
                'status' => 'mocked',
                'attempts' => 1,
                'sent_at' => now(),
            ]);
        }
        // A sixth (circular-style queue + drain): stays queued for tomorrow.
        $sixth = WhatsAppMessage::create([
            'parent_user_id' => $parent->id,
            'student_record_id' => $sr->id,
            'recipient_phone' => '919811000222',
            'event' => 'circular_published',
            'template_name' => 'hackstra_circular_published',
            'template_language' => 'en',
            'params' => ['T2', 'School'],
            'dedupe_key' => 'cap-sixth',
            'status' => 'queued',
            'attempts' => 0,
        ]);
        ParentWhatsApp::drain(25);
        $this->assertSame('queued', $sixth->fresh()->status, 'per-parent cap of 5/day holds the rest queued');
    }
}
