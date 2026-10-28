<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\ParentMessage;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\MarkScheme;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 34 — END-TO-END ALLOTMENT LIFECYCLE WORKFLOW.
 *
 * Owner expansion directive: a covered section expands into its full
 * interaction workflow. Rounds 1–33 proved the allotment links
 * separately (scope flips, marks gate, messaging close, content
 * persistence, push survival, leader matrix, liveness guards) but
 * never as ONE chain through the real routes. This suite runs one
 * continuous workflow: ASSIGN → TEACH → REMOVE → RE-ASSIGN, and
 * asserts every flip immediately, with no re-login and no reset
 * step (TeacherScope's static memo was removed in round 8, so all
 * scope reads here are live).
 *
 * Patterns applied (audit-patterns.md): P1 liveness (all fixture
 * teachers are live — status null — so no liveness leg interferes),
 * P3 stale-resolution freeze (teacherIdFor flips are asserted, never
 * assumed), P10 count-vs-rows (dashboard KPIs vs My Account banner
 * vs TeacherScope must agree at every state), P12 ownership-vs-
 * teaching gates (each surface is asserted at its own granularity,
 * never forced into uniformity).
 *
 * BRIEF-PREMISE CORRECTION, proven from code before writing tests
 * (AssignmentController::index, lines ~103-106): a teacher's
 * assignment index is OWNERSHIP-scoped
 * (where('teacher_id', $user->id)) — so the new teacher N does NOT
 * see O's old assignment in her own teacher index after re-assign.
 * That is the deliberate P12 shape (teacher sees own), not a break
 * in the chain: the assignment stays visible to the SECTION'S
 * STUDENTS (student index is class+section scoped) and to admin
 * (reads everything), while N cannot push it (ownership 403).
 * Test 2 locks exactly that shape instead of asserting the brief's
 * wording. If the owner wants handover visibility for the incoming
 * teacher, that is a product call, not a silent fix.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions):
 * class K (ONE section S led by D, subjects X + Y direct-taught by
 * D, scheme Theory 60 / Internal 30 / Viva 10 for X under exam 1),
 * teachers O (no link) and N (no link), two students in S with
 * linked parents P1/P2. Exam 1 must be unpublished or every push
 * refuses on the published guard.
 *
 * Matrix-save shape (SectionController::updateTeachers): PUT
 * sections.teachers.update with a HASHED section id and a
 * 'teachers' map of subject_id => HASHED teacher id (values are
 * Qs::decodeHash'd — a raw id decodes to '' and would CLEAR the
 * row instead of assigning it; P9 hash trap). '' clears.
 */
