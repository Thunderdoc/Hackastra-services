<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wave 2 feature 4 — BULK ID CARDS (whole-class/section PDF sheet).
 *
 * NOTE ON ROUTES: routes/web.php is integrated centrally by the
 * coordinator, so until then this test registers the EXACT proposed
 * route lines itself (same URIs, controller methods, names and
 * middleware) in setUp. Once the real routes land, these test-only
 * registrations resolve to the same controller/middleware and the
 * tests keep passing unchanged. URIs carry class/section as QUERY
 * params (class_id, section_id) — never as {id} params.
 *
 * Seed facts used (verified on the snapshot, never hardcoded ids for
 * users): teacher@hackstra.app teaches Prep (id 1) among others but
 * NOT Class 1 (id 2); Prep section A (id 1) holds 41 active students.
 * Users are resolved by email, classes/sections by the seeded ids
 * the task itself states (1 = Prep).
 */
class OwnerIdCardBulkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Proposed route lines (coordinator to add to routes/web.php
        // inside the auth + SupportTeam namespace groups, students prefix):
        Route::middleware(['web', 'auth', 'teamSAT'])
            ->group(function () {
                Route::get('students/id_cards/bulk', 'App\Http\Controllers\SupportTeam\IdCardController@bulkSelector')
                    ->name('students.id_cards.bulk');
                Route::get('students/id_cards/bulk/pdf', 'App\Http\Controllers\SupportTeam\IdCardController@bulk')
                    ->name('students.id_cards.bulk.pdf');
            });

        // This app's route name lookups are built at boot: routes
        // added after boot are invisible to route() until refreshed
        // (proven by probe: getByName() null until refreshNameLookups).
        Route::getRoutes()->refreshNameLookups();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function sectionRoster()
    {
        return StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->where('my_class_id', 1)
            ->where('section_id', 1)
            ->orderBy('adm_no')
            ->orderBy('id')
            ->get();
    }

    public function test_selector_page_ok_for_admin()
    {
        $response = $this->actingAs($this->admin())
            ->get('/students/id_cards/bulk');

        $response->assertOk();
        $response->assertSee('Bulk ID Cards');
        $response->assertSee('Prep');
    }

    public function test_selector_teacher_sees_only_own_classes()
    {
        $response = $this->actingAs($this->teacher())
            ->get('/students/id_cards/bulk');

        $response->assertOk();
        // Scope the check to the CLASS dropdown: section id 2 (Prep B)
        // legitimately appears in the section dropdown for this
        // teacher, so a page-wide value="2" check would false-positive.
        preg_match('/<select[^>]*id="bulk_class".*?<\/select>/s', $response->getContent(), $classSelect);
        $this->assertNotEmpty($classSelect, 'Class dropdown must render');
        $this->assertStringContainsString('value="1"', $classSelect[0]);
        // Class 1 (id 2) is foreign to this teacher: its option must
        // not appear in the class dropdown. (Closing quote, so ids
        // like 12 cannot false-positive.)
        $this->assertStringNotContainsString('value="2"', $classSelect[0]);
    }

    public function test_selector_roster_is_admission_ordered_for_admin()
    {
        $response = $this->actingAs($this->admin())
            ->get('/students/id_cards/bulk?class_id=1&section_id=1');

        $response->assertOk();
        $admNos = [];
        preg_match_all('/HA\/[A-Z]+\/\d+\/\d+/', $response->getContent(), $admNos);
        $admNos = array_values(array_unique($admNos[0]));
        $this->assertCount(41, $admNos, 'Prep section A roster must list its 41 students');
        $sorted = $admNos;
        sort($sorted);
        $this->assertSame($sorted, $admNos, 'Roster must be admission-number ordered');
    }

    public function test_selector_foreign_class_403_for_teacher()
    {
        $response = $this->actingAs($this->teacher())
            ->get('/students/id_cards/bulk?class_id=2');

        $response->assertForbidden();
    }

    public function test_bulk_pdf_returns_pdf_for_admin_on_real_section()
    {
        $response = $this->actingAs($this->admin())
            ->get('/students/id_cards/bulk/pdf?class_id=1&section_id=1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
    }

    public function test_bulk_pdf_whole_class_returns_pdf_for_admin()
    {
        // Whole class, no section filter: all 121 active Prep students.
        $response = $this->actingAs($this->admin())
            ->get('/students/id_cards/bulk/pdf?class_id=1');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF', substr($response->getContent(), 0, 4));
    }

    public function test_bulk_pdf_teacher_own_class_200_and_foreign_403()
    {
        $own = $this->actingAs($this->teacher())
            ->get('/students/id_cards/bulk/pdf?class_id=1&section_id=1');
        $own->assertOk();
        $own->assertHeader('Content-Type', 'application/pdf');

        $foreign = $this->actingAs($this->teacher())
            ->get('/students/id_cards/bulk/pdf?class_id=2');
        $foreign->assertForbidden();
    }

    public function test_bulk_pdf_unknown_class_404()
    {
        $response = $this->actingAs($this->admin())
            ->get('/students/id_cards/bulk/pdf?class_id=9999');

        $response->assertNotFound();
    }

    public function test_bulk_pdf_view_card_count_matches_roster_size()
    {
        $students = $this->sectionRoster();
        $this->assertCount(41, $students);

        $html = view('pages.support_team.students.id_card_bulk_pdf', [
            'students' => $students,
            'my_class' => $students->first()->my_class,
            'section' => $students->first()->section,
            'school' => 'Hackstra Academy',
            'session' => '2026-2027',
        ])->render();

        $this->assertSame(
            41,
            substr_count($html, '<div class="bdc-card">'),
            'One card per roster student, no more, no fewer'
        );
    }

    public function test_bulk_pdf_has_no_qr_and_no_stock_photo()
    {
        // No QR library exists in this app, so no QR may be claimed
        // or faked anywhere in the bulk card output.
        $students = $this->sectionRoster();

        $html = view('pages.support_team.students.id_card_bulk_pdf', [
            'students' => $students,
            'my_class' => $students->first()->my_class,
            'section' => $students->first()->section,
            'school' => 'Hackstra Academy',
            'session' => '2026-2027',
        ])->render();

        $this->assertStringNotContainsStringIgnoringCase('QR', $html);
        $this->assertStringNotContainsStringIgnoringCase('qrcode', $html);
        // Photo rule: the stock silhouette must never pose as a photo.
        $this->assertStringNotContainsString('user.png', $html);
        // ...but the school name and a real admission number must be there.
        $this->assertStringContainsString('Hackstra Academy', $html);
        $this->assertStringContainsString($students->first()->adm_no, $html);
    }
}
