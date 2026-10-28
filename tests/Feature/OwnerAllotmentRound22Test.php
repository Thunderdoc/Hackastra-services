<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MyAccountController;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 22 — COUNT immediacy after override removal.
 *
 * Rounds 8/16/17 proved the GATE flips are immediate (override
 * scope, leader swap, reassignment — all with no reset step and
 * no re-login, after the TeacherScope static memo was removed in
 * round 8). Rounds 18–21 proved the same for the removal story's
 * writes and reads. What was never probed is the third leg: the
 * COUNTS. A memo or cache that the round-8 removal missed would
 * show up here as a stale number on a page that still gates
 * correctly — the worst kind of staleness, because the teacher
 * would see yesterday's workload on a dashboard that otherwise
 * behaves.
 *
 * Fixture (same shape as rounds 18/20/21, fully fresh, rolls
 * back): fresh class + section led by D + subject directly
 * taught by D. Override teacher O holds ONLY the override row
 * for (section, subject). Three fresh students sit in the
 * section, and a section-grid record carries TWO cells for the
 * subject on TODAY's weekday (computed in-test, so the test is
 * day-robust) — two distinct slots, so the period count is 2,
 * not an accidental 1.
 *
 * Code read BEFORE asserting (all three surfaces query live
 * rows per request — no memo, no cache — so the expectation is
 * that every count flips in the same process, with no logout,
 * no login, no reset step between the before and after reads):
 *
 * (a) HomeController::teacherHomeData merges override subjects
 * into $subjects (F2 fix), derives class ids from them, and
 * filters today's periods through SectionTeacher::
 * applyTeacherFilter — which EXCLUDES a section-grid cell from
 * the class-level subject teacher whenever ANY override exists
 * for that section+subject, and INCLUDES it for the override
 * teacher. So before removal O reads Subjects 1 / Students 3 /
 * Periods 2 while D reads Subjects 1 / Students 3 / Periods 0;
 * after removal O reads 0 / 0 / 0 and D reads 1 / 3 / 2.
 * Student counts are class-level (distinct user_id over scope
 * classes), so D keeps 3 throughout — D leads the section
 * regardless of the override.
 *
 * (b) MyAccountController::bannerStats/bannerChips merge
 * override subjects the same way (parent fix, round 9). O's
 * banner reads Subjects 1 / Classes 1 / Students 3 before and
 * 0 / 0 / 0 after; D's reads 1 / 1 / 3 throughout.
 *
 * (c) Staffing::report() takes committed loads from
 * SectionTeacher::effectiveLoads() — placed cells resolved
 * through teacherIdFor (override first). O's committed load is
 * 2 before (both cells resolve to her) and 0 after; D's is 0
 * before and 2 after.
 *
 * Honesty note on "no re-login": feature-test HTTP calls share
 * one app instance, so a static memo WOULD survive across them
 * — that is exactly what the round-8 bug did. The test performs
 * no logout/login cycle; actingAs only selects the user for the
 * next in-process request, and the before/after reads happen in
 * the same test method around the real matrix save.
 */
