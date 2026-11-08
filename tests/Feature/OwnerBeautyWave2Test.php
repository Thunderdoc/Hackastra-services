<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Dorm;
use App\Models\HealthRecord;
use App\Models\HostelRoom;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Beauty Wave 2 (2026-11-06, 50/50 phase):
 * (1) The slim module banner (partials/module_banner, Wave 1)
 *     opens Students / Payments / Library too — its figures are
 *     the same numbers the pages already carry (scope counts on
 *     the students landing, the session KPI figures on Payments,
 *     the whole-catalogue $stats on Books), never new queries
 *     dressed up as stats.
 * (2) The nurse home gains a second row from the same
 *     health_records ledger: latest referrals (student, date,
 *     referred-to) and the current month at a glance (visits per
 *     week of the month + the busiest weekday), with honest
 *     pat-empty states when the clinic has nothing yet.
 * (3) The Hostel Warden gets the role's missing home: the shared
 *     role banner (10th illustration, hk-bn-hostel), stat cards
 *     from the hostel ledger (dorms, rooms, beds, allocated,
 *     occupancy) and a recent-allocations list. The demo seed has
 *     NO warden login — the warden here is a test-created user,
 *     resolved by email like everywhere else; the home renders
 *     for the 'hostel' user_type whenever a school creates one.
 * (4) Stretch: the Principal home's four executive KPIs move off
 *     the private pr-kpi island onto the shared dashboard-stat-card
 *     pattern — same labels, values, subtexts and links.
 * Presentation + additive view data only: no form, field, route
 * or POST behaviour changes anywhere in this wave.
 */
