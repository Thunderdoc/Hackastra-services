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
 * Batch 2026-10-28 (exam-timetable round 34) — the round-32
 * cleanup-path gap, tested to a conclusion instead of carried
 * forward as an open guess.
 *
 * Background: round 32 closed the read side of class-scoped
 * exams (F-R32-1/F-R32-2), but noted that legacy mismatched
 * rows are invisible yet un-clearable: the round-31 write
 * guard in ExamDateController::save() refuses a mismatched
 * (exam, class) pair wholesale, before any row is touched,
 * with no distinction between SETTING a date and CLEARING
 * one. This round proves, through the real Manage save path,
 * what a BLANK date actually does and whether clearing is
 * exempt from that guard.
 *
 * Code facts read first (ExamDateController::save):
 * - A blank exam_date is the REMOVE action: the row is
 *   deleted and $cleared counts it; the flash reads
 *   "...: N date(s) set, M cleared."
 * - A subject absent from the form is left alone.
 * - The scope guard (line ~350) fires before any of that,
 *   on the (exam, class) pair alone — it never inspects
 *   whether the submission sets or clears.
 *
 * (1) Normal pair: a blank date for a kept row DELETES it
 * (flash "0 date(s) set, 1 cleared."), while a row for a
 * subject absent from the form survives untouched.
 * (2) Mismatched pair, pure clearing: a save submitting
 * ONLY a blank date for the legacy row is refused with the
 * exact scope message and the legacy row SURVIVES — the
 * gap is CONFIRMED, not closed: there is no UI path that
 * clears a legacy mismatched row.
 * (3) Mismatched pair, mixed save: blank for the legacy
 * row + a real date for another subject of the same
 * foreign class is refused WHOLESALE — the legacy row
 * survives AND the other subject gains no row. The guard
 * is all-or-nothing per save.
 * (4) Control: the same blank-date save for the scoped
 * exam's OWN class clears normally — the guard is
 * scope-aware, and clearing itself is a healthy path.
 *
 * Disposition: NO code changed in this round. Clearing-only
 * saves exempt from the scope guard would be a semantics
 * change (the guard would have to inspect submission
 * content, and a "clear" that also smuggles other edits
 * would need a rule) — that stays an owner call, now with
 * evidence instead of a guess.
 */
