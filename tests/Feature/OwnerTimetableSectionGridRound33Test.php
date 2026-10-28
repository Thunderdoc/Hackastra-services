<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 33 — section-grid precedence WORKFLOW chain (P15):
 * class grid only -> section grid created -> sibling unaffected ->
 * stream flag on the section grid -> section grid deleted ->
 * fallback. Every link through the REAL routes on fresh fixtures.
 *
 * Patterns applied (audit-patterns.md): P15 chain handoff (per-link
 * guards do not prove the workflow — assert scope at EVERY handoff);
 * P10 count-vs-rows (validate page v_errors must agree with
 * cleanRecordIds, both ways); P11 display-vs-stored (the grid a
 * student sees must be exactly the record the resolver returns);
 * P5 list-vs-detail (tt.index/validate/show must agree on one
 * record); P2 scope (a section grid for class K never renders
 * for class X).
 *
 * HONEST SHAPES LOCKED HERE (not bugs):
 * - Student/parent mine() is RECORD-selected: the section grid
 *   REPLACES the class grid for that section only (SectionTeacher::
 *   recordForClassSection, section first, else whole-class).
 * - Teacher week view is PERIOD-aggregated, not record-selected:
 *   creating a section grid ADDS its periods to the section
 *   teacher's week view; it does not "switch" her away from the
 *   class grid's periods. The test asserts the gain, not a switch.
 * - Manage placement stays possible for out-of-stream subjects
 *   (visibility, not restriction — rounds 9/20/21 boundary); only
 *   validation differs.
 *
 * REAL FINDING F-R33-1 (failing-first, fixed in this round):
 * delete_record removed only the record row. Its cells and slots
 * survived as orphans, so the deleted grid's periods KEPT
 * rendering in the direct teacher's week view (period aggregation
 * has no record-existence check) even though students had
 * correctly fallen back to the class grid. Fix: delete_record
 * deletes the record's cells + slots in the same transaction
 * (the attendance guard already refuses when any cell carries a
 * submitted session, so no session can point at a deleted cell).
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableSectionGridRound33Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function user(string $type, string $tag): User
    {
        return User::create([
            'name' => 'R33 ' . $tag . ' ' . uniqid(),
            'email' => 'r33-' . strtolower($type) . '-' . uniqid() . '@test.local',
            'password' => bcrypt($type),
            'user_type' => $type,
            'code' => 'R33' . strtoupper(substr($type, 0, 1)) . uniqid(),
        ]);
    }

    protected function slot(int $recordId, string $from, string $to): TimeSlot
    {
        return TimeSlot::create([
            'ttr_id' => $recordId,
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
    }

    /** Create a record through the REAL ttr.store route; returns the stored row. */
    protected function createRecordViaRoute(int $classId, ?int $sectionId, string $name): TimeTableRecord
    {
        $payload = ['name' => $name, 'my_class_id' => $classId];
        if ($sectionId) {
            $payload['section_id'] = $sectionId;
        }
        $this->actingAs($this->admin())->post(route('ttr.store'), $payload)->assertOk()
            ->assertJson(['ok' => true]);

        return TimeTableRecord::where('name', $name)->firstOrFail();
    }

    /** Place one cell through the REAL tt.store (Manage add) route. */
    protected function placeCell(int $recordId, int $slotId, int $subjectId, string $day = 'Monday'): void
    {
        $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $recordId, 'ts_id' => $slotId, 'day' => $day, 'subject_id' => $subjectId,
        ])->assertOk()->assertJson(['ok' => true]);
    }

    /**
     * Fresh chain fixture: class K with streamed section S1 (pcb)
     * + untagged sibling section S2, students in each, a parent
     * linked to the S1 student, Common (untagged) + PcmOnly (pcm)
     * + PcbOnly (pcb) subjects with distinct teachers.
     * Returns everything the tests need, with NO grid yet.
     */
    protected function fixture(): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R33 Class ' . $u]);
        $tCommon = $this->user('teacher', 'Common');
        $tPcm = $this->user('teacher', 'Pcm');
        $tPcb = $this->user('teacher', 'Pcb');
        $common = Subject::create([
            'name' => 'R33Common' . $u, 'slug' => 'r33-common-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $tCommon->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $pcm = Subject::create([
            'name' => 'R33Pcm' . $u, 'slug' => 'r33-pcm-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $tPcm->id,
            'periods_per_week' => 6, 'stream' => 'pcm',
        ]);
        $pcb = Subject::create([
            'name' => 'R33Pcb' . $u, 'slug' => 'r33-pcb-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $tPcb->id,
            'periods_per_week' => 6, 'stream' => 'pcb',
        ]);
        $s1 = Section::create([
            'name' => 'R33SecA' . $u, 'my_class_id' => $class->id,
            'active' => 1, 'teacher_id' => $tCommon->id, 'stream' => 'pcb',
        ]);
        $s2 = Section::create([
            'name' => 'R33SecB' . $u, 'my_class_id' => $class->id,
            'active' => 1, 'teacher_id' => $tCommon->id, 'stream' => null,
        ]);
        $parent = $this->user('parent', 'Parent');
        $stu1User = $this->user('student', 'Stu1');
        $stu1 = StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $stu1User->id,
            'my_class_id' => $class->id, 'section_id' => $s1->id,
            'my_parent_id' => $parent->id, 'adm_no' => 'R33/' . $u . '/1', 'year_admitted' => '2026',
        ]);
        $stu2User = $this->user('student', 'Stu2');
        $stu2 = StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $stu2User->id,
            'my_class_id' => $class->id, 'section_id' => $s2->id,
            'adm_no' => 'R33/' . $u . '/2', 'year_admitted' => '2026',
        ]);

        return compact('class', 'tCommon', 'tPcm', 'tPcb', 'common', 'pcm', 'pcb',
            's1', 's2', 'parent', 'stu1User', 'stu1', 'stu2User', 'stu2');
    }

    /** The Monday slot-1 title a student sees on tt.mine, or null when no grid. */
    protected function mineMondayTitle(User $student): ?string
    {
        $mine = $this->actingAs($student)->get(route('tt.mine'))->assertOk();
        $map = $mine->getOriginalContent()->getData()['map'] ?? [];

        return $map['Monday']['9:00 AM - 9:40 AM']['title'] ?? null;
    }

    protected function mineRecordId(User $student): ?int
    {
        $mine = $this->actingAs($student)->get(route('tt.mine'))->assertOk();
        $ttr = $mine->getOriginalContent()->getData()['ttr'] ?? null;

        return $ttr ? (int) $ttr->id : null;
    }

    public function test_section_grid_precedence_chain_student_parent_sibling_cross_class()
    {
        $f = $this->fixture();
        extract($f);

        // (a) CLASS GRID ONLY: both sections see the class grid.
        $classGrid = $this->createRecordViaRoute($class->id, null, 'R33 ClassGrid ' . uniqid());
        $classSlot = $this->slot($classGrid->id, '9:00 AM', '9:40 AM');
        $this->placeCell($classGrid->id, $classSlot->id, $common->id);
        $this->assertSame($classGrid->id, $this->mineRecordId($stu1User),
            'S1 student must resolve to the class grid while no section grid exists');
        $this->assertSame($common->name, $this->mineMondayTitle($stu1User));
        $this->assertSame($classGrid->id, $this->mineRecordId($stu2User),
            'sibling section student must also resolve to the class grid');
        $parentMine = $this->actingAs($parent)
            ->get(route('tt.mine', ['child' => $stu1->id]))->assertOk();
        $this->assertSame($classGrid->id, (int) $parentMine->getOriginalContent()->getData()['ttr']->id,
            'parent viewing the S1 child must see the class grid first');

        // (b) SECTION GRID for S1 only: S1 switches, S2 stays, parent follows the child.
        $secGrid = $this->createRecordViaRoute($class->id, $s1->id, 'R33 SecGrid ' . uniqid());
        $secSlot = $this->slot($secGrid->id, '9:00 AM', '9:40 AM');
        $this->placeCell($secGrid->id, $secSlot->id, $pcb->id);
        $this->assertSame($secGrid->id, $this->mineRecordId($stu1User),
            'S1 student must switch to the section grid the moment it exists');
        $this->assertSame($pcb->name, $this->mineMondayTitle($stu1User),
            'S1 student Monday slot 1 must now be the section grid subject, not the class subject');
        $this->assertSame($classGrid->id, $this->mineRecordId($stu2User),
            'sibling section must NOT switch when another section gains a grid');
        $this->assertSame($common->name, $this->mineMondayTitle($stu2User));
        $parentMine = $this->actingAs($parent)
            ->get(route('tt.mine', ['child' => $stu1->id]))->assertOk();
        $this->assertSame($secGrid->id, (int) $parentMine->getOriginalContent()->getData()['ttr']->id,
            'parent viewing the S1 child must follow the child onto the section grid');

        // Teacher week view is period-aggregated (honest shape): the
        // section teacher GAINS the section period; the class-grid
        // period stays visible too — no record "switch" for teachers.
        $weekPcb = $this->actingAs($tPcb)->get(route('tt.mine'))->assertOk();
        $entriesPcb = collect($weekPcb->getOriginalContent()->getData()['entries'] ?? []);
        $this->assertTrue(
            $entriesPcb->where('day', 'Monday')->where('subject', $pcb->name)
                ->contains(fn ($e) => str_contains($e['class'], $s1->name)),
            'section-grid teacher week must show the section period named with its section');
        $weekCommon = $this->actingAs($tCommon)->get(route('tt.mine'))->assertOk();
        $entriesCommon = collect($weekCommon->getOriginalContent()->getData()['entries'] ?? []);
        $this->assertTrue(
            $entriesCommon->where('day', 'Monday')->where('subject', $common->name)->isNotEmpty(),
            'class-grid teacher must keep seeing the class-grid period after the section grid appears');

        // Cross-class negative (P2): a foreign class never renders K's grids.
        $u = uniqid();
        $classX = MyClass::create(['name' => 'R33 ClassX ' . $u]);
        $tX = $this->user('teacher', 'XTeacher');
        $xSub = Subject::create([
            'name' => 'R33XSub' . $u, 'slug' => 'r33-xsub-' . $u,
            'my_class_id' => $classX->id, 'teacher_id' => $tX->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $sX = Section::create([
            'name' => 'R33SecX' . $u, 'my_class_id' => $classX->id,
            'active' => 1, 'teacher_id' => $tX->id, 'stream' => null,
        ]);
        $stuXUser = $this->user('student', 'StuX');
        StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $stuXUser->id,
            'my_class_id' => $classX->id, 'section_id' => $sX->id,
            'adm_no' => 'R33/X/' . $u, 'year_admitted' => '2026',
        ]);
        $xGrid = $this->createRecordViaRoute($classX->id, null, 'R33 XGrid ' . $u);
        $xSlot = $this->slot($xGrid->id, '9:00 AM', '9:40 AM');
        $this->placeCell($xGrid->id, $xSlot->id, $xSub->id);
        $this->assertSame($xGrid->id, $this->mineRecordId($stuXUser),
            'foreign-class student must resolve to her own class grid, never K grids');
        $this->assertSame($xSub->name, $this->mineMondayTitle($stuXUser));
        // Foreign-class student must be refused the K section grid detail (guardRecordScope).
        $this->actingAs($stuXUser)->get(route('ttr.show', $secGrid->id))->assertForbidden();
        // Own-section student must open her section grid detail.
        $this->actingAs($stu1User)->get(route('ttr.show', $secGrid->id))->assertOk();
    }

    public function test_section_grid_stream_flag_and_delete_fallback_cleanup()
    {
        $f = $this->fixture();
        extract($f);

        $classGrid = $this->createRecordViaRoute($class->id, null, 'R33 ClassGrid2 ' . uniqid());
        $classSlot = $this->slot($classGrid->id, '9:00 AM', '9:40 AM');
        $this->placeCell($classGrid->id, $classSlot->id, $common->id);
        $secGrid = $this->createRecordViaRoute($class->id, $s1->id, 'R33 SecGrid2 ' . uniqid());
        $secSlot1 = $this->slot($secGrid->id, '9:00 AM', '9:40 AM');
        $secSlot2 = $this->slot($secGrid->id, '9:40 AM', '10:20 AM');
        $this->placeCell($secGrid->id, $secSlot1->id, $pcb->id);

        // (c) STREAM: Manage form for the pcb section grid offers
        // Common + PcbOnly but NOT the pcm-only subject (round 20
        // boundary, now inside the chain).
        $manage = $this->actingAs($this->admin())->get(route('ttr.manage', $secGrid->id))->assertOk();
        $manageSubjects = $manage->getOriginalContent()->getData()['subjects'] ?? collect();
        $manageNames = collect($manageSubjects)->pluck('name')->all();
        $this->assertContains($common->name, $manageNames, 'Manage must offer the common subject');
        $this->assertContains($pcb->name, $manageNames, 'Manage must offer the in-stream subject');
        $this->assertNotContains($pcm->name, $manageNames, 'Manage must not offer the out-of-stream subject');

        // Placement stays possible (visibility, not restriction) but
        // validation flags exactly one stream error, and the pill
        // agrees with the validate page (P10).
        $this->placeCell($secGrid->id, $secSlot2->id, $pcm->id, 'Tuesday');
        $report = TimetableValidator::report($secGrid->fresh());
        $streamErrors = array_values(array_filter($report['errors'],
            fn ($e) => str_contains($e, "is not offered to this section's stream")));
        $this->assertCount(1, $streamErrors, 'section grid must carry exactly one stream error');
        $this->assertStringContainsString($pcm->name, $streamErrors[0], 'stream error must name the subject');
        $this->assertStringContainsString('PCB (Science – Biology)', $streamErrors[0],
            'stream error must name the section stream');
        $validate = $this->actingAs($this->admin())->get(route('ttr.validate', $secGrid->id))->assertOk();
        $this->assertSame($report['errors'], $validate->getOriginalContent()->getData()['v_errors'],
            'validate page must agree with the validator report');
        $this->assertNotContains($secGrid->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'flagged section record must lose its green Validated pill');
        $this->assertContains($classGrid->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()),
            'clean class grid must keep its green Validated pill');

        // (d) DELETE the section grid: student falls back to the
        // class grid with no stale section cell (P11), and — the
        // F-R33-1 leg — the teacher week view must stop showing
        // the deleted grid's periods, with no orphan cells/slots.
        $this->actingAs($this->admin())->from(route('tt.index'))
            ->delete(route('ttr.destroy', $secGrid->id))->assertRedirect(route('tt.index'));
        $this->assertDatabaseMissing('time_table_records', ['id' => $secGrid->id]);
        $this->assertSame($classGrid->id, $this->mineRecordId($stu1User),
            'S1 student must fall back to the class grid after the section grid is deleted');
        $this->assertSame($common->name, $this->mineMondayTitle($stu1User),
            'fallback grid must show the class subject, never a stale section cell');
        $this->assertSame(0, TimeTable::where('ttr_id', $secGrid->id)->count(),
            'F-R33-1: deleting a record must delete its cells, not orphan them');
        $this->assertSame(0, TimeSlot::where('ttr_id', $secGrid->id)->count(),
            'F-R33-1: deleting a record must delete its slots, not orphan them');
        $weekAfter = $this->actingAs($tPcb)->get(route('tt.mine'))->assertOk();
        $entriesAfter = collect($weekAfter->getOriginalContent()->getData()['entries'] ?? []);
        $this->assertFalse(
            $entriesAfter->where('day', 'Monday')->where('subject', $pcb->name)->isNotEmpty(),
            'F-R33-1: teacher week must stop showing the deleted grid period');
    }
}
