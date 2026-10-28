<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Http\Controllers\MyAccountController;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Allocation;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 35 — END-TO-END staffing + allocation WORKFLOW chain
 * (owner expansion directive: a covered section expands into its full
 * interaction workflow; audit patterns P10 counts-vs-rows and P15
 * chain handoffs, plus P12 gate-by-surface for the marks leg).
 *
 * One continuous workflow through the REAL routes, with fresh
 * fixtures, asserting at EVERY handoff — staffing loads, allocation
 * issues, sections-index readiness, banner counts, dashboard
 * today-periods, and the marks effective-teacher gate — before and
 * after a real subjects.update reassignment. Per-link guards were
 * all proven separately (rounds 8/17/22/24/30/31/32); this is the
 * first test that runs them as one chain reacting to one change.
 *
 * Fixture shape (cap shrunk to 3 in-transaction, Round 24
 * precedent, so the fixture needs only a handful of placed cells):
 * - D1 overloaded via direct subjects + an override: X (class K,
 *   3 placed cells) + Z (class K2, 1 cell) + W override (class K3,
 *   1 cell) = 5 > cap 3.
 * - D2 light: Y (class K, 0 cells) = 0. D2 holds an eligibility
 *   row for X's name so subjects.update passes eligibilityRefusal.
 * - All 5 D1 cells sit on TODAY's weekday, so the teacher
 *   dashboard today-periods card is part of the chain too.
 * After the reassignment (X: D1 -> D2 via real subjects.update):
 * D1 = 2 (at cap, not over), D2 = 3 (at cap, not over), so the
 * overloaded issues for X and Z both clear entirely and no
 * recommendation names D1 for X anymore.
 */