class OwnerExamDateSheetR34Test extends TestCase
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

    protected function plantRow($examId, $classId, $subjectId, $date, $time, $note)
    {
        return ExamDate::create([
            'exam_id' => $examId,
            'my_class_id' => $classId,
            'subject_id' => $subjectId,
            'exam_date' => $date,
            'start_time' => $time,
            'note' => $note,
        ]);
    }

    protected function savePost($examId, $classId, array $dates)
    {
        return $this->post(route('exam_dates.save'), [
            'exam_id' => $examId,
            'class_id' => $classId,
            'dates' => $dates,
        ]);
    }

    public function test_blank_date_on_normal_pair_clears_the_row_and_leaves_unsubmitted_rows_alone()
    {
        [$class, $subjects] = $this->makeClassWithSubjects(
            'R34 Normal Class', ['R34-NORM-A', 'R34-NORM-B'], 'r34-norm');
        $exam = $this->makeExam('R34 Global Exam', 'R34 Global Term', 0);
        $this->plantRow($exam->id, $class->id, $subjects[0]->id, '2026-11-02', '09:00', 'R34-KEPT-A');
        $this->plantRow($exam->id, $class->id, $subjects[1]->id, '2026-11-03', '13:00', 'R34-KEPT-B');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $class->id, [
            $subjects[0]->id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $class->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertSame(
            'Date sheet saved for R34 Global Exam — R34 Normal Class: 0 date(s) set, 1 cleared.',
            session('flash_success'));

        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => $exam->id,
            'my_class_id' => $class->id,
            'subject_id' => $subjects[0]->id,
        ]);
        $kept = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)
            ->where('subject_id', $subjects[1]->id)->firstOrFail();
        $this->assertSame('2026-11-03', (string) $kept->exam_date);
        $this->assertSame('13:00', substr((string) $kept->start_time, 0, 5));
        $this->assertSame('R34-KEPT-B', $kept->note);
    }

    public function test_pure_clearing_save_on_mismatched_pair_is_refused_and_legacy_row_survives()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R34 Scope X Class', ['R34-SCOPE-XA'], 'r34-scope-x');
        [$classY, $subjectsY] = $this->makeClassWithSubjects(
            'R34 Scope Y Class', ['R34-SCOPE-YA', 'R34-SCOPE-YB'], 'r34-scope-y');
        $exam = $this->makeExam('R34 Scoped Exam', 'R34 Scoped Term', $classX->id);
        // Legacy row: direct-DB-only shape since F-R31-1 — the UI
        // can no longer create a row for Y under X's exam.
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id, '2026-11-02', '09:00', 'R34-LEGACY');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $classY->id, [
            $subjectsY[0]->id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame(
            'This exam is scoped to R34 Scope X Class — it cannot carry dates for R34 Scope Y Class.',
            session('flash_danger'));

        // Gap CONFIRMED: the pure clearing attempt changed nothing.
        $legacy = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)
            ->where('subject_id', $subjectsY[0]->id)->firstOrFail();
        $this->assertSame('2026-11-02', (string) $legacy->exam_date);
        $this->assertSame('09:00', substr((string) $legacy->start_time, 0, 5));
        $this->assertSame('R34-LEGACY', $legacy->note);
    }

    public function test_mixed_clearing_plus_setting_save_on_mismatched_pair_is_refused_wholesale()
    {
        [$classX] = $this->makeClassWithSubjects(
            'R34 Mix X Class', ['R34-MIX-XA'], 'r34-mix-x');
        [$classY, $subjectsY] = $this->makeClassWithSubjects(
            'R34 Mix Y Class', ['R34-MIX-YA', 'R34-MIX-YB'], 'r34-mix-y');
        $exam = $this->makeExam('R34 Mix Exam', 'R34 Mix Term', $classX->id);
        $this->plantRow($exam->id, $classY->id, $subjectsY[0]->id, '2026-11-02', '09:00', 'R34-MIX-LEGACY');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $classY->id, [
            $subjectsY[0]->id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
            $subjectsY[1]->id => ['exam_date' => '2026-11-04', 'start_time' => '09:00', 'note' => 'R34-MIX-NEW'],
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('flash_danger');
        $this->assertSame(
            'This exam is scoped to R34 Mix X Class — it cannot carry dates for R34 Mix Y Class.',
            session('flash_danger'));

        // All-or-nothing: legacy row intact AND the new date never set.
        $legacy = ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $classY->id)
            ->where('subject_id', $subjectsY[0]->id)->firstOrFail();
        $this->assertSame('2026-11-02', (string) $legacy->exam_date);
        $this->assertSame('R34-MIX-LEGACY', $legacy->note);
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => $exam->id,
            'my_class_id' => $classY->id,
            'subject_id' => $subjectsY[1]->id,
        ]);
    }

    public function test_blank_date_on_scoped_own_pair_clears_normally()
    {
        [$classX, $subjectsX] = $this->makeClassWithSubjects(
            'R34 Own X Class', ['R34-OWN-XA', 'R34-OWN-XB'], 'r34-own-x');
        $exam = $this->makeExam('R34 Own Exam', 'R34 Own Term', $classX->id);
        $this->plantRow($exam->id, $classX->id, $subjectsX[0]->id, '2026-11-02', '09:00', 'R34-OWN-KEPT');

        $this->actingAs($this->admin());
        $resp = $this->savePost($exam->id, $classX->id, [
            $subjectsX[0]->id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $classX->id]));
        $resp->assertSessionHas('flash_success');
        $this->assertSame(
            'Date sheet saved for R34 Own Exam — R34 Own X Class: 0 date(s) set, 1 cleared.',
            session('flash_success'));
        $this->assertDatabaseMissing('exam_dates', [
            'exam_id' => $exam->id,
            'my_class_id' => $classX->id,
            'subject_id' => $subjectsX[0]->id,
        ]);
    }
}
