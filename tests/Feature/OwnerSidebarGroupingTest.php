<?php

namespace Tests\Feature;

use App\User;
use Tests\TestCase;

/**
 * Sidebar grouping (owner order 2026-10-29 night — "grouping of
 * sidebar is must now", approving the grouping plan written
 * earlier that day): headers only. No item name, link or order
 * changes — the plan's whole point is that every role's items
 * sit under a labelled group, in the same sequence as before.
 */
class OwnerSidebarGroupingTest extends TestCase
{
    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function dashboardHtml(string $email): string
    {
        return $this->actingAs($this->userByEmail($email))
            ->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_teacher_items_sit_under_reports_and_classroom_headers()
    {
        $html = $this->dashboardHtml('teacher@hackstra.app');

        $reportsHeader = strpos($html, '>Reports</div>');
        $builder = strpos($html, route('reports.builder'));
        $classroomHeader = strpos($html, '>Classroom</div>');
        $overview = strpos($html, route('class_overview.index'));

        $this->assertNotFalse($reportsHeader, 'teacher menu needs a Reports header');
        $this->assertNotFalse($classroomHeader, 'teacher menu needs a Classroom header');
        $this->assertTrue($reportsHeader < $builder, 'Reports header precedes the builder link');
        $this->assertTrue($classroomHeader < $overview, 'Classroom header precedes Class Overview');
        $this->assertTrue($builder < $classroomHeader, 'Reports group comes before Classroom group');

        foreach (['parent_messages.index', 'health_records.index', 'discipline_records.index', 'leave.student_requests'] as $routeName) {
            $this->assertStringContainsString(route($routeName), $html, $routeName . ' still linked');
        }
    }

    public function test_principal_menu_is_grouped()
    {
        $html = $this->dashboardHtml('principal@hackstra.app');
        foreach (['Overview', 'Academics', 'People', 'Exams', 'Approvals', 'School', 'Insights', 'Account'] as $group) {
            $this->assertStringContainsString('>' . $group . '</div>', $html, 'principal group ' . $group);
        }
        // All 15 pre-Account items still present, in the original order.
        $needles = ['classes.index', 'tt.index', 'users.index', 'attendance.periods.index', 'exams.index',
            'leave.index', 'exam_dates.approvals', 'payments.manage', 'parent_messages.index',
            'transport.index', 'discipline_records.index', 'reports.builder', 'audit_logs.index'];
        $last = -1;
        foreach ($needles as $routeName) {
            $pos = strpos($html, route($routeName));
            $this->assertNotFalse($pos, $routeName . ' still linked for principal');
            $this->assertTrue($pos > $last, $routeName . ' kept its order');
            $last = $pos;
        }
    }

    public function test_admin_planning_tools_have_their_own_header()
    {
        $html = $this->dashboardHtml('admin@hackstra.app');
        $header = strpos($html, '>Planning</div>');
        $this->assertNotFalse($header, 'admin menu needs a Planning header');
        foreach (['planning.readiness', 'planning.staffing', 'planning.eligibility', 'planning.allocation'] as $routeName) {
            $pos = strpos($html, route($routeName));
            $this->assertNotFalse($pos, $routeName . ' still linked for admin');
            $this->assertTrue($pos > $header, $routeName . ' sits under the Planning header');
        }
    }
}
