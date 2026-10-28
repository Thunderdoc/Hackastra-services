<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AttendanceSession;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 1 feature 5 — Teacher Class Overview + home attention strip.
 * Every expected number is recomputed from the database inside the
 * test; no figure is invented here.
 */
class OwnerClassOverviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** Class ids the demo teacher teaches (own subjects + overrides). */
    protected function teacherClassIds()
    {
        $teacher = $this->teacher();
        $ids = Subject::where('teacher_id', $teacher->id)->pluck('my_class_id');
        if (Schema::hasTable('section_subject_teachers')) {
            $overrideSubjects = DB::table('section_subject_teachers')
                ->where('teacher_id', $teacher->id)
                ->pluck('subject_id');
            $ids = $ids->merge(
                Subject::whereIn('id', $overrideSubjects)->pluck('my_class_id')
            );
        }

        return $ids->filter()->unique()->values();
    }

    public function test_teacher_index_shows_own_classes_with_db_strengths()
    {
        $response = $this->actingAs($this->teacher())
            ->get(route('class_overview.index'));
        $response->assertOk();

        foreach ($this->teacherClassIds() as $classId) {
            $class = MyClass::findOrFail($classId);
            $strength = StudentRecord::where('grad', 0)
                ->where('my_class_id', $classId)
                ->count();
            $response->assertSee($class->name);
            $response->assertSee(number_format($strength));
        }
    }

    public function test_attendance_pct_is_within_zero_to_one_hundred()
    {
        $teacher = $this->teacher();
        $subjectIds = Subject::where('teacher_id', $teacher->id)->pluck('id');
        $sessionIds = AttendanceSession::where('teacher_id', $teacher->id)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('id');
        $marked = DB::table('attendance_session_entries')
            ->whereIn('session_id', $sessionIds)->count();
        $ok = DB::table('attendance_session_entries')
            ->whereIn('session_id', $sessionIds)
            ->whereIn('status', ['present', 'late'])->count();

        $response = $this->actingAs($teacher)->get(route('class_overview.index'));
        $response->assertOk();

        if ($marked > 0) {
            $pct = ($ok / $marked) * 100;
            $this->assertGreaterThanOrEqual(0, $pct);
            $this->assertLessThanOrEqual(100, $pct);
            $response->assertSee(number_format($pct, 1));
        } else {
            $this->assertSame(0, $marked);
        }
    }

    public function test_topper_name_matches_db_for_prep_class()
    {
        // Prep (id 1): the teacher's subject there is English; the
        // latest (only) exam with marks is exam 1. Topper = highest
        // mean (tca + exm) across the teacher's subjects in the class.
        $teacher = $this->teacher();
        $subjectIds = Subject::where('teacher_id', $teacher->id)
            ->where('my_class_id', 1)->pluck('id');
        $examId = Mark::where('my_class_id', 1)
            ->whereIn('subject_id', $subjectIds)->max('exam_id');
        $this->assertNotNull($examId);
        $top = Mark::where('my_class_id', 1)
            ->where('exam_id', $examId)
            ->whereIn('subject_id', $subjectIds)
            ->select('student_id', DB::raw('avg(tca + exm) as avg_score'))
            ->groupBy('student_id')
            ->orderByDesc('avg_score')
            ->first();
        $topperName = User::findOrFail($top->student_id)->name;

        $response = $this->actingAs($teacher)
            ->get(route('class_overview.index', ['class_id' => 1]));
        $response->assertOk();
        $response->assertSee('Prep');
        $response->assertSee($topperName);
    }

    public function test_foreign_class_id_is_forbidden_for_teacher_but_ok_for_admin()
    {
        $foreignId = MyClass::whereNotIn('id', $this->teacherClassIds())
            ->orderBy('id')->value('id');
        $this->assertNotNull($foreignId);

        $this->actingAs($this->teacher())
            ->get(route('class_overview.index', ['class_id' => $foreignId]))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('class_overview.index', ['class_id' => $foreignId]))
            ->assertOk();
    }

    public function test_admin_index_sees_all_classes()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('class_overview.index'));
        $response->assertOk();
        $response->assertSee('Prep');
        $response->assertSee('Class 12');
    }

    public function test_teacher_dashboard_shows_attention_strip()
    {
        $teacher = $this->teacher();

        // Untaken periods today, recomputed from the timetable: this
        // weekday's periods under the same effective-teacher filter,
        // minus the ones with a submitted session today.
        $today = Carbon::now();
        $periods = TimeTable::where('day', $today->format('l'));
        SectionTeacher::applyTeacherFilter($periods, (int) $teacher->id);
        $periodIds = $periods->pluck('id');
        $submitted = AttendanceSession::whereDate('date', $today->toDateString())
            ->whereIn('time_table_id', $periodIds)
            ->pluck('time_table_id');
        $untaken = $periodIds->diff($submitted)->count();

        $ungradedAssignments = Assignment::where('teacher_id', $teacher->id)
            ->whereHas('submissions', function ($q) {
                $q->whereNull('marks_awarded');
            })->count();

        $response = $this->actingAs($teacher)->get('/dashboard');
        $response->assertOk();
        $response->assertSee('Needs attention');
        if ($untaken > 0) {
            $response->assertSee(number_format($untaken));
            $response->assertSee('still');
        }
        if ($ungradedAssignments > 0) {
            $response->assertSee('ungraded submissions');
            $response->assertSee(number_format($ungradedAssignments));
        }
    }
}
