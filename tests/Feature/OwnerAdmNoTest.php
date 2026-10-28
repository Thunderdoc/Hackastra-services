<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Admission-number lookup + generation options (owner order 2026-10-28):
 * (1) the Issue Certificate page finds a student by admission number;
 * (2) the school Admin picks how admission numbers are generated in
 * Settings ("Admission Numbers"). All runs on a snapshot DB; every
 * settings change rolls back via DatabaseTransactions.
 *
 * Generation contract locked here:
 * - No settings saved = the historic format APPCODE / CLASS / YEAR /
 *   SERIAL, first number 1000 (Qs::generateAdmNo defaults).
 * - adm_no_format switches the segments; adm_no_serial_start sets the
 *   floor of a FRESH series only (a started series keeps counting from
 *   its last number); adm_no_serial_width pads newly minted serials.
 * - Qs::admNoPrefix is the one prefix builder for minted AND typed
 *   numbers (controllers + both admit validators call it), so a typed
 *   suffix can never compose a different shape from a minted number.
 */
class OwnerAdmNoTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function setSetting(string $type, string $value): void
    {
        Setting::updateOrCreate(['type' => $type], ['description' => $value]);
    }

    private function clearAdmSettings(): void
    {
        Setting::whereIn('type', ['adm_no_format', 'adm_no_serial_start', 'adm_no_serial_width'])->delete();
    }

    /** Qs::$admNoSeq is a per-request cache — reset it so each test
     *  re-reads the (rolled-back) database instead of a stale prefix. */
    private function resetAdmSeq(): void
    {
        $ref = new \ReflectionProperty(Qs::class, 'admNoSeq');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    private function code(): string
    {
        return Qs::getAppCode();
    }

    public function test_default_format_is_historic_shape()
    {
        $this->clearAdmSettings();
        $this->resetAdmSeq();

        $this->assertSame($this->code() . '/ZZ/2099/1000', Qs::generateAdmNo('ZZ', '2099'));
    }

    public function test_format_code_year_serial()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'code_year_serial');
        $this->resetAdmSeq();

        $this->assertSame($this->code() . '/2099/1000', Qs::generateAdmNo('ZZ', '2099'));
    }

    public function test_format_year_serial_and_class_year_serial()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'year_serial');
        $this->resetAdmSeq();
        $this->assertSame('2099/1000', Qs::generateAdmNo('ZZ', '2099'));

        $this->setSetting('adm_no_format', 'class_year_serial');
        $this->resetAdmSeq();
        $this->assertSame('ZZ/2099/1000', Qs::generateAdmNo('ZZ', '2099'));

        $this->setSetting('adm_no_format', 'code_serial');
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/1000', Qs::generateAdmNo('ZZ', '2099'));
    }

    public function test_serial_start_only_affects_fresh_series()
    {
        $this->clearAdmSettings();
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2098/1000', Qs::generateAdmNo('ZZ', '2098'));

        // The series already started at 1000: raising the start to 5000
        // must NOT jump it — it keeps counting from its last number.
        // (Mint through the real path: a row under the prefix exists
        // only via the generator's own cache here, so re-seed from a
        // stored row instead — create the row, reset, generate again.)
        StudentRecord::query()->where('id', 1)->update(['adm_no' => $this->code() . '/ZZ/2098/1000']);
        $this->setSetting('adm_no_serial_start', '5000');
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2098/1001', Qs::generateAdmNo('ZZ', '2098'));

        // A genuinely fresh series starts at the new start value.
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/ZZ/2097/5000', Qs::generateAdmNo('ZZ', '2097'));
    }

    public function test_serial_width_pads_new_numbers()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_serial_start', '7');
        $this->setSetting('adm_no_serial_width', '4');
        $this->resetAdmSeq();

        $this->assertSame($this->code() . '/ZZ/2096/0007', Qs::generateAdmNo('ZZ', '2096'));
        $this->assertSame($this->code() . '/ZZ/2096/0008', Qs::generateAdmNo('ZZ', '2096'));
    }

    public function test_typed_prefix_follows_format()
    {
        $this->clearAdmSettings();
        $this->resetAdmSeq();
        $this->assertSame($this->code() . '/PN/2026/', Qs::admNoPrefix('PN', '2026'));

        $this->setSetting('adm_no_format', 'code_serial');
        $this->assertSame($this->code() . '/', Qs::admNoPrefix('PN', '2026'));

        $this->setSetting('adm_no_format', 'bogus_format');
        $this->assertSame($this->code() . '/PN/2026/', Qs::admNoPrefix('PN', '2026'));
    }

    public function test_settings_save_persists_adm_no_options()
    {
        $payload = [
            'system_name' => Setting::where('type', 'system_name')->first()->description,
            'current_session' => Setting::where('type', 'current_session')->first()->description,
            'address' => Setting::where('type', 'address')->first()->description,
            'adm_no_format' => 'code_year_serial',
            'adm_no_serial_start' => '5000',
            'adm_no_serial_width' => '4',
        ];

        // SettingController::update returns back(), so set the referer.
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), $payload)
            ->assertRedirect(route('settings'));

        $this->assertSame('code_year_serial', Setting::where('type', 'adm_no_format')->first()->description);
        $this->assertSame('5000', Setting::where('type', 'adm_no_serial_start')->first()->description);
        $this->assertSame('4', Setting::where('type', 'adm_no_serial_width')->first()->description);

        // An invalid format is a validation error, never a stored row.
        $payload['adm_no_format'] = 'not_a_format';
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), $payload)
            ->assertSessionHasErrors('adm_no_format');
        $this->assertSame('code_year_serial', Setting::where('type', 'adm_no_format')->first()->description);
    }

    public function test_certificate_create_preselects_by_adm_no()
    {
        $record = StudentRecord::find(1);
        $admNo = $record->adm_no;

        $page = $this->actingAs($this->admin())
            ->get(route('certificates.create', ['adm_no' => $admNo]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Find by Admission No.', $page);
        $this->assertStringContainsString('adm_no_lookup', $page);
        $this->assertStringContainsString('data-adm-no="' . $admNo . '"', $page);

        // Lowercase input resolves too (stored numbers are uppercase).
        $this->actingAs($this->admin())
            ->get(route('certificates.create', ['adm_no' => strtolower($admNo)]))
            ->assertOk()
            ->assertViewHas('preselected', function ($preselected) use ($record) {
                return $preselected && (int) $preselected->id === (int) $record->id;
            });

        // Unknown number: page still renders, nothing preselected.
        // (assertViewHas with null only checks the key exists, so the
        // null case needs the closure form.)
        $this->actingAs($this->admin())
            ->get(route('certificates.create', ['adm_no' => 'NOPE/0000']))
            ->assertOk()
            ->assertViewHas('preselected', function ($preselected) {
                return $preselected === null;
            });
    }
}
