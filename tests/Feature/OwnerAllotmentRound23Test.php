<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 23 — READINESS + SUBSTITUTION immediacy after
 * override removal (the last unproven immediacy legs).
 *
 * Round 22 closed the COUNT leg (dashboard, banner, staffing
 * loads all flip immediately). This round closes readiness and
 * substitution — with one brief-premise CORRECTION, stated
 * openly (round-20/21 style), not a leak:
 *
 * The brief's fixture ("O holds ONLY an override; D is the
 * direct teacher") can NEVER flip readiness, because readiness
 * (SectionController::index, round 2) keys off RESOLUTION, not
 * override presence: a subject is unassigned only when NEITHER
 * a live override NOR a live direct teacher resolves. With D
 * directly teaching, removing O's override leaves D resolving
 * — the section correctly stays "All staffed". The flip case is
 * a subject with NO direct teacher at all (subjects.teacher_id
 * is nullable since migration 2026_10_23_000007), taught ONLY
 * via O's override: before removal it resolves through O
 * ("All staffed"); after removal nothing resolves ("1
 * unassigned: <name>"). Both shapes are test-locked here: the
 * flip (test 1) and the stays-staffed control (test 2).
 *
 * Edit-matrix markers (sections/edit.blade.php:69): $eff is the
 * override else the direct teacher; $effLive is false only for
 * a NON-NULL unresolved id. So after removal of the ONLY
 * teacher, $eff is null → the marker is "— No teacher
 * assigned" (not "No live teacher assigned", which is the
 * ghost/demoted/suspended shape). Both markers are asserted
 * exactly.
 *
 * Substitution (test 3) is an OBSERVATION, not a fix target:
 * store derives the absent teacher from teacherIdFor and checks
 * only that the substitute is an active teacher, is not the
 * absent teacher, and has no clash — it never checks whether
 * the substitute currently teaches the subject, because cover
 * duty is not teaching. Index visibility follows the STORED
 * row (absent_teacher_id / substitute_teacher_id), not current
 * teaching — so a row created before removal still shows to its
 * stored parties after removal, and a row created after removal
 * names the new effective teacher as absent. All of that is
 * reported exactly, not "fixed": inventing a teaching check on
 * cover duty would be a product call.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * Dates: 2026-11-02 and 2026-11-09 are both Mondays (established
 * by agent D round 21), matching the Monday grid cell.
 */