class OwnerAllotmentRound35Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R35 ' . $code,
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

    protected function fixtureClass(string $suffix): array
    {
        // class_type_id 4 (Primary): fresh classes without a type
        // 500 the marks manage view (class_type->id on null) —
        // real classes always carry a type (F round-26 trap).
        $class = MyClass::create(['name' => 'ProbeChainClass R35' . $suffix, 'class_type_id' => 4]);
        $section = Section::create([
            'name' => 'R35 probe',
            'my_class_id' => $class->id,
            'teacher_id' => null,
        ]);

        return [$class, $section];
    }

    protected function fixtureSubject(MyClass $class, ?User $direct, string $suffix, string $name): Subject
    {
        return Subject::create([
            'name' => $name,
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
            'slug' => 'probe-chain-r35-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
    }

    /** Section-grid record; cells() creates its own slots. */
    protected function recordWithSlot(MyClass $class, Section $section, string $suffix, int $slotNo): array
    {
        $record = TimeTableRecord::create([
            'name' => 'ProbeChain Record R35' . $suffix,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);

        return [$record];
    }

    /**
     * Placed cells on TODAY's weekday (dashboard leg needs today).
     * One fresh slot per cell with a DISTINCT time window:
     * time_tables is UNIQUE(ttr_id, ts_id, day) AND time_slots is
     * UNIQUE(timestamp_from, timestamp_to, ttr_id), so same-day
     * cells in one record need distinct slots AND distinct times
     * (test-craft fix, not an app shape).
     */
    protected function cells(TimeTableRecord $record, Subject $subject, int $count): void
    {
        $day = Carbon::now()->format('l');
        for ($i = 0; $i < $count; $i++) {
            $from = date('g:i A', strtotime('9:00 AM +' . (40 * $i) . ' minutes'));
            $to = date('g:i A', strtotime($from . ' +40 minutes'));
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
                'day_num' => $i + 1,
                'timestamp_from' => $from,
                'timestamp_to' => $to,
            ]);
        }
    }

    protected function staffingRowFor(int $teacherId): ?array
    {
        foreach (Staffing::report()['teachers'] as $row) {
            if ((int) $row['teacher']->id === $teacherId) {
                return $row;
            }
        }

        return null;
    }

    protected function recommendationFor(int $subjectId): ?array
    {
        foreach (Allocation::recommendations() as $row) {
            if ((int) $row['subject']->id === $subjectId) {
                return $row;
            }
        }

        return null;
    }

    protected function bannerStat(User $me, string $label)
    {
        $m = new \ReflectionMethod(MyAccountController::class, 'bannerStats');
        $m->setAccessible(true);
        foreach ($m->invoke(app(MyAccountController::class), $me) as $s) {
            if ($s['label'] === $label) {
                return $s['value'];
            }
        }
        $this->fail("No banner stat labelled {$label}");
    }

    /**
     * One roster student in section S + one legacy mark row for X,
     * so marks.manage renders a grid (without a mark row, manage()
     * takes the noStudentRecord redirect — the 302 is the
     * empty-roster path, not a gate, and would mask the gate flip).
     */
    protected function rosterStudentWithMark(MyClass $class, Section $section, Subject $subject): \App\Models\Mark
    {
        $student = User::create([
            'name' => 'Probe Student R35',
            'username' => 'PROBE-R35-STU',
            'email' => 'probe-r35-stu@example.com',
            'password' => 'secret',
            'code' => 'PROBE-R35-STU',
            'user_type' => 'student',
        ]);
        \App\Models\StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'adm_no' => 'R35-CHAIN-1',
            'grad' => 0,
        ]);

        return \App\Models\Mark::create([
            'exam_id' => 1,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'student_id' => $student->id,
            'year' => Qs::getCurrentSession(),
        ]);
    }

    protected function unresolvedForSection(int $classId, int $sectionId): array
    {
        $response = $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => $classId]))
            ->assertOk();
        $unresolved = $response->viewData('unresolvedSubjects');

        return $unresolved[$sectionId] ?? [];
    }

    public function test_staffing_allocation_readiness_counts_marks_chain()
    {
        // Small in-transaction weekly cap so 5 placed cells overload.
        Setting::updateOrCreate(['type' => 'teacher_max_periods_week'], ['description' => '3']);
        $this->assertSame(3, Staffing::maxPeriodsPerWeek());

        $d1 = $this->makeTeacher('PROBE-R35-D1', 'probe-r35-d1@example.com');
        $d2 = $this->makeTeacher('PROBE-R35-D2', 'probe-r35-d2@example.com');
        $l = $this->makeTeacher('PROBE-R35-L', 'probe-r35-l@example.com');

        [$k, $s] = $this->fixtureClass('K');
        [$k2, $s2] = $this->fixtureClass('K2');
        [$k3, $s3] = $this->fixtureClass('K3');

        $x = $this->fixtureSubject($k, $d1, 'X', 'Probe Chain Subject R35X');
        $y = $this->fixtureSubject($k, $d2, 'Y', 'Probe Chain Subject R35Y');
        $z = $this->fixtureSubject($k2, $d1, 'Z', 'Probe Chain Subject R35Z');
        $w = $this->fixtureSubject($k3, $l, 'W', 'Probe Chain Subject R35W');

        // D1's override on W (section S3) + D2's eligibility for X.
        DB::table('section_subject_teachers')->insert([
            'section_id' => $s3->id,
            'subject_id' => $w->id,
            'teacher_id' => $d1->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        TeacherEligibility::create(['teacher_id' => $d2->id, 'subject_name' => $x->name]);

        // Placed cells: X 3 (D1 via direct), Z 1 (D1 via direct),
        // W 1 (D1 via override), Y 0 (D2 light).
        [$recX] = $this->recordWithSlot($k, $s, 'X', 1);
        $this->cells($recX, $x, 3);
        [$recZ] = $this->recordWithSlot($k2, $s2, 'Z', 1);
        $this->cells($recZ, $z, 1);
        [$recW] = $this->recordWithSlot($k3, $s3, 'W', 1);
        $this->cells($recW, $w, 1);
        $mark = $this->rosterStudentWithMark($k, $s, $x);

        $this->assertSame($d1->id, SectionTeacher::teacherIdFor($s->id, $x));
        $this->assertSame($d1->id, SectionTeacher::teacherIdFor($s3->id, $w));

        // (a) STAFFING BASELINE: D1 5 > cap 3 overloaded; D2 0 light.
        $d1Before = $this->staffingRowFor($d1->id);
        $d2Before = $this->staffingRowFor($d2->id);
        $this->assertNotNull($d1Before);
        $this->assertNotNull($d2Before);
        $this->assertSame(5, $d1Before['committed']);
        $this->assertTrue($d1Before['overloaded']);
        $this->assertSame(0, $d2Before['committed']);
        $this->assertFalse($d2Before['overloaded']);
        $this->assertSame(5, SectionTeacher::effectiveLoads()[$d1->id]);
        $this->assertSame(0, SectionTeacher::effectiveLoads()[$d2->id] ?? 0);
        $staffXBefore = Staffing::report()['subjects'][$x->name];
        $this->assertSame(6, $staffXBefore['demand']);
        $this->assertSame(6, $staffXBefore['committed']);
        $this->assertSame(0, $staffXBefore['unassigned']);
        // Readiness: X and Y both resolve for section S (live teachers).
        $unresolvedBefore = $this->unresolvedForSection($k->id, $s->id);
        $this->assertNotContains($x->name, $unresolvedBefore);
        $this->assertNotContains($y->name, $unresolvedBefore);

        // (b) ALLOCATION BASELINE: X overloaded; Z overloaded too.
        $recXBefore = $this->recommendationFor($x->id);
        $this->assertNotNull($recXBefore, 'X must carry an overloaded issue before the move');
        $this->assertSame('overloaded', $recXBefore['issue']);
        // Only D2 is an eligible candidate besides D1, and need 6
        // cannot fit under cap 3 on top of any load — honest shape:
        // no recommendation, cap reason named.
        $this->assertNull($recXBefore['recommended_id']);
        $this->assertStringContainsString('exceed the weekly cap', $recXBefore['reason']);
        $recZBefore = $this->recommendationFor($z->id);
        $this->assertNotNull($recZBefore);
        $this->assertSame('overloaded', $recZBefore['issue']);
        // W carries no issue: its direct teacher L is light; the
        // override never creates an allocation row (round-24 design).
        $this->assertNull($this->recommendationFor($w->id));

        // (f) COUNTS + MARKS BASELINE (P10: counts agree with loads).
        $this->assertSame(3, $this->bannerStat($d1, 'Subjects'));
        $this->assertSame(3, $this->bannerStat($d1, 'Classes'));
        $this->assertSame(1, $this->bannerStat($d1, 'Students'));
        $this->assertSame(1, $this->bannerStat($d2, 'Subjects'));
        $this->assertSame(1, $this->bannerStat($d2, 'Classes'));
        $this->assertSame(1, $this->bannerStat($d2, 'Students'));
        // Dashboard leg via view data (the blade renders this
        // collection as the "Today's Periods" KPI count; the
        // "N periods today" title string lives only in the
        // controller's reminders array, which this blade never
        // renders — asserting it in HTML was a test-craft error).
        $this->assertSame(5, $this->actingAs($d1)->get(route('dashboard'))->assertOk()
            ->viewData('today_periods')->count());
        $this->assertSame(0, $this->actingAs($d2)->get(route('dashboard'))->assertOk()
            ->viewData('today_periods')->count());
        $combo = [1, $k->id, $s->id, $x->id];
        $this->actingAs($d1)->get(route('marks.manage', $combo))->assertOk();
        $this->actingAs($d2)->get(route('marks.manage', $combo))->assertForbidden();

        // (c) REASSIGN through the REAL subjects.update path.
        $response = $this->actingAs($this->admin())->put(route('subjects.update', $x->id), [
            'name' => $x->name,
            'my_class_id' => $k->id,
            'periods_per_week' => 6,
            'teacher_id' => Qs::hash($d2->id),
        ]);
        $response->assertRedirect(route('subjects.index'));
        $this->assertSame($d2->id, $x->fresh()->teacher_id);
        $this->assertSame($d2->id, SectionTeacher::teacherIdFor($s->id, $x->fresh()));

        // (d) STAFFING AFTER: D1 5 -> 2 (at cap, not over);
        // D2 0 -> 3 (at cap, not over). No re-login, no reset.
        $d1After = $this->staffingRowFor($d1->id);
        $d2After = $this->staffingRowFor($d2->id);
        $this->assertSame(2, $d1After['committed']);
        $this->assertFalse($d1After['overloaded']);
        $this->assertSame(3, $d2After['committed']);
        $this->assertFalse($d2After['overloaded']);
        $this->assertSame(2, SectionTeacher::effectiveLoads()[$d1->id]);
        $this->assertSame(3, SectionTeacher::effectiveLoads()[$d2->id]);
        $staffXAfter = Staffing::report()['subjects'][$x->name];
        $this->assertSame(6, $staffXAfter['demand']);
        $this->assertSame(6, $staffXAfter['committed']);
        $this->assertSame(0, $staffXAfter['unassigned']);
        // Readiness still resolves: the new teacher is live.
        $unresolvedAfter = $this->unresolvedForSection($k->id, $s->id);
        $this->assertNotContains($x->name, $unresolvedAfter);
        $this->assertNotContains($y->name, $unresolvedAfter);

        // (e) ALLOCATION AFTER: X's overloaded issue is gone (not
        // merely renamed), and Z's clears with the same unload.
        $this->assertNull($this->recommendationFor($x->id));
        $this->assertNull($this->recommendationFor($z->id));
        $this->assertNull($this->recommendationFor($w->id));

        // (f) COUNTS + MARKS AFTER: every count moved with the load.
        $this->assertSame(2, $this->bannerStat($d1, 'Subjects'));
        $this->assertSame(2, $this->bannerStat($d1, 'Classes'));
        $this->assertSame(0, $this->bannerStat($d1, 'Students'));
        $this->assertSame(2, $this->bannerStat($d2, 'Subjects'));
        $this->assertSame(1, $this->bannerStat($d2, 'Classes'));
        $this->assertSame(1, $this->bannerStat($d2, 'Students'));
        $this->assertSame(2, $this->actingAs($d1)->get(route('dashboard'))->assertOk()
            ->viewData('today_periods')->count());
        $this->assertSame(3, $this->actingAs($d2)->get(route('dashboard'))->assertOk()
            ->viewData('today_periods')->count());
        $this->actingAs($d1)->get(route('marks.manage', $combo))->assertForbidden();
        $this->actingAs($d2)->get(route('marks.manage', $combo))->assertOk();
        // Save leg: D1's write is refused; D2's write saves a cell.
        $this->actingAs($d1)->put(route('marks.update', $combo), [])->assertForbidden();
        $this->actingAs($d2)->put(route('marks.update', $combo), ['t1_' . $mark->id => '10'])->assertOk();
        $this->assertSame(10, (int) $mark->fresh()->t1);
    }

    public function test_chain_control_light_teacher_and_unassigned_subject_unaffected()
    {
        // The chain's second leg: a light teacher with no cells and
        // a teacher-less subject keep their own shapes while the
        // chain above moves loads around them.
        Setting::updateOrCreate(['type' => 'teacher_max_periods_week'], ['description' => '3']);
        $light = $this->makeTeacher('PROBE-R35-LT', 'probe-r35-lt@example.com');
        [$k, $s] = $this->fixtureClass('CTL');
        $vacant = $this->fixtureSubject($k, null, 'CTL', 'Probe Chain Subject R35CTL');
        TeacherEligibility::create(['teacher_id' => $light->id, 'subject_name' => $vacant->name]);

        $row = $this->staffingRowFor($light->id);
        $this->assertSame(0, $row['committed']);
        $this->assertFalse($row['overloaded']);
        $rec = $this->recommendationFor($vacant->id);
        $this->assertNotNull($rec);
        $this->assertSame('unassigned', $rec['issue']);
        // Cap 3 + need 6: the light teacher IS eligible (pool
        // non-empty, so the reason is not 'No eligible teacher'),
        // but no candidate fits under the cap — honest null.
        $this->assertGreaterThanOrEqual(1, $rec['eligible_count']);
        $this->assertNull($rec['recommended_id']);
        $this->assertStringContainsString('exceed the weekly cap', $rec['reason']);
        $unresolved = $this->unresolvedForSection($k->id, $s->id);
        $this->assertContains($vacant->name, $unresolved);
    }
}
