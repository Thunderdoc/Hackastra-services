<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Concession;
use App\Models\FeeHead;
use App\Models\FeeInstallment;
use App\Models\FeeReminderLog;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\Support\FeeReminderEngine;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F5 (Round 4) — Auto Fee Reminders + Instalment View.
 * Covers design §9 groups 1–17: engine/dedupe/catchup, quiet
 * anchors (Aarav ₹0 / the ₹14,400 anchor), age fallback, channel
 * matrix, manual send + gates (SA refused on the new routes),
 * settings canonicalisation, plan views + FIFO + concession,
 * the log page, the opportunistic trigger and the ping endpoint.
 * Day control is Carbon::setTestNow throughout; every fixture
 * student is fresh so counts are exact per student.
 */
class OwnerFeeRemindersF5Test extends TestCase
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
    protected function accountant() { return $this->userByEmail('accountant@hackstra.app'); }
    protected function superAdmin() { return $this->userByEmail('superadmin@hackstra.app'); }

    protected function setSetting($type, $value)
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    protected function getSetting($type)
    {
        $s = Setting::where('type', $type)->first();

        return $s ? $s->description : null;
    }

    protected function enableReminders(array $overrides = [])
    {
        $values = array_merge([
            'fee_reminders_enabled' => '1',
            'fee_reminder_offsets' => '-3,0,7',
            'fee_reminder_channel_bell' => '1',
            'fee_reminder_channel_whatsapp' => '0',
        ], $overrides);
        foreach ($values as $k => $v) {
            $this->setSetting($k, $v);
        }
        Setting::where('type', 'fee_reminder_last_run')->delete();
    }

    protected function makeUser($type, $tag)
    {
        return User::create([
            'name' => 'F5 ' . ucfirst($tag),
            'email' => 'f5_' . $tag . '@example.test',
            'username' => 'f5_' . $tag,
            'password' => Hash::make('f5-pass'),
            'user_type' => $type,
            'code' => 'F5-' . strtoupper($tag),
        ]);
    }

    /** Fresh active student (+ optional linked parent). Returns [User, StudentRecord, ?User parent] */
    protected function makeStudent($tag, $withParent = false, $sectionId = 1)
    {
        $parent = $withParent ? $this->makeUser('parent', $tag . '_parent') : null;
        $student = $this->makeUser('student', $tag);
        $sr = StudentRecord::create([
            'user_id' => $student->id,
            'my_class_id' => 1,
            'section_id' => $sectionId,
            'my_parent_id' => $parent ? $parent->id : null,
            'adm_no' => 'F5/' . strtoupper($tag),
            'session' => Qs::getCurrentSession(),
            'year_admitted' => '2026',
        ]);

        return [$student, $sr, $parent];
    }

    /** A fee + an owing record for the student. Returns [Payment, PaymentRecord] */
    protected function makeFee($studentUserId, $tag, $amount = 12000, $amtPaid = 0)
    {
        $payment = Payment::create([
            'title' => 'F5 Fee ' . $tag,
            'amount' => $amount,
            'my_class_id' => 1,
            'description' => 'F5 fixture',
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'F5-' . strtoupper($tag) . '-' . Str::random(6),
            'method' => 'Cash',
        ]);
        $record = PaymentRecord::create([
            'payment_id' => $payment->id,
            'student_id' => $studentUserId,
            'amt_paid' => $amtPaid,
            'balance' => $amtPaid >= $amount ? 0 : $amount - $amtPaid,
            'paid' => $amtPaid >= $amount ? 1 : 0,
            'year' => Qs::getCurrentSession(),
            'ref_no' => 'F5R-' . strtoupper($tag) . '-' . Str::random(6),
        ]);

        return [$payment, $record];
    }

    protected function makeHead(Payment $payment, array $installments)
    {
        $head = FeeHead::create([
            'my_class_id' => $payment->my_class_id,
            'title' => $payment->title,
            'amount' => $payment->amount,
            'year' => $payment->year,
        ]);
        foreach ($installments as $i => $inst) {
            FeeInstallment::create([
                'fee_head_id' => $head->id,
                'installment_no' => $inst[0],
                'due_date' => $inst[1] ?? null,
                'amount' => $inst[2],
            ]);
        }

        return $head;
    }

    protected function bellsFor($userId)
    {
        return UserNotification::where('user_id', $userId)->where('type', 'fee_reminder')->count();
    }

    protected function logsFor($userId)
    {
        return FeeReminderLog::where('student_id', $userId);
    }

    /** Instalment fixture: due 15 Jun 2026, ₹12,000 fee, ₹2,000 paid (FIFO: inst 1 covered 2,000). */
    protected function instalmentFixture($tag = 'inst', $withParent = true)
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));
        [$student, $sr, $parent] = $this->makeStudent($tag, $withParent);
        [$payment, $record] = $this->makeFee($student->id, $tag, 12000, 2000);
        $head = $this->makeHead($payment, [
            [1, '2026-06-15', 6000],
            [2, '2026-09-15', 6000],
        ]);
        $this->enableReminders();

        return [$student, $sr, $parent, $payment, $record, $head];
    }

    /* ---------------- §9.1 offsets fire on exact days ---------------- */

    public function test_instalment_offsets_fire_on_exact_days_only()
    {
        [$student, $sr, $parent, $payment, $record, $head] = $this->instalmentFixture();
        $engine = new FeeReminderEngine();
        $year = Qs::getCurrentSession();

        // Preview is read-only and names the event.
        Carbon::setTestNow(Carbon::parse('2026-06-12 09:00:00'));
        $preview = $engine->previewDue($year)->where('student_id', $student->id);
        $this->assertCount(1, $preview);
        $this->assertSame(-3, $preview->first()['rule_offset']);
        $this->assertSame(0, FeeReminderLog::count());

        // -3 day fires: one bell each to student + parent, body
        // carries the instalment number and uncovered amount.
        $engine->runDue(Carbon::parse('2026-06-12'));
        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame(1, $this->bellsFor($parent->id));
        $bell = UserNotification::where('user_id', $student->id)->where('type', 'fee_reminder')->first();
        $this->assertStringContainsString('Instalment 1 of F5 Fee inst', $bell->body);
        $this->assertStringContainsString('₹4,000 due on 15 Jun 2026', $bell->body);
        $this->assertStringContainsString('Fee reminder: ₹10,000 outstanding', $bell->title);
        $log = $this->logsFor($student->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('scheduled', $log->trigger);
        $this->assertSame('bell', $log->channel);
        $this->assertSame(-3, $log->rule_offset);
        $this->assertSame('2026-06-15', $log->due_date->format('Y-m-d'));
        $this->assertSame((int) $head->id, (int) $log->fee_head_id);
        $this->assertSame(10000, (int) $log->amount_due);
        $this->assertSame('sent', $log->status);
        $inst = FeeInstallment::where('fee_head_id', $head->id)->where('installment_no', 1)->first();
        $this->assertSame('sched|' . $student->id . '|' . $year . '|inst:' . $inst->id . '|off:-3|bell', $log->dedupe_key);

        // -2 day: nothing.
        Carbon::setTestNow(Carbon::parse('2026-06-13 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-13'));
        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame(1, $this->logsFor($student->id)->count());

        // Due day fires (offset 0), +1 does not, +7 fires.
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-15'));
        $this->assertSame(2, $this->bellsFor($student->id));

        Carbon::setTestNow(Carbon::parse('2026-06-16 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-16'));
        $this->assertSame(2, $this->bellsFor($student->id));

        Carbon::setTestNow(Carbon::parse('2026-06-22 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-22'));
        $this->assertSame(3, $this->bellsFor($student->id));
        $this->assertSame([ -3, 0, 7 ], $this->logsFor($student->id)->orderBy('rule_offset')->pluck('rule_offset')->map(function ($v) { return (int) $v; })->all());
    }

    /* ---------------- §9.2 dedupe: re-runs send nothing ---------------- */

    public function test_rerun_same_day_and_other_triggers_send_nothing_twice()
    {
        [$student] = $this->instalmentFixture('dedupe');
        $engine = new FeeReminderEngine();
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $engine->runDue(Carbon::parse('2026-06-15'));
        $engine->runDue(Carbon::parse('2026-06-15'));
        Artisan::call('fees:remind-due', ['--date' => '2026-06-15']);
        FeeReminderEngine::maybeRunOpportunistically();

        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame(1, $this->logsFor($student->id)->where('status', 'sent')->count());

        // The unique key itself is the guarantee: a direct
        // duplicate insert is refused by the database.
        $log = $this->logsFor($student->id)->first();
        $this->expectException(\Illuminate\Database\QueryException::class);
        FeeReminderLog::create([
            'student_id' => $student->id, 'year' => $log->year, 'channel' => 'bell',
            'trigger' => 'scheduled', 'amount_due' => 1, 'status' => 'sent',
            'dedupe_key' => $log->dedupe_key,
        ]);
    }

    /* ---------------- §9.3 missed-day catchup ---------------- */

    public function test_missed_days_catchup_fires_the_missed_rule_once()
    {
        [$student] = $this->instalmentFixture('catchup');
        // Last run was 11 Jun; nobody opened the app on 12 Jun
        // (the -3 rule day). Running on 14 Jun must still fire it.
        $this->setSetting('fee_reminder_last_run', '2026-06-11');
        Carbon::setTestNow(Carbon::parse('2026-06-14 09:00:00'));
        (new FeeReminderEngine())->runDue(Carbon::parse('2026-06-14'));

        $this->assertSame(1, $this->bellsFor($student->id));
        $log = $this->logsFor($student->id)->first();
        $this->assertSame(-3, (int) $log->rule_offset);
        $this->assertSame('2026-06-14', $this->getSetting('fee_reminder_last_run'));
    }

    /* ---------------- §9.4 quiet anchors + skipped_paid ---------------- */

    public function test_quiet_anchors_aarav_zero_and_14400_student()
    {
        $year = Qs::getCurrentSession();
        $aarav = $this->userByEmail('student@hackstra.app');
        $this->assertSame(0, \App\Support\FeeDefaulter::outstandingFor($aarav->id, $year));

        // The ₹14,400 anchor: student_record 2 (₹10,800 + ₹3,000 + ₹600).
        $anchorSr = StudentRecord::findOrFail(2);
        $this->assertSame(14400, \App\Support\FeeDefaulter::outstandingFor($anchorSr->user_id, $year));

        $this->enableReminders();
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        (new FeeReminderEngine())->sendNow($year, null, ['q' => $anchorSr->user->name], $this->admin());

        $log = $this->logsFor($anchorSr->user_id)->where('trigger', 'manual')->first();
        $this->assertNotNull($log, 'anchor student must be reminded by the manual send');
        $this->assertSame(14400, (int) $log->amount_due);
        $bell = UserNotification::where('user_id', $anchorSr->user_id)->where('type', 'fee_reminder')->first();
        $this->assertNotNull($bell);
        $this->assertStringContainsString('₹14,400', $bell->title);

        // Aarav (₹0) receives nothing and gets no log row, ever.
        $this->assertSame(0, $this->bellsFor($aarav->id));
        $this->assertSame(0, $this->logsFor($aarav->id)->count());
    }

    public function test_skipped_paid_when_student_pays_between_selection_and_send()
    {
        [$student, $sr, $parent] = $this->makeStudent('paidrace');
        [$payment, $record] = $this->makeFee($student->id, 'paidrace', 5000, 5000); // fully paid

        $engine = new FeeReminderEngine();
        $method = new \ReflectionMethod($engine, 'dispatch');
        $method->setAccessible(true);
        $event = [[
            'student_id' => $student->id,
            'student_record_id' => $sr->id,
            'parent_user_id' => null,
            'year' => Qs::getCurrentSession(),
            'rule_offset' => 0,
            'due_date' => '2026-06-15',
            'fee_head_id' => null,
            'installment_id' => 1,
            'amount_due' => 5000,
            'dedupe_part' => 'inst:1',
            'label' => null,
        ]];
        $res = $method->invoke($engine, $event, 'scheduled', ['bell'], null, null, false);

        $this->assertSame(1, $res['skipped_paid']);
        $this->assertSame(0, $this->bellsFor($student->id));
        $log = $this->logsFor($student->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped_paid', $log->status);
    }

    /* ---------------- §9.5 age-event fallback ---------------- */

    public function test_age_events_positive_offsets_only_from_oldest_since()
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 09:00:00'));
        [$student] = $this->makeStudent('age');
        [$payment, $record] = $this->makeFee($student->id, 'age', 8000, 0); // no head anywhere
        $this->enableReminders();
        $engine = new FeeReminderEngine();

        // -3 relative to the fee's age makes no sense: nothing.
        Carbon::setTestNow(Carbon::parse('2026-05-29 09:00:00'));
        $engine->runDue(Carbon::parse('2026-05-29'));
        $this->assertSame(0, $this->logsFor($student->id)->count());

        // Offset 0 fires on the day the oldest record was created.
        Carbon::setTestNow(Carbon::parse('2026-06-01 10:00:00'));
        $engine->runDue(Carbon::parse('2026-06-01'));
        $this->assertSame(1, $this->bellsFor($student->id));
        $log = $this->logsFor($student->id)->first();
        $this->assertSame(0, (int) $log->rule_offset);
        $this->assertNull($log->due_date);
        $this->assertStringContainsString('age:' . $record->id, $log->dedupe_key);

        // +7 fires a week later; a random day in between does not.
        Carbon::setTestNow(Carbon::parse('2026-06-04 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-04'));
        $this->assertSame(1, $this->bellsFor($student->id));
        Carbon::setTestNow(Carbon::parse('2026-06-08 09:00:00'));
        $engine->runDue(Carbon::parse('2026-06-08'));
        $this->assertSame(2, $this->bellsFor($student->id));
        $this->assertSame([0, 7], $this->logsFor($student->id)->orderBy('rule_offset')->pluck('rule_offset')->map(function ($v) { return (int) $v; })->all());
    }

    /* ---------------- §9.6 disabled switch ---------------- */

    public function test_disabled_master_switch_noop_but_manual_still_works()
    {
        [$student] = $this->instalmentFixture('disabled');
        $this->setSetting('fee_reminders_enabled', '0');
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $res = (new FeeReminderEngine())->runDue(Carbon::parse('2026-06-15'));
        $this->assertTrue($res['disabled']);
        $this->assertSame(0, $this->bellsFor($student->id));
        $this->assertSame(0, FeeReminderLog::count());

        $manual = (new FeeReminderEngine())->sendNow(Qs::getCurrentSession(), null, ['q' => 'F5 disabled'], $this->admin());
        $this->assertSame(1, $manual['defaulters']);
        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame('manual', $this->logsFor($student->id)->first()->trigger);
    }

    /* ---------------- §9.7 channel matrix ---------------- */

    public function test_channel_matrix_bell_whatsapp_both()
    {
        // WhatsApp only: F1 absent ⇒ skipped_no_channel, no bell, no throw.
        [$waStudent] = $this->instalmentFixture('waonly');
        $this->enableReminders(['fee_reminder_channel_bell' => '0', 'fee_reminder_channel_whatsapp' => '1']);
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        (new FeeReminderEngine())->runDue(Carbon::parse('2026-06-15'));
        $this->assertSame(0, $this->bellsFor($waStudent->id));
        $waLog = $this->logsFor($waStudent->id)->first();
        $this->assertNotNull($waLog);
        $this->assertSame('whatsapp', $waLog->channel);
        $this->assertSame('skipped_no_channel', $waLog->status);

        // Both: bell sent + WhatsApp visibly skipped.
        [$bothStudent] = $this->instalmentFixture('bothchan');
        $this->enableReminders(['fee_reminder_channel_bell' => '1', 'fee_reminder_channel_whatsapp' => '1']);
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        (new FeeReminderEngine())->runDue(Carbon::parse('2026-06-15'));
        $this->assertSame(1, $this->bellsFor($bothStudent->id));
        $statuses = $this->logsFor($bothStudent->id)->pluck('status', 'channel')->all();
        $this->assertSame('sent', $statuses['bell']);
        $this->assertSame('skipped_no_channel', $statuses['whatsapp']);
    }

    /* -------- F1 integration: the WhatsApp leg is live -------- */

    public function test_whatsapp_leg_reaches_f1_engine_when_gateway_enabled()
    {
        [$student, $sr, $parent] = $this->instalmentFixture('walive');
        $parent->update(['phone' => '+91 98110 00006']);
        $this->enableReminders(['fee_reminder_channel_bell' => '0', 'fee_reminder_channel_whatsapp' => '1']);
        $this->setSetting('whatsapp_enabled', '1'); // no credentials ⇒ F1 mock mode
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));
        (new FeeReminderEngine())->runDue(Carbon::parse('2026-06-15'));

        // F5's own log records the dispatch as sent on WhatsApp…
        $waLog = $this->logsFor($student->id)->where('channel', 'whatsapp')->first();
        $this->assertNotNull($waLog);
        $this->assertSame('sent', $waLog->status);

        // …and F1's outbox carries the mocked delivery truth.
        $row = \App\Models\WhatsAppMessage::where('student_record_id', $sr->id)
            ->where('event', 'fee_reminder')->first();
        $this->assertNotNull($row);
        $this->assertSame('mocked', $row->status);
        $this->assertSame((int) $parent->id, (int) $row->parent_user_id);
    }

    /* ---------------- §9.8 manual send: logs + audit + refresh ---------------- */

    public function test_manual_send_via_route_logs_manual_and_refreshes_bell()
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 09:00:00'));
        [$student] = $this->makeStudent('manual');
        $this->makeFee($student->id, 'manual', 7000, 0);

        $url = route('payments.defaulters.remind') . '?q=' . urlencode('F5 manual');
        $this->actingAs($this->accountant())->post($url)->assertRedirect()->assertSessionHas('flash_success');
        $this->assertSame(1, $this->bellsFor($student->id));
        $log = $this->logsFor($student->id)->where('trigger', 'manual')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->accountant()->id, (int) $log->sent_by);
        $this->assertSame(7000, (int) $log->amount_due);
        $this->assertDatabaseHas('audit_logs', ['action' => 'fee_reminders.sent']);

        // Wave 5 semantics preserved: re-send refreshes, still one unread bell.
        $this->actingAs($this->accountant())->post($url)->assertRedirect();
        $this->assertSame(1, UserNotification::where('user_id', $student->id)->where('type', 'fee_reminder')->whereNull('read_at')->count());
        $this->assertSame(2, $this->logsFor($student->id)->where('trigger', 'manual')->count());
    }

    /* ---------------- §9.9 gates ---------------- */

    public function test_gates_admin_accountant_only_super_admin_refused()
    {
        foreach ([$this->admin(), $this->accountant()] as $ok) {
            $this->actingAs($ok)->get(route('payments.reminders'))->assertOk();
            $this->actingAs($ok)->get(route('payments.reminders.export'))->assertOk();
        }
        foreach (['teacher@hackstra.app', 'student@hackstra.app', 'parent@hackstra.app', 'principal@hackstra.app'] as $email) {
            $this->actingAs($this->userByEmail($email))->get(route('payments.reminders'))
                ->assertRedirect(route('login'));
        }
        // The pinned §6.1 decision: Super Admin is REFUSED on the
        // new F5 routes — PlatformOnly (the app's SA choke point)
        // bounces school GETs to the platform dashboard and 403s
        // non-GET school requests, before teamAccountWrite would.
        $this->actingAs($this->superAdmin())->get(route('payments.reminders'))
            ->assertRedirect(route('dashboard'));
        $this->actingAs($this->superAdmin())->post(route('payments.reminders.run'))
            ->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('payments.reminders.export'))
            ->assertRedirect(route('dashboard'));
    }

    /* ---------------- §9.10 filtered send-now ---------------- */

    public function test_filtered_send_now_reaches_only_that_section()
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 09:00:00'));
        [$inSection] = $this->makeStudent('secin', false, 1);
        $this->makeFee($inSection->id, 'secin', 5000, 0);
        [$otherSection] = $this->makeStudent('secout', false, 2);
        $this->makeFee($otherSection->id, 'secout', 5000, 0);
        // A fully-paid student in the SAME section gets nothing.
        [$paidStudent] = $this->makeStudent('secpaid', false, 1);
        $this->makeFee($paidStudent->id, 'secpaid', 5000, 5000);

        $res = (new FeeReminderEngine())->sendNow(Qs::getCurrentSession(), null, ['section_id' => 1], $this->admin());
        $this->assertSame(1, $this->bellsFor($inSection->id));
        $this->assertSame(0, $this->bellsFor($otherSection->id));
        $this->assertSame(0, $this->bellsFor($paidStudent->id));
        $this->assertSame(0, $this->logsFor($paidStudent->id)->count());
    }

    /* ---------------- §9.11 settings ---------------- */

    protected function settingsPayload(array $overrides = [])
    {
        return array_merge([
            'system_name' => $this->getSetting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->getSetting('current_session') ?? '2026-2027',
            'address' => $this->getSetting('address') ?? 'Madurai, Tamil Nadu, India',
        ], $overrides);
    }

    public function test_settings_keys_accepted_and_offsets_canonicalised()
    {
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload([
                'fee_reminders_enabled' => '1',
                'fee_reminder_offsets' => '7,-3,7,0',
                'fee_reminder_channel_bell' => '1',
                'fee_reminder_channel_whatsapp' => '0',
            ]))->assertRedirect()->assertSessionHas('flash_success');

        $this->assertSame('-3,0,7', $this->getSetting('fee_reminder_offsets'));
        $this->assertSame('1', $this->getSetting('fee_reminders_enabled'));
        $this->assertContains('fee_reminders_enabled', \App\Http\Controllers\SuperAdmin\SettingController::SCHOOL_FIELDS);
        $this->assertContains('fee_reminder_offsets', \App\Http\Controllers\SuperAdmin\SettingController::ALLOWED_FIELDS);
    }

    public function test_settings_validation_refuses_bad_offsets_channels_and_both_channels_off()
    {
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload(['fee_reminder_offsets' => '99']))
            ->assertSessionHasErrors('fee_reminder_offsets');
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload(['fee_reminder_offsets' => '1,2,3,4,5,6']))
            ->assertSessionHasErrors('fee_reminder_offsets');
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload(['fee_reminder_channel_bell' => '2']))
            ->assertSessionHasErrors('fee_reminder_channel_bell');
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload([
                'fee_reminders_enabled' => '1',
                'fee_reminder_channel_bell' => '0',
                'fee_reminder_channel_whatsapp' => '0',
            ]))->assertSessionHasErrors('fee_reminder_channel_bell');
    }

    public function test_settings_sa_save_with_fee_key_refused_and_last_run_not_postable()
    {
        $this->setSetting('fee_reminders_enabled', '0');

        // SA carrying a school (fee) key: refused whole (a non-GET
        // school request from the SA is a hard 403 at PlatformOnly,
        // the same boundary every school write goes through).
        $this->actingAs($this->superAdmin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload(['fee_reminders_enabled' => '1']))
            ->assertForbidden();
        $this->assertSame('0', $this->getSetting('fee_reminders_enabled'));

        // fee_reminder_last_run posted by hand: unknown key, refused whole.
        $this->actingAs($this->admin())->from(route('settings'))
            ->put(route('settings.update'), $this->settingsPayload(['fee_reminder_last_run' => '2026-06-01']))
            ->assertSessionHas('flash_danger');
        $this->assertNull($this->getSetting('fee_reminder_last_run'));
    }

    /* ---------------- §9.12–14 plan views ---------------- */

    /** Parent + child with a 4-instalment plan, ₹4,400 paid of inst 2 etc. */
    protected function planFixture($tag = 'plan')
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00'));
        [$student, $sr, $parent] = $this->makeStudent($tag, true);
        // Head total 48,000 in 4 × 12,000. Paid 16,400: inst 1
        // fully covered, inst 2 covered 4,400 (FIFO), rest 0.
        [$payment, $record] = $this->makeFee($student->id, $tag, 48000, 16400);
        $head = $this->makeHead($payment, [
            [1, '2026-06-10', 12000],
            [2, '2026-09-10', 12000],
            [3, '2026-12-10', 12000],
            [4, '2027-03-10', 12000],
        ]);

        return [$student, $sr, $parent, $payment, $record, $head];
    }

    public function test_parent_sees_instalment_plan_with_fifo_coverage_and_statuses()
    {
        [$student, $sr, $parent, $payment, $record, $head] = $this->planFixture();

        $html = $this->actingAs($parent)->get(route('my_fees.show', $student->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Instalment Plan', $html);
        $this->assertStringContainsString('10 Jun 2026', $html);
        $this->assertStringContainsString('10 Dec 2026', $html);
        // FIFO coverage figures: 12,000 then 4,400 then 0.
        $this->assertStringContainsString('₹ 4,400', $html);
        $this->assertStringContainsString('Partially paid', $html);
        $this->assertStringContainsString('Upcoming', $html);
        $this->assertStringContainsString('Payments are applied to the earliest instalment first. For any other arrangement, contact the school office.', $html);
        // No concession yet: the concession line must not appear.
        $this->assertStringNotContainsString('Concession', $html);

        // List page carries only the one-line summary.
        $list = $this->actingAs($parent)->get(route('my_fees'))->assertOk()->getContent();
        $this->assertStringContainsString('1 of 4 instalments covered', $list);
        $this->assertStringContainsString('next due 10 Sep 2026', $list);
    }

    public function test_concession_line_appears_only_with_a_concession()
    {
        [$student, $sr, $parent, $payment, $record, $head] = $this->planFixture('conc');
        Concession::create([
            'student_record_id' => $sr->id, 'fee_head_id' => $head->id,
            'type' => 'amount', 'value' => 2000, 'reason' => 'F5 test',
        ]);

        $html = $this->actingAs($parent)->get(route('my_fees.show', $student->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Concession', $html);
        $this->assertStringContainsString('Net payable', $html);
        $this->assertStringContainsString('₹ 46,000', $html);
    }

    public function test_no_head_match_page_unchanged_and_foreign_child_forbidden()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 09:00:00'));
        [$student, $sr, $parent] = $this->makeStudent('noplan', true);
        $this->makeFee($student->id, 'noplan', 9000, 0); // no FeeHead

        $html = $this->actingAs($parent)->get(route('my_fees.show', $student->id))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString('Instalment Plan', $html);

        $otherParent = $this->makeUser('parent', 'foreign');
        $this->actingAs($otherParent)->get(route('my_fees.show', $student->id))->assertForbidden();
    }

    public function test_payment_history_method_resolution_and_student_plan()
    {
        [$student, $sr, $parent, $payment, $record, $head] = $this->planFixture('hist');
        Receipt::create([
            'pr_id' => $record->id, 'year' => Qs::getCurrentSession(),
            'amt_paid' => 12000, 'balance' => 36000, 'method' => null,
        ]);
        Receipt::create([
            'pr_id' => $record->id, 'year' => Qs::getCurrentSession(),
            'amt_paid' => 4400, 'balance' => 31600, 'method' => 'online_razorpay',
        ]);

        $html = $this->actingAs($parent)->get(route('my_fees.show', $student->id))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Payment History', $html);
        $this->assertStringContainsString('Online Razorpay', $html);
        $this->assertStringContainsString('Paid On', $html);
        $this->assertStringContainsString('Method', $html);

        // The student portal shows the identical plan section.
        $studentHtml = $this->actingAs($student)->get(route('student.fees'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Instalment Plan', $studentHtml);
        $this->assertStringContainsString('₹ 4,400', $studentHtml);
    }

    /* ---------------- §9.15 log page ---------------- */

    public function test_log_page_renders_hk_std_table_and_filters_narrow_it()
    {
        [$bellStudent] = $this->makeStudent('logbell');
        [$waStudent] = $this->makeStudent('logwa');
        $mk = function ($studentUser, $srId, $channel, $status, $amount) {
            return FeeReminderLog::create([
                'student_id' => $studentUser, 'student_record_id' => $srId,
                'year' => Qs::getCurrentSession(), 'channel' => $channel,
                'trigger' => 'scheduled', 'rule_offset' => 0, 'amount_due' => $amount,
                'status' => $status, 'dedupe_key' => 'test|' . Str::random(12),
            ]);
        };
        $srBell = StudentRecord::where('user_id', $bellStudent->id)->first();
        $srWa = StudentRecord::where('user_id', $waStudent->id)->first();
        $mk($bellStudent->id, $srBell->id, 'bell', 'sent', 14400);
        $mk($waStudent->id, $srWa->id, 'whatsapp', 'skipped_no_channel', 8000);

        $html = $this->actingAs($this->accountant())->get(route('payments.reminders'))
            ->assertOk()->getContent();
        $this->assertStringContainsString('hk-std-fee-reminders', $html);
        $this->assertStringContainsString('F5 Logbell', $html);
        $this->assertStringContainsString('₹ 14,400', $html);
        $this->assertStringContainsString('Skipped – no channel', $html);
        $this->assertStringContainsString('They run when this page or the dashboard is opened', $html);

        $filtered = $this->actingAs($this->accountant())
            ->get(route('payments.reminders', ['channel' => 'whatsapp']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('F5 Logwa', $filtered);
        $this->assertStringNotContainsString('F5 Logbell', $filtered);
    }

    /* ---------------- §9.16 opportunistic trigger ---------------- */

    public function test_opportunistic_trigger_runs_once_per_day_on_dashboard()
    {
        [$student] = $this->instalmentFixture('opport');
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        $this->actingAs($this->accountant())->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame('2026-06-15', $this->getSetting('fee_reminder_last_run'));

        $this->actingAs($this->accountant())->get(route('dashboard'))->assertOk();
        $this->assertSame(1, $this->bellsFor($student->id));
        $this->assertSame(1, $this->logsFor($student->id)->where('status', 'sent')->count());
    }

    /* ---------------- §9.17 ping endpoint ---------------- */

    public function test_ping_endpoint_token_gates()
    {
        [$student] = $this->instalmentFixture('ping');
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        // No token configured anywhere: disabled (404).
        Setting::where('type', 'fee_reminder_cron_token')->delete();
        $this->get('/cron/fee-reminders/whatever')->assertNotFound();

        $this->setSetting('fee_reminder_cron_token', 'f5-test-token');
        $this->get('/cron/fee-reminders/wrong-token')->assertForbidden();
        $this->assertSame(0, $this->bellsFor($student->id));

        $this->get('/cron/fee-reminders/f5-test-token')->assertOk();
        $this->assertSame(1, $this->bellsFor($student->id));
    }
}
