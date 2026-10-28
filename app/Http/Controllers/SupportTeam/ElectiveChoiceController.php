<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\ElectiveChoice;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\RolePermission;
use App\Support\Streams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Student Elective Choice (Class 11/12) — 2026-10-28.
 *
 * Controller-guarded (routes carry no team middleware, so students
 * must reach them): every action gates its own role here.
 *  - index: admin sees ALL choices; a Class 11/12 student sees only
 *    their own choices plus the subjects offered for their
 *    class/stream; any other role aborts 403.
 *  - store: Class 11/12 students only. A student of another class
 *    gets 403 on POST (on GET they get an explanatory page, not an
 *    error — see index).
 *  - approve / reject: ADMIN ONLY (Qs::userIsAdmin, i.e. user_type
 *    'admin') OR a holder of the matching custom grant
 *    (electives.approve / electives.reject — grant add-on 2026-10-28,
 *    see App\Support\RolePermission). A teacher without the grant
 *    aborts 403; so does super_admin, parent, or anyone else.
 *
 * Offered subjects (verified against the real schema, not assumed):
 * subjects of the student's class, filtered through
 * Streams::subjectsForSection() against the student's section stream:
 * a subject with a blank (null) stream tag is common to every stream
 * (English, Hindi); a tagged subject applies only when its CSV tag
 * list contains the section's stream (Physics/Chemistry carry
 * 'pcm,pcb' and so are shared). A section with no stream tag sees
 * every subject of its class (Streams' old behaviour, unchanged).
 *
 * Duplicate rule: one LIVE (pending or approved) row per student per
 * subject. A second pick while a pending/approved row exists is
 * refused (redirect back with flash_danger, no new row). A rejected
 * row does NOT block a re-pick: re-picking creates a NEW pending
 * row, leaving the rejected row as history.
 *
 * MARKS INTEGRATION BOUNDARY: this feature writes ONLY to
 * elective_choices. It never writes into marks, tabulation, or exam
 * scope. Approved choices are displayed on the electives index
 * itself. No hook into the student record view was added: that view
 * is shared marks-adjacent code and the index display satisfies the
 * spec, so marks integration is honestly cut (see report).
 */
class ElectiveChoiceController extends Controller
{
    /** Classes eligible to pick electives. */
    public const ELIGIBLE_CLASSES = ['Class 11', 'Class 12'];

    protected function studentRecord()
    {
        return StudentRecord::where('user_id', Auth::id())->first();
    }

    protected function isEligible($record): bool
    {
        if (!$record || !$record->my_class) {
            return false;
        }

        return in_array($record->my_class->name, self::ELIGIBLE_CLASSES, true);
    }

    /** Subjects offered to this student: own class, filtered by section stream. */
    protected function offeredSubjects($record)
    {
        $subjects = Subject::where('my_class_id', $record->my_class_id)
            ->orderBy('name')
            ->get();

        return Streams::subjectsForSection($subjects, $record->section);
    }

    public function index()
    {
        if (Qs::userIsAdmin()) {
            $choices = ElectiveChoice::with(['student_record.user', 'student_record.my_class', 'student_record.section', 'subject', 'decidedBy'])
                ->orderBy('id', 'desc')
                ->get();

            return view('pages.support_team.electives.index', [
                'is_admin' => true,
                'eligible' => true,
                'record' => null,
                'offered' => collect(),
                'choices' => $choices,
                'latestBySubject' => collect(),
            ]);
        }

        if (!Qs::userIsStudent()) {
            abort(403);
        }

        $record = $this->studentRecord();
        if ($record) {
            $record->load(['my_class', 'section']);
        }

        // Other classes (and students with no record) get an
        // explanatory page, not an error.
        if (!$this->isEligible($record)) {
            return view('pages.support_team.electives.index', [
                'is_admin' => false,
                'eligible' => false,
                'record' => $record,
                'offered' => collect(),
                'choices' => collect(),
                'latestBySubject' => collect(),
            ]);
        }

        $choices = ElectiveChoice::with(['subject'])
            ->where('student_record_id', $record->id)
            ->orderBy('id', 'desc')
            ->get();

        // Latest row per subject decides the pick button state
        // (a rejected row + a newer pending row must show as picked).
        $latestBySubject = $choices->sortBy('id')->groupBy('subject_id')
            ->map(function ($rows) {
                return $rows->last();
            });

        return view('pages.support_team.electives.index', [
            'is_admin' => false,
            'eligible' => true,
            'record' => $record,
            'offered' => $this->offeredSubjects($record),
            'choices' => $choices,
            'latestBySubject' => $latestBySubject,
        ]);
    }

    public function store(Request $request)
    {
        if (!Qs::userIsStudent()) {
            abort(403);
        }

        $record = $this->studentRecord();
        if ($record) {
            $record->load(['my_class', 'section']);
        }
        if (!$this->isEligible($record)) {
            abort(403);
        }

        $request->validate(['subject_id' => 'required|integer|exists:subjects,id']);
        $subject = Subject::findOrFail((int) $request->input('subject_id'));

        // Foreign subject: wrong class, or a subject whose stream tags
        // do not cover the student's section stream.
        if ((int) $subject->my_class_id !== (int) $record->my_class_id) {
            abort(403);
        }
        if (!Streams::appliesTo($subject, $record->section ? $record->section->stream : null)) {
            abort(403);
        }

        $live = ElectiveChoice::where('student_record_id', $record->id)
            ->where('subject_id', $subject->id)
            ->whereIn('status', [ElectiveChoice::STATUS_PENDING, ElectiveChoice::STATUS_APPROVED])
            ->first();
        if ($live) {
            return back()->with('flash_danger', 'You have already picked ' . $subject->name . ' — it is ' . $live->status . '.');
        }

        ElectiveChoice::create([
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => ElectiveChoice::STATUS_PENDING,
            'decided_by' => null,
        ]);

        return back()->with('flash_success', 'Your choice of ' . $subject->name . ' is submitted and pending approval.');
    }

    public function approve(ElectiveChoice $elective)
    {
        // Grant add-on (2026-10-28): admin OR grant — base rule unchanged.
        if (!Qs::userIsAdmin() && !RolePermission::allows(Auth::user(), 'electives.approve')) {
            abort(403);
        }

        $elective->status = ElectiveChoice::STATUS_APPROVED;
        $elective->decided_by = Auth::id();
        $elective->save();

        return back()->with('flash_success', 'Elective choice approved.');
    }

    public function reject(ElectiveChoice $elective)
    {
        // Grant add-on (2026-10-28): admin OR grant — base rule unchanged.
        if (!Qs::userIsAdmin() && !RolePermission::allows(Auth::user(), 'electives.reject')) {
            abort(403);
        }

        $elective->status = ElectiveChoice::STATUS_REJECTED;
        $elective->decided_by = Auth::id();
        $elective->save();

        return back()->with('flash_success', 'Elective choice rejected — the student may pick it again.');
    }
}