class OwnerBeautyWave2Test extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function warden(): User
    {
        // Same pattern as OwnerHostelTest: a plain users row with
        // user_type 'hostel' (no user_types seed row exists).
        if (!User::where('email', 'warden@hackstra.app')->exists()) {
            DB::table('users')->insert([
                'name' => 'Warden Test',
                'email' => 'warden@hackstra.app',
                'code' => 'WARDEN1',
                'username' => 'warden',
                'password' => bcrypt('hackstra'),
                'user_type' => 'hostel',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->user('warden@hackstra.app');
    }

    /* ---------- (1) slim module banners ---------- */

    public function test_students_landing_carries_the_slim_banner_with_scope_counts()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.list'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('Student Information', $html);
        $this->assertStringContainsString('Search any student across classes', $html);
        // The figures are the view's own scope data: every class and
        // section in the seed for an admin.
        $this->assertStringContainsString('<b>' . MyClass::count() . '</b>', $html);
        $this->assertStringContainsString('<b>' . Section::count() . '</b>', $html);
        $this->assertStringContainsString('Session ' . Qs::getCurrentSession(), $html);
    }

    public function test_students_landing_banner_is_scoped_for_a_teacher()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('students.list'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('My Classes', $html);
    }

    public function test_payments_index_carries_the_slim_banner_with_session_stats()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('payments.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('hk-mod-ic-green', $html);
        $this->assertStringContainsString('Manage Payments', $html);
        $this->assertStringContainsString('Collected', $html);
        $this->assertStringContainsString('Pending Dues', $html);
        $this->assertStringContainsString('Defaulters', $html);
        $this->assertStringContainsString('Session ' . Qs::getCurrentSession(), $html);
    }

    public function test_books_index_carries_the_slim_banner_with_catalogue_stats()
    {
        $html = $this->actingAs($this->user('librarian@hackstra.app'))
            ->get(route('books.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-mod-banner', $html);
        $this->assertStringContainsString('hk-mod-ic-purple', $html);
        $this->assertStringContainsString('Library Books', $html);
        $this->assertStringContainsString('Titles', $html);
        $this->assertStringContainsString('Available', $html);
        // The toolbar subtitle moved into the banner: the sentence
        // has exactly one home on the page now.
        $this->assertSame(1, substr_count($html, 'Manage your library collection'));
    }

    public function test_principal_kpis_sit_on_the_shared_stat_card_pattern()
    {
        $html = $this->actingAs($this->user('principal@hackstra.app'))
            ->get(route('dashboard'))->assertOk()->getContent();

        /* Re-expressed 2026-11-08: the owner approved principal
           home sample v2 ("principal home aisa hi banao"), whose
           KPI row is four pastel tiles (Students / Staff /
           Attendance / Fees) — the Wave 2 shared stat-card row and
           its Academics + Attention tiles are superseded by that
           pick; the attention and academics data still live in the
           Approval Center and the attention strip. The original
           promise stands in the new shape: the pre-Wave-2 private
           island (pr-kpi) stays gone, the four headline figures
           render with their labels, and the tile destinations stay
           one tap away in the sidebar. */
        $this->assertStringNotContainsString('pr-kpi', $html);
        $this->assertStringContainsString('pr2-kpi', $html);
        $this->assertStringContainsString('Total Students', $html);
        $this->assertStringContainsString('Teaching Staff', $html);
        $this->assertStringContainsString('Attendance', $html);
        $this->assertStringContainsString('Fees Collected', $html);
        $this->assertStringContainsString(route('users.index'), $html);
        $this->assertStringContainsString(route('exams.index'), $html);
        $this->assertStringContainsString(route('leave.index'), $html);
    }

    /* ---------- (2) nurse home second row ---------- */

    public function test_nurse_home_second_row_shows_referrals_and_month_glance()
    {
        $nurse = $this->user('nurse@hackstra.app');
        $student = User::where('user_type', 'student')->firstOrFail();
        $today = Carbon::now();

        HealthRecord::create([
            'student_id' => $student->id,
            'visit_date' => $today->toDateString(),
            'complaint' => 'Wave2 fixture complaint',
            'treatment' => 'Wave2 fixture treatment',
            'is_referred' => 1,
            'referred_to' => 'Wave2 Referral Hospital',
            'recorded_by' => $nurse->id,
        ]);
        // Four more visits today, so today is the month's busiest
        // weekday by a clear margin.
        for ($i = 0; $i < 4; $i++) {
            HealthRecord::create([
                'student_id' => $student->id,
                'visit_date' => $today->toDateString(),
                'complaint' => 'Wave2 fixture complaint ' . $i,
                'treatment' => 'Wave2 fixture treatment',
                'is_referred' => 0,
                'referred_to' => null,
                'recorded_by' => $nurse->id,
            ]);
        }

        $html = $this->actingAs($nurse)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Referred for Outside Care', $html);
        $this->assertStringContainsString('Wave2 Referral Hospital', $html);
        $this->assertStringContainsString($student->name, $html);
        $this->assertStringContainsString('This Month at a Glance', $html);
        $this->assertStringContainsString('Week ' . (int) ceil($today->day / 7), $html);
        $this->assertStringContainsString('Most visits fall on', $html);
        $this->assertStringContainsString($today->format('l') . 's', $html);
    }

    public function test_nurse_home_second_row_empty_states_are_honest()
    {
        // The seed carries no health records and every creating
        // test is transactional, so the clinic is genuinely empty.
        $this->assertSame(0, HealthRecord::count());

        $html = $this->actingAs($this->user('nurse@hackstra.app'))
            ->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Referred for Outside Care', $html);
        $this->assertStringContainsString('No students have been referred for outside care yet.', $html);
        $this->assertStringContainsString('This Month at a Glance', $html);
        $this->assertStringContainsString('No clinic visits recorded this month yet.', $html);
    }

    /* ---------- (3) warden home ---------- */

    public function test_warden_home_renders_banner_stats_and_recent_allocations()
    {
        $warden = $this->warden();

        $dorm = Dorm::create(['name' => 'Wave2 Test Hostel']);
        HostelRoom::create(['dorm_id' => $dorm->id, 'room_no' => 'W2-1', 'capacity' => 4]);
        HostelRoom::create(['dorm_id' => $dorm->id, 'room_no' => 'W2-2', 'capacity' => 6]);

        $records = StudentRecord::where('grad', 0)->whereNull('dorm_id')->take(2)->get();
        $this->assertCount(2, $records);
        foreach ($records as $record) {
            $record->update(['dorm_id' => $dorm->id, 'dorm_room_no' => 'W2-1']);
        }

        $beds = (int) HostelRoom::sum('capacity');
        $allocated = StudentRecord::whereNotNull('dorm_id')->count();
        $pct = $beds > 0 ? (int) round($allocated / $beds * 100) : 0;

        $html = $this->actingAs($warden)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-banner-hero', $html);
        $this->assertStringContainsString('hk-bn-hostel', $html);
        $this->assertStringContainsString('Hostel Warden', $html);
        $this->assertStringContainsString('Dorms', $html);
        $this->assertStringContainsString('Total Beds', $html);
        $this->assertStringContainsString('<strong>' . number_format($beds) . '</strong>', $html);
        $this->assertStringContainsString('Students Allocated', $html);
        $this->assertStringContainsString('Occupancy', $html);
        $this->assertStringContainsString('<strong>' . $pct . '%</strong>', $html);
        $this->assertStringContainsString('Recent Allocations', $html);
        $this->assertStringContainsString('Wave2 Test Hostel', $html);
        $this->assertStringContainsString($records->first()->user->name, $html);
        // The warden does NOT land on the school-wide board any more.
        $this->assertStringNotContainsString('Total Students', $html);
    }

    public function test_warden_home_empty_state_when_nothing_is_allocated()
    {
        $this->assertSame(0, StudentRecord::whereNotNull('dorm_id')->count());

        $html = $this->actingAs($this->warden())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-bn-hostel', $html);
        $this->assertStringContainsString('No students are allocated to the hostel yet.', $html);
    }
}