class OwnerAllotmentRound23Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R23',
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

    /** Fresh class + section + subject; $direct null = teacher-less subject. */
    protected function fixture(?User $direct, string $suffix): array
    {
        $class = MyClass::create(['name' => 'ProbeReadinessClass R23' . $suffix]);
        $section = Section::create([
            'name' => 'R23 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Readiness Subject R23' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct ? $direct->id : null,
            'slug' => 'probe-readiness-r23' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
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
        $this->assertSame(0, DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $subject->id)->count());
    }

    /** Sections-index HTML for the fresh class, as admin. */
    protected function indexHtml(MyClass $class): string
    {
        return $this->actingAs($this->admin())
            ->get(route('sections.index', ['class_id' => $class->id]))
            ->assertOk()->getContent();
    }

    /** Section edit-page HTML, as admin (raw section id). */
    protected function editHtml(Section $section): string
    {
        return $this->actingAs($this->admin())
            ->get(route('sections.edit', $section->id))
            ->assertOk()->getContent();
    }

    /** Section-grid record with ONE Monday cell for the subject. */
    protected function makeMondayCell(MyClass $class, Section $section, Subject $subject, string $suffix): TimeTable
    {
        $record = TimeTableRecord::create([
            'name' => 'ProbeSub Record R23' . $suffix,
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

        return TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'subject_id' => $subject->id,
            'day' => 'Monday',
            'day_num' => 1,
            'timestamp_from' => '9:00 AM',
            'timestamp_to' => '9:40 AM',
        ]);
    }

    public function test_readiness_flips_immediately_when_override_only_teacher_removed()
    {
        $o = $this->makeTeacher('PROBE-R23-O', 'probe-r23-o@example.com');
        // Teacher-less subject: NO direct teacher, taught ONLY via O.
        [$class, $section, $subject] = $this->fixture(null, 'A');
        $this->giveOverride($o, $class, $section, $subject);

        // Before: the override resolves — staffed, no marker.
        // (Assert '1 unassigned:' absence, not bare 'unassigned':
        // the column header itself reads "Unassigned".)
        $before = $this->indexHtml($class);
        $this->assertStringContainsString('All staffed', $before);
        $this->assertStringNotContainsString('1 unassigned:', $before);
        $editBefore = $this->editHtml($section);
        $this->assertStringNotContainsString('No teacher assigned', $editBefore);
        $this->assertStringNotContainsString('No live teacher assigned', $editBefore);

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        // After, same process, no re-login, no reset step: the
        // subject is unassigned on the index AND flagged on the
        // edit matrix — with the null-teacher marker, not the
        // ghost-teacher one.
        $after = $this->indexHtml($class);
        $this->assertStringContainsString('1 unassigned: Probe Readiness Subject R23A', $after);
        $this->assertStringNotContainsString('All staffed', $after);
        $editAfter = $this->editHtml($section);
        $this->assertStringContainsString('No teacher assigned', $editAfter);
        $this->assertStringNotContainsString('No live teacher assigned', $editAfter);
    }

    public function test_readiness_stays_staffed_when_direct_teacher_survives_removal()
    {
        // Brief-premise correction (see docblock): with D directly
        // teaching, removing O's override must NOT flip readiness
        // — resolution survives through D. A flip here would be
        // the bug (readiness keyed off override presence).
        $d = $this->makeTeacher('PROBE-R23-D', 'probe-r23-d@example.com');
        $o = $this->makeTeacher('PROBE-R23-O2', 'probe-r23-o2@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B');
        $this->giveOverride($o, $class, $section, $subject);

        $this->assertStringContainsString('All staffed', $this->indexHtml($class));

        $this->removeOverride($section, $subject);
        $this->assertSame([], TeacherScope::classIds($o));

        $after = $this->indexHtml($class);
        $this->assertStringContainsString('All staffed', $after);
        $this->assertStringNotContainsString('1 unassigned:', $after);
        $this->assertStringNotContainsString('No teacher assigned', $this->editHtml($section));
    }

    public function test_substitution_naming_and_visibility_around_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R23-D3', 'probe-r23-d3@example.com');
        $o = $this->makeTeacher('PROBE-R23-O3', 'probe-r23-o3@example.com');
        $s = $this->makeTeacher('PROBE-R23-S', 'probe-r23-s@example.com');
        $u = $this->makeTeacher('PROBE-R23-U', 'probe-r23-u@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'C');
        $cell = $this->makeMondayCell($class, $section, $subject, 'C');
        $this->giveOverride($o, $class, $section, $subject);

        $subjectName = 'Probe Readiness Subject R23C';
        // Overnight menus 2026-10-29, item 19: teachers no longer
        // open the board at all, so per-teacher row visibility is
        // retired — the board is read by admin (all rows) and
        // principal (monitoring). The stored-row semantics below
        // (absent/substitute derivation, post-removal naming) are
        // still locked through the admin board.
        $adminSees = function () use ($subjectName): bool {
            $html = $this->actingAs($this->admin())->get(route('substitutions.index'))->assertOk()->getContent();

            return str_contains($html, $subjectName);
        };
        $teacherRefused = function (User $teacher): void {
            $this->actingAs($teacher)->get(route('substitutions.index'))->assertForbidden();
        };

        // Control: naming O for her OWN override period is refused
        // — store derives absent = O via teacherIdFor, and the
        // substitute cannot be the absent teacher.
        $this->actingAs($this->admin())->post(route('substitutions.store'), [
            'date' => '2026-11-02',
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $o->id,
        ])->assertSessionHasErrors('substitute_teacher_id');
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->count());

        // Pre-removal row: absent = O (override), substitute = S.
        $this->actingAs($this->admin())->post(route('substitutions.store'), [
            'date' => '2026-11-02',
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $s->id,
        ])->assertRedirect(route('substitutions.index'));
        $pre = Substitution::where('time_table_id', $cell->id)->whereDate('date', '2026-11-02')->firstOrFail();
        $this->assertSame($o->id, (int) $pre->absent_teacher_id);
        $this->assertSame($s->id, (int) $pre->substitute_teacher_id);
        $this->assertTrue($adminSees());
        $teacherRefused($o);
        $teacherRefused($s);
        $teacherRefused($u);

        $this->removeOverride($section, $subject);

        // Stored-row semantics: the pre-removal row still exists
        // with its stored parties (O as absent, S as substitute)
        // even though O no longer teaches — visible on the admin
        // board, refused to every teacher including D, never a
        // party to that row.
        $this->assertTrue($adminSees());
        $teacherRefused($o);
        $teacherRefused($s);
        $teacherRefused($d);
        $teacherRefused($u);

        // After-removal naming: cover duty is NOT teaching, so
        // store imposes no teaching check — admin CAN name O as
        // substitute now that the absent teacher is D, and the
        // row records absent = D, substitute = O.
        $this->actingAs($this->admin())->post(route('substitutions.store'), [
            'date' => '2026-11-09',
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $o->id,
        ])->assertRedirect(route('substitutions.index'));
        $post = Substitution::where('time_table_id', $cell->id)->whereDate('date', '2026-11-09')->firstOrFail();
        $this->assertSame($d->id, (int) $post->absent_teacher_id);
        $this->assertSame($o->id, (int) $post->substitute_teacher_id);
        // Board visibility after both rows exist: the admin
        // board shows both dates (both rows), the principal
        // board shows both dates too (monitoring), and every
        // teacher is refused outright — the old per-teacher
        // date discrimination (O both, D post-only, S pre-only,
        // U neither) is retired with teacher board access.
        $adminBoard = $this->actingAs($this->admin())->get(route('substitutions.index'))->assertOk()->getContent();
        $this->assertStringContainsString('02 Nov 2026', $adminBoard);
        $this->assertStringContainsString('09 Nov 2026', $adminBoard);
        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $principalBoard = $this->actingAs($principal)->get(route('substitutions.index'))->assertOk()->getContent();
        $this->assertStringContainsString('02 Nov 2026', $principalBoard);
        $this->assertStringContainsString('09 Nov 2026', $principalBoard);
        $teacherRefused($o);
        $teacherRefused($d);
        $teacherRefused($s);
        $teacherRefused($u);
    }
}
