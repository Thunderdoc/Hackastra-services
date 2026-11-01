<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Setting;
use App\User;
use Tests\TestCase;

/**
 * Owner fix 2026-10-30: printed documents carried the factory
 * placeholder "Tel: 0123456789" on the school letterhead.
 * Qs::getSchoolPhone() now returns NULL for a blank phone and
 * for the placeholder, and every document data builder routes
 * through it — documents simply omit the Tel segment. These
 * pins prove the three states on the two document families the
 * owner saw: the monthly attendance document and a report card.
 * (The real-number leg for more surfaces is also pinned by
 * OwnerSettingsWorkflowRound5Test.)
 */
class OwnerDocumentPhoneGuardTest extends TestCase
{
    protected const REAL_PHONE = '+91 98765 43210';

    protected function tearDown(): void
    {
        // Leave the setting exactly as the seed ships it, whatever
        // a leg changed — the suite shares one seeded database.
        Setting::updateOrCreate(['type' => 'phone'], ['description' => self::REAL_PHONE]);

        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function setPhone(string $value): void
    {
        Setting::updateOrCreate(['type' => 'phone'], ['description' => $value]);
    }

    protected function monthlyPrint()
    {
        return $this->actingAs($this->admin())
            ->get(route('attendance.report.print', ['class_id' => 1]));
    }

    protected function reportCardPrint()
    {
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();

        return $this->actingAs($this->admin())
            ->get('/marks/print/' . Qs::hash($student->id) . '/1/2026-2027');
    }

    public function test_get_school_phone_helper_states()
    {
        $this->setPhone('0123456789');
        $this->assertNull(Qs::getSchoolPhone());

        $this->setPhone(' 0123 456 789 ');
        $this->assertNull(Qs::getSchoolPhone());

        $this->setPhone('');
        $this->assertNull(Qs::getSchoolPhone());

        $this->setPhone(self::REAL_PHONE);
        $this->assertSame(self::REAL_PHONE, Qs::getSchoolPhone());
    }

    public function test_monthly_document_omits_tel_for_placeholder_and_blank()
    {
        $this->setPhone('0123456789');
        $this->monthlyPrint()->assertOk()
            ->assertDontSee('0123456789')
            ->assertDontSee('Tel:', false);

        $this->setPhone('');
        $this->monthlyPrint()->assertOk()
            ->assertDontSee('Tel:', false);
    }

    public function test_monthly_document_shows_tel_for_a_real_number()
    {
        $this->setPhone(self::REAL_PHONE);
        $this->monthlyPrint()->assertOk()
            ->assertSee('Tel: ' . self::REAL_PHONE, false);
    }

    public function test_report_card_omits_tel_for_placeholder_and_shows_it_for_a_real_number()
    {
        $this->setPhone('0123456789');
        $this->reportCardPrint()->assertOk()
            ->assertDontSee('0123456789')
            ->assertDontSee('Tel:', false);

        $this->setPhone(self::REAL_PHONE);
        $this->reportCardPrint()->assertOk()
            ->assertSee('Tel: ' . self::REAL_PHONE, false);
    }
}
