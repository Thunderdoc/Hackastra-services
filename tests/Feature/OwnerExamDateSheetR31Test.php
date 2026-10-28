<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 31) — class-scoped
 * exams on the DATE-SHEET write paths.
 *
 * Background: exam creation fixes an exam's scope (ExamController
 * examScopeConflict + the UNIQUE(term, year, my_class_id) backstop:
 * my_class_id 0 = global, anything else = exactly one class).
 * The marks selector rejects scope mismatch. But the date-sheet
 * writes never checked scope at all: ExamDateController::generate
 * and ::save took any (exam_id, class_id) pair and wrote rows.
 * An admin picking a Class-X exam for Class Y in the generate
 * form — the form lists every exam against every class — got a
 * full sheet for Y under X's exam, and resolveScope's read side
 * (rows keyed by exam_id + my_class_id, no scope check) would
 * then show that sheet to Y's students as if the exam were
 * theirs. generate() is teamSAWrite (admin-only), so this is an
 * admin footgun, not a privilege escalation — but a wrong sheet
 * published to the wrong class is exactly the misinformation
 * the scope rule exists to prevent.
 *
 * Failing-first: tests 1, 4 and 5 assert the REFUSAL. Pre-fix
 * they fail — generate/save succeed and write the mismatched
 * rows. Post-fix (a shared scope check in generate() + save())
 * all five pass. The fix is deliberately writes-only: the
 * manage GET form render is admin-only and harmless, and no
 * mismatched rows can exist after the write guard except
 * direct-DB rows, which are out of the UI's reach.
 *
 * (1) scope=one generate for Y under an X-scoped exam is
 * refused with the exact message and writes ZERO rows for Y
 * (and touches nothing for X).
 * (2) Positive control: the same exam generates for X and
 * writes exactly X's 3 rows.
 * (3) Global-exam control: a my_class_id=0 exam generates
 * for Y normally (the guard is scope-aware, not a blanket
 * refusal).
 * (4) scope=all under an X-scoped exam is refused wholesale
 * (generating "for all classes" contradicts a one-class exam)
 * and writes zero rows for any class.
 * (5) Manage save for Y under an X-scoped exam is refused
 * with the exact message and writes zero rows; the X control
 * save succeeds.
 */
class OwnerExamDateSheetR31Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    protected function makeExam($name, $term, $classId)
    {
        return Exam::create([
            'name' => $name,
            'term' => $term,
            'year' => '2026-2027',
            'my_class_id' => $classId,
        ]);
    }

    protected function generateParams($examId, $scope, $classId = null)
    {
        $params = [
            'exam_id' => $examId,
            'scope' => $scope,
            'start_date' => '2026-12-01',
            'exam_days' => 10,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'venue' => 'Main Hall',
            'skip_sunday' => 1,
            'gap_every' => 2,
            'gap_days' => 1,
            'skip_dates' => '2026-12-05',
            'mode' => 'replace',
        ];
        if ($classId !== null) {
            $params['class_id'] = $classId;
        }
        return $params;
    }

    protected function scopedPair()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R31 Scope X Class', ['R31-SCOPE-XA', 'R31-SCOPE-XB', 'R31-SCOPE-XC'], 'r31-scope-x');
        [$classY] = $this->makeClassWithSubjects(
            'R31 Scope Y Class', ['R31-SCOPE-YA', 'R31-SCOPE-YB', 'R31-SCOPE-YC'], 'r31-scope-y');
        $exam = $this->makeExam('R31 Scoped Exam', 'R31 Term', $classX->id);
        return [$classX, $classY, $exam];
    }

    public function test_scope_one_generate_for_foreign_class_under_scoped_exam_is_refused_with_zero_rows()
    {
        [$classX, $classY, $exam] = $this->scopedPair();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generateParams($exam->id, 'one', $classY->id));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame(
            'This exam is scoped to R31 Scope X Class — it cannot carry dates for R31 Scope Y Class.',
            session('flash_danger'));

        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)->count(),
            'A refused generate must write nothing for the foreign class');
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classX->id)->count(),
            'A refused generate must not touch the scoped class either');
    }

    public function test_scope_one_generate_for_the_scoped_class_itself_succeeds()
    {
        [$classX, $classY, $exam] = $this->scopedPair();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generateParams($exam->id, 'one', $classX->id));
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id]));
        $resp->assertSessionHas('flash_success');

        $this->assertSame(3, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classX->id)->count(),
            'The scoped class itself gets exactly its 3 papers');
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)->count());
    }

    public function test_global_exam_generates_for_any_class_normally()
    {
        [$classY] = $this->makeClassWithSubjects(
            'R31 Global Y Class', ['R31-GLOBAL-YA', 'R31-GLOBAL-YB', 'R31-GLOBAL-YC'], 'r31-global-y');
        $exam = $this->makeExam('R31 Global Exam', 'R31 Global Term', 0);

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generateParams($exam->id, 'one', $classY->id));
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id]));
        $resp->assertSessionHas('flash_success');

        $this->assertSame(3, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)->count(),
            'A global exam is not blocked by the scope guard');
    }

    public function test_scope_all_under_a_scoped_exam_is_refused_with_zero_rows_anywhere()
    {
        [$classX, $classY, $exam] = $this->scopedPair();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generateParams($exam->id, 'all'));
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame(
            'This exam is scoped to R31 Scope X Class — generate for that class only, not for all classes.',
            session('flash_danger'));

        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)->count(),
            'A refused scope=all generate must write nothing for any class');
    }

    public function test_manage_save_for_foreign_class_under_scoped_exam_is_refused()
    {
        [$classX, $classY, $exam] = $this->scopedPair();
        $subjectsY = Subject::where('my_class_id', $classY->id)->orderBy('name')->get();
        $subjectsX = Subject::where('my_class_id', $classX->id)->orderBy('name')->get();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => $exam->id,
            'class_id' => $classY->id,
            'dates' => [
                $subjectsY[0]->id => ['exam_date' => '2026-11-02', 'start_time' => '09:00', 'note' => 'R31-SAVE-Y'],
            ],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame(
            'This exam is scoped to R31 Scope X Class — it cannot carry dates for R31 Scope Y Class.',
            session('flash_danger'));
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)->count(),
            'A refused Manage save must write nothing for the foreign class');

        // Control: the same save shape for the scoped class itself succeeds.
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => $exam->id,
            'class_id' => $classX->id,
            'dates' => [
                $subjectsX[0]->id => ['exam_date' => '2026-11-02', 'start_time' => '09:00', 'note' => 'R31-SAVE-X'],
            ],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $classX->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertSame(1, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classX->id)->count());
    }
}
