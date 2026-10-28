<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Allocation;
use App\Support\Staffing;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 24 — ALLOCATION + STAFFING-PAGE immediacy after
 * override removal (the last unproven immediacy legs; readiness,
 * counts, gates, reads and substitution are all closed).
 *
 * BRIEF-PREMISE CORRECTION, stated openly (round-23 style), not
 * a leak: the brief expected the allocation recommendation and
 * the staffing-page badge for a teacher-less subject taught only
 * via override to FLIP when the override is removed. They do
 * NOT flip — by design, and the code says why:
 *
 * - Allocation::recommendations() keys 'unassigned' off the
 *   DIRECT subjects.teacher_id only (a null or non-live direct
 *   teacher). It never consults section_subject_teachers.
 * - Staffing::report() per-name COMMITTED likewise counts only
 *   direct-teacher rows; its docblock scope note says this
 *   estimate "deliberately does NOT split by
 *   section_subject_teachers overrides" and warns "Do not 'fix'
 *   this estimate by substituting override teachers for the
 *   whole subject."
 *
 * So a teacher-less subject is ALREADY 'unassigned' /
 * "Needs allocation" before removal, and stays so after —
 * removal changes nothing on these two surfaces, because
 * neither surface ever credited the override. The D-direct
 * control likewise never flips ("Covered" throughout). A flip
 * on either surface here would have been the bug (a surface
 * keyed off override presence while claiming to measure
 * direct assignment).
 *
 * The GENUINE flip leg is test 3: Allocation's 'overloaded'
 * issue keys off SectionTeacher::effectiveLoads(), which IS
 * override-aware (placed cells resolved through teacherIdFor).
 * So removing an override DOES clear an overloaded issue when
 * it drops the direct teacher's load to the cap — proven here
 * with a small in-transaction weekly cap (2) so the fixture
 * needs only 3 placed cells instead of 41.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * The cap-setting change in test 3 rolls back with them.
 */
