<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\ExamController;
use App\Models\Exam;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\Models\UserNotification;
use App\Repositories\ExamRepo;
use App\Support\Notify;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave: section-wise
 * timetables (per-section teachers + section-over-class precedence),
 * teacher tabulation scoping, result-published notifications, and
 * class-scoped exams (partition rule). Same discipline as
 * ProgramFixesTest: DatabaseTransactions, self-created fixtures.
 */
class OwnerWave2Test extends TestCase
{
    use DatabaseTransactions;

    /** Override wins; other sections and class grids fall back. */
    public function test_section_teacher_override_resolution()
    {
        $subject = Subject::create([
            'name' => 'Probe Subject', 'slug' => 'probe-subject',
            'my_class_id' => 1, 'teacher_id' => 4,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => 1, 'subject_id' => $subject->id, 'teacher_id' => 8,
        ]);

        $this->assertSame(8, SectionTeacher::teacherIdFor(1, $subject));
        $this->assertSame(4, SectionTeacher::teacherIdFor(2, $subject));
        $this->assertSame(4, SectionTeacher::teacherIdFor(null, $subject));
    }

    /** Section grid wins for its section; class grid is the fallback. */
    public function test_section_record_precedence()
    {
        $classRecord = TimeTableRecord::create([
            'name' => 'Probe Class Grid', 'my_class_id' => 1,
            'section_id' => null, 'exam_id' => null, 'year' => '2099-2100',
        ]);
        $sectionRecord = TimeTableRecord::create([
            'name' => 'Probe Section Grid', 'my_class_id' => 1,
            'section_id' => 1, 'exam_id' => null, 'year' => '2099-2100',
        ]);

        $this->assertSame($sectionRecord->id, SectionTeacher::recordForClassSection(1, 1, '2099-2100')->id);
        $this->assertSame($classRecord->id, SectionTeacher::recordForClassSection(1, 2, '2099-2100')->id);
    }

    /** Subject teachers see every section of the classes they teach. */
    public function test_teacher_section_scope()
    {
        $teacher = User::find(4); // Ananya Sharma: Prep + Class 7 subjects
        $this->assertContains(1, TeacherScope::classIds($teacher));
        $prepSections = Section::where('my_class_id', 1)->pluck('id')
            ->map(function ($id) { return (int) $id; })->all();
        // Protected helper, invoked without the controller's constructor
        // (it touches only Subject/Section/TeacherScope, no services).
        $controller = (new \ReflectionClass(\App\Http\Controllers\SupportTeam\MarkController::class))
            ->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'teacherSectionIds');
        $method->setAccessible(true);
        $this->assertSame($prepSections, $method->invoke($controller, $teacher, 1));
    }

    /** Partition rule: global blocks class and vice versa, per class. */
    public function test_exam_partition_rule()
    {
        $controller = new ExamController(new ExamRepo);
        $guard = new \ReflectionMethod($controller, 'examScopeConflict');
        $guard->setAccessible(true);

        // Existing global Term 1 exam blocks both a new global and a class exam.
        $this->assertNotNull($guard->invoke($controller, 1, '2026-2027', 0));
        $this->assertNotNull($guard->invoke($controller, 1, '2026-2027', 11));
        // A free session allows anything.
        $this->assertNull($guard->invoke($controller, 1, '2099-2100', 0));
        $this->assertNull($guard->invoke($controller, 1, '2099-2100', 11));

        // One class exam blocks a global and the same class, not another class.
        Exam::create(['name' => 'Probe Class Exam', 'term' => 1, 'year' => '2099-2100', 'my_class_id' => 11]);
        $this->assertNotNull($guard->invoke($controller, 1, '2099-2100', 0));
        $this->assertNotNull($guard->invoke($controller, 1, '2099-2100', 11));
        $this->assertNull($guard->invoke($controller, 1, '2099-2100', 12));
    }

    /** Publish notifies the session's students; republish does not duplicate. */
    public function test_result_published_notification_dedupe()
    {
        $exam = Exam::find(1); // Term 1, 2026-2027, unpublished in fixtures
        $first = Notify::resultsPublished($exam);
        $this->assertGreaterThan(3000, $first);
        $second = Notify::resultsPublished($exam);
        $this->assertSame($first, $second);
        $this->assertSame($first, UserNotification::where('type', 'result_published')->count());
    }
}
