<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-30: "if school want they can have adm no
 * in numerical" — a school may pick a numbers-only admission
 * number style in Settings (Admission Numbers → "Numbers only").
 * The number is then the serial itself: no school code, no
 * class, no year, no slashes. The series continues from the
 * highest purely numeric number already issued (composite
 * numbers from an older style never pollute it), the configured
 * start and zero-padding still apply, and a hand-typed number
 * with letters is refused so the style cannot drift.
 */
class OwnerAdmNoNumericOnlyTest extends TestCase
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

    private function resetAdmSeq(): void
    {
        $ref = new \ReflectionProperty(Qs::class, 'admNoSeq');
        $ref->setAccessible(true);
        $ref->setValue(null, []);
    }

    public function test_numbers_only_mints_plain_numbers_counting_up()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'serial_only');
        $this->resetAdmSeq();

        $this->assertSame('', Qs::admNoPrefix('PN', '2026'));
        $first = Qs::generateAdmNo('ZZ', '2099');
        $second = Qs::generateAdmNo('ZZ', '2099');
        $this->assertSame('1000', $first);
        $this->assertSame('1001', $second);
        $this->assertTrue(ctype_digit($first));
    }

    public function test_numeric_series_continues_from_highest_numeric_and_ignores_composites()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'serial_only');

        StudentRecord::query()->where('id', 1)->update(['adm_no' => '1007']);
        StudentRecord::query()->where('id', 2)->update(['adm_no' => 'HA/ZZ/2099/5000']);
        $this->resetAdmSeq();

        // 5000 belongs to a composite series — the numeric series
        // must continue from 1007, not jump to 5001.
        $this->assertSame('1008', Qs::generateAdmNo('ZZ', '2099'));
    }

    public function test_numbers_only_still_honours_start_and_zero_padding()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'serial_only');
        $this->setSetting('adm_no_serial_start', '7');
        $this->setSetting('adm_no_serial_width', '4');
        $this->resetAdmSeq();

        $this->assertSame('0007', Qs::generateAdmNo('ZZ', '2099'));
    }

    public function test_settings_save_accepts_numbers_only()
    {
        $payload = [
            'system_name' => Setting::where('type', 'system_name')->first()->description,
            'current_session' => Setting::where('type', 'current_session')->first()->description,
            'address' => Setting::where('type', 'address')->first()->description,
            'adm_no_format' => 'serial_only',
        ];
        $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), $payload)
            ->assertRedirect(route('settings'));
        $this->assertSame('serial_only', Setting::where('type', 'adm_no_format')->first()->description);
    }

    public function test_typed_number_must_be_digits_only_under_numbers_only()
    {
        $this->clearAdmSettings();
        $this->setSetting('adm_no_format', 'serial_only');
        $this->resetAdmSeq();

        $form = [
            'name' => 'Numeric Only Child',
            'gender' => 'Female',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => \App\Models\State::where('name', 'Tamil Nadu')->value('id'),
            'lga_id' => null,
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08039990000',
        ];

        $this->actingAs($this->admin())
            ->post(route('students.store'), array_merge($form, ['adm_no' => 'AB123']))
            ->assertSessionHasErrors('adm_no');

        $ok = $this->actingAs($this->admin())
            ->post(route('students.store'), array_merge($form, ['adm_no' => '1007']));
        $ok->assertRedirect();
        $ok->assertSessionDoesntHaveErrors('adm_no');
        $record = StudentRecord::where('adm_no', '1007')->first();
        $this->assertNotNull($record, 'The typed numeric admission number must be stored as-is');
    }
}
