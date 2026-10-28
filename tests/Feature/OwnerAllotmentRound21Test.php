<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\MyClass;
use App\Models\QuestionBankQuestion;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 21 — READ surfaces after override removal.
 *
 * Rounds 18–20 proved the WRITE surfaces: scope drops immediately,
 * creation for the removed subject is refused, but the owner's own
 * existing rows stay editable (assignment non-retarget exemption,
 * round 18) / deletable (lesson-plan destroy ownership-or-admin,
 * round 20) / fully alive (quiz lifecycle ownership-only). This
 * round asks the same question of the three READ surfaces.
 *
 * Fixture (same shape as rounds 18/20, fully fresh, rolls back):
 * fresh class + section led by D + subject directly taught by D.
 * Override teacher O holds ONLY the override row for
 * (section, subject), so O qualifies for the class ONLY via that
 * override. While the override stands, O creates an assignment
 * and a bank question for the section/subject. Admin then removes
 * the override through the real matrix save (blank clears).
 *
 * VERDICTS (read from code BEFORE asserting, stated openly —
 * the brief's expectation holds for exactly one of the three
 * surfaces, and the other two are a brief-premise correction in
 * the round-20 style, NOT a leak):
 *
 * (a) Class Overview — SUBJECT-BASED visibility
 * (visibleClasses reads subjects.teacher_id + live override rows,
 * never ownership). After removal the class VANISHES from O's
 * overview index and ?class_id= refuses her with 403. This is
 * the brief's expected outcome, and it holds.
 *
 * (b) Homework Tracker — OWNERSHIP-scoped, not subject-scoped
 * (index filters teacher_id = caller; detail gates on
 * teacher_id). O's own assignment STAYS listed after removal,
 * with its detail still opening. That is NOT stale visibility:
 * the row is hers (teacher_id = O), created while she taught
 * the section, and hiding it would orphan her grading work —
 * the exact round-18 principle ("removal does not
 * retroactively orphan her work"). The current-teaching gate
 * correctly applies to CREATION (refused, round 18). Control:
 * D's assignment for the same class/subject still 403s for O.
 *
 * (c) Question Bank — likewise OWNERSHIP-scoped on index
 * (teacher_id = caller), while the CREATE pickers and STORE
 * follow CURRENT teaching (teacherSubjectIds reads live
 * override rows). After removal O's own banked question STAYS
 * on her index, but the subject VANISHES from the create-form
 * dropdown and a new store for the removed subject is refused
 * with validation errors and no row. Same distinction as (b):
 * own content persists, new teaching surface closes.
 *
 * REAL FINDING F-R21-1 (QuestionBankController, in F scope):
 * the bank UPDATE froze after removal — the round-18 pattern
 * on a surface the brief did not ask about. Edit opens 200
 * (ownership-only) but every update save failed validation,
 * because validatedData re-checks teacherSubjectIds (and the
 * section teacherIdFor) even for a NON-retargeting edit of the
 * owner's own question. The new effective teacher cannot edit
 * it either (ownership gate) and admin cannot (teacher-only
 * guard) — the question was editable by NOBODY (deletable by
 * the owner only, so less severe than round 20's destroy
 * freeze, but the same bug class). Fix, mirroring round 18
 * exactly: a non-retargeting update (submitted
 * class/section/subject triple identical to the existing row)
 * is exempt from the teaching checks; retargets and creates
 * still check; the subject-class match check stays always-on.
 *
 * Route mechanics: sections.teachers.update takes
 * Qs::hash($section->id); assignments, question_bank and
 * class_overview/homework_tracker take RAW ids / query params.
 */
class OwnerAllotmentRound21Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R21',
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
        $class = MyClass::create(['name' => 'ProbeRemovalClass R21' . $suffix]);
        $section = Section::create([
            'name' => 'R21 probe',
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
        ]);
        $subject = Subject::create([
            'name' => 'Probe Removal Subject R21' . $suffix,
            'my_class_id' => $class->id,
            'teacher_id' => $direct->id,
            'slug' => 'probe-removal-r21' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        return [$class, $section, $subject];
    }

    /** Give $override the override row, and have her create an assignment + a bank question for it. */
    protected function setupOverrideWithWork(User $override, MyClass $class, Section $section, Subject $subject): array
    {
        DB::table('section_subject_teachers')->insert([
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'teacher_id' => $override->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame([$class->id], TeacherScope::classIds($override));
        $this->assertSame($override->id, SectionTeacher::teacherIdFor($section->id, $subject));

        // O creates the assignment while the override stands (round-6 gate).
        $this->actingAs($override)
            ->post(route('assignments.store'), [
                'title' => 'R21 removal probe assignment',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect();
        $assignment = Assignment::where('title', 'R21 removal probe assignment')->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $assignment->teacher_id);

        // O banks a question while the override stands (validatedData teaching checks pass).
        $this->actingAs($override)
            ->post(route('question_bank.store'), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'question' => 'R21 bank probe question?',
                'options' => ['Alpha', 'Beta'],
                'correct_index' => 0,
                'marks' => 1,
            ])
            ->assertRedirect(route('question_bank.index'));
        $bankQuestion = QuestionBankQuestion::where('question', 'R21 bank probe question?')->orderBy('id', 'desc')->firstOrFail();
        $this->assertSame($override->id, (int) $bankQuestion->teacher_id);

        return [$assignment, $bankQuestion];
    }

    public function test_overview_follows_current_teaching_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R21-D', 'probe-r21-d@example.com');
        $o = $this->makeTeacher('PROBE-R21-O', 'probe-r21-o@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'A');
        $this->setupOverrideWithWork($o, $class, $section, $subject);

        // While teaching: the class renders on the overview.
        $this->actingAs($o)->get(route('class_overview.index', ['class_id' => $class->id]))
            ->assertOk()
            ->assertSee('ProbeRemovalClass R21A');

        // Admin removes the override through the real matrix save.
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame([], TeacherScope::classIds($o));

        // After removal: the class is GONE from the index, and a
        // direct ?class_id= request is refused — overview follows
        // CURRENT teaching, not history.
        $this->actingAs($o)->get(route('class_overview.index'))
            ->assertOk()
            ->assertDontSee('ProbeRemovalClass R21A');
        $this->actingAs($o)->get(route('class_overview.index', ['class_id' => $class->id]))
            ->assertForbidden();

        // Control: the teacher who still teaches the class keeps it.
        $this->actingAs($d)->get(route('class_overview.index', ['class_id' => $class->id]))
            ->assertOk()
            ->assertSee('ProbeRemovalClass R21A');
    }

    public function test_homework_tracker_ownership_persists_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R21-D2', 'probe-r21-d2@example.com');
        $o = $this->makeTeacher('PROBE-R21-O2', 'probe-r21-o2@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'B');
        [$assignment] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        // D's own assignment for the same class/subject (control row O must never see).
        // Whole-class (section_id null): teacherIdFor(null) resolves to the subject
        // teacher D, so D can create it even while O's section override stands —
        // a section-scoped D assignment would 403, because the override wins.
        $this->actingAs($d)
            ->post(route('assignments.store'), [
                'title' => 'R21 direct teacher assignment',
                'description' => 'probe',
                'my_class_id' => $class->id,
                'subject_id' => $subject->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ])
            ->assertRedirect();
        $directAssignment = Assignment::where('title', 'R21 direct teacher assignment')->orderBy('id', 'desc')->firstOrFail();

        // While teaching: O sees her own assignment, never D's.
        $this->actingAs($o)->get(route('homework_tracker.index'))
            ->assertOk()
            ->assertSee('R21 removal probe assignment')
            ->assertDontSee('R21 direct teacher assignment');
        $this->actingAs($o)->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]))
            ->assertOk();

        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame([], TeacherScope::classIds($o));

        // After removal: O's OWN assignment still lists (ownership
        // persistence, the round-18 principle — hiding it would
        // orphan her grading work), and its detail still opens.
        // This is not a leak: no other teacher's row appears.
        $this->actingAs($o)->get(route('homework_tracker.index'))
            ->assertOk()
            ->assertSee('R21 removal probe assignment')
            ->assertDontSee('R21 direct teacher assignment');
        $this->actingAs($o)->get(route('homework_tracker.index', ['assignment_id' => $assignment->id]))
            ->assertOk();
        // And D's assignment detail still refuses her.
        $this->actingAs($o)->get(route('homework_tracker.index', ['assignment_id' => $directAssignment->id]))
            ->assertForbidden();
    }

    public function test_question_bank_ownership_persists_but_pickers_follow_current_teaching()
    {
        $d = $this->makeTeacher('PROBE-R21-D3', 'probe-r21-d3@example.com');
        $o = $this->makeTeacher('PROBE-R21-O3', 'probe-r21-o3@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'C');
        $this->setupOverrideWithWork($o, $class, $section, $subject);

        // While teaching: the subject is offered on the create form.
        $this->actingAs($o)->get(route('question_bank.create'))
            ->assertOk()
            ->assertSee('Probe Removal Subject R21C');
        $this->actingAs($o)->get(route('question_bank.index'))
            ->assertOk()
            ->assertSee('R21 bank probe question?');

        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame([], TeacherScope::classIds($o));

        // After removal: her OWN banked question still lists
        // (ownership persistence), but the subject is GONE from
        // the create-form picker — new teaching surface closed.
        $this->actingAs($o)->get(route('question_bank.index'))
            ->assertOk()
            ->assertSee('R21 bank probe question?');
        $this->actingAs($o)->get(route('question_bank.create'))
            ->assertOk()
            ->assertDontSee('Probe Removal Subject R21C');

        // And a new store for the removed subject is refused with
        // validation errors and no row.
        $before = QuestionBankQuestion::where('question', 'R21 bank probe second?')->count();
        $this->actingAs($o)
            ->post(route('question_bank.store'), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'question' => 'R21 bank probe second?',
                'options' => ['One', 'Two'],
                'correct_index' => 1,
                'marks' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('subject_id');
        $this->assertSame($before, QuestionBankQuestion::where('question', 'R21 bank probe second?')->count());
    }

    public function test_question_bank_non_retarget_update_survives_override_removal()
    {
        $d = $this->makeTeacher('PROBE-R21-D4', 'probe-r21-d4@example.com');
        $o = $this->makeTeacher('PROBE-R21-O4', 'probe-r21-o4@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'D');
        [, $bankQuestion] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame([], TeacherScope::classIds($o));

        // F-R21-1 (pre-fix this failed validation): the edit page
        // opens (ownership-only) and a NON-retargeting save — same
        // class/section/subject triple, only the text changes —
        // succeeds. No new teaching surface is gained.
        $this->actingAs($o)->get(route('question_bank.edit', $bankQuestion->id))->assertOk();
        $this->actingAs($o)
            ->put(route('question_bank.update', $bankQuestion->id), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $subject->id,
                'question' => 'R21 bank probe question edited?',
                'options' => ['Alpha', 'Beta', 'Gamma'],
                'correct_index' => 2,
                'marks' => 2,
            ])
            ->assertRedirect(route('question_bank.index'));
        $this->assertSame('R21 bank probe question edited?', $bankQuestion->fresh()->question);
        $this->assertSame(2, (int) $bankQuestion->fresh()->correct_index);
    }

    public function test_question_bank_retarget_update_still_refused_after_removal()
    {
        $d = $this->makeTeacher('PROBE-R21-D5', 'probe-r21-d5@example.com');
        $o = $this->makeTeacher('PROBE-R21-O5', 'probe-r21-o5@example.com');
        [$class, $section, $subject] = $this->fixture($d, 'E');
        [, $bankQuestion] = $this->setupOverrideWithWork($o, $class, $section, $subject);

        // A second subject in the same class, taught by D only.
        $other = Subject::create([
            'name' => 'Probe Removal Subject R21E2',
            'my_class_id' => $class->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-removal-r21e2',
            'periods_per_week' => 6,
        ]);

        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame([], TeacherScope::classIds($o));

        // Retargeting the owner's own question onto a subject she
        // does not teach is still refused, and the row is
        // byte-identical (the exemption covers non-retargets
        // only — same shape as round 18's assignment fix).
        $this->actingAs($o)
            ->put(route('question_bank.update', $bankQuestion->id), [
                'my_class_id' => $class->id,
                'section_id' => $section->id,
                'subject_id' => $other->id,
                'question' => 'R21 bank probe question retargeted?',
                'options' => ['Alpha', 'Beta'],
                'correct_index' => 0,
                'marks' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('subject_id');
        $this->assertSame('R21 bank probe question?', $bankQuestion->fresh()->question);
        $this->assertSame($subject->id, (int) $bankQuestion->fresh()->subject_id);
    }
}
