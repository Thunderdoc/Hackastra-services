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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent D round 34 — P18 SIBLINGS: delete-without-cascade across
 * timetable-referenced rows (audit-patterns.md P18, proven in
 * round 33 for record delete). Every leg through the REAL routes
 * on fresh fixtures: one class, one teacher, one subject, one
 * section, one student, one section grid, one Monday 9:00 cell.
 *
 * Patterns applied: P18 delete-without-cascade (a delete that
 * removes only the parent row while children survive, and any
 * surface reading the children WITHOUT joining the parent keeps
 * rendering — or crashing on — the deleted thing); P10
 * count-vs-rows (validator report vs validate page vs pill);
 * P11 display-vs-stored (week/mine titles vs stored cells);
 * P13 artifacts name their emptiness ('Deleted class', '—').
 *
 * REAL FIXES IN THIS ROUND (failing-first, guard-proven below):
 * - F-R34-1 (in-scope TimetableValidator::report()): the
 *   coverage warnings read $record->my_class->name unguarded, so
 *   report() THREW for a record whose class was deleted — the
 *   validate page 500'd while cleanRecordIds() (which uses
 *   recordErrors, not report) still certified the record green
 *   (P10 disagreement). Now the warnings name 'Deleted class'.
 * - F-R34-2 (timetable views, flagged: blades outside the listed
 *   D blade scope, one-line optional() guards matching adjacent
 *   patterns): subjects/edit.blade.php:25 read $tt->subject->name
 *   unguarded, so the Manage edit tab 500'd on a dead-subject
 *   cell (line 26 already used optional() for the teacher).
 *   manage/show/mine/validate blades read $my_class->name /
 *   $child->my_class->name unguarded, so all four 500'd for a
 *   record whose class was deleted (the section name on show
 *   was already optional-guarded). All now render 'Deleted
 *   class' / '—' instead of crashing.
 *
 * OPEN FINDINGS (NOT fixed — guard-vs-cascade is an owner call,
 * and the fix files belong to other scopes):
 * - Subject delete orphans its cells (SubjectController::destroy
 *   deletes override rows only, Agent F scope): after F-R34-2
 *   the orphan is VISIBLE (Manage '—' row, deletable via the
 *   row's Delete button) and FLAGGED (validator dead-subject
 *   error, pill lost), and it no longer renders in teacher week
 *   views or as a subject title on student mine — but the cell
 *   row itself survives until an admin deletes it.
 * - Section delete (inactive only) leaves a GHOST GRID
 *   (SectionController::destroy deletes overrides + section
 *   only; recordForClassSection in SectionTeacher, Agent F scope,
 *   never checks section existence): the deleted section's grid
 *   still resolves for its students, still renders in teacher
 *   week views (section suffix dropped), and still validates
 *   CLEAN. The ghost assertions below document this current
 *   shape and MUST change when the owner picks guard-vs-cascade.
 * - Class delete leaves the WHOLE ghost (MyClassController::
 *   destroy, parent-owned, is a bare destroy): sections,
 *   subjects, records, cells and slots all survive. After
 *   F-R34-1/3 the surfaces render honestly ('Deleted class')
 *   instead of 500ing, but the ghost data remains and the
 *   record keeps its green pill. Guard-vs-cascade = owner call.
 * - Class-print blade (print.blade.php:110, Agent B scope) also
 *   reads $my_class->name unguarded and was NOT touched here —
 *   handed to Agent B round 21.
 * - Section delete REFUSAL is locked: every seeded section is
 *   active=1, so the 'default section' guard refuses ALL seeded
 *   section deletes; only UI-created (active=0) sections are
 *   deletable at all.
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableDeleteCascadeRound34Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function user(string $type, string $tag): User
    {
        return User::create([
            'name' => 'R34 ' . $tag . ' ' . uniqid(),
            'email' => 'r34-' . strtolower($type) . '-' . uniqid() . '@test.local',
            'password' => bcrypt($type),
            'user_type' => $type,
            'code' => 'R34' . strtoupper(substr($type, 0, 1)) . uniqid(),
        ]);
    }

    protected function fixture(int $sectionActive): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'R34 Class ' . $u]);
        $teacher = $this->user('teacher', 'T');
        $subject = Subject::create([
            'name' => 'R34Sub' . $u, 'slug' => 'r34-sub-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6, 'stream' => null,
        ]);
        $section = Section::create([
            'name' => 'R34Sec' . $u, 'my_class_id' => $class->id,
            'active' => $sectionActive, 'teacher_id' => $teacher->id, 'stream' => null,
        ]);
        $stuUser = $this->user('student', 'Stu');
        StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $stuUser->id,
            'my_class_id' => $class->id, 'section_id' => $section->id,
            'adm_no' => 'R34/' . $u, 'year_admitted' => '2026',
        ]);
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => 'R34 Grid ' . $u, 'my_class_id' => $class->id, 'section_id' => $section->id,
        ])->assertOk()->assertJson(['ok' => true]);
        $record = TimeTableRecord::where('name', 'R34 Grid ' . $u)->firstOrFail();
        // Premise update (timetable approval, owner order
        // 2026-10-29): new records start as drafts; this fixture's
        // grids are exercised as in-force timetables.
        TimeTableRecord::where('id', $record->id)->update(['approval_status' => 'approved']);
        $record = $record->fresh();
        $slot = TimeSlot::create([
            'ttr_id' => $record->id, 'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $record->id, 'ts_id' => $slot->id, 'day' => 'Monday', 'subject_id' => $subject->id,
        ])->assertOk()->assertJson(['ok' => true]);

        return compact('class', 'teacher', 'subject', 'section', 'stuUser', 'record', 'slot');
    }

    protected function weekSubjects(User $teacher): array
    {
        $week = $this->actingAs($teacher)->get(route('tt.mine'))->assertOk();
        $entries = $week->getOriginalContent()->getData()['entries'] ?? [];

        return collect($entries)->pluck('subject')->all();
    }

    protected function mineMondayTitle(User $student): ?string
    {
        $mine = $this->actingAs($student)->get(route('tt.mine'))->assertOk();
        $map = $mine->getOriginalContent()->getData()['map'] ?? [];

        return $map['Monday']['9:00 AM - 9:40 AM']['title'] ?? null;
    }

    public function test_subject_delete_orphans_cell_but_surfaces_hold_and_cleanup_works()
    {
        $f = $this->fixture(1);
        extract($f);

        $this->actingAs($this->admin())->from(route('subjects.index'))
            ->delete(route('subjects.destroy', $subject->id))
            ->assertRedirect(route('subjects.index'))
            ->assertSessionHas('flash_success', 'Record Deleted Successfully');
        $this->assertDatabaseMissing('subjects', ['id' => $subject->id]);
        // Override rows go with the subject (pre-existing behaviour);
        // the placed CELL and its slot survive — the P18 orphan.
        $this->assertSame(0, DB::table('section_subject_teachers')->where('subject_id', $subject->id)->count());
        $this->assertSame(1, TimeTable::where('ttr_id', $record->id)->count(),
            'subject delete must leave the placed cell row behind (P18 orphan — see docblock)');
        $this->assertSame(1, TimeSlot::where('ttr_id', $record->id)->count());
        $this->assertTrue($record->fresh() !== null, 'the grid record itself survives');

        // F-R34-2 leg: Manage must render (pre-fix: 500 on the edit tab).
        $manage = $this->actingAs($this->admin())->get(route('ttr.manage', $record->id))->assertOk();
        $this->assertCount(1, $manage->getOriginalContent()->getData()['tts'] ?? [],
            'Manage must still list the orphan cell so an admin can delete it');
        // Validator flags the dead subject; the pill is lost (P10).
        $report = TimetableValidator::report($record->fresh());
        $this->assertContains('A period on Monday points at a subject that no longer exists.', $report['errors']);
        $validate = $this->actingAs($this->admin())->get(route('ttr.validate', $record->id))->assertOk();
        $this->assertSame($report['errors'], $validate->getOriginalContent()->getData()['v_errors']);
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));
        // Week view drops the orphan (filter needs a live subject);
        // student mine shows the honest '—', never a stale title.
        $this->assertSame([], $this->weekSubjects($teacher));
        $this->assertSame('—', $this->mineMondayTitle($stuUser));

        // Cleanup path works now that Manage renders: deleting the
        // orphan cell leaves a clean, empty grid.
        $cell = TimeTable::where('ttr_id', $record->id)->firstOrFail();
        $this->actingAs($this->admin())->from(route('ttr.manage', $record->id))
            ->delete(route('tt.delete', $cell->id));
        $this->assertSame(0, TimeTable::where('ttr_id', $record->id)->count());
    }

    public function test_section_delete_active_refused_with_exact_warning()
    {
        $f = $this->fixture(1);
        extract($f);

        $this->actingAs($this->admin())->from(route('sections.index'))
            ->delete(route('sections.destroy', $section->id))
            ->assertRedirect(route('sections.index'))
            ->assertSessionHas('pop_warning', 'Every class must have a default section, You Cannot Delete It');
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
        $this->assertSame(1, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertSame(1, TimeSlot::where('ttr_id', $record->id)->count());
    }

    public function test_section_delete_inactive_leaves_ghost_grid_open_finding()
    {
        // OPEN FINDING (see docblock): this test DOCUMENTS the
        // current ghost shape; it must change when the owner picks
        // guard-vs-cascade for section delete. It is NOT an
        // assertion that the ghost is correct behaviour.
        $f = $this->fixture(0);
        extract($f);

        $this->actingAs($this->admin())->from(route('sections.index'))
            ->delete(route('sections.destroy', $section->id))
            ->assertRedirect(route('sections.index'))
            ->assertSessionHas('flash_success', 'Record Deleted Successfully');
        $this->assertDatabaseMissing('sections', ['id' => $section->id]);
        $this->assertSame(0, DB::table('section_subject_teachers')->where('section_id', $section->id)->count());
        // Record, cell and slot all survive the section delete.
        $this->assertTrue($record->fresh() !== null);
        $this->assertSame(1, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertSame(1, TimeSlot::where('ttr_id', $record->id)->count());
        // Ghost: the section's students still resolve to the
        // deleted section's grid, the teacher week still renders
        // the period (section suffix dropped), and the validator
        // still certifies the record clean.
        $mine = $this->actingAs($stuUser)->get(route('tt.mine'))->assertOk();
        $this->assertSame($record->id, (int) $mine->getOriginalContent()->getData()['ttr']->id);
        $this->assertSame($subject->name, $this->mineMondayTitle($stuUser));
        $this->assertContains($subject->name, $this->weekSubjects($teacher));
        $this->actingAs($this->admin())->get(route('ttr.manage', $record->id))->assertOk();
        $this->assertSame([], TimetableValidator::report($record->fresh())['errors']);
        $this->assertContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));
    }

    public function test_class_delete_surfaces_render_deleted_class_label_not_500()
    {
        $f = $this->fixture(1);
        extract($f);

        $this->actingAs($this->admin())->from(route('classes.index'))
            ->delete(route('classes.destroy', $class->id))
            ->assertRedirect(route('classes.index'))
            ->assertSessionHas('flash_success', 'Record Deleted Successfully');
        $this->assertDatabaseMissing('my_classes', ['id' => $class->id]);
        // OPEN FINDING (see docblock): the whole ghost survives —
        // sections, subjects, record, cells, slots. Only the
        // surfaces are fixed here; guard-vs-cascade is an owner call.
        $this->assertDatabaseHas('sections', ['id' => $section->id]);
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->assertTrue($record->fresh() !== null);
        $this->assertSame(1, TimeTable::where('ttr_id', $record->id)->count());
        $this->assertSame(1, TimeSlot::where('ttr_id', $record->id)->count());

        // F-R34-1/3 legs: every screen surface renders (pre-fix:
        // manage, show, student mine and validate all 500'd) and
        // names the deleted class honestly instead of crashing.
        $manage = $this->actingAs($this->admin())->get(route('ttr.manage', $record->id))->assertOk();
        $this->assertStringContainsString('Deleted class', $manage->getContent());
        $show = $this->actingAs($this->admin())->get(route('ttr.show', $record->id))->assertOk();
        $this->assertStringContainsString('Deleted class', $show->getContent());
        $mine = $this->actingAs($stuUser)->get(route('tt.mine'))->assertOk();
        $this->assertStringContainsString('Deleted class', $mine->getContent());
        // Validator report must not throw (pre-fix: ErrorException
        // at TimetableValidator:232) and must name the deleted class.
        $report = TimetableValidator::report($record->fresh());
        $this->assertStringContainsString('Deleted class', implode(' ', $report['warnings']));
        $validate = $this->actingAs($this->admin())->get(route('ttr.validate', $record->id))->assertOk();
        $this->assertSame($report['errors'], $validate->getOriginalContent()->getData()['v_errors']);
        // Teacher week still renders the period (subject alive);
        // the class string is empty (both class relations dead).
        $this->assertContains($subject->name, $this->weekSubjects($teacher));
        // Index stays up; the ghost record is still listed there.
        $index = $this->actingAs($this->admin())->get(route('tt.index'))->assertOk();
        $this->assertTrue(collect($index->getOriginalContent()->getData()['tt_records'] ?? [])
            ->pluck('id')->contains($record->id));
    }
}
