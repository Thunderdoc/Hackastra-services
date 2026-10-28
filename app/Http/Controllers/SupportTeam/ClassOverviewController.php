<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\Subject;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 feature 5 — Teacher Class Overview.
 *
 * ONE route (already in routes/web.php, no team middleware): GET
 * /class-overview -> index. This controller guards it: teachers see
 * ONLY the classes they teach, admins see all classes, everyone else
 * gets 403. Per-class detail is a ?class_id= query param on the same
 * page — never a route param.
 *
 * Every figure is computed LIVE from existing rows; nothing is
 * stored and nothing is invented:
 *
 * - Strength: StudentRecord count for the class with grad = 0.
 * - Attendance %: (present + late) / marked * 100 over period
 *   attendance entries. For a teacher, only sessions of the
 *   teacher's own periods in that class: attendance_sessions with
 *   teacher_id = the teacher and subject_id in the teacher's
 *   effective subjects for the class (subjects they own at class
 *   level, plus subjects they teach via a section override). For an
 *   admin, all sessions in the class.
 * - Marks: for the teacher's effective subjects in the class (all
 *   subjects for an admin), the LATEST exam that has marks for
 *   them — the highest exam_id among marks rows for those subjects
 *   in that class (seeded data: exam 1, Term 1 Examination). A
 *   student's marks figure is the mean, across those subjects, of
 *   (tca + exm) expressed as a percentage of that subject's exam
 *   total for the exam (sum of exam_subject_components max_marks
 *   for the exam+subject, falling back to 100 when no components
 *   exist). The class marks average is the mean of the per-student
 *   percentages. The topper is the student with the highest
 *   per-student percentage.
 * - At-risk: a student is at-risk when their own attendance % is
 *   below 50 (students with no marked periods are excluded, never
 *   treated as 0%) OR their marks percentage is below 40% of the
 *   exam total. Names are listed, not just counted.
 */
