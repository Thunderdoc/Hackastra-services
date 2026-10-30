<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\AnalyticsController;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wave 2 feature 5 — Analytics Dashboard.
 *
 * Every aggregate below is checked against an independently
 * hand-computed value from a RAW SQL query over the seed — never
 * against the controller's own helpers or query builders.
 *
 * Route note: routes/web.php integration is done centrally by the
 * coordinator (this workstream creates files only), so setUp
 * registers the exact proposed route when it is not already present.
 * Once integrated, Route::has() is true and this registration is
 * skipped, so the test keeps passing unchanged.
 *
 * Run ONLY on the isolated snapshot (never the main DB):
 *   DB_DATABASE=/tmp/w2_analytics.sqlite php artisan test --filter=OwnerAnalyticsTest
 */
class OwnerAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!Route::has('analytics.index')) {
            Route::middleware(['web', 'auth', 'teamSAT'])
                ->get('analytics', [AnalyticsController::class, 'index'])
                ->name('analytics.index');
            // Runtime-added routes are invisible to route() until the
            // name look-up table is rebuilt (it is built at boot).
            Route::getRoutes()->refreshNameLookups();
            Route::getRoutes()->refreshActionLookups();
        }
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    public function test_admin_attendance_pct_matches_hand_computed_value()
    {
        // Hand computation, raw SQL: class id 1, October 2026.
        $byStatus = [];
        foreach (DB::select(
            "SELECT e.status AS status, COUNT(*) AS c
             FROM attendance_session_entries e
             JOIN attendance_sessions s ON s.id = e.session_id
             WHERE s.my_class_id = 1 AND strftime('%Y-%m', s.date) = '2026-10'
             GROUP BY e.status"
        ) as $row) {
            $byStatus[$row->status] = (int) $row->c;
        }
        $this->assertNotEmpty($byStatus, 'Seed must contain Class 1 October attendance');
        $marked = ($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0) + ($byStatus['absent'] ?? 0);
        $expectedPct = (int) round((($byStatus['present'] ?? 0) + ($byStatus['late'] ?? 0)) / $marked * 100);

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 1, 'from_month' => '2026-10', 'to_month' => '2026-10']));

        $response->assertOk();
        $response->assertViewHas('attendance', function ($rows) use ($expectedPct, $marked) {
            return count($rows) === 1
                && $rows[0]['pct'] === $expectedPct
                && $rows[0]['marked'] === $marked;
        });
        $response->assertSee($expectedPct . '%');
    }

    public function test_admin_fee_collection_and_outstanding_match_hand_computed_values()
    {
        $collected = (int) DB::selectOne(
            "SELECT COALESCE(SUM(pr.amt_paid), 0) AS tot
             FROM payment_records pr
             JOIN student_records sr ON sr.user_id = pr.student_id
             WHERE pr.paid = 1 AND sr.my_class_id = 1"
        )->tot;
        $outstanding = (int) DB::selectOne(
            "SELECT COALESCE(SUM(pr.balance), 0) AS tot
             FROM payment_records pr
             JOIN student_records sr ON sr.user_id = pr.student_id
             WHERE sr.my_class_id = 1"
        )->tot;
        $this->assertGreaterThan(0, $collected);

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 1]));

        $response->assertOk();
        $response->assertViewHas('feeTotal', $collected);
        $response->assertViewHas('feeOutstandingTotal', $outstanding);
        $response->assertSee(number_format($collected));
        $response->assertSee(number_format($outstanding));
    }

    public function test_marks_average_matches_hand_computed_exam_record_average()
    {
        // The app's own per-student figure is exam_records.ave; the
        // dashboard averages it per class+exam. Hand-compute likewise.
        $expected = (float) DB::selectOne(
            "SELECT AVG(CAST(ave AS REAL)) AS avg_ave
             FROM exam_records WHERE exam_id = 1 AND my_class_id = 1"
        )->avg_ave;
        $this->assertGreaterThan(0, $expected);

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 1, 'exam_id' => 1]));

        $response->assertOk();
        $response->assertViewHas('marks', function ($rows) use ($expected) {
            return count($rows) === 1
                && abs($rows[0]['avg'] - round($expected, 2)) < 0.01
                && $rows[0]['exam_name'] === 'Term 1 Examination';
        });
        $response->assertSee(number_format(round($expected, 2), 2));
    }

    public function test_class_filter_scopes_fee_totals_to_that_class_only()
    {
        $class2 = (int) DB::selectOne(
            "SELECT COALESCE(SUM(pr.amt_paid), 0) AS tot
             FROM payment_records pr
             JOIN student_records sr ON sr.user_id = pr.student_id
             WHERE pr.paid = 1 AND sr.my_class_id = 2"
        )->tot;
        $class1 = (int) DB::selectOne(
            "SELECT COALESCE(SUM(pr.amt_paid), 0) AS tot
             FROM payment_records pr
             JOIN student_records sr ON sr.user_id = pr.student_id
             WHERE pr.paid = 1 AND sr.my_class_id = 1"
        )->tot;
        $this->assertNotSame($class1, $class2, 'Seed classes must differ so scoping is observable');

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 2]));

        $response->assertOk();
        $response->assertViewHas('feeTotal', $class2);
        $response->assertDontSee(number_format($class1));
    }

    public function test_teacher_sees_own_classes_only_and_foreign_class_is_forbidden()
    {
        // Owner order 2026-10-30: the analytics dashboard was
        // REMOVED for teachers entirely — it is the school Admin's
        // page alone. Every teacher request is a plain 403 now
        // (scoped, foreign, or unfiltered alike).
        $teacher = $this->teacher();

        $this->actingAs($teacher)
            ->get(route('analytics.index', ['class_id' => 1]))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get(route('analytics.index', ['class_id' => 2]))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->get(route('analytics.index'))
            ->assertForbidden();
    }

    public function test_empty_data_classes_and_months_render_without_errors()
    {
        // Class 13 has fee + marks data; with zero period-attendance
        // sessions the attendance block must render its empty state,
        // not divide by zero. The zero-session condition is CREATED
        // here (owner CI fix 2026-10-29): the old test assumed the
        // seed left class 13 session-free, which was true only in
        // one dev database — a fresh seed gives it sessions and the
        // precondition failed before the real assertions ran. The
        // deletes roll back with the test transaction.
        DB::table('attendance_session_entries')->whereIn('session_id',
            DB::table('attendance_sessions')->where('my_class_id', 13)->pluck('id')
        )->delete();
        DB::table('attendance_sessions')->where('my_class_id', 13)->delete();
        $sessions = (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM attendance_sessions WHERE my_class_id = 13"
        )->c;
        $this->assertSame(0, $sessions);

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['class_id' => 13]));
        $response->assertOk();
        $response->assertViewHas('attendance', []);
        $response->assertSee('No attendance data');

        // A month range with no data anywhere: every block empty, no error.
        $empty = $this->actingAs($this->admin())
            ->get(route('analytics.index', ['from_month' => '2020-01', 'to_month' => '2020-02']));
        $empty->assertOk();
        $empty->assertViewHas('attendance', []);
        $empty->assertViewHas('fees', []);
        $empty->assertViewHas('feeTotal', 0);
        $empty->assertSee('No attendance data');
        $empty->assertSee('No fee collection');
    }

    public function test_denied_roles_and_guests_have_no_access()
    {
        $this->actingAs($this->accountant())
            ->get(route('analytics.index'))
            ->assertRedirect('/login');

        $this->actingAs($this->student())
            ->get(route('analytics.index'))
            ->assertRedirect('/login');

        $this->get(route('analytics.index'))->assertRedirect('/login');
    }
}
