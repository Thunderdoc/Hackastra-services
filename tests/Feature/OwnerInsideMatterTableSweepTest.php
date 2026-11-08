<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Inside-Matter Job 2 (2026-11-08): table unification sweep.
 *
 * The ~45-table hk-std backlog (plus the pages the inventory found
 * beyond it) was migrated to the house table standard: .hk-std with
 * a screen-only min-width variant, a <colgroup> whose widths sum to
 * exactly 100%, hk-nw on short fields and hk-ellip where truncation
 * is right, inside a .table-responsive wrapper. This pin walks the
 * migrated list (file => table indexes) and asserts, from the blade
 * sources:
 *
 *  1. every migrated table carries hk-std;
 *  2. at least one colgroup sits between the table tag and its
 *     thead, and EVERY colgroup in that span sums to exactly 100
 *     (conditional-column tables carry one colgroup per branch);
 *  3. no migrated table still carries a retired private skin
 *     (l111-table, lms-table, def95-table, mp94-table, mp89-table,
 *     l106-table, l103-table, dashboard-table);
 *  4. the phone-stack contract survives: a migrated table whose
 *     body cells carry data-label attributes MUST keep l108-stack
 *     (Loop-108: on phones the table stacks into labelled row-cards
 *     so every column stays visible);
 *  5. qs.css carries the stack exemption — inside the stack's own
 *     breakpoint a stacked hk-std table's min-width is lifted, or
 *     the cards would stretch past the phone and push the values
 *     off-screen (found by render check on Manage Payments, whose
 *     stacked values sat at x=846 on a 360px phone before the fix).
 *
 * Deliberately NOT in the list (documented in the job inventory):
 * print/PDF/document blades, form grids and editors, key-value
 * detail tables, the My Week fit grid, leave panel tables (already
 * standard), marks tabulation (blade-computed min-width by design)
 * and the pages the brief excluded (leave/substitution surfaces,
 * principal home, courses/My Week, header partials).
 */
class OwnerInsideMatterTableSweepTest extends TestCase
{
    use DatabaseTransactions;

    /** file (relative to resources/views) => migrated table indexes */
    private function migrated(): array
    {
        return [
            'pages/hostel/home.blade.php' => [0],
            'pages/librarian/home.blade.php' => [0],
            'pages/nurse/home.blade.php' => [0, 1],
            'pages/super_admin/platform_home.blade.php' => [0, 1, 2],
            'pages/super_admin/platform_mail/index.blade.php' => [0],
            'pages/student/home.blade.php' => [0],
            'pages/student/events.blade.php' => [0, 1],
            'pages/student/fees.blade.php' => [0, 1],
            'pages/student/library.blade.php' => [0, 1],
            'pages/parent/children.blade.php' => [0],
            'pages/parent/fees.blade.php' => [0],
            'pages/parent/fees_show.blade.php' => [0, 1],
            'pages/parent/progress.blade.php' => [0, 1],
            'partials/fee_plan.blade.php' => [0, 1],
            'pages/support_team/students/graduated.blade.php' => [0],
            'pages/support_team/students/import.blade.php' => [0, 1],
            'pages/support_team/students/list_picker.blade.php' => [0],
            'pages/support_team/students/promotion/promote.blade.php' => [0],
            'pages/support_team/students/promotion/reset.blade.php' => [0],
            'pages/support_team/classes/index.blade.php' => [0],
            'pages/support_team/sections/index.blade.php' => [0],
            'pages/support_team/subjects/index.blade.php' => [0],
            'pages/support_team/dorms/index.blade.php' => [0],
            'pages/support_team/rooms/index.blade.php' => [0],
            'pages/support_team/grades/index.blade.php' => [0],
            'pages/support_team/role_permissions/index.blade.php' => [0],
            'pages/support_team/settings_unlock/index.blade.php' => [0],
            'pages/support_team/search_results.blade.php' => [0, 1, 2, 3],
            'pages/support_team/exams/index.blade.php' => [0],
            'pages/support_team/exams/periods/index.blade.php' => [0],
            'pages/support_team/exams/admit_cards.blade.php' => [0],
            'pages/support_team/exams/admit_my.blade.php' => [0],
            'pages/support_team/exams/date_sheet.blade.php' => [0],
            'pages/support_team/exams/duties.blade.php' => [0],
            'pages/support_team/exams/my_duties.blade.php' => [0],
            'pages/support_team/exams/publication.blade.php' => [0],
            'pages/support_team/exams/scheme/index.blade.php' => [0],
            'pages/support_team/fee_structure/index.blade.php' => [0],
            'pages/support_team/fee_structure/net.blade.php' => [0],
            'pages/support_team/fee_structure/net_index.blade.php' => [0],
            'pages/support_team/fee_structure/show.blade.php' => [0, 1],
            'pages/support_team/payments/index.blade.php' => [0],
            'pages/support_team/payments/collection_day.blade.php' => [0],
            'pages/support_team/payments/defaulters.blade.php' => [0, 1],
            'pages/support_team/payroll/my_index.blade.php' => [0],
            'pages/support_team/transport/index.blade.php' => [0, 1, 2],
            'pages/support_team/transport/assignments.blade.php' => [0],
            'pages/support_team/transport/fees.blade.php' => [0],
            'pages/support_team/transport/mine.blade.php' => [0],
            'pages/support_team/assignments/index.blade.php' => [0],
            'pages/support_team/assignments/internal.blade.php' => [0],
            'pages/support_team/assignments/parent_index.blade.php' => [0],
            'pages/support_team/assignments/show.blade.php' => [0],
            'pages/support_team/materials/index.blade.php' => [0],
            'pages/support_team/quizzes/index.blade.php' => [0],
            'pages/support_team/quizzes/results.blade.php' => [0, 1],
            'pages/support_team/quizzes/show.blade.php' => [0, 1],
            'pages/support_team/lesson_plans/index.blade.php' => [0, 1],
            'pages/support_team/lesson_plans/show.blade.php' => [0],
            'pages/support_team/homework_tracker/index.blade.php' => [0, 1],
            'pages/support_team/question_bank/index.blade.php' => [0],
            'pages/support_team/question_papers/index.blade.php' => [0],
            'pages/support_team/alumni/index.blade.php' => [0],
            'pages/support_team/alumni/show.blade.php' => [0],
            'pages/support_team/analytics/index.blade.php' => [0, 1, 2, 3],
            'pages/support_team/attendance/index.blade.php' => [0],
            'pages/support_team/attendance_periods/show.blade.php' => [0],
            'pages/support_team/bulk/index.blade.php' => [0],
            'pages/support_team/bulk/job.blade.php' => [0, 1, 2],
            'pages/support_team/bulk/upload.blade.php' => [0],
            'pages/support_team/certificates/index.blade.php' => [0],
            'pages/support_team/discipline_records/show.blade.php' => [0],
            'pages/support_team/documents/index.blade.php' => [0],
            'pages/support_team/electives/index.blade.php' => [0, 1, 2],
            'pages/support_team/events/index.blade.php' => [0],
            'pages/support_team/health_records/index.blade.php' => [0],
            'pages/support_team/hostel/allocations/index.blade.php' => [0],
            'pages/support_team/hostel/dorms/index.blade.php' => [0],
            'pages/support_team/hostel/my.blade.php' => [0],
            'pages/support_team/hostel/rooms/index.blade.php' => [0],
            'pages/support_team/inventory/index.blade.php' => [0],
            'pages/support_team/inventory/show.blade.php' => [0],
            'pages/support_team/library_reservations/index.blade.php' => [0, 1],
            'pages/support_team/parent_messages/index.blade.php' => [0],
            'pages/support_team/parent_messages/show.blade.php' => [0],
            'pages/support_team/planning/allocation.blade.php' => [0],
            'pages/support_team/planning/eligibility.blade.php' => [0],
            'pages/support_team/planning/readiness.blade.php' => [0],
            'pages/support_team/planning/staffing.blade.php' => [0, 1],
            'pages/support_team/timetables/approvals.blade.php' => [0, 1],
            'pages/support_team/timetables/manage.blade.php' => [0],
            'pages/support_team/timetables/validate.blade.php' => [0, 1],
        ];
    }