class OwnerAllotmentRound34Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R34',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function makeParent(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Parent R34',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'parent',
        ]);
    }

    protected function makeStudent(string $code, string $email, MyClass $class, Section $section, ?User $parent = null): User
    {
        $student = User::create([
            'name' => 'Probe Student R34',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'student',
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'my_parent_id' => $parent ? $parent->id : null,
            'adm_no' => 'R34-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /**
     * Returns [O, D, N, K, S, X, Y, P1, P2, ST1, ST2].
     * O and N start with no link to K at all.
     */
    protected function fixture(string $suffix): array
    {
        $o = $this->makeTeacher('PROBE-R34-O' . $suffix, 'probe-r34-o' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R34-D' . $suffix, 'probe-r34-d' . strtolower($suffix) . '@example.com');
        $n = $this->makeTeacher('PROBE-R34-N' . $suffix, 'probe-r34-n' . strtolower($suffix) . '@example.com');

        // class_type_id is REQUIRED for the marks pipeline
        // (MarkComputation::recomputeSubject reads findTypeByClass);
        // 4 = Primary, as Class 1.
        $k = MyClass::create(['name' => 'ProbeR34Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R34 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $x = Subject::create([
            'name' => 'Probe Chain Subject R34' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r34-x-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        $y = Subject::create([
            'name' => 'Probe Colleague Subject R34' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r34-y-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        // Seeded-English-shaped scheme for X under exam 1, so the
        // marks grid and the Internal push both have real targets.
        ExamSubjectComponent::create(['exam_id' => 1, 'subject_id' => $x->id, 'name' => 'Theory', 'short_code' => 'TH', 'max_marks' => 60, 'sort' => 1]);
        ExamSubjectComponent::create(['exam_id' => 1, 'subject_id' => $x->id, 'name' => 'Internal', 'short_code' => 'INT', 'max_marks' => 30, 'sort' => 2]);
        ExamSubjectComponent::create(['exam_id' => 1, 'subject_id' => $x->id, 'name' => 'Viva', 'short_code' => 'VIV', 'max_marks' => 10, 'sort' => 3]);

        $p1 = $this->makeParent('PROBE-R34-P1' . $suffix, 'probe-r34-p1' . strtolower($suffix) . '@example.com');
        $p2 = $this->makeParent('PROBE-R34-P2' . $suffix, 'probe-r34-p2' . strtolower($suffix) . '@example.com');
        $st1 = $this->makeStudent('PROBE-R34-S1' . $suffix, 'probe-r34-s1' . strtolower($suffix) . '@example.com', $k, $s, $p1);
        $st2 = $this->makeStudent('PROBE-R34-S2' . $suffix, 'probe-r34-s2' . strtolower($suffix) . '@example.com', $k, $s, $p2);

        $this->assertFalse((bool) DB::table('exams')->where('id', 1)->value('results_published'),
            'Exam 1 must be unpublished or every push refuses on the published guard.');
        $this->assertSame([], TeacherScope::classIds($o), 'O must start with no class scope.');
        $this->assertSame([], TeacherScope::classIds($n), 'N must start with no class scope.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $x), 'X must start direct-taught by D.');

        return [$o, $d, $n, $k, $s, $x, $y, $p1, $p2, $st1, $st2];
    }

    protected function matrixSave(Section $section, array $teachers): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => $teachers])
            ->assertRedirect(route('sections.index'));
    }

    protected function assignOverride(Section $section, Subject $x, Subject $y, User $teacher): void
    {
        $this->matrixSave($section, [$x->id => Qs::hash($teacher->id), $y->id => '']);
        $this->assertTrue(DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $x->id)
            ->where('teacher_id', $teacher->id)->exists(),
            'The matrix save must actually store the override.');
        $this->assertSame($teacher->id, SectionTeacher::teacherIdFor($section->id, $x),
            'teacherIdFor must flip to the override teacher immediately.');
    }

    protected function removeOverride(Section $section, Subject $x, Subject $y): void
    {
        $this->matrixSave($section, [$x->id => '', $y->id => '']);
        $this->assertFalse(DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $x->id)->exists(),
            'The matrix save must actually remove the override.');
    }

    /** Dashboard KPIs for a teacher, from view data (no HTML scraping). */
    protected function dashboardCounts(User $teacher): array
    {
        $resp = $this->actingAs($teacher)->get(route('dashboard'))->assertOk();
        return [
            'subjects' => count($resp->viewData('my_subjects') ?? []),
            'classes' => (int) $resp->viewData('my_class_count'),
            'students' => (int) $resp->viewData('my_student_count'),
            'scope' => (int) $resp->viewData('scope_class_count'),
        ];
    }

    /** My Account banner stats for a teacher, from view data. */
    protected function bannerValues(User $teacher): array
    {
        $resp = $this->actingAs($teacher)->get(route('my_account'))->assertOk();
        $out = [];
        foreach ($resp->viewData('banner_stats') ?? [] as $stat) {
            $out[$stat['label']] = (int) $stat['value'];
        }

        return $out;
    }

    protected function assertCounts(User $teacher, int $subjects, int $classes, int $students, string $when): void
    {
        $dash = $this->dashboardCounts($teacher);
        $this->assertSame($subjects, $dash['subjects'], "Dashboard subjects $when.");
        $this->assertSame($classes, $dash['classes'], "Dashboard classes $when.");
        $this->assertSame($students, $dash['students'], "Dashboard students $when.");
        $this->assertSame($classes, $dash['scope'], "Dashboard scope classes $when.");
        $banner = $this->bannerValues($teacher);
        $this->assertSame($subjects, $banner['Subjects'] ?? null, "Banner subjects $when (P10: must agree with dashboard).");
        $this->assertSame($classes, $banner['Classes'] ?? null, "Banner classes $when (P10).");
        $this->assertSame($students, $banner['Students'] ?? null, "Banner students $when (P10).");
    }

    protected function createAssignmentAs(User $teacher, MyClass $k, Section $s, Subject $x, string $title)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.index'))
            ->post(route('assignments.store'), [
                'title' => $title,
                'description' => 'chain probe',
                'my_class_id' => $k->id,
                'section_id' => $s->id,
                'subject_id' => $x->id,
                'max_marks' => 100,
                'due_at' => '2030-01-01 10:00:00',
                'allow_late' => false,
            ]);
    }

    protected function gradeAs(User $teacher, AssignmentSubmission $sub, int $marks)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.show', $sub->assignment_id))
            ->post(route('assignments.grade', $sub->id), ['marks_awarded' => $marks]);
    }

    protected function pushAs(User $teacher, Assignment $a)
    {
        return $this->actingAs($teacher)
            ->from(route('assignments.show', $a->id))
            ->post(route('assignments.push_internal', $a->id), ['exam_id' => 1]);
    }

    protected function internalComponentId(Subject $x): int
    {
        foreach (MarkScheme::components(1, $x->id) as $c) {
            if ($c['name'] === 'Internal') {
                return (int) $c['id'];
            }
        }
        $this->fail('No Internal component resolved for the chain subject.');
    }

    protected function internalScore(User $student, Subject $x): ?int
    {
        $markId = DB::table('marks')
            ->where('exam_id', 1)->where('student_id', $student->id)
            ->where('subject_id', $x->id)->value('id');
        if (!$markId) {
            return null;
        }
        $score = DB::table('mark_components')
            ->where('mark_id', $markId)->where('component_id', $this->internalComponentId($x))->value('score');

        return $score === null ? null : (int) $score;
    }

    /** Current marks-entry grid as the update form posts it, with one cell changed. */
    protected function marksPayloadWithOneChange(array $combo): array
    {
        [$exam, $class, $section, $subject] = $combo;
        $data = [];
        foreach (Mark::where(['exam_id' => $exam, 'my_class_id' => $class, 'section_id' => $section, 'subject_id' => $subject])->get() as $mk) {
            foreach (MarkScheme::components($exam, $subject) as $comp) {
                $v = MarkScheme::scoreFor($mk, $comp);
                $key = $comp['field'] ? $comp['field'] . '_' . $mk->id : 'comp_' . $mk->id . '_' . $comp['id'];
                $data[$key] = $v === null ? '' : (string) $v;
            }
        }
        $this->assertNotEmpty($data, 'Marks grid must have cells for a write test.');
        $marks = Mark::where(['exam_id' => $exam, 'my_class_id' => $class, 'section_id' => $section, 'subject_id' => $subject])->get();
        $first = $marks->first();
        $comp = MarkScheme::components($exam, $subject)[0];
        $key = $comp['field'] ? $comp['field'] . '_' . $first->id : 'comp_' . $first->id . '_' . $comp['id'];
        $current = $data[$key] === '' ? null : (int) $data[$key];
        $max = (int) $comp['max_marks'];
        $new = $current === null ? min(10, $max) : ($current < $max ? $current + 1 : $current - 1);
        $data[$key] = (string) $new;

        return [$data, $first->id, $comp['id'], $new];
    }

    public function test_full_chain_assign_teach_remove_reassign()
    {
        [$o, $d, $n, $k, $s, $x, $y, $p1, $p2, $st1, $st2] = $this->fixture('A');
        $combo = [1, $k->id, $s->id, $x->id];

        // BASELINE: O teaches nothing, counts are all zero.
        $this->assertCounts($o, 0, 0, 0, 'at baseline');

        // (a) ASSIGN: matrix save gives O the (S, X) override.
        $this->assignOverride($s, $x, $y, $o);
        $this->assertContains($k->id, TeacherScope::classIds($o),
            'O scope must gain K immediately, same session, no re-login.');
        $this->assertCounts($o, 1, 1, 2, 'after assign');

        // (b) TEACH: assignment + material + marks + message, all via real routes.
        $this->createAssignmentAs($o, $k, $s, $x, 'R34 chain assignment')
            ->assertRedirect();
        $a = Assignment::where('teacher_id', $o->id)->where('subject_id', $x->id)->firstOrFail();
        $this->assertSame('R34 chain assignment', $a->title);

        $this->actingAs($o)
            ->from(route('materials.index'))
            ->post(route('materials.store'), [
                'title' => 'R34 chain notes',
                'my_class_id' => $k->id,
                'section_id' => $s->id,
                'subject_id' => $x->id,
                'link_url' => 'https://example.com/r34-notes',
            ])->assertRedirect();
        $this->assertTrue(StudyMaterial::where('teacher_id', $o->id)->where('subject_id', $x->id)
            ->where('link_url', 'https://example.com/r34-notes')->exists(),
            'O material must store while the override is intact.');

        // Marks: selector creates the rows, then a real grid save lands.
        $this->actingAs($o)
            ->post(route('marks.selector'), ['exam_id' => 1, 'my_class_id' => $k->id, 'section_id' => $s->id, 'subject_id' => $x->id])
            ->assertRedirect(route('marks.manage', $combo));
        $this->actingAs($o)->get(route('marks.manage', $combo))->assertOk();
        [$payload, $markId, $compId, $new] = $this->marksPayloadWithOneChange($combo);
        $this->actingAs($o)->put(route('marks.update', $combo), $payload)->assertOk();
        $this->assertSame($new, (int) DB::table('mark_components')->where('mark_id', $markId)->where('component_id', $compId)->value('score'),
            'O marks save must land while the override is intact.');

        // Colleague's subject in the same class stays 403 (F-R24-1 inside the chain).
        $this->actingAs($o)->get(route('marks.manage', [1, $k->id, $s->id, $y->id]))->assertForbidden();
        $this->actingAs($o)->put(route('marks.update', [1, $k->id, $s->id, $y->id]), [])->assertForbidden();

        // Parent message to S parents succeeds (class-teacher surface, P12).
        $this->actingAs($o)
            ->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'section_id' => $s->id,
                'title' => 'R34 chain message', 'body' => 'chain probe body',
            ])->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, ParentMessage::where('sender_id', $o->id)->where('my_class_id', $k->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $p1->id)->where('type', 'parent_message')->where('title', 'R34 chain message')->count());
        $this->assertSame(1, UserNotification::where('user_id', $p2->id)->where('type', 'parent_message')->where('title', 'R34 chain message')->count());

        // Push leg inside the chain: grade 80 → push writes 24 (80% of 30).
        $sub = AssignmentSubmission::create([
            'assignment_id' => $a->id, 'student_id' => $st1->id,
            'text_answer' => 'chain answer', 'submitted_at' => now(), 'is_late' => false,
        ]);
        $this->gradeAs($o, $sub, 80)->assertRedirect();
        $this->pushAs($o, $a)
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Internal marks pushed for 1 student(s).');
        $this->assertSame(24, $this->internalScore($st1, $x));

        // (c) REMOVE: matrix save takes the override away.
        $this->removeOverride($s, $x, $y);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $x),
            'teacherIdFor must flip back to D immediately.');
        $this->assertSame([], TeacherScope::classIds($o),
            'O scope must drop K immediately, same session, no re-login.');
        $this->assertCounts($o, 0, 0, 0, 'after removal');

        // Marks gate flips with the scope: O is refused now.
        $this->actingAs($o)->get(route('marks.manage', $combo))->assertForbidden();
        $this->actingAs($o)->put(route('marks.update', $combo), [])->assertForbidden();

        // Own content still editable (non-retarget update, round-18 shape).
        $this->actingAs($o)->get(route('assignments.edit', $a->id))->assertOk();
        $this->actingAs($o)
            ->put(route('assignments.update', $a->id), [
                'title' => 'R34 chain assignment (edited after removal)',
                'description' => 'chain probe',
                'my_class_id' => $k->id, 'section_id' => $s->id, 'subject_id' => $x->id,
                'max_marks' => 100, 'due_at' => '2030-01-01 10:00:00', 'allow_late' => false,
            ])->assertRedirect(route('assignments.show', $a->id));
        $this->assertSame('R34 chain assignment (edited after removal)', $a->fresh()->title);

        // Push survives removal too (round-26 shape): re-grade 50 → push writes 15, still one mark row.
        $this->gradeAs($o, $sub, 50)->assertRedirect();
        $this->pushAs($o, $a)
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Internal marks pushed for 1 student(s).');
        $this->assertSame(15, $this->internalScore($st1, $x));
        $this->assertSame(1, (int) DB::table('marks')->where('exam_id', 1)->where('student_id', $st1->id)->where('subject_id', $x->id)->count(),
            'Re-push must overwrite, never duplicate.');

        // New surfaces close: create assignment + material + message all refuse.
        $this->createAssignmentAs($o, $k, $s, $x, 'R34 after-removal assignment')->assertForbidden();
        $this->assertFalse(Assignment::where('title', 'R34 after-removal assignment')->exists());
        $this->actingAs($o)
            ->from(route('materials.index'))
            ->post(route('materials.store'), [
                'title' => 'R34 after-removal notes', 'my_class_id' => $k->id,
                'section_id' => $s->id, 'subject_id' => $x->id,
                'link_url' => 'https://example.com/r34-after',
            ])->assertForbidden();
        $this->assertFalse(StudyMaterial::where('title', 'R34 after-removal notes')->exists());
        $create = $this->actingAs($o)->get(route('parent_messages.create'))->assertOk();
        $this->assertNotContains($k->id, $create->viewData('classes')->pluck('id')->all(),
            'Compose form must drop K after removal.');
        $this->actingAs($o)
            ->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'section_id' => $s->id,
                'title' => 'R34 after-removal message', 'body' => 'must not send',
            ])->assertForbidden();
        $this->assertSame(1, ParentMessage::where('sender_id', $o->id)->where('my_class_id', $k->id)->count(),
            'No new message row after removal.');

        // Sent mail stays sent: O and the parent still see the chain message.
        $this->actingAs($o)->get(route('parent_messages.index'))->assertOk()->assertSee('R34 chain message');
        $this->actingAs($p1)->get(route('parent_messages.index'))->assertOk()->assertSee('R34 chain message');

        // (d) RE-ASSIGN: the same override goes to N.
        $this->assignOverride($s, $x, $y, $n);
        $this->assertContains($k->id, TeacherScope::classIds($n),
            'N scope must gain K immediately, same session, no re-login.');
        $this->assertCounts($n, 1, 1, 2, 'after re-assign to N');
        $this->assertSame([], TeacherScope::classIds($o), 'O stays out of scope after re-assign.');

        // N enters marks for (K, X) — the chain's work continues under the new teacher.
        $this->actingAs($n)->get(route('marks.manage', $combo))->assertOk();
        [$payloadN, $markIdN, $compIdN, $newN] = $this->marksPayloadWithOneChange($combo);
        $this->actingAs($n)->put(route('marks.update', $combo), $payloadN)->assertOk();
        $this->assertSame($newN, (int) DB::table('mark_components')->where('mark_id', $markIdN)->where('component_id', $compIdN)->value('score'),
            'N marks save must land after re-assign.');

        // N cannot push O's assignment (ownership 403); the pushed score stays 15.
        $this->pushAs($n, $a)->assertForbidden();
        $this->assertSame(15, $this->internalScore($st1, $x),
            'N failed push must not touch the pushed score.');
    }

    public function test_chain_counts_agree_dashboard_banner_scope_at_every_state()
    {
        [$o, $d, $n, $k, $s, $x, $y, $p1, $p2, $st1, $st2] = $this->fixture('B');

        // P10: three independent sources — TeacherScope, dashboard
        // KPIs, My Account banner — must agree at every state, or a
        // count is lying about the list it links to.
        $this->assertSame([], TeacherScope::classIds($o));
        $this->assertCounts($o, 0, 0, 0, 'at baseline');

        $this->assignOverride($s, $x, $y, $o);
        $this->assertSame([$k->id], TeacherScope::classIds($o));
        $this->assertCounts($o, 1, 1, 2, 'while override intact');

        $this->removeOverride($s, $x, $y);
        $this->assertSame([], TeacherScope::classIds($o));
        $this->assertCounts($o, 0, 0, 0, 'after removal');

        $this->assignOverride($s, $x, $y, $n);
        $this->assertSame([$k->id], TeacherScope::classIds($n));
        $this->assertCounts($n, 1, 1, 2, 'after re-assign to N');
        $this->assertSame([], TeacherScope::classIds($o));
        $this->assertCounts($o, 0, 0, 0, 'for O after re-assign to N');
    }

    public function test_chain_handover_visibility_is_ownership_scoped()
    {
        [$o, $d, $n, $k, $s, $x, $y, $p1, $p2, $st1, $st2] = $this->fixture('C');

        $this->assignOverride($s, $x, $y, $o);
        $this->createAssignmentAs($o, $k, $s, $x, 'R34 handover assignment')->assertRedirect();
        $a = Assignment::where('teacher_id', $o->id)->where('subject_id', $x->id)->firstOrFail();
        $this->removeOverride($s, $x, $y);
        $this->assignOverride($s, $x, $y, $n);

        // The incoming teacher's own index is ownership-scoped: O's
        // row is NOT in it (brief-premise correction — see header).
        $nIndex = $this->actingAs($n)->get(route('assignments.index'))->assertOk();
        $nIds = collect($nIndex->viewData('assignments')->items())->pluck('id')->all();
        $this->assertNotContains($a->id, $nIds, 'N teacher index must not list O-owned assignment.');

        // The section's student still sees it: continuity for learners.
        $stIndex = $this->actingAs($st1)->get(route('assignments.index'))->assertOk();
        $stIds = collect($stIndex->viewData('assignments')->items())->pluck('id')->all();
        $this->assertContains($a->id, $stIds, 'Section student must still see the assignment.');

        // The owner still sees it, and admin reads everything.
        $oIndex = $this->actingAs($o)->get(route('assignments.index'))->assertOk();
        $this->assertContains($a->id, collect($oIndex->viewData('assignments')->items())->pluck('id')->all());
        $adIndex = $this->actingAs($this->admin())->get(route('assignments.index'))->assertOk();
        $this->assertContains($a->id, collect($adIndex->viewData('assignments')->items())->pluck('id')->all());
    }
}
