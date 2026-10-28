<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Admission;
use App\Models\Exam;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent G round 6 — Academic Calendar / current_session propagation chain.
 * Patterns: P15 (chain handoff — one switch, asserted at every consumer),
 * P16 (settings mass-write, now whitelisted), P20 (consumer-form-only
 * config — current_session DOES have a settings-form path, proven below),
 * P4 (label vs control — the dropdown range finding below).
 *
 * All tests run on a snapshot DB (DatabaseTransactions); nothing here
 * touches the shared DB's real settings. The switch target is always
 * Qs::getNextSession() computed from the seeded value, never hardcoded.
 */
class OwnerSettingsWorkflowRound6Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function setting(string $type): ?string
    {
        $row = Setting::where('type', $type)->first();

        return $row ? $row->description : null;
    }

    private function basePayload(): array
    {
        return [
            'system_name' => $this->setting('system_name') ?? 'Hackstra Academy',
            'current_session' => $this->setting('current_session') ?? '2026-2027',
            'address' => $this->setting('address') ?? 'Madurai, Tamil Nadu, India',
        ];
    }

    private function putSettings(array $overrides = [])
    {
        return $this->actingAs($this->admin())
            ->from(route('settings'))
            ->put(route('settings.update'), array_merge($this->basePayload(), $overrides));
    }

    /** Switch the session via the REAL settings save; returns the new value. */
    private function switchSession(): string
    {
        $next = Qs::getNextSession();
        $this->putSettings(['current_session' => $next])
            ->assertRedirect(route('settings'));
        $this->assertSame($next, $this->setting('current_session'));
        $this->assertSame($next, Qs::getCurrentSession());

        return $next;
    }

    private function admitForm(): array
    {
        return [
            'name' => 'G6 Session Child',
            'gender' => 'Male',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => 1,
            'lga_id' => 1,
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08031110000',
        ];
    }

    // ---------- The settings-form path + its calendar-gated dropdown ----------

    public function test_settings_dropdown_offers_calendar_range_exactly()
    {
        $response = $this->actingAs($this->admin())->get(route('settings'));
        $response->assertOk();
        $html = $response->getContent();

        // The blade builds the range from date('Y'): (y-1)-y for
        // y in [Y-3, Y+1]. Extract the select's option values and
        // require EXACT equality with that range — the dropdown is
        // calendar-gated, not session-gated, whatever year this runs in.
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="current_session"[^>]*>(.*?)<\/select>/s',
            $html
        );
        preg_match('/<select[^>]*name="current_session"[^>]*>(.*?)<\/select>/s', $html, $m);
        preg_match_all('/<option value="([^"]+)"/', $m[1], $values);
        $year = (int) Carbon::now()->format('Y');
        $expected = [];
        for ($y = $year - 3; $y <= $year + 1; $y++) {
            $expected[] = ($y - 1).'-'.$y;
        }
        $this->assertSame($expected, $values[1]);
    }

    public function test_crafted_save_to_next_session_succeeds_and_shows_fallback_option()
    {
        $next = Qs::getNextSession();
        // Validation is required|string only (SettingUpdate), so a
        // session outside the dropdown range still saves.
        $this->putSettings(['current_session' => $next])
            ->assertRedirect(route('settings'));
        $this->assertSame($next, $this->setting('current_session'));

        $html = $this->actingAs($this->admin())->get(route('settings'))
            ->assertOk()->getContent();
        // Out-of-range current value renders via the @unless fallback.
        $this->assertStringContainsString($next.' (current)', $html);
    }

    public function test_blank_session_is_refused_and_value_unchanged()
    {
        $before = $this->setting('current_session');
        $this->putSettings(['current_session' => ''])
            ->assertSessionHasErrors('current_session');
        $this->assertSame($before, $this->setting('current_session'));
    }

    // ---------- Consumers that FOLLOW the switch ----------

    public function test_new_admit_gets_new_session_and_old_record_keeps_old()
    {
        $next = $this->switchSession();

        $this->actingAs($this->admin())
            ->post(route('students.store'), $this->admitForm())
            ->assertRedirect(route('students.list', 1));

        $new = StudentRecord::whereHas('user', function ($q) {
            $q->where('name', 'G6 Session Child');
        })->firstOrFail();
        $this->assertSame($next, $new->session);

        // The seeded record is untouched by the switch.
        $this->assertSame('2026-2027', StudentRecord::findOrFail(1)->session);
    }

    public function test_new_exam_gets_new_session_year()
    {
        $next = $this->switchSession();

        $this->actingAs($this->admin())
            ->post(route('exams.store'), ['name' => 'G6 Term 1 Exam', 'term' => 1])
            ->assertRedirect(route('exams.index'));

        $exam = Exam::where('name', 'G6 Term 1 Exam')->firstOrFail();
        $this->assertSame($next, $exam->year);
    }

    public function test_public_enquiry_gets_new_session()
    {
        $next = $this->switchSession();

        $this->post(route('admission_enquiry.store'), [
            'name' => 'G6 Parent',
            'phone' => '08031112222',
            'my_class_id' => 1,
            'message' => 'G6 session probe',
        ]);

        $row = Admission::where('name', 'G6 Parent')->firstOrFail();
        $this->assertSame($next, $row->session);
    }

    public function test_next_session_helper_moves_with_the_switch()
    {
        $next = $this->switchSession();
        $parts = explode('-', $next);
        $this->assertSame(((int) $parts[0] + 1).'-'.((int) $parts[1] + 1), Qs::getNextSession());
    }

    // ---------- Consumers that do NOT follow / prior data visibility ----------

    public function test_dashboard_student_count_ignores_session()
    {
        $next = $this->switchSession();
        $expected = StudentRecord::where('grad', 0)->count();
        $this->assertGreaterThan(0, $expected);

        // Counted under the NEW session, the KPI still counts every
        // non-graduated record, old session included — it is
        // all-time, not session-scoped.
        $this->actingAs($this->admin())->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('student_count', $expected);
        $this->assertSame($next, Qs::getCurrentSession());
    }

    public function test_prior_session_exams_vanish_from_pickers()
    {
        $this->switchSession();

        // Admit picker, marks bulk picker, date-sheet picker all filter
        // exams by year = current session, so with no new-session exam
        // yet they are all empty — the 2026-2027 exams are gone here.
        $this->actingAs($this->admin())->get(route('admit_cards.index'))
            ->assertOk()
            ->assertViewHas('exams', function ($exams) {
                return $exams->isEmpty();
            });
        $this->actingAs($this->admin())->get(route('marks.bulk'))
            ->assertOk()
            ->assertViewHas('exams', function ($exams) {
                return $exams->isEmpty();
            });
        $this->actingAs($this->admin())->get(route('exam_dates.index'))
            ->assertOk()
            ->assertViewHas('exams', function ($exams) {
                return $exams->isEmpty();
            });
    }

    public function test_prior_session_data_still_reachable()
    {
        $this->switchSession();

        // exams.index lists ALL years (no session filter).
        $this->actingAs($this->admin())->get(route('exams.index'))
            ->assertOk()
            ->assertViewHas('exams', function ($exams) {
                return $exams->contains('id', 1);
            });
        // The student list is not session-filtered either.
        $this->actingAs($this->admin())->get(route('students.list', 1))
            ->assertOk()
            ->assertSee('Aarav Sharma');
        // And the old marksheet is reachable by direct old-year URL.
        $this->actingAs($this->admin())
            ->get(route('marks.show', [Qs::hash(57), '2026-2027']))
            ->assertOk()
            ->assertSee('Aarav Sharma');
    }
}