    private function bladeSrc(string $rel): string
    {
        $path = resource_path('views/' . $rel);
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_every_migrated_table_carries_the_standard()
    {
        $skins = ['l111-table', 'lms-table', 'def95-table', 'mp94-table',
            'mp89-table', 'l106-table', 'l103-table', 'dashboard-table'];
        $checked = 0;

        foreach ($this->migrated() as $rel => $indexes) {
            $src = $this->bladeSrc($rel);
            preg_match_all('/<table\b[^>]*>/', $src, $tags, PREG_OFFSET_CAPTURE);

            foreach ($indexes as $i) {
                $checked++;
                $this->assertArrayHasKey($i, $tags[0], "$rel table [$i] missing");
                [$tag, $offset] = $tags[0][$i];
                preg_match('/class="([^"]*)"/', $tag, $cm);
                $class = $cm[1] ?? '';

                $this->assertStringContainsString('hk-std', $class, "$rel [$i] lost hk-std: $class");
                foreach ($skins as $skin) {
                    $this->assertStringNotContainsString($skin, $class, "$rel [$i] still carries retired skin $skin");
                }

                // Colgroup span: from the end of the table tag to its thead.
                $after = substr($src, $offset + strlen($tag), 4000);
                $theadAt = strpos($after, '<thead');
                $span = $theadAt === false ? $after : substr($after, 0, $theadAt);
                preg_match_all('/<colgroup>(.*?)<\/colgroup>/s', $span, $groups);
                $this->assertNotEmpty($groups[1], "$rel [$i] has no colgroup before its thead");
                foreach ($groups[1] as $g) {
                    preg_match_all('/width:(\d+)%/', $g, $wm);
                    $this->assertSame(100, array_sum(array_map('intval', $wm[1])),
                        "$rel [$i] colgroup widths must sum to 100");
                }

                // Phone-stack contract: data-label cells => l108-stack stays.
                $end = strpos($src, '</table>', $offset);
                $body = $end === false ? '' : substr($src, $offset, $end - $offset);
                if (strpos($body, 'data-label') !== false) {
                    $this->assertStringContainsString('l108-stack', $class,
                        "$rel [$i] has data-label cells but lost the l108-stack phone contract");
                }
            }
        }

        $this->assertGreaterThanOrEqual(120, $checked, 'the sweep must cover the whole migrated list');
    }

    public function test_qs_css_carries_the_stack_exemption()
    {
        $css = (string) file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.table-responsive>.hk-std.l108-stack{min-width:0}', $css,
            'qs.css lost the Job-2 stack exemption (stacked cards would stretch past phone widths)');
    }
}