class ClassOverviewController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        if (!$user || !in_array($user->user_type, ['teacher', 'admin'], true)) {
            abort(403);
        }
        $isAdmin = $user->user_type === 'admin';

        $classes = $this->visibleClasses($user, $isAdmin);

        $requestedId = request()->query('class_id');
        if ($requestedId !== null) {
            $selected = MyClass::find($requestedId);
            if (!$selected) {
                abort(404);
            }
            if (!$isAdmin && !$classes->contains('id', $selected->id)) {
                abort(403);
            }
        } else {
            $selected = $classes->first();
        }

        $overviews = $classes->map(function ($class) use ($user, $isAdmin) {
            return $this->snapshot($class, $user, $isAdmin);
        })->values();

        $detail = $selected
            ? $overviews->firstWhere('class.id', $selected->id)
            : null;

        return view('pages.support_team.class_overview.index', [
            'overviews' => $overviews,
            'detail' => $detail,
            'selected_class_id' => $selected ? $selected->id : null,
            'is_admin' => $isAdmin,
        ]);
    }

    /**
     * Classes the viewer may see, with each class's effective subject
     * ids attached: a teacher's own subjects plus subjects they teach
     * through a section override (SectionTeacher resolution). An
     * admin sees every class with every subject in it.
     */
    protected function visibleClasses($user, bool $isAdmin)
    {
        if ($isAdmin) {
            return MyClass::with('section')->orderBy('name')->get()->map(function ($class) {
                $class->overview_subject_ids = Subject::where('my_class_id', $class->id)
                    ->pluck('id')->all();

                return $class;
            });
        }

        $own = Subject::where('teacher_id', $user->id)->get();
        $classIds = $own->pluck('my_class_id')->filter()->unique()->values();

        $overrideSubjectIds = collect();
        if (Schema::hasTable('section_subject_teachers')) {
            $overrideSubjectIds = DB::table('section_subject_teachers')
                ->where('teacher_id', $user->id)
                ->pluck('subject_id');
        }
        $overrideSubjects = $overrideSubjectIds->isEmpty()
            ? collect()
            : Subject::whereIn('id', $overrideSubjectIds)->get();
        $classIds = $classIds->merge($overrideSubjects->pluck('my_class_id'))
            ->filter()->unique()->values();

        if ($classIds->isEmpty()) {
            return collect();
        }

        return MyClass::whereIn('id', $classIds)->orderBy('name')->get()
            ->map(function ($class) use ($own, $overrideSubjects) {
                $ids = $own->where('my_class_id', $class->id)->pluck('id')
                    ->merge($overrideSubjects->where('my_class_id', $class->id)->pluck('id'))
                    ->unique()->values()->all();
                $class->overview_subject_ids = $ids;

                return $class;
            });
    }

    protected function snapshot($class, $user, bool $isAdmin)
    {
        $subjectIds = $class->overview_subject_ids ?: [];
        $classId = $class->id;

        $records = StudentRecord::with('user')
            ->where('grad', 0)
            ->where('my_class_id', $classId)
            ->get();
        $strength = $records->count();
        $nameByUserId = $records->mapWithKeys(function ($record) {
            return [$record->user_id => optional($record->user)->name ?: 'Student'];
        });
        $recordIdToUserId = $records->mapWithKeys(function ($record) {
            return [$record->id => $record->user_id];
        });

        // Attendance, class-wide and per student, from period sessions.
        $attendancePct = null;
        $attendanceMarked = 0;
        $attByUser = [];
        if (Schema::hasTable('attendance_sessions') && Schema::hasTable('attendance_session_entries')) {
            $sessionQuery = DB::table('attendance_sessions')
                ->where('my_class_id', $classId);
            if (!$isAdmin) {
                $sessionQuery->where('teacher_id', $user->id);
                if (!empty($subjectIds)) {
                    $sessionQuery->whereIn('subject_id', $subjectIds);
                } else {
                    $sessionQuery->whereRaw('1 = 0');
                }
            }
            $sessionIds = $sessionQuery->pluck('id');
            if ($sessionIds->isNotEmpty()) {
                $rows = DB::table('attendance_session_entries')
                    ->whereIn('session_id', $sessionIds)
                    ->select('student_record_id',
                        DB::raw("sum(case when status in ('present','late') then 1 else 0 end) as ok"),
                        DB::raw('count(*) as total'))
                    ->groupBy('student_record_id')
                    ->get();
                $ok = $rows->sum('ok');
                $attendanceMarked = (int) $rows->sum('total');
                if ($attendanceMarked > 0) {
                    $attendancePct = ($ok / $attendanceMarked) * 100;
                }
                foreach ($rows as $row) {
                    $userId = $recordIdToUserId[$row->student_record_id] ?? null;
                    if ($userId && (int) $row->total > 0) {
                        $attByUser[$userId] = ((int) $row->ok / (int) $row->total) * 100;
                    }
                }
            }
        }

        // Marks: latest exam with marks for the scoped subjects.
        $exam = null;
        $marksAvg = null;
        $marksByUser = [];
        $topper = null;
        if (!empty($subjectIds) && Schema::hasTable('marks')) {
            $latestExamId = Mark::where('my_class_id', $classId)
                ->whereIn('subject_id', $subjectIds)
                ->max('exam_id');
            if ($latestExamId) {
                $exam = Exam::find($latestExamId);
                $totals = ExamSubjectComponent::where('exam_id', $latestExamId)
                    ->whereIn('subject_id', $subjectIds)
                    ->select('subject_id', DB::raw('sum(max_marks) as total'))
                    ->groupBy('subject_id')
                    ->pluck('total', 'subject_id');
                $markRows = Mark::where('my_class_id', $classId)
                    ->where('exam_id', $latestExamId)
                    ->whereIn('subject_id', $subjectIds)
                    ->get();
                $perStudent = [];
                foreach ($markRows as $mark) {
                    $total = (int) ($totals[$mark->subject_id] ?? 100);
                    if ($total <= 0) {
                        continue;
                    }
                    $score = ((int) ($mark->tca ?? 0)) + ((int) ($mark->exm ?? 0));
                    $perStudent[$mark->student_id][] = ($score / $total) * 100;
                }
                foreach ($perStudent as $studentId => $percents) {
                    $marksByUser[$studentId] = array_sum($percents) / count($percents);
                }
                if (!empty($marksByUser)) {
                    $marksAvg = array_sum($marksByUser) / count($marksByUser);
                    $topperId = collect($marksByUser)->sortDesc()->keys()->first();
                    $topper = [
                        'name' => $nameByUserId[$topperId] ?? 'Student',
                        'percent' => $marksByUser[$topperId],
                    ];
                }
            }
        }

        // At-risk: attendance below 50% OR marks below 40% of total.
        $atRisk = [];
        foreach ($nameByUserId as $studentId => $name) {
            $lowAttendance = isset($attByUser[$studentId]) && $attByUser[$studentId] < 50;
            $lowMarks = isset($marksByUser[$studentId]) && $marksByUser[$studentId] < 40;
            if ($lowAttendance || $lowMarks) {
                $atRisk[] = [
                    'name' => $name,
                    'attendance_pct' => $attByUser[$studentId] ?? null,
                    'marks_pct' => $marksByUser[$studentId] ?? null,
                ];
            }
        }
        usort($atRisk, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return [
            'class' => $class,
            'subjects' => $subjectIds ? Subject::whereIn('id', $subjectIds)->orderBy('name')->get() : collect(),
            'strength' => $strength,
            'attendance_pct' => $attendancePct,
            'attendance_marked' => $attendanceMarked,
            'exam' => $exam,
            'marks_avg' => $marksAvg,
            'marks_students' => count($marksByUser),
            'topper' => $topper,
            'at_risk' => $atRisk,
        ];
    }
}
