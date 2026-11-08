<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\DisciplineRecord;
use App\Models\ElectiveChoice;
use App\Models\Subject;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Beauty Round 3 — Item 6 (2026-11-07, R5 audit): the Batch-4
 * .hk-std table standard (fixed layout + colgroup summing to
 * 100% + hk-nw on short fields + a screen-only min-width inside
 * the .table-responsive scrollport) adopted by the non-LMS /
 * non-exams families: Payroll, attendance monthly results +
 * defaulters, period sessions, the three Feedback queues,
 * Admissions, PTM, date-sheet approvals, electives approvals,
 * Expenses, Discipline (plus its date render d M Y), the
 * Tabulation screen (blade-computed min-width, no fixed
 * layout), Alumni (l108-stack), My Attendance and the
 * low-density rosters/reports on hk-std-760.
 * The new min-width variants + payroll dark mirrors +
 * .hk-ellip-2 + tabulation nowrap shipped as a CSS fragment the
 * coordinator appended to qs.css serially; the append has landed,
 * so the stylesheet pins read the shipped qs.css itself
 * (see test_fragment_carries_the_variants_and_dark_mirrors).
 * Presentation only — no content, column or order changes.
 */
class OwnerBeautyRound3Test extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function bladeFile(string $path): string
    {
        return file_get_contents(resource_path('views/' . $path));
    }

    private function stylesheet(): string
    {
        // The Item-6 rules were authored as a build-time fragment
        // under hk-tmp/ — a scratch path that only ever existed on
        // the author's machine, so the pin errored in CI. The
        // fragment has been appended to the shipped stylesheet;
        // assert against qs.css itself (repo-relative).
        return file_get_contents(public_path('assets/css/qs.css'));
    }

    /* ---------- CSS: variants, dark mirrors, helpers ---------- */

    public function test_fragment_carries_the_variants_and_dark_mirrors()
    {
        $css = $this->stylesheet();

        foreach ([
            '.hk-std-payroll{min-width:1020px}',
            '.hk-std-attrep{min-width:900px}',
            '.hk-std-attrep-wide{min-width:940px}',
            '.hk-std-periods{min-width:1040px}',
            '.hk-std-feedback{min-width:880px}',
            '.hk-std-admissions{min-width:900px}',
            '.hk-std-880{min-width:880px}',
            '.hk-std-760{min-width:760px}',
            '.l103-table{min-width:760px}',
        ] as $rule) {
            $this->assertStringContainsString($rule, $css);
        }
        // Screen-only: the variants live inside the @media screen block.
        $this->assertStringContainsString('@media screen{', $css);
        // Payroll dark mirrors (light rules untouched).
        $this->assertStringContainsString('html.hk-dark .pay-edit-row>td{background:#26282b}', $css);
        $this->assertStringContainsString('html.hk-dark .pay-badge-none{', $css);
        // Two-line clamp for the feedback Report cell (NOT nowrap).
        $this->assertStringContainsString('.hk-ellip-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}', $css);
        // Tabulation cell nowrap append.
        $this->assertStringContainsString('.qs-tab-table .qs-tab-mark,.qs-tab-table .qs-tab-total,.qs-tab-table .qs-tab-ave{white-space:nowrap}', $css);
    }

    /* ---------- 1. Payroll ---------- */

    public function test_payroll_table_follows_the_standard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('payroll.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-payroll', $html);
        // Widths rebalanced in Round 5 P11 (was 15/8/7/…/25): at the
        // standard table width the 10-char staff Code at 8% visually
        // ran into the Role cell ("MCVOC51YGWTeacher" in the capture);
        // Code 8→10 and Role 7→9, funded from Staff 15→14 and
        // Actions 25→22. Sum stays 100.
        $this->assertStringContainsString(
            '<colgroup><col style="width:14%"><col style="width:10%"><col style="width:9%"><col style="width:8%"><col style="width:9%"><col style="width:9%"><col style="width:9%"><col style="width:10%"><col style="width:22%"></colgroup>',
            $html
        );
        // NET PAY header stays on one line; money cells are nowrap.
        $this->assertStringContainsString('hk-nw">Net Pay</th>', $html);
        $this->assertStringContainsString('hk-nw', $html);
    }

    /* ---------- 2. Attendance monthly results partial ---------- */

    public function test_monthly_results_partial_follows_the_standard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->getJson(route('attendance.report.results', ['class_id' => 1, 'section_id' => 1, 'month' => '2026-09']))
            ->assertOk()->json('results');

        $this->assertStringContainsString('table-responsive', $html);
        $this->assertStringContainsString('hk-std hk-std-attrep', $html);
        $this->assertStringContainsString('<colgroup>', $html);
    }

    /* ---------- 3. Attendance defaulters ---------- */

    public function test_defaulters_table_follows_the_standard()
    {
        // Resolve a class/section/month that really has a student
        // below 75% on this seed (never a hardcoded record id).
        $low = \Illuminate\Support\Facades\DB::table('attendance_session_entries as e')
            ->join('attendance_sessions as s', 's.id', '=', 'e.session_id')
            ->join('student_records as sr', 'sr.id', '=', 'e.student_record_id')
            ->selectRaw("sr.my_class_id as class_id, sr.section_id as section_id, strftime('%Y-%m', s.date) as ym")
            ->selectRaw("SUM(CASE WHEN e.status IN ('present','late') THEN 1 ELSE 0 END) * 1.0 / COUNT(*) as pct")
            ->where('sr.grad', 0)
            ->whereNotNull('sr.section_id')
            // Raw grouping/having: quoted aliases mis-resolve in
            // SQLite (the alias becomes a string literal there).
            // percentFor() rounds to an int: a raw 74.x% can round
            // UP to 75 and stop being a defaulter — bind to < 74.5%.
            ->groupByRaw("strftime('%Y-%m', s.date), sr.my_class_id, sr.section_id, e.student_record_id")
            ->havingRaw("SUM(CASE WHEN e.status IN ('present','late') THEN 1 ELSE 0 END) * 1.0 / COUNT(*) < 0.745")
            ->orderByRaw("strftime('%Y-%m', s.date)")
            ->first();
        if (!$low) {
            $this->markTestSkipped('No below-75 attendance on this seed.');
        }

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->getJson(route('attendance.defaulters.results', ['class_id' => $low->class_id, 'section_id' => $low->section_id, 'month' => $low->ym]))
            ->assertOk()->json('results');

        $this->assertStringContainsString('hk-std hk-std-attrep hk-std-attrep-wide', $html);
        $this->assertStringContainsString('<colgroup>', $html);

        $blade = $this->bladeFile('pages/support_team/attendance/partials/defaulters_results.blade.php');
        $this->assertStringContainsString('table-responsive', $blade);
    }

    /* ---------- 4. Period-attendance sessions index ---------- */

    public function test_periods_index_blade_follows_the_standard()
    {
        $blade = $this->bladeFile('pages/support_team/attendance_periods/index.blade.php');

        $this->assertStringContainsString('hk-std hk-std-periods', $blade);
        $this->assertStringContainsString('<colgroup>', $blade);
        // Topic cell ellipsizes with the full text in the title attr.
        $this->assertStringContainsString('ma-topic hk-ellip', $blade);
        $this->assertStringContainsString('title="{{ $row[\'session\'] ? $row[\'session\']->topic : \'\' }}"', $blade);

        $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.periods.index'))->assertOk();
    }

    /* ---------- 5. Feedback queues x3 ---------- */

    public function test_feedback_inbox_follows_the_standard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('feedback.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-feedback', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        // The hand-rolled inline nowrap is gone, replaced by hk-nw.
        $this->assertStringNotContainsString('<td style="white-space:nowrap">', $html);
    }

    public function test_feedback_sa_queue_follows_the_standard()
    {
        $html = $this->actingAs($this->user('superadmin@hackstra.app'))
            ->get(route('feedback.sa_index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-feedback', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringNotContainsString('<td style="white-space:nowrap">', $html);
    }

    public function test_feedback_mine_follows_the_standard()
    {
        $html = $this->actingAs($this->user('teacher@hackstra.app'))
            ->get(route('feedback.mine'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-feedback', $html);
        $this->assertStringContainsString('<colgroup>', $html);

        foreach (['index', 'sa_index', 'mine'] as $view) {
            $blade = $this->bladeFile('pages/support_team/feedback/' . $view . '.blade.php');
            $this->assertStringContainsString('hk-ellip-2', $blade, $view . ' Report cell clamps to 2 lines');
            $this->assertStringContainsString('hk-nw', $blade, $view . ' date cell uses hk-nw');
        }
    }

    /* ---------- 6. Admissions ---------- */

    public function test_admissions_table_follows_the_standard()
    {
        \App\Models\Admission::create([
            'name' => 'Beauty Round 3 Enquiry',
            'phone' => '9876543210',
            'my_class_id' => \App\Models\MyClass::query()->orderBy('id')->firstOrFail()->id,
            'message' => 'Beauty round 3 admissions probe message',
            'status' => 'new',
        ]);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('admissions.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-admissions', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString('hk-nw', $html);
    }

    /* ---------- 7-11. hk-std-880 family ---------- */

    public function test_ptm_table_follows_the_standard()
    {
        $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('ptm.index'))->assertOk();

        $blade = $this->bladeFile('pages/support_team/ptm/index.blade.php');
        $this->assertStringContainsString('hk-std hk-std-880', $blade);
        $this->assertStringContainsString('<colgroup>', $blade);
        $this->assertStringContainsString('hk-nw', $blade);
    }

    public function test_date_sheet_approvals_table_follows_the_standard()
    {
        $this->actingAs($this->user('principal@hackstra.app'))
            ->get(route('exam_dates.approvals'))->assertOk();

        $blade = $this->bladeFile('pages/support_team/exams/date_sheet_approvals.blade.php');
        $this->assertStringContainsString('hk-std hk-std-880', $blade);
        $this->assertStringContainsString('<colgroup>', $blade);
    }

    public function test_electives_admin_table_follows_the_standard()
    {
        \App\Models\ElectiveChoice::create([
            'student_record_id' => StudentRecord::query()->orderBy('id')->firstOrFail()->id,
            'subject_id' => \App\Models\Subject::query()->orderBy('id')->firstOrFail()->id,
            'status' => 'pending',
        ]);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('electives.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-880', $html);
        $this->assertStringContainsString('<colgroup>', $html);
    }

    public function test_expenses_table_follows_the_standard()
    {
        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('expenses.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-880', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertStringContainsString('hk-nw', $html);
    }

    public function test_discipline_table_and_date_format_follow_the_standard()
    {
        $student = StudentRecord::query()->orderBy('id')->firstOrFail();
        DisciplineRecord::create([
            'student_id' => $student->user_id,
            'recorded_by' => $this->user('admin@hackstra.app')->id,
            'incident_date' => '2026-12-01',
            'type' => 'other',
            'description' => 'Beauty round 3 discipline date probe',
            'action_taken' => 'Counselled',
        ]);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('discipline_records.index'))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-880', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        // Owner's date order: d M Y on screen, never the raw Y-m-d.
        $this->assertStringContainsString('01 Dec 2026', $html);
        $this->assertStringNotContainsString('2026-12-01', $html);
    }

    /* ---------- 12. Tabulation screen ---------- */

    public function test_tabulation_uses_a_blade_computed_min_width_without_fixed_layout()
    {
        $blade = $this->bladeFile('pages/support_team/marks/tabulation/index.blade.php');

        $this->assertStringContainsString('style="min-width:{{ 300 + ($subjects->count() * 72) }}px"', $blade);
        // Fixed layout is deliberately NOT forced on the dynamic sheet.
        $this->assertStringNotContainsString('qs-tab-table hk-std', $blade);

        $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('marks.tabulation'))->assertOk();
    }

    /* ---------- 14. Alumni ---------- */

    public function test_alumni_table_carries_the_stack_class_its_labels_expect()
    {
        $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('alumni.index'))->assertOk();

        $blade = $this->bladeFile('pages/support_team/alumni/index.blade.php');
        // Re-expressed 2026-11-08 (Inside-Matter Job 2): the table
        // adopted the house hk-std standard (hk-std classes now sit
        // between table-striped and the phone stack), so the two
        // contracts are asserted separately at the same strength —
        // striped skin AND the l108-stack its data-labels expect.
        $this->assertStringContainsString('table-striped', $blade);
        $this->assertStringContainsString('hk-std', $blade);
        $this->assertStringContainsString('l108-stack', $blade);
    }

    /* ---------- 15. My Attendance ---------- */

    public function test_my_attendance_desktop_colgroups_keep_the_phone_stack()
    {
        // Re-expressed 2026-11-08 (LMS build): the By-Subject +
        // register tables moved OUT of my.blade.php into the
        // shared attendance/partials/summary_block.blade.php —
        // My Attendance and My Week render the same block from
        // one source now. The pin follows the tables: desktop
        // colgroups + hk-std + the phone stack (l108-stack,
        // data-labels) must survive in the partial, and the page
        // must include it.
        $page = $this->bladeFile('pages/support_team/attendance/my.blade.php');
        $this->assertStringContainsString('attendance.partials.summary_block', $page);

        $blade = $this->bladeFile('pages/support_team/attendance/partials/summary_block.blade.php');

        $this->assertMatchesRegularExpression('/<table[^>]*hk-std[^>]*l108-stack/', $blade);
        $this->assertStringContainsString('<colgroup>', $blade);
        $this->assertStringContainsString('data-label="Date"', $blade);
        $this->assertStringContainsString('data-label="Period"', $blade);
        $this->assertStringContainsString('data-label="Status"', $blade);
        // The phone stack classes are untouched (subjects + register).
        $this->assertSame(2, substr_count($blade, 'l108-stack'));
    }

    /* ---------- 16. Low-density hk-std-760 family ---------- */

    public function test_id_card_rosters_follow_the_standard()
    {
        $classId = \App\Models\MyClass::query()->orderBy('id')->firstOrFail()->id;

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('students.id_cards.bulk', ['class_id' => $classId]))->assertOk()->getContent();
        $this->assertStringContainsString('hk-std hk-std-760', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        // The inline word-break that broke names mid-word is gone.
        $this->assertStringNotContainsString('word-break: break-word', $html);

        $staff = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('staff.id_cards.bulk'))->assertOk()->getContent();
        $this->assertStringContainsString('hk-std hk-std-760', $staff);
        $this->assertStringContainsString('<colgroup>', $staff);
    }

    public function test_marks_bulk_roster_follows_the_standard()
    {
        $class = \App\Models\MyClass::query()->orderBy('id')->firstOrFail();
        $section = \App\Models\Section::where('my_class_id', $class->id)->orderBy('id')->firstOrFail();

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('marks.bulk', [$class->id, $section->id]))->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-760', $html);
        $this->assertStringContainsString('<colgroup>', $html);
    }

    public function test_student_attendance_report_tables_follow_the_standard()
    {
        $record = StudentRecord::query()->orderBy('id')->firstOrFail();

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.student_report', \App\Helpers\Qs::hash($record->id)))
            ->assertOk()->getContent();

        $this->assertStringContainsString('hk-std hk-std-760', $html);
        $this->assertStringContainsString('<colgroup>', $html);
    }

    public function test_period_report_and_expenses_report_blades_follow_the_standard()
    {
        $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('attendance.periods.report'))->assertOk();
        $periods = $this->bladeFile('pages/support_team/attendance_periods/report.blade.php');
        $this->assertStringContainsString('hk-std hk-std-760', $periods);
        $this->assertSame(2, substr_count($periods, '<colgroup>'));
        // Hand-rolled inline nowrap replaced by hk-nw.
        $this->assertStringNotContainsString('style="white-space: nowrap"', $periods);

        $html = $this->actingAs($this->user('admin@hackstra.app'))
            ->get(route('expenses.report'))->assertOk()->getContent();
        $this->assertStringContainsString('hk-std hk-std-760', $html);
        $this->assertStringContainsString('<colgroup>', $html);
    }
}
