<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Exam;
use App\Models\Mark;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Repositories\ExamRepo;
use App\Support\Audit;
use App\Support\MarkComputation;
use App\Support\Notify;
use App\Support\MarkScheme;
use App\Support\ShareTargets;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * LMS — Assignments (Workstream CS1, 2026-10-22).
 *
 * Access model (server-side, mirrors the app's role-data-scoping rule):
 *  - Teacher: full control of OWN assignments only (teacher_id = self),
 *    grades only own assignments' submissions.
 *  - Student: sees assignments for own class (+ own section when the
 *    assignment is section-targeted), submits once, sees only own work.
 *  - Parent: read-only view of own children's assignments + their
 *    submission status/marks.
 *  - Admin: reads everything, may moderate (delete) — school operator.
 *  - Super Admin: READ-ONLY everywhere (platform boundary: the SA
 *    watches the school, never writes inside it). Every write action
 *    below refuses non-teacher callers (plus admin moderation on
 *    destroy) before touching data.
 */
class AssignmentController extends Controller
{
    protected $fileRules = 'file|mimes:pdf,doc,docx,zip,txt,jpg,jpeg,png,gif,webp|max:10240';

    /* Owner rule 2026-10-25: student submissions are capped at 2 MB.
       Only submit() uses this — teacher assignment attachments keep
       the 10 MB rule above, and teacher study materials have their own
       200 MB rule in StudyMaterialController. */
    protected $studentFileRules = 'file|mimes:pdf,doc,docx,zip,txt,jpg,jpeg,png,gif,webp|max:2048';

    /*****************************************************************
     * READ SIDE
     ****************************************************************/

    public function index(Request $req)
    {
        $user = Auth::user();

        $base = function () {
            return Assignment::with(['subject', 'my_class', 'section', 'teacher'])
                ->withCount(['submissions', 'submissions as graded_count' => function ($q) {
                    $q->whereNotNull('marks_awarded');
                }])
                ->latest('due_at');
        };

        if (Qs::userIsTeacher() || Qs::userIsTeamSA()) {
            // Teacher sees own; Admin + Super Admin read everything
            // (SA read-only). CS2 view: assignments.index.
            // Loop 82: summary cards + server-side filters ride the
            // SAME scoped query — cards always describe the viewer's
            // whole scope; filters only narrow the listing.
            $scope = function () use ($user) {
                $q = Assignment::query();
                if (Qs::userIsTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
                return $q;
            };
            // "Open" follows the model's actual submission rule
            // (submit()): the window is open while the due moment has
            // not passed, or late submissions are allowed.
            $openScope = function ($q) {
                $q->where(function ($w) {
                    $w->whereNull('due_at')
                        ->orWhere('due_at', '>', now())
                        ->orWhere('allow_late', true);
                });
            };
            $d['card_total'] = $scope()->count();
            $d['card_open'] = $scope()->tap($openScope)->count();
            $d['card_closed'] = $d['card_total'] - $d['card_open'];
            $d['card_classes'] = $scope()->distinct()->count('my_class_id');
            $d['filter_classes'] = \App\Models\MyClass::whereIn('id', $scope()->distinct()->pluck('my_class_id'))
                ->orderBy('name')->get();
            $d['filter_subjects'] = Subject::whereIn('id', $scope()->distinct()->pluck('subject_id'))
                ->orderBy('name')->get();
            $d['filters_active'] = $req->filled('q') || $req->filled('class_id')
                || $req->filled('subject_id') || $req->filled('status');

            $query = $base();
            if (Qs::userIsTeacher()) {
                $query->where('teacher_id', $user->id);
            }
            if ($req->filled('q')) {
                $term = '%' . trim((string) $req->query('q')) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhereHas('subject', function ($s) use ($term) {
                            $s->where('name', 'like', $term);
                        })
                        ->orWhereHas('my_class', function ($c) use ($term) {
                            $c->where('name', 'like', $term);
                        });
                });
            }
            if ($req->filled('class_id')) {
                $query->where('my_class_id', (int) $req->query('class_id'));
            }
            if ($req->filled('subject_id')) {
                $query->where('subject_id', (int) $req->query('subject_id'));
            }
            if ($req->query('status') === 'open') {
                $query->tap($openScope);
            } elseif ($req->query('status') === 'closed') {
                $query->whereNotNull('due_at')
                    ->where('due_at', '<=', now())
                    ->where('allow_late', false);
            }
            switch ($req->query('sort')) {
                case 'due_asc':
                    $query->reorder('due_at', 'asc');
                    break;
                case 'newest':
                    $query->reorder('created_at', 'desc')->orderBy('id', 'desc');
                    break;
                case 'title':
                    $query->reorder('title', 'asc');
                    break;
                default:
                    // default stays latest('due_at') from $base()
                    break;
            }
            $d['assignments'] = $query->paginate(15)
                ->appends($req->only(['q', 'class_id', 'subject_id', 'status', 'sort', 'view']));
            $this->attachStudentsCount($d['assignments']);

            return view('pages.support_team.assignments.index', $d);
        }

        if (Qs::userIsStudent()) {
            // CS2 view: assignments.student_index — each assignment
            // carries my_submission (own submission or null) plus the
            // eager-loaded own submissions collection as fallback.
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr) {
                return $this->deny();
            }
            $studentQuery = $base()
                ->where('my_class_id', $sr->my_class_id)
                ->where(function ($q) use ($sr) {
                    $q->whereNull('section_id')->orWhere('section_id', $sr->section_id);
                });
            if ($req->filled('q')) {
                $term = '%' . trim((string) $req->query('q')) . '%';
                $studentQuery->where(function ($q) use ($term) {
                    $q->where('title', 'like', $term)
                        ->orWhereHas('subject', function ($s) use ($term) {
                            $s->where('name', 'like', $term);
                        })
                        ->orWhereHas('teacher', function ($t) use ($term) {
                            $t->where('name', 'like', $term);
                        });
                });
            }
            $d['assignments'] = $studentQuery
                ->with(['submissions' => function ($q) use ($user) {
                    $q->where('student_id', $user->id);
                }])
                ->paginate(15)
                ->appends($req->only(['q']));
            foreach ($d['assignments'] as $assignment) {
                $assignment->setRelation('my_submission', $assignment->submissions->first());
            }

            return view('pages.support_team.assignments.student_index', $d);
        }

        if (Qs::userIsParent()) {
            // CS2 view: assignments.parent_index — $children entries of
            // ['sr' => StudentRecord, 'rows' => assignments for that
            // child], each row carrying the child's my_submission.
            $children = $this->childrenRecords($user)->load(['user', 'my_class', 'section']);
            $d['children'] = [];
            foreach ($children as $child) {
                $rows = $base()
                    ->where('my_class_id', $child->my_class_id)
                    ->where(function ($q) use ($child) {
                        $q->whereNull('section_id')->orWhere('section_id', $child->section_id);
                    })
                    ->with(['submissions' => function ($q) use ($child) {
                        $q->where('student_id', $child->user_id);
                    }])
                    ->get();
                foreach ($rows as $row) {
                    $row->setRelation('my_submission', $row->submissions->first());
                }
                $d['children'][] = ['sr' => $child, 'rows' => $rows];
            }
            // Kept for any consumer of the flat list.
            $d['assignments'] = $base()
                ->whereIn('my_class_id', $children->pluck('my_class_id')->unique())
                ->where(function ($q) use ($children) {
                    $q->whereNull('section_id')
                        ->orWhereIn('section_id', $children->pluck('section_id')->unique());
                })
                ->paginate(15);

            return view('pages.support_team.assignments.parent_index', $d);
        }

        return $this->deny();
    }

    /** students_count = students in the assignment's scope (CS2 index). */
    protected function attachStudentsCount($assignments): void
    {
        foreach ($assignments as $assignment) {
            $q = StudentRecord::where('my_class_id', $assignment->my_class_id)->where('grad', 0);
            if ($assignment->section_id) {
                $q->where('section_id', $assignment->section_id);
            }
            $assignment->students_count = $q->count();
        }
    }

    public function show($assignment_id)
    {
        $user = Auth::user();
        $assignment = Assignment::with(['subject', 'my_class', 'section', 'teacher'])
            ->withCount(['submissions', 'submissions as graded_count' => function ($q) {
                $q->whereNotNull('marks_awarded');
            }])
            ->find($assignment_id);

        if (!$assignment) {
            return redirect()->route('assignments.index')->with($this->errFlash(__('msg.rnf')));
        }

        if (!$this->canView($user, $assignment)) {
            return $this->deny();
        }

        $d['assignment'] = $assignment;
        $d['my_submission'] = null;
        $d['submissions'] = collect();

        if (Qs::userIsTeamSA() || (Qs::userIsTeacher() && $assignment->teacher_id == $user->id)) {
            $d['submissions'] = $assignment->submissions()
                ->with(['student', 'grader'])->latest('submitted_at')->get();
            $records = StudentRecord::whereIn('user_id', $d['submissions']->pluck('student_id'))
                ->get()->keyBy('user_id');
            $d['submissions']->each(function ($sub) use ($records) {
                $sub->setRelation('student_record', $records->get($sub->student_id));
            });

            return view('pages.support_team.assignments.show', $d);
        }

        if (Qs::userIsStudent()) {
            // CS2 view: assignments.student_show — it reads
            // $my_submission, and defensively $assignment->my_submission
            // or the first eager-loaded submissions item; set all three.
            $mine = $assignment->submissions()
                ->where('student_id', $user->id)->get();
            $assignment->setRelation('submissions', $mine);
            $assignment->setRelation('my_submission', $mine->first());
            $d['my_submission'] = $mine->first();
            $d['submissions'] = $mine;

            return view('pages.support_team.assignments.student_show', $d);
        }

        if (Qs::userIsParent()) {
            $childIds = $this->childrenRecords($user)->pluck('user_id');
            $d['submissions'] = $assignment->submissions()
                ->with(['student', 'grader'])
                ->whereIn('student_id', $childIds)->get();
        }

        // CS2 has no dedicated parent show view; parents land on the
        // read-only parent_index list. A direct show URL renders the
        // standard show view with parent-scoped submissions only.
        return view('pages.support_team.assignments.show', $d);
    }

    /*****************************************************************
     * TEACHER WRITE SIDE
     ****************************************************************/

    public function create()
    {
        if (!Qs::userIsTeacher()) {
            return $this->deny();
        }

        return view('pages.support_team.assignments.create', $this->formData());
    }

    public function store(Request $req)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }

        $data = $this->validateAssignment($req);

        $assignment = Assignment::create($data + ['teacher_id' => Auth::id()]);

        Audit::log('assignment.created', $assignment, 'Assignment "' . $assignment->title . '" for class ' . $assignment->my_class_id . ', subject ' . $assignment->subject_id);

        return redirect()->route('assignments.show', $assignment->id)
            ->with($this->okFlash(__('msg.store_ok')));
    }

    public function edit($assignment_id)
    {
        $assignment = Assignment::findOrFail($assignment_id);
        if (!Qs::userIsTeacher() || $assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }

        $d = $this->formData();
        $d['assignment'] = $assignment;

        return view('pages.support_team.assignments.edit', $d);
    }

    public function update(Request $req, $assignment_id)
    {
        $assignment = Assignment::findOrFail($assignment_id);
        if (!Qs::userIsTeacher() || $assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }

        $data = $this->validateAssignment($req, $assignment);

        $assignment->update($data);

        Audit::log('assignment.updated', $assignment, 'Assignment "' . $assignment->title . '" updated');

        return redirect()->route('assignments.show', $assignment->id)
            ->with($this->okFlash(__('msg.update_ok')));
    }

    /** Teacher deletes own; Admin may moderate-delete any. SA never. */
    public function destroy($assignment_id)
    {
        $assignment = Assignment::findOrFail($assignment_id);
        $user = Auth::user();

        $ownTeacher = Qs::userIsTeacher() && $assignment->teacher_id == $user->id;
        if (!$ownTeacher && !Qs::userIsAdmin()) {
            abort(403, __('msg.denied'));
        }

        foreach ($assignment->submissions as $sub) {
            if ($sub->attachment_path) {
                Storage::disk('local')->delete($sub->attachment_path);
            }
            $sub->delete();
        }
        if ($assignment->attachment_path) {
            Storage::disk('public')->delete($assignment->attachment_path);
        }
        $title = $assignment->title;
        $assignment->delete();

        Audit::log('assignment.deleted', null, 'Assignment "' . $title . '" deleted by ' . $user->name);

        return redirect()->route('assignments.index')->with($this->okFlash(__('msg.del_ok')));
    }

    /**
     * Batch 2026-10-26 (share, owner ask): same as materials — a
     * teacher teaching in 9-A and 9-C seeds the assignment to the
     * other section instead of recreating it. The seeded copy
     * starts with no submissions; the attachment is copied
     * server-side, never re-uploaded.
     */
    public function share($assignment_id)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $assignment = Assignment::findOrFail($assignment_id);
        if ($assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }
        if (!$assignment->section_id) {
            return redirect()->route('assignments.index')->with($this->errFlash('Whole-class assignments already reach every section — nothing to share.'));
        }
        $subject = Subject::find($assignment->subject_id);
        $targets = ShareTargets::forSubject(Auth::id(), $subject, $assignment->section_id);
        $already = $this->sharedSectionIds($assignment);
        $d = ['assignment' => $assignment, 'subject' => $subject, 'targets' => $targets, 'already' => $already];
        return view('pages.support_team.assignments.share', $d);
    }

    public function shareStore(Request $req, $assignment_id)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $assignment = Assignment::findOrFail($assignment_id);
        if ($assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }
        if (!$assignment->section_id) {
            abort(422, 'Whole-class assignments already reach every section.');
        }
        $data = $req->validate([
            'section_ids' => 'required|array|min:1',
            'section_ids.*' => 'integer|exists:sections,id',
        ]);
        $subject = Subject::find($assignment->subject_id);
        $targets = ShareTargets::forSubject(Auth::id(), $subject, $assignment->section_id)->keyBy('id');
        $shared = 0;
        $skipped = 0;
        foreach ($data['section_ids'] as $sid) {
            if (!isset($targets[$sid])) {
                abort(403, __('msg.denied'));
            }
            if (in_array((int) $sid, $this->sharedSectionIds($assignment), true)) {
                $skipped++;
                continue;
            }
            $row = $assignment->only(['teacher_id', 'my_class_id', 'subject_id', 'title', 'description', 'max_marks', 'due_at', 'allow_late']);
            $row['section_id'] = (int) $sid;
            if ($assignment->attachment_path && Storage::disk('public')->exists($assignment->attachment_path)) {
                $row['attachment_path'] = ShareTargets::copyPublicFile($assignment->attachment_path, 'assignments');
            } else {
                $row['attachment_path'] = null;
            }
            Assignment::create($row);
            $shared++;
        }
        Audit::log('assignment.shared', $assignment, 'Assignment "' . $assignment->title . '" shared to ' . $shared . ' section(s).');
        $msg = 'Shared to ' . $shared . ' section(s) — no need to recreate it.';
        if ($skipped > 0) {
            $msg .= ' ' . $skipped . ' section(s) already had it.';
        }
        return redirect()->route('assignments.index')->with($this->okFlash($msg));
    }

    /** Sections where this teacher already has same-title assignment for this class+subject. */
    protected function sharedSectionIds(Assignment $assignment): array
    {
        return Assignment::where('teacher_id', $assignment->teacher_id)
            ->where('my_class_id', $assignment->my_class_id)
            ->where('subject_id', $assignment->subject_id)
            ->where('title', $assignment->title)
            ->whereNotNull('section_id')
            ->pluck('section_id')->map(function ($id) { return (int) $id; })->all();
    }

    /*****************************************************************
     * STUDENT SUBMISSION + GRADING
     ****************************************************************/

    public function submit(Request $req, $assignment_id)
    {
        if (!Qs::userIsStudent()) {
            abort(403, __('msg.denied'));
        }

        $assignment = Assignment::findOrFail($assignment_id);
        $user = Auth::user();
        $sr = Qs::getSRByUserID($user->id);

        if (!$sr || !$this->studentInScope($assignment, $sr)) {
            abort(403, __('msg.denied'));
        }

        $req->validate([
            'attachment' => 'nullable|' . $this->studentFileRules,
            'text_answer' => 'nullable|string',
        ]);

        $existing = AssignmentSubmission::where([
            'assignment_id' => $assignment->id, 'student_id' => $user->id,
        ])->first();

        if ($existing && $existing->isGraded()) {
            return back()->with($this->errFlash('This submission has already been graded and can no longer be changed.'));
        }

        if ($assignment->isPastDue() && !$assignment->allow_late) {
            return back()->with($this->errFlash('The submission deadline for this assignment has passed.'));
        }

        if (!$req->hasFile('attachment') && !trim((string) $req->input('text_answer'))
            && (!$existing || !$existing->attachment_path)) {
            return back()->with($this->errFlash('Attach a file or write an answer before submitting.'));
        }

        $data = [
            'text_answer' => $req->input('text_answer'),
            'submitted_at' => now(),
            'is_late' => $assignment->isPastDue(),
        ];

        if ($req->hasFile('attachment')) {
            if ($existing && $existing->attachment_path) {
                Storage::disk('local')->delete($existing->attachment_path);
            }
            // Private disk on purpose: a submission file is only ever
            // served by the authorized assignments.download action.
            $data['attachment_path'] = $req->file('attachment')
                ->store('submissions/' . $assignment->id, 'local');
        }

        $submission = AssignmentSubmission::updateOrCreate(
            ['assignment_id' => $assignment->id, 'student_id' => $user->id],
            $data
        );

        Audit::log('assignment.submitted', $submission, 'Student ' . $user->name . ' submitted "' . $assignment->title . '"' . ($data['is_late'] ? ' (late)' : ''));

        return redirect()->route('assignments.show', $assignment->id)
            ->with($this->okFlash('Assignment submitted successfully.'));
    }

    /**
     * Grade one submission. CS2's show page posts per submission:
     * route('assignments.grade', $submission->id) with marks_awarded +
     * feedback in the body — the route parameter IS the submission.
     */
    public function grade(Request $req, $submission_id)
    {
        $submission = AssignmentSubmission::with('assignment')->find($submission_id);

        if (!$submission) {
            return back()->with($this->errFlash(__('msg.rnf')));
        }

        $assignment = $submission->assignment;
        if (!Qs::userIsTeacher() || $assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }

        $req->validate([
            'marks_awarded' => 'required|numeric|min:0|max:' . (float) $assignment->max_marks,
            'feedback' => 'nullable|string',
        ]);

        $submission->update([
            'marks_awarded' => $req->input('marks_awarded'),
            'feedback' => $req->input('feedback'),
            'graded_by' => Auth::id(),
            'graded_at' => now(),
        ]);

        Audit::log('assignment.graded', $submission, 'Submission for "' . $assignment->title . '" graded ' . $req->input('marks_awarded') . '/' . $assignment->max_marks);

        // Owner spec: the student AND their parent hear about the
        // grade in the bell as soon as it lands.
        Notify::toStudentAndParents(
            $submission->student_id,
            'assignment_graded',
            'Your assignment \'' . $assignment->title . '\' was graded: '
                . Notify::fmtMarks($submission->marks_awarded) . '/' . Notify::fmtMarks($assignment->max_marks),
            $submission->feedback ? 'Feedback: ' . $submission->feedback : null,
            route('assignments.show', $assignment->id)
        );

        return back()->with($this->okFlash('Submission graded successfully.'));
    }

    /**
     * Downloads, hanging off the assignment (CS2's link shapes):
     *  - route('assignments.download', $assignment->id) serves the
     *    assignment's own question file (public disk), for anyone who
     *    may view the assignment.
     *  - route('assignments.download', [$assignment->id,
     *    'submission' => $submission->id]) serves that submission's
     *    file from the PRIVATE disk — allowed only for the owning
     *    student, the assignment's teacher, a parent of the student,
     *    Admin, and Super Admin (read-only oversight).
     */
    public function download(Request $req, $assignment_id)
    {
        $assignment = Assignment::find($assignment_id);
        $user = Auth::user();

        if (!$assignment) {
            return back()->with($this->errFlash(__('msg.rnf')));
        }

        if ($req->query('submission')) {
            $submission = AssignmentSubmission::where([
                'id' => $req->query('submission'), 'assignment_id' => $assignment->id,
            ])->first();

            if (!$submission || !$submission->attachment_path) {
                return back()->with($this->errFlash(__('msg.rnf')));
            }

            $allowed = Qs::userIsTeamSA()
                || $submission->student_id == $user->id
                || $assignment->teacher_id == $user->id
                || (Qs::userIsParent() && Qs::userIsMyChild($submission->student_id, $user->id));

            if (!$allowed) {
                abort(403, __('msg.denied'));
            }

            if (!Storage::disk('local')->exists($submission->attachment_path)) {
                return back()->with($this->errFlash(__('msg.rnf')));
            }

            return Storage::disk('local')->download($submission->attachment_path);
        }

        if (!$assignment->attachment_path || !$this->canView($user, $assignment)) {
            abort(403, __('msg.denied'));
        }

        if (!Storage::disk('public')->exists($assignment->attachment_path)) {
            return back()->with($this->errFlash(__('msg.rnf')));
        }

        return Storage::disk('public')->download($assignment->attachment_path);
    }

    /*****************************************************************
     * INTERNAL-MARKS BRIDGE
     *
     * Rule (owner directive, 2026-10-22): a teacher may push the
     * assignment average into the Internal component of a term exam.
     * For every student in the source assignment's scope, the average
     * is taken over the student's GRADED submissions across ALL of
     * THIS teacher's assignments for the same class + subject (not
     * other teachers' assignments, not other subjects), each graded
     * submission contributing marks_awarded / max_marks x 100 — and,
     * at the teacher's choice on the preview page, the average runs
     * over ALL graded submissions, only the student's BEST N, or only
     * the student's LATEST N (N typed freely: 3, 5, anything). The
     * average % is scaled onto the max of the scheme's Internal
     * component and written through the very write path the marks
     * screens use (MarkScheme::saveComponentScores +
     * MarkComputation::recomputeSubject/recomputeRecords) — never a
     * raw update of the marks tables.
     ****************************************************************/

    /**
     * GET preview panel (CS2 view: assignments.internal). The blade
     * lists exams itself and posts the choice back here as ?exam_id=;
     * with no exam chosen yet only the panel shell renders (no
     * $preview). With an exam, $preview rows carry exactly the keys
     * the blade consumes via data_get: student_name, adm_no,
     * graded_count, average_percent, internal_marks.
     */
    public function internalPreview(Request $req, $assignment_id)
    {
        $assignment = Assignment::with(['subject', 'my_class', 'section'])->find($assignment_id);
        if (!$assignment || !Qs::userIsTeacher() || $assignment->teacher_id != Auth::id()) {
            return $this->deny();
        }

        $d['assignment'] = $assignment;
        $d['exam_id'] = $req->input('exam_id') ? (int) $req->input('exam_id') : null;
        // Owner rule (2026-10-22, extended 2026-10-25): the average
        // mode is the teacher's choice on this page — all graded
        // assignments, the best N, or the latest N (N typed freely:
        // 3, 5, any number). Defaults to all of them.
        $d['avg_choice'] = $this->averageChoiceFromRequest($req);
        $d['avg_mode'] = $d['avg_choice']['mode'];
        $d['avg_n'] = $d['avg_choice']['n'];
        $d['population_count'] = $this->siblingAssignments($assignment)->count();

        if ($d['exam_id']) {
            $exam = Exam::find($d['exam_id']);
            if ($exam) {
                $component = $this->internalComponent($exam->id, $assignment->subject_id);
                $d['preview'] = collect($this->computeAverages($assignment, $component, $d['avg_mode'], $d['avg_n']))
                    ->map(function ($row) {
                        return [
                            'student_name' => $row['student_name'],
                            'adm_no' => $row['adm_no'],
                            'graded_count' => $row['graded_count'],
                            'counted' => $row['counted'],
                            'average_percent' => $row['average_percent'],
                            'internal_marks' => $row['scaled_internal'],
                        ];
                    })->all();
            }
        }

        return view('pages.support_team.assignments.internal', $d);
    }

    /** POST execute: write the scaled Internal marks + audit entry. */
    public function pushInternal(Request $req, $assignment_id)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }

        $assignment = Assignment::find($assignment_id);
        if (!$assignment || $assignment->teacher_id != Auth::id()) {
            abort(403, __('msg.denied'));
        }

        $exam = Exam::find($req->input('exam_id'));
        if (!$exam) {
            return back()->with($this->errFlash('Please choose an exam.'));
        }

        // The teacher must actually teach this subject in this class.
        $teaches = Subject::where('id', $assignment->subject_id)
            ->where('my_class_id', $assignment->my_class_id)
            ->where('teacher_id', Auth::id())->exists();
        if (!$teaches || !TeacherScope::canTeachClass(Auth::user(), $assignment->my_class_id)) {
            return back()->with($this->errFlash('You do not teach this subject for this class.'));
        }

        if ($exam->results_published) {
            return back()->with($this->errFlash('Results for this exam are already published. Unpublish them before pushing internal marks.'));
        }

        $component = $this->internalComponent($exam->id, $assignment->subject_id);
        if (!$component) {
            return back()->with($this->errFlash('The marking scheme for this exam and subject has no Internal component, so assignment marks cannot be pushed into it.'));
        }

        $avgChoice = $this->averageChoiceFromRequest($req);
        $rows = $this->computeAverages($assignment, $component, $avgChoice['mode'], $avgChoice['n']);
        $examRepo = app(ExamRepo::class);
        $written = 0;
        $sectionsTouched = [];
        $pushedStudentIds = [];

        foreach ($rows as $row) {
            if ($row['scaled_internal'] === null) {
                continue;
            }
            $pushedStudentIds[$row['student_id']] = true;

            $mark = Mark::firstOrCreate([
                'exam_id' => $exam->id,
                'student_id' => $row['student_id'],
                'subject_id' => $assignment->subject_id,
                'my_class_id' => $assignment->my_class_id,
                'section_id' => $row['section_id'],
                'year' => $exam->year,
            ]);
            $examRepo->createRecord([
                'exam_id' => $exam->id,
                'student_id' => $row['student_id'],
                'my_class_id' => $assignment->my_class_id,
                'section_id' => $row['section_id'],
                'year' => $exam->year,
            ]);

            MarkScheme::saveComponentScores($mark, [$component['id'] => $row['scaled_internal']]);
            $sectionsTouched[$row['section_id']] = true;
            $written++;
        }

        foreach (array_keys($sectionsTouched) as $sectionId) {
            MarkComputation::recomputeSubject($exam, $assignment->my_class_id, $sectionId, $assignment->subject_id, $exam->year);
            MarkComputation::recomputeRecords($exam, $assignment->my_class_id, $sectionId, $exam->year);
        }

        $avgDesc = $avgChoice['mode'] === 'best' ? 'best ' . $avgChoice['n'] . ' graded assignments'
            : ($avgChoice['mode'] === 'latest' ? 'latest ' . $avgChoice['n'] . ' graded assignments' : 'all graded assignments');
        Audit::log('assignment.internal_pushed', $assignment,
            'Teacher ' . Auth::user()->name . ' pushed assignment averages to Internal marks: exam '
            . $exam->id . ' (' . $exam->name . '), class ' . $assignment->my_class_id
            . ', subject ' . $assignment->subject_id . ', students written: ' . $written
            . ', average over: ' . $avgDesc
            . ', component: ' . $component['name'] . ' (max ' . $component['max_marks'] . ')');

        // Owner spec: every student whose Internal marks landed —
        // and their parent — hears about it in the bell.
        $subjectName = optional($assignment->subject)->name ?: 'a subject';
        foreach (array_keys($pushedStudentIds) as $studentUserId) {
            Notify::toStudentAndParents(
                $studentUserId,
                'internal_pushed',
                'Internal marks for ' . $subjectName . ' were added to ' . $exam->name,
                'From your assignment average, pushed by your teacher.',
                route('assignments.show', $assignment->id)
            );
        }

        return back()->with($this->okFlash('Internal marks pushed for ' . $written . ' student(s).'));
    }

    /*****************************************************************
     * HELPERS
     ****************************************************************/

    protected function deny()
    {
        return redirect()->route('dashboard')->with($this->errFlash(__('msg.denied')));
    }

    /**
     * Dual flash keys: pop_* drives the app-wide modal pattern, while
     * CS2's LMS blades render flash_success / flash_danger alerts.
     */
    protected function okFlash(string $msg): array
    {
        return ['pop_success' => $msg, 'flash_success' => $msg];
    }

    protected function errFlash(string $msg): array
    {
        return ['pop_error' => $msg, 'flash_danger' => $msg];
    }

    protected function childrenRecords($user)
    {
        return StudentRecord::where('my_parent_id', $user->id)->where('grad', 0)->get();
    }

    protected function studentInScope(Assignment $assignment, StudentRecord $sr): bool
    {
        return $assignment->my_class_id == $sr->my_class_id
            && ($assignment->section_id === null || $assignment->section_id == $sr->section_id);
    }

    protected function canView($user, Assignment $assignment): bool
    {
        if (Qs::userIsTeamSA()) {
            return true;
        }
        if (Qs::userIsTeacher()) {
            return $assignment->teacher_id == $user->id;
        }
        if (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            return $sr && $this->studentInScope($assignment, $sr);
        }
        if (Qs::userIsParent()) {
            foreach ($this->childrenRecords($user) as $child) {
                if ($this->studentInScope($assignment, $child)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Form data shared by create/edit. */
    protected function formData(): array
    {
        $subjects = Subject::with('my_class')
            ->where('teacher_id', Auth::id())->get();
        $classIds = $subjects->pluck('my_class_id')->unique();

        return [
            'subjects' => $subjects,
            'classes' => \App\Models\MyClass::whereIn('id', $classIds)->get(),
            'sections' => Section::whereIn('my_class_id', $classIds)->get(),
        ];
    }

    /** Validation + attachment handling shared by store/update. */
    protected function validateAssignment(Request $req, Assignment $existing = null): array
    {
        $data = $req->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'my_class_id' => 'required|integer|exists:my_classes,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'max_marks' => 'required|numeric|min:0.5|max:1000',
            'due_at' => 'required|date',
            'allow_late' => 'nullable|boolean',
            'attachment' => 'nullable|' . $this->fileRules,
        ]);

        // The subject row is class-bound and must be THIS teacher's.
        $subject = Subject::where('id', $data['subject_id'])
            ->where('teacher_id', Auth::id())->first();
        if (!$subject || $subject->my_class_id != $data['my_class_id']) {
            abort(403, 'You do not teach this subject for this class.');
        }
        if (!empty($data['section_id'])) {
            $ok = Section::where('id', $data['section_id'])
                ->where('my_class_id', $data['my_class_id'])->exists();
            if (!$ok) {
                abort(422, 'The chosen section does not belong to this class.');
            }
        }

        $data['allow_late'] = $req->boolean('allow_late');
        unset($data['attachment']);

        if ($req->hasFile('attachment')) {
            if ($existing && $existing->attachment_path) {
                Storage::disk('public')->delete($existing->attachment_path);
            }
            $data['attachment_path'] = $req->file('attachment')
                ->store('assignments', 'public');
        }

        return $data;
    }

    /**
     * The scheme's Internal component for (exam, subject), or NULL.
     * Matches the component the scheme editor creates: name
     * 'Internal' / 'Internal Assessment' or short code 'INT'.
     * Legacy schemes (CA/Exam columns) have none by construction.
     */
    protected function internalComponent($examId, $subjectId)
    {
        foreach (MarkScheme::components($examId, $subjectId) as $component) {
            if ($component['field'] !== null) {
                continue; // legacy column component — never Internal
            }
            if ($component['name'] === 'Internal'
                || $component['name'] === 'Internal Assessment'
                || $component['short_code'] === 'INT'
                || stripos($component['name'], 'Internal') === 0) {
                return $component;
            }
        }
        return null;
    }

    /** The bridge population: ALL assignments of THIS teacher for the
     *  source assignment's class + subject. */
    protected function siblingAssignments(Assignment $source)
    {
        return Assignment::where([
            'teacher_id' => $source->teacher_id,
            'my_class_id' => $source->my_class_id,
            'subject_id' => $source->subject_id,
        ])->get();
    }

    /**
     * The teacher's average choice from the request: a mode
     * ('all', 'best', 'latest') and a count N (free number the
     * teacher types — 3, 5, anything; a missing N means 1 for
     * best/latest, and is ignored for 'all'). The legacy 'best_n'
     * parameter still maps to mode 'best' so old preview links and
     * bookmarks keep working.
     */
    protected function averageChoiceFromRequest(Request $req)
    {
        $mode = strtolower((string) $req->input('mode', ''));
        if (!in_array($mode, ['best', 'latest'], true)) {
            $legacyN = $req->input('best_n');
            if ($legacyN !== null && $legacyN !== '' && strtolower((string) $legacyN) !== 'all' && (int) $legacyN > 0) {
                return ['mode' => 'best', 'n' => (int) $legacyN];
            }
            if ($mode !== 'all') {
                $mode = 'all';
            }
        }
        if ($mode === 'all') {
            return ['mode' => 'all', 'n' => null];
        }
        $n = (int) $req->input('avg_n', 0);

        return ['mode' => $mode, 'n' => $n > 0 ? $n : 1];
    }

    /**
     * Per-student averages for the bridge. Population of assignments:
     * ALL assignments of THIS teacher for the source assignment's
     * class + subject. Only GRADED submissions count; each contributes
     * marks_awarded / max_marks x 100. Mode 'all' averages every
     * graded percent; 'best' sorts best-first and averages the top N;
     * 'latest' sorts by assignment recency (created, then id, both
     * descending) and averages the newest N. A student with fewer
     * graded counts what they have — the average is over
     * min(N, graded_count), reported as 'counted'. Scaled = round
     * (avg% of the component max) — the marks pipeline stores whole
     * numbers.
     */
    protected function computeAverages(Assignment $source, $component, $mode = 'all', $n = null): array
    {
        $siblings = $this->siblingAssignments($source)
            ->load(['submissions' => function ($q) {
                $q->whereNotNull('marks_awarded');
            }]);

        $percents = [];
        foreach ($siblings as $assignment) {
            foreach ($assignment->submissions as $sub) {
                if ($assignment->max_marks > 0) {
                    $percents[$sub->student_id][] = [
                        'pct' => ($sub->marks_awarded / $assignment->max_marks) * 100,
                        'at' => $assignment->created_at ? $assignment->created_at->timestamp : 0,
                        'id' => $assignment->id,
                    ];
                }
            }
        }

        $students = StudentRecord::with('user')
            ->where('my_class_id', $source->my_class_id)->where('grad', 0);
        if ($source->section_id) {
            $students->where('section_id', $source->section_id);
        }

        $rows = [];
        foreach ($students->get() as $sr) {
            $list = $percents[$sr->user_id] ?? [];
            if ($mode === 'best') {
                usort($list, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
                $used = array_slice($list, 0, $n ?: 1);
            } elseif ($mode === 'latest') {
                usort($list, function ($a, $b) {
                    return ($b['at'] <=> $a['at']) ?: ($b['id'] <=> $a['id']);
                });
                $used = array_slice($list, 0, $n ?: 1);
            } else {
                $used = $list;
            }
            $values = array_column($used, 'pct');
            $avg = $values ? array_sum($values) / count($values) : null;
            $rows[] = [
                'student_id' => (int) $sr->user_id,
                'student_name' => $sr->user ? $sr->user->name : '',
                'adm_no' => $sr->adm_no,
                'section_id' => (int) $sr->section_id,
                'graded_count' => count($list),
                'counted' => count($used),
                'average_percent' => $avg === null ? null : round($avg, 2),
                'scaled_internal' => ($avg === null || !$component)
                    ? null
                    : (int) round($avg / 100 * $component['max_marks']),
            ];
        }

        return $rows;
    }
}
