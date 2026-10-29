<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Overnight batch S2-ATTENDANCE (2026-10-29).
 *
 * Item 2 — Low Attendance for a teacher must read as assigned
 * classes only: the underlying query is teacher-scoped (verified
 * here by count), but the page wording said "All Classes" /
 * "Across all classes", which made a scoped total look like the
 * whole school. Labels must say assigned classes for teachers
 * and keep the original wording for everyone else.
 *
 * Item 3 — Section-wise attendance: a section period's register
 * must list only that section's students on every surface (take
 * page, register view/print, periods-index Students column, and
 * the subject-wise period report, which previously had no section
 * filter at all and always listed the whole class).
 *
 * Item 4 — Student Information picker: Class + Section choice
 * must survive the redirect and filter the list to that section.
 *
 * Seed anchors (snapshot DB): teacher@hackstra.app is Ananya
 * Sharma (id 4), teaching English in Prep / Class 3 / Class 6 /
 * Class 9 / Class 12; Class 3-C is section 14 (40 students);
 * Class 12-E is section 68 (40 students), Class 12-A section 64.
 */
class OwnerOvernightAttendanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function studentName($classId, $sectionId, $firstByName = true)
    {
        $students = StudentRecord::with('user')
            ->where('my_class_id', $classId)
            ->where('section_id', $sectionId)
            ->where('grad', 0)
            ->get()
            ->sortBy(function ($s) { return strtolower(optional($s->user)->name ?? ''); })
            ->values();

        $this->assertNotEmpty($students, "No students for class {$classId} section {$sectionId}");

        return $firstByName
            ? optional($students->first()->user)->name
            : optional($students->last()->user)->name;
    }

    // ---- Item 2: Low Attendance scope + labels ----

    public function test_teacher_defaulter_total_covers_assigned_classes_only()
    {
        $teacher = $this->teacher();
        $scoped = StudentRecord::where('grad', 0)
            ->whereIn('my_class_id', TeacherScope::classIds($teacher))
            ->count();
        $wholeSchool = StudentRecord::where('grad', 0)->count();
        $this->assertGreaterThan(0, $scoped);
        $this->assertGreaterThan($scoped, $wholeSchool, 'Fixture must distinguish scoped from whole-school totals');

        $html = $this->actingAs($teacher)
            ->get(route('attendance.defaulters') . '?month=2026-10')
            ->assertOk()
            ->getContent();

        // The KPI total is the scoped enrolment, not the school.
        $this->assertStringContainsString(number_format($scoped), $html);
        $this->assertStringNotContainsString(number_format($wholeSchool), $html);
        // ... and the wording must say so.
        $this->assertStringContainsString('Across your assigned classes', $html);
        $this->assertStringNotContainsString('Across all classes', $html);
        $this->assertStringContainsString('All assigned classes', $html);
    }

    public function test_teacher_defaulter_out_of_scope_class_is_403()
    {
        // Class 1 (id 2) is not among Ananya's assigned classes.
        $this->actingAs($this->teacher())
            ->get(route('attendance.defaulters') . '?month=2026-10&class_id=2')
            ->assertForbidden();
    }

    public function test_admin_defaulter_keeps_all_classes_wording()
    {
        $html = $this->actingAs($this->admin())
            ->get(route('attendance.defaulters') . '?month=2026-10')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Across all classes', $html);
        $this->assertStringNotContainsString('Across your assigned classes', $html);
    }

    public function test_teacher_monthly_report_all_classes_label_is_assigned()
    {
        $html = $this->actingAs($this->teacher())
            ->get(route('attendance.report') . '?class_id=all&month=2026-10')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('All assigned classes', $html);
    }

    // ---- Item 3: section scoping ----

    public function test_monthly_report_section_filter_lists_section_only()
    {
        $sectionE = $this->studentName(13, 68);
        $sectionA = $this->studentName(13, 64);

        $response = $this->actingAs($this->admin())
            ->get(route('attendance.report') . '?class_id=13&section_id=68&month=2026-10');
        $response->assertOk();
        $response->assertSee($sectionE);
        $response->assertDontSee($sectionA);
    }

    public function test_periods_index_section_row_shows_section_count()
    {
        // Wednesday 2026-10-28: Ananya's Class 12-E period is a
        // section row (40 students); section-less class-grid rows
        // still show their class total by timetable design.
        $html = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.index') . '?date=2026-10-28')
            ->assertOk()
            ->getContent();

        $found = preg_match('/Class 12 — E<\/td>.*?ma-students">\s*(?:\d+\/)?(\d+)\s*</s', $html, $m);
        $this->assertSame(1, $found, 'Class 12-E row not found on the periods index');
        $this->assertSame('40', $m[1], 'Section row must show the section size, not the class size');
    }

    public function test_period_subject_report_section_filter_lists_section_only()
    {
        // One legacy-style Class 12-E English session (section_id
        // NULL, section carried by its timetable record tt 805) with
        // entries for the 40 section-E students plus 5 section-A
        // students: the unfiltered report must still show both, the
        // section-filtered report only section E.
        $teacher = $this->teacher();
        $session = AttendanceSession::create([
            'date' => '2026-10-28',
            'time_table_id' => 805,
            'my_class_id' => 13,
            'section_id' => null,
            'subject_id' => 79,
            'teacher_id' => $teacher->id,
            'topic' => 'Overnight section probe',
            'status' => AttendanceSession::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
        $sectionE = StudentRecord::where('my_class_id', 13)->where('section_id', 68)->where('grad', 0)->get();
        $sectionA = StudentRecord::where('my_class_id', 13)->where('section_id', 64)->where('grad', 0)->take(5)->get();
        $this->assertCount(40, $sectionE);
        foreach ($sectionE->merge($sectionA) as $student) {
            AttendanceSessionEntry::create([
                'session_id' => $session->id,
                'student_record_id' => $student->id,
                'status' => AttendanceSessionEntry::STATUS_PRESENT,
            ]);
        }
        $eName = $this->studentName(13, 68);
        $aName = $this->studentName(13, 64);

        $base = route('attendance.periods.report')
            . '?class_id=13&subject_id=79&from=2026-10-26&to=2026-10-30';

        $plain = $this->actingAs($this->admin())->get($base)->assertOk();
        $plain->assertSee('Sessions held: 1');
        $plain->assertSee($aName);

        $filtered = $this->actingAs($this->admin())->get($base . '&section_id=68')->assertOk();
        $filtered->assertSee('Sessions held: 1');
        $filtered->assertSee($eName);
        $filtered->assertDontSee($aName);
    }

    public function test_period_subject_report_is_teacher_scoped()
    {
        // Class 1 (id 2) is outside Ananya's scope: report and
        // session-log export must refuse it; her own class stays open.
        $this->actingAs($this->teacher())
            ->get(route('attendance.periods.report') . '?class_id=2&subject_id=1&from=2026-10-01&to=2026-10-31')
            ->assertForbidden();
        $this->actingAs($this->teacher())
            ->get(route('attendance.periods.export') . '?class_id=2&from=2026-10-01&to=2026-10-31')
            ->assertForbidden();

        $html = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.report'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Class 1</option>', $html);
        $this->assertStringContainsString('Class 3</option>', $html);
    }

    // ---- Item 4: Student Information picker ----

    public function test_student_info_picker_section_flows_to_section_only_list()
    {
        $cName = $this->studentName(4, 14);
        $aName = $this->studentName(4, 12);

        $picker = $this->actingAs($this->teacher())
            ->get(route('students.list'))
            ->assertOk();
        $picker->assertSee('Class 3 C');

        $redirect = $this->actingAs($this->teacher())
            ->get(route('students.list') . '?class_id=4&section_id=14');
        $redirect->assertRedirect(route('students.list', [4]) . '?section_id=14');

        $list = $this->actingAs($this->teacher())
            ->get(route('students.list', [4]) . '?section_id=14')
            ->assertOk();
        $list->assertSee($cName);
        $list->assertDontSee($aName);
        $list->assertSee('Showing 40');
    }
}
