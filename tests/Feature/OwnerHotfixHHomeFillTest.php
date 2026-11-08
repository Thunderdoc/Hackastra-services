<?php

namespace Tests\Feature;

use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ExamRecord;
use App\Models\LeaveRequest;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HOTFIX H (2026-11-08, owner screenshots image-153 + image-154).
 *
 * Student home: the two dashboard columns stretched to the row
 * height but their panels were height:auto, so the shorter column
 * ended in a slab of bare page background (measured 152px at
 * 1920px, 162px at 1366px, above the full-width Reminders panel).
 * The fix is layout-only — the columns become flex columns at xl
 * widths and each column's last panel grows to the row bottom,
 * spreading its list rows. These pins hold the layout hooks in
 * place and re-pin what the home has always promised: the blocks
 * show the SIGNED-IN student's own class subjects/teachers and
 * the student's own attendance figures, nothing from other
 * classes.
 *
 * Principal home: the analytics bars were 12px near-black slabs
 * (workload widths pinned at 100% for anyone with 10+ registers,
 * so every row looked identical) and the Term 1 class averages
 * were a wall of chips. Bars are now slim app-blue pills (tier
 * colours only below the settled attendance tiers), workload
 * widths are relative to the top teacher, and the averages are a
 * ranked compact table. The Finance "Fees outstanding" tile now
 * carries the same (year) scope its sibling tile always had —
 * both sums are payment_records scoped to the current calendar
 * year (see HomeController); no total changed.
 */
class OwnerHotfixHHomeFillTest extends TestCase
{
    use DatabaseTransactions;

