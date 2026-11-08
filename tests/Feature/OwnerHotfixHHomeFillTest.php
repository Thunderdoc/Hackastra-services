<?php

namespace Tests\Feature;

use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ExamRecord;
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

        // A real table, ranked best-first, and no chips inside it.
        $this->assertStringContainsString('pr-avg-table', $block);
        $this->assertStringNotContainsString('pr-chip', $block);
        $pos = -1;
        foreach ($ranked as $row) {
            $found = strpos($block, '>' . $row['class'] . '</td>');
            $this->assertNotFalse($found, 'Missing ranked row for ' . $row['class']);
            $this->assertGreaterThan($pos, $found, 'Rows out of ranked order at ' . $row['class']);
            $pos = $found;
        }

        // The Top subjects line and its chips survive untouched.
        $response->assertSee('Top subjects by Term 1 average');
        $response->assertSee('pr-chip', false);
    }

    public function test_principal_bars_are_slim_app_blue_with_tier_colours()
    {
        $blade = file_get_contents(resource_path('views/pages/principal/home.blade.php'));

        $this->assertStringContainsString('.pr-bar-fill{display:block;background:#3478f6;height:6px', $blade);
        $this->assertStringContainsString('.pr-bar-fill.pr-tier-risk{background:#e8a317}', $blade);
        $this->assertStringContainsString('.pr-bar-fill.pr-tier-critical{background:#d64545}', $blade);
        $this->assertStringContainsString('html.hk-dark .pr-bar-fill{background:#6ea8ff}', $blade);
        // Workload widths are relative to the top teacher, not the
        // old registers*10 scale that saturated at 10 registers.
        $this->assertStringContainsString("collect(\$pr_workload)->max('registers')", $blade);
        $this->assertStringNotContainsString("min(100, \$row['registers'] * 10)", $blade);

        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');
        $response->assertOk();
        $response->assertSee('pr-bar-track', false);
    }

    public function test_principal_fees_outstanding_label_carries_its_year_scope()
    {
        $response = $this->actingAs($this->user('principal@hackstra.app'))->get('/home');

        $response->assertOk();
        $response->assertSee('Fees collected (' . now()->year . ')');
        $response->assertSee('Fees outstanding (' . now()->year . ')');
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