class OwnerAllotmentRound24Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R24',
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

    /** Fresh class + untagged section + subject; $direct null = teacher-less. */
    protected function fixture(?User $direct, string $suffix, string $name): array
    {
        $class = MyClass::create(['name' => 'ProbeAllocClass R24' . $suffix]);
        $section = Section::create([
            'name' => 'R24 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
        ]);
        $subject = Subject::create([
            'name' => $name,
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
            'slug' => 'probe-alloc-r24' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    protected function giveOverride(User $override, MyClass $class, Section $section, Subject $subject, ?array $expectedClassIds = null): void
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $override->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actual = TeacherScope::classIds($override);
        sort($actual);
        $expected = $expectedClassIds ?? [$class->id];
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /** Remove the override through the real matrix save (blank clears). */
    protected function removeOverride(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame(0, DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $subject->id)->count());
    }

    /** Section-grid record + one 9:00–9:40 slot; returns [record, slot]. */
    protected function recordWithSlot(MyClass $class, Section $section, string $suffix): array
    {
        $record = TimeTableRecord::create([
            'name' => 'ProbeAlloc Record R24' . $suffix,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9,
            'min_from' => '00',
            'meridian_from' => 'AM',
            'hour_to' => 9,
            'min_to' => '40',
            'meridian_to' => 'AM',
            'time_from' => '9:00 AM',
            'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'),
            'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);

        return [$record, $slot];
    }

    protected function cell(TimeTableRecord $record, TimeSlot $slot, Subject $subject, string $day, int $dayNum): void
    {
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'subject_id' => $subject->id,
            'day' => $day,
            'day_num' => $dayNum,
            'timestamp_from' => '9:00 AM',
            'timestamp_to' => '9:40 AM',
        ]);
    }

    /** Recommendations row for one subject id, or null. */
    protected function recommendationFor(int $subjectId): ?array
    {
        foreach (Allocation::recommendations() as $row) {
            if ((int) $row['subject']->id === $subjectId) {
                return $row;
            }
        }

        return null;
    }

    /** Staffing per-name row, or null. */
    protected function staffingRowFor(string $name): ?array
    {
        return Staffing::report()['subjects'][$name] ?? null;
    }

    /** Table-row segment for a needle (first occurrence to next </tr>). */
    protected function rowSegment(string $html, string $needle): string
    {
        $start = strpos($html, $needle);
        $this->assertNotFalse($start, "Expected to find '{$needle}' in page HTML");
        $end = strpos($html, '</tr>', $start);
        $this->assertNotFalse($end, "Expected a closing </tr> after '{$needle}'");

        return substr($html, $start, $end - $start);
    }

    protected function allocationHtml(): string
    {
        return $this->actingAs($this->admin())
            ->get(route('planning.allocation'))->assertOk()->getContent();
    }

    protected function staffingHtml(): string
    {
        return $this->actingAs($this->admin())
            ->get(route('planning.staffing'))->assertOk()->getContent();
    }

    public function test_allocation_and_staffing_do_not_flip_for_teacherless_subject_with_override()
    {
        $o = $this->makeTeacher('PROBE-R24-O', 'probe-r24-o@example.com');
        // Teacher-less subject, taught ONLY via O's override.
        [$class, $section, $subject] = $this->fixture(null, 'A', 'Probe Allocation Subject R24A');
        // Record O as eligible for the name, so the staffing badge
        // is "Needs allocation" (not "No eligible teacher"): the
        // only variable under test is the override removal.
        TeacherEligibility::create(['teacher_id' => $o->id, 'subject_name' => $subject->name]);
        $this->giveOverride($o, $class, $section, $subject);

        // BEFORE: neither surface ever credited the override, so
        // both already report the subject as needing a teacher.
        $recBefore = $this->recommendationFor($subject->id);
        $this->assertNotNull($recBefore, 'Allocation must already flag the teacher-less subject');
        $this->assertSame('unassigned', $recBefore['issue']);
        $allocBefore = $this->allocationHtml();
        $segBefore = $this->rowSegment($allocBefore, $class->name . ' — ' . $subject->name);
        $this->assertStringContainsString('No teacher', $segBefore);
        $staffBefore = $this->staffingRowFor($subject->name);
        $this->assertNotNull($staffBefore);
        $this->assertSame(6, $staffBefore['demand']);
        $this->assertSame(0, $staffBefore['committed']);
        $this->assertSame(6, $staffBefore['unassigned']);
        $staffSegBefore = $this->rowSegment($this->staffingHtml(), $subject->name);
        $this->assertStringContainsString('Needs allocation', $staffSegBefore);
        $this->assertStringNotContainsString('Covered', $staffSegBefore);

        $this->removeOverride($section, $subject);

        // AFTER, same process, no re-login, no reset step: both
        // surfaces read exactly the same — no flip, by design.
        $recAfter = $this->recommendationFor($subject->id);
        $this->assertNotNull($recAfter);
        $this->assertSame('unassigned', $recAfter['issue']);
        $allocAfter = $this->allocationHtml();
        $segAfter = $this->rowSegment($allocAfter, $class->name . ' — ' . $subject->name);
        $this->assertStringContainsString('No teacher', $segAfter);
        $staffAfter = $this->staffingRowFor($subject->name);
        $this->assertNotNull($staffAfter);
        $this->assertSame(6, $staffAfter['demand']);
        $this->assertSame(0, $staffAfter['committed']);
        $this->assertSame(6, $staffAfter['unassigned']);
        $staffSegAfter = $this->rowSegment($this->staffingHtml(), $subject->name);
        $this->assertStringContainsString('Needs allocation', $staffSegAfter);
        $this->assertStringNotContainsString('Covered', $staffSegAfter);
    }

    public function test_allocation_and_staffing_stay_covered_when_direct_teacher_survives_removal()
    {
        $o = $this->makeTeacher('PROBE-R24-O2', 'probe-r24-o2@example.com');
        $d = $this->makeTeacher('PROBE-R24-D', 'probe-r24-d@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B', 'Probe Allocation Subject R24B');
        $this->giveOverride($o, $class, $section, $subject);

        // BEFORE: direct teacher resolves — no issue, Covered.
        $this->assertNull($this->recommendationFor($subject->id));
        $staffBefore = $this->staffingRowFor($subject->name);
        $this->assertNotNull($staffBefore);
        $this->assertSame(6, $staffBefore['demand']);
        $this->assertSame(6, $staffBefore['committed']);
        $this->assertSame(0, $staffBefore['unassigned']);
        $this->assertStringContainsString('Covered', $this->rowSegment($this->staffingHtml(), $subject->name));

        $this->removeOverride($section, $subject);

        // AFTER: D still resolves — still no issue, still Covered.
        $this->assertNull($this->recommendationFor($subject->id));
        $staffAfter = $this->staffingRowFor($subject->name);
        $this->assertNotNull($staffAfter);
        $this->assertSame(6, $staffAfter['demand']);
        $this->assertSame(6, $staffAfter['committed']);
        $this->assertSame(0, $staffAfter['unassigned']);
        $this->assertStringContainsString('Covered', $this->rowSegment($this->staffingHtml(), $subject->name));
    }

    public function test_overloaded_issue_clears_when_override_removal_drops_load_to_cap()
    {
        // Small in-transaction weekly cap so 3 placed cells exceed it.
        Setting::updateOrCreate(['type' => 'teacher_max_periods_week'], ['description' => '2']);
        $this->assertSame(2, Staffing::maxPeriodsPerWeek());

        $o = $this->makeTeacher('PROBE-R24-O3', 'probe-r24-o3@example.com');
        // X directly taught by O; Y teacher-less, taught only via O's override.
        [$classA, $sectionA, $subjectX] = $this->fixture($o, 'X', 'Probe Overload Subject R24X');
        [$classB, $sectionB, $subjectY] = $this->fixture(null, 'Y', 'Probe Overload Subject R24Y');
        // O already teaches X directly, so scope covers both classes.
        $this->giveOverride($o, $classB, $sectionB, $subjectY, [$classA->id, $classB->id]);

        // O's placed cells: 2 × X (direct) + 1 × Y (override) = 3 > cap 2.
        [$recordA, $slotA] = $this->recordWithSlot($classA, $sectionA, 'X');
        $this->cell($recordA, $slotA, $subjectX, 'Monday', 1);
        $this->cell($recordA, $slotA, $subjectX, 'Tuesday', 2);
        [$recordB, $slotB] = $this->recordWithSlot($classB, $sectionB, 'Y');
        $this->cell($recordB, $slotB, $subjectY, 'Monday', 1);
        $this->assertSame(3, Allocation::teacherLoads()[$o->id] ?? 0);

        // BEFORE: X is overloaded; the allocation page says so.
        $recBefore = $this->recommendationFor($subjectX->id);
        $this->assertNotNull($recBefore, 'X must be flagged while O is over cap');
        $this->assertSame('overloaded', $recBefore['issue']);
        $segBefore = $this->rowSegment($this->allocationHtml(), $classA->name . ' — ' . $subjectX->name);
        $this->assertStringContainsString('Teacher over cap', $segBefore);

        $this->removeOverride($sectionB, $subjectY);

        // AFTER, same process, no re-login, no reset step: O's
        // load drops to 2 = cap, so the overloaded issue is gone
        // (Y stays 'unassigned' — removal did not break Y's row).
        $this->assertSame(2, Allocation::teacherLoads()[$o->id] ?? 0);
        $this->assertNull($this->recommendationFor($subjectX->id));
        $this->assertStringNotContainsString($subjectX->name, $this->allocationHtml());
        $recY = $this->recommendationFor($subjectY->id);
        $this->assertNotNull($recY);
        $this->assertSame('unassigned', $recY['issue']);
    }
}