    private function css(): string
    {
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function studentRecord(string $email): StudentRecord
    {
        return StudentRecord::where('user_id', $this->user($email)->id)
            ->where('grad', 0)->firstOrFail();
    }

    // ---- Student home: layout hooks ----

    public function test_student_home_columns_carry_the_fill_layout()
    {
        $response = $this->actingAs($this->user('student@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('stu-home-cols', false);
        $response->assertSee('hk-col-stack', false);

        $css = $this->css();
        $this->assertStringContainsString('.row.stu-home-cols>[class*=col].hk-col-stack{display:flex;flex-direction:column}', $css);
        $this->assertStringContainsString('>.dashboard-panel:last-of-type{flex:1 0 auto', $css);
    }

    // ---- Student home: own data only ----

    public function test_student_home_shows_only_own_class_subjects_and_teachers()
    {
        $record = $this->studentRecord('student@hackstra.app');
        $subjects = Subject::with('teacher')->where('my_class_id', $record->my_class_id)->orderBy('name')->get();
        $this->assertGreaterThan(0, $subjects->count());

        $response = $this->actingAs($this->user('student@hackstra.app'))->get('/home');
        $response->assertOk();
        foreach ($subjects as $subject) {
            $response->assertSee($subject->name);
            if ($subject->teacher) {
                $response->assertSee($subject->teacher->name);
            }
        }

        // A subject that exists only in another class must not leak in.
        $ownNames = $subjects->pluck('name')->all();
        $foreign = Subject::where('my_class_id', '!=', $record->my_class_id)
            ->whereNotIn('name', $ownNames)->orderBy('id')->first();
        if ($foreign) {
            $response->assertDontSee($foreign->name);
        }
    }

    public function test_student_home_attendance_figures_match_the_entries()
    {
        $record = $this->studentRecord('student@hackstra.app');
        $rows = AttendanceSessionEntry::where('student_record_id', $record->id)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->pluck('total', 'status');
        $present = (int) ($rows['present'] ?? 0);
        $late = (int) ($rows['late'] ?? 0);
        $absent = (int) ($rows['absent'] ?? 0);
        $total = $present + $late + $absent;
        $this->assertGreaterThan(0, $total);
        $percent = (int) round((($present + $late) / $total) * 100);

        $response = $this->actingAs($this->user('student@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('stu-attendance-percent">' . $percent . '%<', false);
        $response->assertSee('stu-dot-present"></i> Present <strong>' . number_format($present) . '</strong>', false);
        $response->assertSee('stu-dot-late"></i> Late <strong>' . number_format($late) . '</strong>', false);
        $response->assertSee('stu-dot-absent"></i> Absent <strong>' . number_format($absent) . '</strong>', false);
    }

    public function test_another_class_student_sees_their_own_subjects_not_prep()
    {
        $prep = $this->studentRecord('student@hackstra.app');
        $other = StudentRecord::where('grad', 0)
            ->where('my_class_id', '!=', $prep->my_class_id)
            ->whereHas('user', function ($q) {
                $q->where('user_type', 'student');
            })
            ->orderBy('my_class_id')->firstOrFail();
        $otherUser = User::findOrFail($other->user_id);

        $ownNames = Subject::where('my_class_id', $other->my_class_id)->pluck('name')->all();
        $this->assertNotEmpty($ownNames);
        $prepOnly = Subject::where('my_class_id', $prep->my_class_id)
            ->whereNotIn('name', $ownNames)->orderBy('id')->first();

        $response = $this->actingAs($otherUser)->get('/home');
        $response->assertOk();
        $response->assertSee($ownNames[0]);
        if ($prepOnly) {
            $response->assertDontSee($prepOnly->name);
        }
    }

    // ---- Principal home: presentation ----

    public function test_principal_class_averages_render_as_a_ranked_table()
    {
        $term1 = Exam::where('term', 1)->orderBy('id')->firstOrFail();
        $rows = ExamRecord::where('exam_id', $term1->id)
            ->join('student_records', 'student_records.user_id', '=', 'exam_records.student_id')
            ->whereNotNull('exam_records.ave')->where('exam_records.ave', '!=', '')
            ->select('student_records.my_class_id', DB::raw('AVG(CAST(exam_records.ave AS DECIMAL(10,2))) as avg'))
            ->groupBy('student_records.my_class_id')->get();
        $names = MyClass::whereIn('id', $rows->pluck('my_class_id'))->pluck('name', 'id');
        $ranked = $rows->map(function ($row) use ($names) {
            return ['class' => $names[$row->my_class_id] ?? ('Class ' . $row->my_class_id), 'avg' => round((float) $row->avg, 1)];
        })->sortByDesc('avg')->values();
        $this->assertGreaterThan(2, $ranked->count());

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $html = $response->getContent();

        $start = strpos($html, '— class averages');
        $end = strpos($html, 'Top subjects by');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $block = substr($html, $start, $end - $start);

        /* Re-expressed 2026-11-08 for the owner-approved principal
           home sample v2 ("principal home aisa hi banao"): the
           ranked table became a top-3 PODIUM over a compact ranked
           list. The promise is unchanged and now stronger — every
           class appears exactly once, in ranked order, ties sharing
           a podium slot, and no chips inside the ranking block.
           Rank numbers follow the sample's rule: each podium
           tie-group counts as ONE entrant, then competition
           counting continues over the individual classes. */
        $this->assertStringNotContainsString('pr-chip', $block);
        $groups = [];
        foreach ($ranked as $row) {
            $groups[(string) $row['avg']][] = $row['class'];
        }
        $groups = array_values($groups);
        $podiumGroups = array_slice($groups, 0, 3);
        $listGroups = array_slice($groups, 3);

        // Podium slots in DOM order (2nd, 1st, 3rd) hold the top
        // three average groups, ties named jointly.
        preg_match_all('/pr2-pod (pr2-p\d)">.*?pr2-pod-cl">(.*?)<\/div>\s*<div class="pr2-pod-av">([\d.]+)/s', $block, $pm);
        $domOrder = count($podiumGroups) === 1 ? [0] : (count($podiumGroups) === 2 ? [1, 0] : [1, 0, 2]);
        $this->assertCount(count($domOrder), $pm[2]);
        foreach ($domOrder as $i => $gi) {
            $this->assertSame(implode(' · ', $podiumGroups[$gi]), html_entity_decode($pm[2][$i]));
            $this->assertEquals($ranked->firstWhere('class', $podiumGroups[$gi][0])['avg'], (float) $pm[3][$i]);
        }

        // The compact list under the podium: exact (rank, class)
        // sequence, so order AND numbering are both pinned.
        preg_match_all('/pr2-avg-rk">(\d+)<\/div><div class="pr2-avg-nm">([^<]+)<\/div>/', $block, $lm);
        $expectedList = [];
        $entrants = count($podiumGroups);
        foreach ($listGroups as $group) {
            foreach ($group as $class) {
                $expectedList[] = [(string) ($entrants + 1), $class];
            }
            $entrants += count($group);
        }
        $actualList = [];
        foreach ($lm[1] as $i => $rank) {
            $actualList[] = [$rank, html_entity_decode($lm[2][$i])];
        }
        $this->assertSame($expectedList, $actualList);

        // The Top subjects line and its chips survive untouched.
        $response->assertSee('Top subjects by Term 1 average');
        $response->assertSee('pr-chip', false);
    }

    public function test_principal_bars_are_slim_app_blue_with_tier_colours()
    {
        $blade = file_get_contents(resource_path('views/pages/principal/home.blade.php'));

        /* Re-expressed 2026-11-08 for the owner-approved principal
           home sample v2: the HOTFIX H pr-bar system retired with
           the old analytics markup. The fills are now the sample's
           slim rounded bars — attendance in the green gradient
           family (tier tints only where genuinely low), class
           averages in the app-blue gradient, workload in
           per-teacher pastel colours — each a real box whose track
           carries both-theme backgrounds. */
        $this->assertStringContainsString('.pr2-ca-fill{height:100%;border-radius:99px}', $blade);
        $this->assertStringContainsString('linear-gradient(90deg,#3ecf8e,#059669)', $blade);
        $this->assertStringContainsString('.pr2-avg-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#7fb0f5,#3478f6)}', $blade);
        $this->assertStringContainsString('.pr2-wl-fill{height:100%;border-radius:99px}', $blade);
        $this->assertStringContainsString('.pr2-ca-bar{flex:1;height:11px;border-radius:99px;overflow:hidden;background:#edf2f7', $blade);
        $this->assertStringContainsString('html.hk-dark .pr2-ca-bar{background:#3a3c40}', $blade);
        $this->assertStringContainsString('html.hk-dark .pr2-avg-bar{background:#3a3c40}', $blade);
        $this->assertStringContainsString('html.hk-dark .pr2-wl-bar{background:#3a3c40}', $blade);
        // Workload widths are relative to the top teacher, not the
        // old registers*10 scale that saturated at 10 registers.
        $this->assertStringContainsString("collect(\$pr_workload)->max('registers')", $blade);
        $this->assertStringNotContainsString("min(100, \$row['registers'] * 10)", $blade);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('pr2-ca-fill', false);
        $response->assertSee('pr2-wl-fill', false);
        $response->assertDontSee('pr-bar-track');
    }

    public function test_principal_fees_outstanding_label_carries_its_year_scope()
    {
        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('Fees collected (' . now()->year . ')');
        $response->assertSee('Fees outstanding (' . now()->year . ')');
    }

    /* Principal home sample v2 (owner-approved 2026-11-08): the
       fees donut must be drawn from the SAME current-year totals
       the controller supplies — the ring percent and its arc are
       collected / billed from those numbers, and the rows beside
       the ring carry the same three totals (collected +
       outstanding = billed). */
    public function test_principal_fees_donut_matches_the_controller_totals()
    {
        $collected = (float) DB::table('payment_records')->whereYear('updated_at', now()->year)->sum('amt_paid');
        $total = (float) DB::table('payments')
            ->join('payment_records', 'payment_records.payment_id', '=', 'payments.id')
            ->whereYear('payment_records.updated_at', now()->year)
            ->sum('payments.amount');
        $this->assertGreaterThan(0, $total);
        $pct = round($collected / $total * 100, 1);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('pr2-ring-big">' . number_format($pct, 1) . '%<', false);
        $response->assertSee('stroke-dasharray="' . round($pct / 100 * 389.6, 1) . ' 389.6"', false);
        $response->assertSee('&#8377;' . \App\Helpers\Money::indianFormat($collected), false);
        $response->assertSee('&#8377;' . \App\Helpers\Money::indianFormat(max(0, $total - $collected)), false);
        $response->assertSee('&#8377;' . \App\Helpers\Money::indianFormat($total), false);
    }

    /* Principal home sample v2: the four pastel KPI tiles must
       carry the controller's own figures — enrolled students,
       teachers on roll, the school-wide 30-day attendance roll-up
       (the grouped sums the controller itself totals), and the
       current-year fees collected. */
    public function test_principal_kpi_tiles_match_the_controller_figures()
    {
        $students = StudentRecord::where('grad', 0)->count();
        $teachers = User::where('user_type', 'teacher')->count();
        $att = DB::table('attendance_sessions')
            ->join('attendance_session_entries', 'attendance_sessions.id', '=', 'attendance_session_entries.session_id')
            ->where('attendance_sessions.date', '>=', now()->subDays(30)->toDateString())
            ->select(DB::raw("SUM(CASE WHEN attendance_session_entries.status IN ('present','late') THEN 1 ELSE 0 END) as present"), DB::raw('COUNT(*) as total'))
            ->first();
        $pct = (int) round(((int) $att->present) / max(1, (int) $att->total) * 100);
        $collected = (float) DB::table('payment_records')->whereYear('updated_at', now()->year)->sum('amt_paid');

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        preg_match_all('/pr2-kpi-num">([^<]+)</', $response->getContent(), $m);
        $this->assertSame([
            number_format($students),
            number_format($teachers),
            $pct . '%',
            '&#8377;' . \App\Helpers\Money::indianFormat($collected),
        ], $m[1]);
    }

    /* Principal home sample v2: the hero pulse chips wire only to
       figures this page already computes — the 30-day attendance
       roll-up, the current analytics term, and the total approvals
       waiting. With nothing waiting (the clean seed), the
       approvals chip must not render; one pending leave makes it
       appear with the true count. */
    public function test_principal_pulse_chips_wire_only_to_real_figures()
    {
        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('attendance · last 30 days', $html);
        $this->assertStringContainsString('Term 1 in progress', $html);
        $this->assertStringNotContainsString('approval waiting', $html);
        $this->assertStringNotContainsString('approvals waiting', $html);

        LeaveRequest::create([
            'user_id' => $this->user('teacher@hackstra.app')->id,
            'start_date' => now()->addDays(40)->toDateString(),
            'end_date' => now()->addDays(40)->toDateString(),
            'reason' => 'Family function',
            'leave_type' => 'casual',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('1 approval waiting');
    }

    /* HOTFIX H sweep — the same void on the teacher home: .t10-main
       ran align-items:start so the right rail ended ~750px above
       the left column's bottom. The grid now stretches and the
       rail's flex-column panels share the extra height. */
    public function test_teacher_home_rail_fills_to_the_left_column()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertMatchesRegularExpression('/\.t10-main\{align-items:stretch\}/', $css);
        $this->assertMatchesRegularExpression('/\.t10-rail>\.t10-panel\{flex:1 0 auto/', $css);

        $response = $this->actingAs($this->user('teacher@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('t10-main', false);
        $response->assertSee('t10-rail', false);
    }

    /* HOTFIX H sweep — admin dashboard: the rail's Upcoming Events
       panel carries l17-grow (base rule flex:1) but a later (0,2,0)
       rule had silently set flex:0 1 auto. The rail-scoped (0,3,0)
       restatement restores the grow inside .l17-rail only. */
    public function test_admin_dashboard_rail_grow_is_restored_inside_the_rail()
    {
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertMatchesRegularExpression('/\.l17-rail \.dashboard-upcoming\.l17-grow\{flex:1 0 auto/', $css);

        $response = $this->actingAs($this->user('admin@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('dashboard-upcoming l17-grow', false);
    }
}
