<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\AssignmentController;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 3: teacher-chosen
 * assignment average modes (All / Best N / Latest N, N typed freely)
 * for the internal-marks bridge, and multi-file study-material
 * upload with the class->subject cascade. Same discipline as
 * OwnerWave2Test: DatabaseTransactions, self-created fixtures.
 */
class OwnerWave3Test extends TestCase
{
    use DatabaseTransactions;

    protected function averageController()
    {
        return (new \ReflectionClass(AssignmentController::class))
            ->newInstanceWithoutConstructor();
    }

    protected function computeFor($assignment, $mode, $n)
    {
        $controller = $this->averageController();
        $method = new \ReflectionMethod($controller, 'computeAverages');
        $method->setAccessible(true);
        // Internal component max 30 (the demo scheme's INT for English).
        $component = ['id' => 1, 'name' => 'Internal Assessment', 'short_code' => 'INT', 'max_marks' => 30, 'field' => null];
        $rows = $method->invoke($controller, $assignment, $component, $mode, $n);
        return collect($rows);
    }

    /** Three graded assignments for one Prep student: oldest 90, middle 50, newest 70. */
    protected function makeGradedSet($studentUserId)
    {
        $teacher = User::find(4); // Ananya Sharma: Prep English
        $made = [];
        foreach ([3 => 90, 2 => 50, 1 => 70] as $daysAgo => $marks) {
            $a = Assignment::create([
                'teacher_id' => $teacher->id, 'my_class_id' => 1, 'section_id' => null,
                'subject_id' => 1, 'title' => 'Probe assignment ' . $daysAgo, 'max_marks' => 100,
                'due_at' => now()->addWeek(),
            ]);
            $a->created_at = now()->subDays($daysAgo);
            $a->save();
            AssignmentSubmission::create([
                'assignment_id' => $a->id, 'student_id' => $studentUserId,
                'submitted_at' => now(), 'marks_awarded' => $marks, 'graded_by' => $teacher->id,
            ]);
            $made[] = $a;
        }
        return [$teacher, $made[0]];
    }

    protected function prepStudentUserId()
    {
        return (int) StudentRecord::where('my_class_id', 1)->where('grad', 0)->orderBy('id')->first()->user_id;
    }

    public function test_average_all_mode()
    {
        $studentId = $this->prepStudentUserId();
        [, $assignment] = $this->makeGradedSet($studentId);
        $row = $this->computeFor($assignment, 'all', null)->firstWhere('student_id', $studentId);
        $this->assertSame(3, $row['graded_count']);
        $this->assertSame(3, $row['counted']);
        $this->assertEquals(70.0, $row['average_percent']);
        $this->assertSame(21, $row['scaled_internal']);
    }

    public function test_average_best_mode()
    {
        $studentId = $this->prepStudentUserId();
        [, $assignment] = $this->makeGradedSet($studentId);
        $row = $this->computeFor($assignment, 'best', 2)->firstWhere('student_id', $studentId);
        $this->assertSame(2, $row['counted']);
        $this->assertEquals(80.0, $row['average_percent']);
        $this->assertSame(24, $row['scaled_internal']);
    }

    public function test_average_latest_mode()
    {
        $studentId = $this->prepStudentUserId();
        [, $assignment] = $this->makeGradedSet($studentId);
        $row = $this->computeFor($assignment, 'latest', 2)->firstWhere('student_id', $studentId);
        $this->assertSame(2, $row['counted']);
        $this->assertEquals(60.0, $row['average_percent']);
        $this->assertSame(18, $row['scaled_internal']);
    }

    public function test_average_choice_request_parsing()
    {
        $controller = $this->averageController();
        $method = new \ReflectionMethod($controller, 'averageChoiceFromRequest');
        $method->setAccessible(true);

        $this->assertSame(['mode' => 'all', 'n' => null], $method->invoke($controller, new Request()));
        $this->assertSame(['mode' => 'best', 'n' => 5], $method->invoke($controller, new Request(['mode' => 'best', 'avg_n' => 5])));
        $this->assertSame(['mode' => 'latest', 'n' => 3], $method->invoke($controller, new Request(['mode' => 'latest', 'avg_n' => 3])));
        $this->assertSame(['mode' => 'best', 'n' => 1], $method->invoke($controller, new Request(['mode' => 'best'])));
        $this->assertSame(['mode' => 'best', 'n' => 2], $method->invoke($controller, new Request(['best_n' => 2])));
        $this->assertSame(['mode' => 'all', 'n' => null], $method->invoke($controller, new Request(['mode' => 'bogus'])));
    }

    public function test_preview_page_renders_with_new_modes()
    {
        $studentId = $this->prepStudentUserId();
        [$teacher, $assignment] = $this->makeGradedSet($studentId);
        $response = $this->actingAs($teacher)->get(
            route('assignments.internal_preview', $assignment->id) . '?exam_id=1&mode=best&avg_n=2'
        );
        $response->assertStatus(200);
        $response->assertSee('Average of best N', false);
    }

    public function test_material_multi_file_upload()
    {
        Storage::fake('public');
        $teacher = User::find(4);
        $before = StudyMaterial::where('teacher_id', $teacher->id)->count();
        $response = $this->actingAs($teacher)->post(route('materials.store'), [
            'title' => 'Probe notes',
            'description' => 'Many files at once',
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'files' => [
                // Wave 19 rule: identical content is deduplicated, so
                // the three probe files must differ in content to
                // assert the multi-file → three-rows behaviour.
                UploadedFile::fake()->createWithContent('notes.pdf', 'probe-notes-content'),
                UploadedFile::fake()->createWithContent('slides.docx', 'probe-slides-content'),
                UploadedFile::fake()->createWithContent('diagram.png', 'probe-diagram-content'),
            ],
        ]);
        $response->assertRedirect(route('materials.index'));
        $created = StudyMaterial::where('teacher_id', $teacher->id)
            ->orderBy('id', 'desc')->take(3)->get();
        $this->assertSame($before + 3, StudyMaterial::where('teacher_id', $teacher->id)->count());
        $this->assertSame(['Probe notes (3)', 'Probe notes (2)', 'Probe notes'], $created->pluck('title')->all());
        $this->assertTrue($created->every(function ($m) { return (bool) $m->file_path; }));
    }

    public function test_material_link_only_still_works()
    {
        $teacher = User::find(4);
        $before = StudyMaterial::where('teacher_id', $teacher->id)->count();
        $response = $this->actingAs($teacher)->post(route('materials.store'), [
            'title' => 'Probe link',
            'my_class_id' => 1,
            'section_id' => null,
            'subject_id' => 1,
            'link_url' => 'https://example.com/video',
        ]);
        $response->assertRedirect(route('materials.index'));
        $this->assertSame($before + 1, StudyMaterial::where('teacher_id', $teacher->id)->count());
        $this->assertSame('https://example.com/video', StudyMaterial::where('teacher_id', $teacher->id)->orderBy('id', 'desc')->first()->link_url);
    }

    public function test_class_subjects_cascade_is_class_scoped()
    {
        $teacher = User::find(4);
        $response = $this->actingAs($teacher)->get(route('get_class_subjects', 1));
        $response->assertStatus(200);
        $ids = collect($response->json('subjects'))->pluck('id')->all();
        // A teacher's cascade lists only THEIR subjects for the class
        // — Ananya teaches one subject (English) in Prep, not all 6.
        $expected = \App\Models\Subject::where('my_class_id', 1)->where('teacher_id', $teacher->id)->pluck('id')->all();
        sort($ids);
        sort($expected);
        $this->assertSame($expected, $ids);
        $this->assertCount(1, $ids);
    }
}
