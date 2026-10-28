<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\GatewayTransaction;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent G round 4 — timetable preset workflow chain + Razorpay
 * settings → payments surface chain. Snapshot DB only; every change
 * rolls back via DatabaseTransactions.
 *
 * Preset write-path map (code-read, then proven below):
 * - The settings page has NO tt_gen_* field and settings.update
 *   REFUSES tt_gen_* keys (r1 whitelist, P16) — the school form is
 *   not the preset path.
 * - The timetable generate FORM is the preset path: it prefills from
 *   SchoolLevel::loadConfig (last-used values), and a successful
 *   generate run saves the used config back via saveConfig
 *   (scope=one → that class's level; scope=all → both levels).
 * - Exam-date generation never reads tt_gen_* (E43 correction).
 *
 * Razorpay path map (code-read, then proven below):
 * - Staff pay_now is counter bookkeeping, NOT the gateway. The
 *   gateway path is the parent checkout: my_fees.pay → order →
 *   pay_checkout view → my_fees.pay_callback (HMAC-verified).
 * - enabled() = gateway_enabled '1' AND a key id AND (mock OR a
 *   secret); mock = GATEWAY_MOCK config OR an rzp_test_ key id.
 */
class OwnerSettingsWorkflowRound4Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    private function basePayload(): array
    {
        return [
            'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->setting('current_session') ?? '2026-2027',
            'address' => $this->setting('address') ?? 'Madurai, Tamil Nadu, India',
        ];
    }

    /** Real school-form settings save (r1-proven shape). */
    private function putSettings(array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), $overrides));
    }

    private function presetA(): array
    {
        return [
            'start_time' => '08:30', 'period_minutes' => 30, 'periods_per_day' => 6,
            'lunch_after' => 3, 'lunch_minutes' => 30,
            'break_after' => 1, 'break_minutes' => 10, 'days' => 6,
        ];
    }

    private function presetB(): array
    {
        return [
            'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
            'lunch_after' => 4, 'lunch_minutes' => 45,
            'break_after' => 2, 'break_minutes' => 15, 'days' => 5,
        ];
    }

    /** Fresh class + 2 subjects so generate never hits a seeded
     *  attendance-locked record (Prep's record is locked, D26 rule). */
    private function freshClass(string $name): MyClass
    {
        $class = MyClass::create(['name' => $name, 'class_type_id' => 4]);
        Subject::create([
            'name' => $name . ' Alpha', 'my_class_id' => $class->id,
            'teacher_id' => 4, 'slug' => 'g4-' . $class->id . '-alpha',
            'periods_per_week' => 6,
        ]);
        Subject::create([
            'name' => $name . ' Beta', 'my_class_id' => $class->id,
            'teacher_id' => 4, 'slug' => 'g4-' . $class->id . '-beta',
            'periods_per_week' => 6,
        ]);

        return $class;
    }

    private function generatePayload(int $classId, array $cfg): array
    {
        return array_merge($cfg, [
            'scope' => 'one', 'class_id' => $classId, 'mode' => 'replace',
        ]);
    }

    private function slotsForClass(int $classId): array
    {
        $record = TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('section_id')->whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())->firstOrFail();

        return TimeSlot::where('ttr_id', $record->id)
            ->orderBy('timestamp_from')->pluck('time_from')->all();
    }

    private function presetSetting(string $level): ?array
    {
        $raw = $this->setting('tt_gen_' . $level);

        return $raw ? json_decode($raw, true) : null;
    }

    public function test_settings_update_still_refuses_tt_gen_keys()
    {
        $this->setSetting('tt_gen_primary', json_encode($this->presetA()));
        $before = $this->setting('tt_gen_primary');

        $this->putSettings(['tt_gen_primary' => json_encode($this->presetB())])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('flash_danger', 'ACCESS DENIED FOR UNAUTHORIZED ACTION');
        $this->assertSame($before, $this->setting('tt_gen_primary'));
    }

    public function test_generate_form_prefills_saved_primary_preset()
    {
        $this->setSetting('tt_gen_primary', json_encode($this->presetA()));

        $response = $this->actingAs($this->admin())
            ->get(route('tt.generate.form', ['class_id' => 1]));
        $response->assertOk();
        // assertEquals, not assertSame: loadConfig's array_merge puts
        // periods_per_day last, so key order differs — values are equal.
        $this->assertEquals($this->presetA(), $response->viewData('defaults'));
        $this->assertEquals($this->presetA(), $response->viewData('level_defaults')['primary']);
        $response->assertSee('value="08:30"', false);
    }

    public function test_generate_form_prefills_saved_secondary_preset()
    {
        $this->setSetting('tt_gen_secondary', json_encode($this->presetB()));

        $response = $this->actingAs($this->admin())
            ->get(route('tt.generate.form', ['class_id' => 7]));
        $response->assertOk();
        $this->assertEquals($this->presetB(), $response->viewData('defaults'));
        $response->assertSee('value="09:00"', false);
    }

    public function test_generate_run_follows_preset_and_remembers_it()
    {
        $class = $this->freshClass('G4 Prep');

        $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), $this->generatePayload($class->id, $this->presetA()))
            ->assertRedirect(route('tt.index'));

        // Slots follow preset A exactly: 08:30 start, 30-min periods,
        // 10-min break after P1, 30-min lunch after P3.
        $this->assertSame(
            ['8:30 AM', '9:10 AM', '9:40 AM', '10:40 AM', '11:10 AM', '11:40 AM'],
            $this->slotsForClass($class->id)
        );
        // And the run remembered A as the primary last-used preset.
        $this->assertSame($this->presetA(), $this->presetSetting('primary'));
    }

    public function test_explicit_form_override_wins_and_overwrites_preset()
    {
        $this->setSetting('tt_gen_primary', json_encode($this->presetA()));
        $class = $this->freshClass('G4 Prep Override');

        $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), $this->generatePayload($class->id, $this->presetB()))
            ->assertRedirect(route('tt.index'));

        // Slots follow the POSTED values (B), not the stored preset (A):
        // 09:00 start, 40-min periods, 15-min break after P2,
        // 45-min lunch after P4.
        $this->assertSame(
            ['9:00 AM', '9:40 AM', '10:35 AM', '11:15 AM', '12:40 PM', '1:20 PM'],
            $this->slotsForClass($class->id)
        );
        // And the stored preset is now B — the form is the memory.
        $this->assertSame($this->presetB(), $this->presetSetting('primary'));
    }

    public function test_secondary_generate_saves_only_secondary_preset()
    {
        $this->setSetting('tt_gen_primary', json_encode($this->presetA()));
        $class = $this->freshClass('G4 Secondary Class');

        $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), $this->generatePayload($class->id, $this->presetB()))
            ->assertRedirect(route('tt.index'));

        $this->assertSame($this->presetB(), $this->presetSetting('secondary'));
        $this->assertSame($this->presetA(), $this->presetSetting('primary'));
    }

    public function test_gateway_disabled_by_default_hides_pay_online()
    {
        $this->assertNull($this->setting('gateway_enabled'));

        $this->actingAs($this->parent())->get(route('my_fees'))
            ->assertOk()->assertDontSee('Pay Online');
        $this->actingAs($this->parent())->get(route('my_fees.show', 58))
            ->assertOk()->assertDontSee('Pay Online');
    }

    public function test_gateway_off_blocks_checkout()
    {
        $this->actingAs($this->parent())
            ->from(route('my_fees'))
            ->post(route('my_fees.pay', 4))
            ->assertRedirect(route('my_fees'))
            ->assertSessionHas('flash_danger', 'Online payment is not available right now.');
        $this->assertSame(0, GatewayTransaction::count());
    }

    public function test_gateway_enabled_without_key_still_blocks_checkout()
    {
        $this->putSettings(['gateway_enabled' => '1', 'razorpay_key_id' => ''])
            ->assertRedirect(route('settings'));
        $this->assertSame('1', $this->setting('gateway_enabled'));
        $this->assertNull($this->setting('razorpay_key_id'));

        $this->actingAs($this->parent())
            ->from(route('my_fees'))
            ->post(route('my_fees.pay', 4))
            ->assertRedirect(route('my_fees'))
            ->assertSessionHas('flash_danger', 'Online payment is not available right now.');
        $this->assertSame(0, GatewayTransaction::count());
    }

    public function test_test_key_enables_pay_online_and_mock_checkout()
    {
        $this->putSettings([
            'gateway_enabled' => '1', 'razorpay_key_id' => 'rzp_test_G4PROBE123',
        ])->assertRedirect(route('settings'));
        $this->assertSame('rzp_test_G4PROBE123', $this->setting('razorpay_key_id'));

        // Fees list + detail show a plain Pay Online button — NO
        // test-mode badge on either surface.
        $this->actingAs($this->parent())->get(route('my_fees'))
            ->assertOk()->assertSee('Pay Online');
        $this->actingAs($this->parent())->get(route('my_fees.show', 58))
            ->assertOk()->assertSee('Pay Online')->assertDontSee('Test mode');

        // Checkout is where mock-vs-live becomes visible: the banner
        // + Simulate buttons, a local order id, server-computed amount.
        $response = $this->actingAs($this->parent())
            ->post(route('my_fees.pay', 4));
        $response->assertOk();
        $this->assertTrue($response->viewData('mock'));
        $this->assertSame(10800, $response->viewData('amount'));
        $this->assertStringStartsWith('order_MOCK', $response->viewData('txn')->order_id);
        $response->assertSee('Test mode:', false);
        $response->assertSee('Simulate Successful Payment', false);
        $this->assertSame(1, GatewayTransaction::where('pr_id', 4)->where('status', 'initiated')->count());
    }

    public function test_mock_callback_records_payment_and_receipt()
    {
        $this->putSettings([
            'gateway_enabled' => '1', 'razorpay_key_id' => 'rzp_test_G4PROBE123',
        ])->assertRedirect(route('settings'));

        $checkout = $this->actingAs($this->parent())
            ->post(route('my_fees.pay', 4));
        $checkout->assertOk();

        $this->actingAs($this->parent())
            ->from(route('my_fees.show', 58))
            ->post(route('my_fees.pay_callback'), [
                'razorpay_order_id' => $checkout->viewData('txn')->order_id,
                'razorpay_payment_id' => $checkout->viewData('mock_payment_id'),
                'razorpay_signature' => $checkout->viewData('mock_signature'),
            ])
            ->assertRedirect(route('my_fees.receipt', 4))
            ->assertSessionHas('flash_success', 'Payment successful. Your receipt is ready.');

        $record = PaymentRecord::find(4);
        $this->assertSame(18000, (int) $record->amt_paid);
        $this->assertSame(1, (int) $record->paid);
        $this->assertTrue(Receipt::where('pr_id', 4)->exists());
        $this->assertSame(
            'captured',
            GatewayTransaction::where('pr_id', 4)->first()->status
        );
    }

    public function test_foreign_parent_checkout_refused()
    {
        $this->putSettings([
            'gateway_enabled' => '1', 'razorpay_key_id' => 'rzp_test_G4PROBE123',
        ])->assertRedirect(route('settings'));

        $foreign = PaymentRecord::where('year', Qs::getCurrentSession())
            ->whereNotIn('student_id', [57, 58])->with('payment')->get()
            ->first(function ($r) {
                return $r->payment && (int) $r->payment->amount > (int) $r->amt_paid;
            });
        $this->assertNotNull($foreign, 'Seeded fixture needs an outstanding foreign record.');

        $this->actingAs($this->parent())
            ->post(route('my_fees.pay', $foreign->id))
            ->assertForbidden();
        $this->assertSame(0, GatewayTransaction::where('pr_id', $foreign->id)->count());
    }
}