class OwnerAllotmentRound22Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R22',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Fresh class + section (led by $direct) + subject directly taught by $direct. */
    protected function fixture(User $direct, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeCountClass R22' . $suffix]);
        $section = Section::create([
            'name' => 'R22 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Count Subject R22' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
            'slug' => 'probe-count-r22' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    /** $n fresh students in the section (grad 0, current session). */
    protected function makeStudents(MyClass $class, Section $section, string $suffix, int $n = 3): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $user = User::create([
                'name' => 'Probe Student R22' . $suffix . $i,
                'username' => 'PROBE-R22-S' . $suffix . $i,
                'email' => 'probe-r22-s' . strtolower($suffix) . $i . '@example.com',
                'password' => 'secret',
                'code' => 'PROBE-R22-S' . $suffix . $i,
                'user_type' => 'student',
            ]);
            StudentRecord::create([
                'session' => Qs::getCurrentSession(),
                'user_id' => $user->id,
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'adm_no' => 'R22-' . $suffix . '-' . $i,
                'grad' => 0,
            ]);
        }
    }

    /** Section-grid record with two cells for the subject on today's weekday. */
    protected function makeSectionGrid(MyClass $class, Section $section, Subject $subject, string $suffix): void
    {
        $record = TimeTableRecord::create([
            'name' => 'ProbeCount Record R22' . $suffix,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        $day = Carbon::now()->format('l');
        foreach ([['9:00 AM', '9:40 AM'], ['9:40 AM', '10:20 AM']] as [$from, $to]) {
            $slot = TimeSlot::create([
                'ttr_id' => $record->id,
                'hour_from' => (int) date('g', strtotime($from)),
                'min_from' => date('i', strtotime($from)),
                'meridian_from' => date('A', strtotime($from)),
                'hour_to' => (int) date('g', strtotime($to)),
                'min_to' => date('i', strtotime($to)),
                'meridian_to' => date('A', strtotime($to)),
                'time_from' => $from,
                'time_to' => $to,
                'timestamp_from' => strtotime($from),
                'timestamp_to' => strtotime($to),
                'full' => $from . ' - ' . $to,
            ]);
            TimeTable::create([
                'ttr_id' => $record->id,
                'ts_id' => $slot->id,
                'subject_id' => $subject->id,
                'day' => $day,
                'day_num' => (int) Carbon::now()->format('N'),
                'timestamp_from' => $from,
                'timestamp_to' => $to,
            ]);
        }
    }

    /** Give $override the override row; assert the scope anchor. */
    protected function giveOverride(User $override, MyClass $class, Section $section, Subject $subject): void
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $override->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame([$class->id], TeacherScope::classIds($override));
    }

    /** Remove the override through the real matrix save (blank clears). */
    protected function removeOverride(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
    }

    /** Dashboard KPIs for $teacher, via the same data the blade renders. */
    protected function dashboardKpis(User $teacher): array
    {
        $this->actingAs($teacher);
        $m = new \ReflectionMethod(HomeController::class, 'teacherHomeData');
        $m->setAccessible(true);
        $d = $m->invoke(app(HomeController::class), Carbon::now());

        return [
            'subjects' => $d['my_subjects']->count(),
            'students' => $d['my_student_count'],
            'periods' => $d['today_periods']->count(),
        ];
    }

    /** My Account banner stats for $teacher. */
    protected function bannerStats(User $teacher): array
    {
        $m = new \ReflectionMethod(MyAccountController::class, 'bannerStats');
        $m->setAccessible(true);
        $stats = $m->invoke(app(MyAccountController::class), $teacher);
        $out = [];
        foreach ($stats as $s) {
            $out[$s['label']] = $s['value'];
        }

        return $out;
    }

    /** Staffing report committed load for $teacher. */
    protected function staffingCommitted(User $teacher): int
    {
        foreach (Staffing::report()['teachers'] as $row) {
            if ((int) $row['teacher']->id === (int) $teacher->id) {
                return (int) $row['committed'];
            }
        }
        $this->fail('Teacher missing from Staffing::report()');
    }

    public function test_dashboard_kpis_flip_immediately_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R22-D', 'probe-r22-d@example.com');
        $o = $this->makeTeacher('PROBE-R22-O', 'probe-r22-o@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'A');
        $this->makeStudents($class, $section, 'A');
        $this->makeSectionGrid($class, $section, $subject, 'A');
        $this->giveOverride($o, $class, $section, $subject);

        // Before: O teaches only via the override — full counts;
        // D teaches the subject directly but the override excludes
        // him from the section-grid cells today.
        $this->assertSame(['subjects' => 1, 'students' => 3, 'periods' => 2], $this->dashboardKpis($o));
        $this->assertSame(['subjects' => 1, 'students' => 3, 'periods' => 0], $this->dashboardKpis($d));
        // And the rendered dashboard itself opens for O.
        $this->actingAs($o)->get(route('dashboard'))->assertOk();

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        // After, same process, no logout/login, no reset step: O's
        // counts collapse to zero and D inherits today's periods.
        $this->assertSame(['subjects' => 0, 'students' => 0, 'periods' => 0], $this->dashboardKpis($o));
        $this->assertSame(['subjects' => 1, 'students' => 3, 'periods' => 2], $this->dashboardKpis($d));
        $this->actingAs($o)->get(route('dashboard'))->assertOk();
    }

    public function test_my_account_banner_flips_immediately_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R22-D2', 'probe-r22-d2@example.com');
        $o = $this->makeTeacher('PROBE-R22-O2', 'probe-r22-o2@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B');
        $this->makeStudents($class, $section, 'B');
        $this->giveOverride($o, $class, $section, $subject);

        $this->assertSame(1, $this->bannerStats($o)['Subjects']);
        $this->assertSame(1, $this->bannerStats($o)['Classes']);
        $this->assertSame(3, $this->bannerStats($o)['Students']);
        $this->assertSame(1, $this->bannerStats($d)['Subjects']);
        $this->assertSame(1, $this->bannerStats($d)['Classes']);
        $this->assertSame(3, $this->bannerStats($d)['Students']);

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        $this->assertSame(0, $this->bannerStats($o)['Subjects']);
        $this->assertSame(0, $this->bannerStats($o)['Classes']);
        $this->assertSame(0, $this->bannerStats($o)['Students']);
        // Control: D's banner is untouched by O's removal.
        $this->assertSame(1, $this->bannerStats($d)['Subjects']);
        $this->assertSame(1, $this->bannerStats($d)['Classes']);
        $this->assertSame(3, $this->bannerStats($d)['Students']);
    }

    public function test_staffing_report_loads_flip_immediately_after_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R22-D3', 'probe-r22-d3@example.com');
        $o = $this->makeTeacher('PROBE-R22-O3', 'probe-r22-o3@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'C');
        $this->makeSectionGrid($class, $section, $subject, 'C');
        $this->giveOverride($o, $class, $section, $subject);

        // Placed-cell loads resolve through teacherIdFor: both
        // cells belong to the override teacher while it stands.
        $this->assertSame(2, $this->staffingCommitted($o));
        $this->assertSame(0, $this->staffingCommitted($d));

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        // After removal the same cells resolve to the direct
        // teacher — no re-login, no reset, no stale load.
        $this->assertSame(0, $this->staffingCommitted($o));
        $this->assertSame(2, $this->staffingCommitted($d));
    }
}
