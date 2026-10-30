<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\ExamSubjectComponent;
use App\Models\Grade;
use App\Models\Mark;
use App\Models\MarkComponent;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\GradeCalculator;
use App\Support\MarkComputation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner loophole review 2026-10-30 (exam-flexibility audit
 * finding): grades were matched against the percentage bands
 * (A = 70–100) using the RAW subject total, so a subject whose
 * marking scheme sums to anything other than 100 was graded on
 * the wrong scale — 72/80 (90%, an A) read as a raw 72, and
 * 40/80 (50%, a C) read as an E. Grading now converts the total
 * to a percentage of the subject's scheme maximum at every
 * grading site (term recompute, grade recompute, annual report
 * card). Demo subjects all sum to 100, so their grades are
 * unchanged — only non-100 schemes move, to the correct band.
 */
class OwnerGradePercentageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_percentage_helper_scales_by_the_scheme_maximum()
    {
        $this->assertSame(90.0, GradeCalculator::percentage(72, 80));
        $this->assertSame(50.0, GradeCalculator::percentage(40, 80));
        $this->assertSame(72.0, GradeCalculator::percentage(72, 100));
        $this->assertNull(GradeCalculator::percentage(null, 80));
        // No scheme context: the total passes through unchanged.
        $this->assertSame(72.0, GradeCalculator::percentage(72, 0));
    }

    public function test_recompute_grades_a_non_100_scheme_by_percentage()
    {
        $teacher = User::create([
            'name' => 'Grade Pct Teacher', 'username' => 'GRADEPCT-T',
            'email' => 'gradepct-t@example.com', 'password' => 'secret',
            'code' => 'GRADEPCT-T', 'user_type' => 'teacher',
        ]);
        $class = MyClass::create(['name' => 'GradePct Class', 'class_type_id' => 4]);
        $section = Section::create(['name' => 'GP', 'my_class_id' => $class->id, 'teacher_id' => $teacher->id]);
        $subject = Subject::create([
            'name' => 'GradePct Subject', 'my_class_id' => $class->id,
            'teacher_id' => $teacher->id, 'slug' => 'gradepct-subject', 'periods_per_week' => 6,
        ]);
        $theory = ExamSubjectComponent::create([
            'exam_id' => 1, 'subject_id' => $subject->id, 'name' => 'Theory',
            'short_code' => 'TH', 'max_marks' => 50, 'sort' => 1,
        ]);
        $practical = ExamSubjectComponent::create([
            'exam_id' => 1, 'subject_id' => $subject->id, 'name' => 'Practical',
            'short_code' => 'PR', 'max_marks' => 30, 'sort' => 2,
        ]);

        $mk = function (string $suffix, int $th, int $pr) use ($class, $section, $subject, $theory, $practical) {
            $student = User::create([
                'name' => 'Grade Pct Student ' . $suffix, 'username' => 'GRADEPCT-S' . $suffix,
                'email' => 'gradepct-s' . strtolower($suffix) . '@example.com', 'password' => 'secret',
                'code' => 'GRADEPCT-S' . $suffix, 'user_type' => 'student',
            ]);
            StudentRecord::create([
                'session' => Qs::getCurrentSession(), 'user_id' => $student->id,
                'my_class_id' => $class->id, 'section_id' => $section->id,
                'adm_no' => 'GRADEPCT-' . $suffix, 'grad' => 0,
            ]);
            $mark = Mark::create([
                'exam_id' => 1, 'my_class_id' => $class->id, 'section_id' => $section->id,
                'subject_id' => $subject->id, 'student_id' => $student->id,
                'year' => Qs::getCurrentSession(),
            ]);
            MarkComponent::create(['mark_id' => $mark->id, 'component_id' => $theory->id, 'score' => $th]);
            MarkComponent::create(['mark_id' => $mark->id, 'component_id' => $practical->id, 'score' => $pr]);
            return $mark;
        };

        $top = $mk('A', 45, 27);   // 72 / 80 = 90% -> A
        $mid = $mk('B', 25, 15);   // 40 / 80 = 50% -> C (raw 40 would be E)

        MarkComputation::recomputeSubject(
            \App\Models\Exam::findOrFail(1), $class->id, $section->id, $subject->id, Qs::getCurrentSession()
        );

        $bandA = Grade::where('name', 'A')->firstOrFail();
        $bandC = Grade::where('name', 'C')->firstOrFail();
        $this->assertSame($bandA->id, (int) $top->fresh()->grade_id, '72/80 = 90% must grade A');
        $this->assertSame($bandC->id, (int) $mid->fresh()->grade_id, '40/80 = 50% must grade C, not the raw-scale E');
        $this->assertSame(72, (int) $top->fresh()->tex1, 'The stored total stays the raw subject total');
    }
}
