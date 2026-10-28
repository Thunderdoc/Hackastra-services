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
 * Batch 2026-10-28 (exam-timetable round 32) — class-scoped
 * exams on the DATE-SHEET + ADMIT read paths.
 *
 * Background: round 31 (F-R31-1) closed the WRITE side —
 * generate() and save() refuse a scoped exam for a foreign
 * class. But resolveScope() never checked scope: rows are
 * keyed by (exam_id, my_class_id) only, so a LEGACY mismatched
 * row — creatable after R31 only via direct DB, which is
 * exactly how these tests plant it — still rendered to the
 * foreign class's families as if the exam were theirs. The
 * admit card has the same hole: AdmitCardController's
 * buildSchedule() reads exam_dates for (exam, class) with no
 * scope check, so a self-service myCard and the teamSA bulk
 * print both rendered the wrong-scope dates.
 *
 * Read-side design (loop-proof, stated so a later reader does
 * not "simplify" it into a redirect loop): resolveScope()
 * redirects to exam_dates.index with the scope flash ONLY when
 * the request EXPLICITLY selected the mismatched pair (an
 * exam_id / class_id / student_id param equal to the resolved
 * exam / class / child). The redirect target carries no scope
 * params, so it can never re-trigger the redirect. When the
 * mismatch comes from DEFAULT resolution (no explicit param —
 * reachable only when the session's first exam is itself a
 * scoped exam for another class), resolveScope instead renders
 * with EMPTY rows (announced 0): the blade's honest "Dates not
 * announced yet" state, never the legacy dates. The manage GET
 * form is deliberately untouched (admin-only, R31's choice);
 * legacy mismatched rows remain UI-undeletable (save refuses
 * the pair wholesale) — a cleanup-path observation, not built
 * here.
 *
 * Failing-first: tests 1–3, 4, 7 and 9 assert REFUSALS.
 * Pre-fix they fail — the legacy dates render (or the card
 * prints) instead of refusing. Post-fix (scope checks in
 * resolveScope + myCard + printSheet) all tests pass.
 * Controls (5, 6, 8, 10, 11, 12) pass pre- and post-fix.
 *
 * (1) Explicit date-sheet show for a foreign class under a
 *     scoped exam → redirect to exam_dates.index + exact
 *     scope flash (no legacy date rendered).
 * (2) Explicit date-sheet PRINT for a foreign class → same
 *     redirect + flash.
 * (3) Explicit date-sheet PDF for a foreign class → same
 *     redirect + flash (pre-fix it downloads).
 * (4) Default resolution (scoped exam sorting first, student
 *     viewer) → 200 with the honest empty state and NO legacy
 *     date label anywhere on the page.
 * (5) Control: the scoped class's own sheet renders its date.
 * (6) Control: a global exam's sheet renders for any class.
 * (7) Self-service myCard for a foreign-scope exam → redirect
 *     to admit_cards.my + exact scope flash.
 * (8) Control: myCard for the scoped class's own exam renders.
 * (9) Bulk admit print for a foreign-scope exam → redirect to
 *     admit_cards.index + exact scope flash.
 * (10) Control: bulk print for the scoped class's own exam
 *      renders the date.
 * (11) Control: bulk print for a global exam renders the date.
 * (12) Control: myCard for a global exam renders the date.
 */
class OwnerExamDateSheetR32Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function student57()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
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

    protected function scopedPair()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R32 Scope X Class', ['R32-SCOPE-XA', 'R32-SCOPE-XB', 'R32-SCOPE-XC'], 'r32-scope-x');
        [$classY] = $this->makeClassWithSubjects(
            'R32 Scope Y Class', ['R32-SCOPE-YA', 'R32-SCOPE-YB', 'R32-SCOPE-YC'], 'r32-scope-y');
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', $classX->id);
        return [$classX, $classY, $exam];
    }

    /**
     * Direct-DB legacy row: the ONLY way a mismatched row can
     * exist after F-R31-1 (generate/save refuse the pair).
     */
    protected function plantRow($examId, $classId, $subjectId)
    {
        return ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => '2026-11-02',
            'start_time' => '09:00:00',
            'note' => 'R32-LEGACY',
        ]);
    }

    protected function scopeMessage($scopedName, $targetName)
    {
        return 'This exam is scoped to ' . $scopedName . ' — it cannot carry dates for ' . $targetName . '.';
    }

    public function test_explicit_show_for_foreign_class_under_scoped_exam_redirects_with_scope_message()
    {
        [$classX, $classY, $exam] = $this->scopedPair();
        $subjectsY = Subject::where('my_class_id', $classY->id)->orderBy('id')->get();
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id);

        $resp = $this->actingAs($this->admin())->get(route('exam_dates.index', [
            'exam_id' => $exam->id, 'class_id' => $classY->id,
        ]));
        $resp->assertRedirect(route('exam_dates.index'));
        $this->assertSame(
            $this->scopeMessage('R32 Scope X Class', 'R32 Scope Y Class'),
            session('flash_danger'));
    }

    public function test_explicit_print_for_foreign_class_under_scoped_exam_redirects_with_scope_message()
    {
        [$classX, $classY, $exam] = $this->scopedPair();
        $subjectsY = Subject::where('my_class_id', $classY->id)->orderBy('id')->get();
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id);

        $resp = $this->actingAs($this->admin())->get(route('exam_dates.print', [
            'exam_id' => $exam->id, 'class_id' => $classY->id,
        ]));
        $resp->assertRedirect(route('exam_dates.index'));
        $this->assertSame(
            $this->scopeMessage('R32 Scope X Class', 'R32 Scope Y Class'),
            session('flash_danger'));
    }

    public function test_explicit_pdf_for_foreign_class_under_scoped_exam_redirects_with_scope_message()
    {
        [$classX, $classY, $exam] = $this->scopedPair();
        $subjectsY = Subject::where('my_class_id', $classY->id)->orderBy('id')->get();
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id);

        $resp = $this->actingAs($this->admin())->get(route('exam_dates.pdf', [
            'exam_id' => $exam->id, 'class_id' => $classY->id,
        ]));
        $resp->assertRedirect(route('exam_dates.index'));
        $this->assertSame(
            $this->scopeMessage('R32 Scope X Class', 'R32 Scope Y Class'),
            session('flash_danger'));
    }

    public function test_default_resolution_never_renders_legacy_rows()
    {
        // Make the scoped exam the session's FIRST (hence
        // default) exam: remove the seeded session exams, then
        // create only the scoped one. The student viewer (57,
        // Prep, class 1) resolves class Prep by record, never by
        // param — so the mismatch arrives via default resolution.
        Exam::where('year', '2026-2027')->delete();
        [$classX] = $this->makeClassWithSubjects(
            'R32 Scope X Class', ['R32-SCOPE-XA', 'R32-SCOPE-XB'], 'r32-scope-x-def');
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', $classX->id);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->student57())->get(route('exam_dates.index'));
        $resp->assertOk();
        $resp->assertDontSee('Mon, 02 Nov 2026');
        $resp->assertSee('Dates not announced yet');
    }

    public function test_scoped_class_own_sheet_still_renders_its_date()
    {
        [$classX, $classY, $exam] = $this->scopedPair();
        $subjectsX = Subject::where('my_class_id', $classX->id)->orderBy('id')->get();
        $this->plantRow($exam->id, $classX->id, $subjectsX[0]->id);

        $resp = $this->actingAs($this->admin())->get(route('exam_dates.index', [
            'exam_id' => $exam->id, 'class_id' => $classX->id,
        ]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }

    public function test_global_exam_sheet_renders_for_any_class()
    {
        [$classY] = $this->makeClassWithSubjects(
            'R32 Global Y Class', ['R32-GLOBAL-YA', 'R32-GLOBAL-YB'], 'r32-global-y');
        $exam = $this->makeExam('R32 Global Exam', 'R32 Global Term', 0);
        $subjectsY = Subject::where('my_class_id', $classY->id)->orderBy('id')->get();
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id);

        $resp = $this->actingAs($this->admin())->get(route('exam_dates.index', [
            'exam_id' => $exam->id, 'class_id' => $classY->id,
        ]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }

    public function test_mycard_for_foreign_scope_exam_redirects_with_scope_message()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R32 Scope X Class', ['R32-SCOPE-XA', 'R32-SCOPE-XB'], 'r32-scope-x-card');
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', $classX->id);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->student57())
            ->get(route('admit_cards.my_card', [$exam->id, 57]));
        $resp->assertRedirect(route('admit_cards.my'));
        $this->assertSame(
            $this->scopeMessage('R32 Scope X Class', 'Prep'),
            session('flash_danger'));
    }

    public function test_mycard_for_scoped_class_own_exam_renders()
    {
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', 1);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->student57())
            ->get(route('admit_cards.my_card', [$exam->id, 57]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }

    public function test_mycard_for_global_exam_renders()
    {
        $exam = $this->makeExam('R32 Global Exam', 'R32 Global Term', 0);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->student57())
            ->get(route('admit_cards.my_card', [$exam->id, 57]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }

    public function test_bulk_print_for_foreign_scope_exam_redirects_with_scope_message()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R32 Scope X Class', ['R32-SCOPE-XA', 'R32-SCOPE-XB'], 'r32-scope-x-bulk');
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', $classX->id);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [$exam->id, 1]));
        $resp->assertRedirect(route('admit_cards.index'));
        $this->assertSame(
            $this->scopeMessage('R32 Scope X Class', 'Prep'),
            session('flash_danger'));
    }

    public function test_bulk_print_for_scoped_class_own_exam_renders()
    {
        $exam = $this->makeExam('R32 Scoped Exam', 'R32 Term', 1);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [$exam->id, 1]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }

    public function test_bulk_print_for_global_exam_renders()
    {
        $exam = $this->makeExam('R32 Global Exam', 'R32 Global Term', 0);
        $prepSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->plantRow($exam->id, 1, $prepSubject->id);

        $resp = $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [$exam->id, 1]));
        $resp->assertOk();
        $resp->assertSee('Mon, 02 Nov 2026');
    }
}
