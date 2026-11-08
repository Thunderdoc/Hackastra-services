<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Exam\ExamCreate;
use App\Http\Requests\Exam\ExamUpdate;
use App\Models\Exam;
use App\Repositories\ExamRepo;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\PublicationReadiness;
use App\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
    protected $exam;
    public function __construct(ExamRepo $exam)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        // Limited principal role (owner order 2026-10-28): reads
        // open to the principal, and result publication is a
        // designated principal approval (not other writes).
        $this->middleware('teamOrPrincipal:SA', ['only' => ['index', 'show', 'publication']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show', 'publishResults', 'unpublishResults', 'publishAdmitCards', 'unpublishAdmitCards', 'publication', 'notifyPending']]);
        $this->middleware('teamOrPrincipal:SAWrite', ['only' => ['publishResults', 'unpublishResults', 'publishAdmitCards', 'unpublishAdmitCards', 'notifyPending']]);

        $this->exam = $exam;
    }

    public function index()
    {
        $d['exams'] = $this->exam->all();
        $d['my_classes'] = \App\Models\MyClass::orderBy('id')->get();
        $d['periods'] = \App\Models\AssessmentPeriod::forYear(Qs::getSetting('current_session'))
            ->where('is_active', true)->get();
        return view('pages.support_team.exams.index', $d);
    }

    /**
     * The legacy term mirror for a period (R1 §1.3): a term
     * period contributes its legacy_term (0 when NULL); a unit
     * period contributes its parent term period's legacy_term
     * (0 when NULL); standalone contributes 0.
     */
    public static function derivedTerm(\App\Models\AssessmentPeriod $period): int
    {
        if ($period->kind === 'unit') {
            $parent = $period->feedsPeriod;
            return $parent && $parent->legacy_term !== null ? (int) $parent->legacy_term : 0;
        }
        return $period->legacy_term !== null ? (int) $period->legacy_term : 0;
    }

    /**
     * Compat shim: an older post carrying only 'term' maps to the
     * session's term period for that legacy number, creating the
     * backfill-shaped period when the session has none (§3.1).
     */
    protected function periodForLegacyTerm($term, $year): \App\Models\AssessmentPeriod
    {
        $period = \App\Models\AssessmentPeriod::where('year', $year)
            ->where('kind', 'term')->where('legacy_term', (int) $term)->first();
        if (!$period) {
            $period = \App\Models\AssessmentPeriod::create([
                'year' => $year, 'name' => 'Term ' . (int) $term, 'kind' => 'term',
                'feeds_period_id' => null, 'counts_toward_annual' => true,
                'sort_order' => ((int) $term) * 10, 'legacy_term' => (int) $term, 'is_active' => true,
            ]);
        }
        return $period;
    }

    public function store(ExamCreate $req)
    {
        $data = $req->only(['name', 'my_class_id']);
        $data['year'] = Qs::getSetting('current_session');
        // Class scope (owner build 2026-10-25): 0 = every class
        // (the historical global exam); anything else = one class.
        $data['my_class_id'] = !empty($data['my_class_id']) ? (int) $data['my_class_id'] : 0;

        if ($data['my_class_id'] !== 0 && !\App\Models\MyClass::find($data['my_class_id'])) {
            return back()->with('flash_danger', 'Unknown class.')->withInput();
        }

        // Phase 1: the exam belongs to a period; 'term' is the
        // derived legacy mirror, never posted by the new form.
        $period = $req->filled('period_id')
            ? \App\Models\AssessmentPeriod::find((int) $req->input('period_id'))
            : $this->periodForLegacyTerm($req->input('term'), $data['year']);
        if (!$period) {
            return back()->with('flash_danger', 'Unknown assessment period.')->withInput();
        }
        $data['period_id'] = $period->id;
        $data['term'] = self::derivedTerm($period);

        // Partition rule (re-keyed to the period, R1 §2.4): for
        // one (period, session), a class is covered by at most one
        // exam — a global exam blocks class exams for that period
        // and vice versa. The DB unique (period_id, year,
        // my_class_id) is the backstop; this refusal is the polite
        // message instead of an exception. A unit-test exam and
        // its term exam for the same class now coexist.
        if ($conflict = $this->examScopeConflict($data['period_id'], $data['year'], $data['my_class_id'])) {
            return back()->with('flash_danger', $conflict)->withInput();
        }

        $this->exam->create($data);
        // Loop 112: post-save lands on the exams index (owner ruling).
        return Qs::storeOk('exams.index');
    }

    /**
     * The partition refusal for one (period, session, scope):
     * null when the exam may be created, else the message. A NULL
     * my_class_id (pre-migration rows) counts as global.
     */
    protected function examScopeConflict($periodId, $year, $scope, $ignoreExamId = null): ?string
    {
        $periodExams = $this->exam->getExam(['period_id' => $periodId, 'year' => $year]);
        // Unlinked exams (period_id NULL — seeded rows before the
        // pattern page links them) still belong to the period their
        // term mirrors: without this merge, a second exam could be
        // created over an unlinked one and the partition would have
        // a hole until the link fixup runs.
        $period = \App\Models\AssessmentPeriod::find($periodId);
        if ($period && $period->legacy_term !== null) {
            $unlinked = $this->exam->getExam(['year' => $year, 'term' => (int) $period->legacy_term])
                ->filter(function ($e) { return empty($e->period_id); });
            $periodExams = $periodExams->merge($unlinked);
        }
        if ($ignoreExamId) {
            $periodExams = $periodExams->filter(function ($e) use ($ignoreExamId) { return (int) $e->id !== (int) $ignoreExamId; });
        }
        if ($scope === 0) {
            if ($periodExams->isNotEmpty()) {
                return 'An exam already exists for this period in the current session ('.$year.'). A global exam covers every class — edit the existing exam instead of adding another.';
            }
            return null;
        }
        $global = $periodExams->filter(function ($e) { return empty($e->my_class_id); });
        if ($global->isNotEmpty()) {
            return 'A global exam ('.$global->first()->name.') already covers this period for every class — a class exam cannot be added for the same period.';
        }
        $same = $periodExams->filter(function ($e) use ($scope) { return (int) $e->my_class_id === $scope; });
        if ($same->isNotEmpty()) {
            return 'An exam for this class and period already exists in the current session ('.$year.'). Edit the existing exam instead.';
        }
        return null;
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (R-13/X-2): the {exam} parameter is not
        // hash-bound, so a Qs-hashed URL missed the lookup and the
        // view dereferenced null (500). Resolve raw OR hashed ids;
        // a genuinely missing exam lands on the index with a warning
        // like the sibling modules, never a 500.
        $d['ex'] = $ex = $this->exam->find(Qs::resolveId($id));
        if (is_null($ex)) {
            return Qs::goWithDanger('exams.index');
        }
        $d['periods'] = \App\Models\AssessmentPeriod::forYear($ex->year)
            ->where('is_active', true)->get();
        // Phase 1: the period is locked once marks exist (moving
        // an exam between periods would re-point its rows' totals).
        $d['has_marks'] = \App\Models\Mark::where('exam_id', $ex->id)->exists();

        return view('pages.support_team.exams.edit', $d);
    }

    public function update(ExamUpdate $req, $id)
    {
        $examId = Qs::resolveId($id);
        $exam = $this->exam->find($examId);
        if (!$exam) {
            return Qs::goWithDanger('exams.index');
        }
        $data = $req->only(['name']);

        // Phase 1: a period change is allowed only while the exam
        // has ZERO mark rows; the legacy 'term' post maps through
        // the same period machinery (compat shim).
        $targetPeriod = null;
        if ($req->filled('period_id')) {
            $targetPeriod = \App\Models\AssessmentPeriod::find((int) $req->input('period_id'));
        } elseif ($req->filled('term')) {
            $targetPeriod = \App\Models\AssessmentPeriod::where('year', $exam->year)
                ->where('kind', 'term')->where('legacy_term', (int) $req->input('term'))->first();
        }
        if ($targetPeriod && (int) $targetPeriod->id !== (int) $exam->period_id) {
            if (\App\Models\Mark::where('exam_id', $exam->id)->exists()) {
                return back()->with('flash_danger', 'This exam already has marks, so its period cannot be changed.')->withInput();
            }
            // The period partition applies to moves too.
            if ($conflict = $this->examScopeConflict($targetPeriod->id, $exam->year, (int) $exam->my_class_id, $exam->id)) {
                // The exam itself is excluded from the check (an
                // unlinked exam may match its own legacy mirror).
                return back()->with('flash_danger', $conflict)->withInput();
            }
            $data['period_id'] = $targetPeriod->id;
            $data['term'] = self::derivedTerm($targetPeriod);
        }

        $this->exam->update($examId, $data);
        // Loop 112: post-save lands on the exams index (owner ruling) —
        // was back() to the edit form.
        return Qs::updateOk('exams.index');
    }

    public function destroy($id)
    {
        $this->exam->delete(Qs::resolveId($id));
        return back()->with('flash_success', __('msg.del_ok'));
    }

    public function create()
    {
        // No separate create page exists: the create form lives on the
        // index page (the resource route otherwise returned 500).
        return $this->index();
    }

    public function show($id)
    {
        // No detail page exists for this module; land on its index
        // instead of returning 500.
        return redirect()->route('exams.index');
    }

    /**
     * Workstream BH — Result Publish switch. Publishing makes this
     * exam's results visible to students and parents (their marksheet
     * and print surfaces read the flag server-side). Staff surfaces
     * never read the flag. teamSA gate: the constructor middleware
     * covers these actions exactly like the rest of exam management.
     */
    /**
     * Publication workflow (owner order 2026-10-29): publishing is
     * the Principal's final act. The Admin prepares: this page shows
     * exactly which teachers have not yet submitted their marks,
     * lets the office remind them, and — once every unit is
     * submitted — lets the Admin send the publication request. The
     * Principal publishes from here (and sees requests in the
     * Command Center's Approval Center).
     */
    public function publication($id)
    {
        $exam = $this->exam->find(Qs::resolveId($id));
        if (!$exam) {
            return redirect()->route('exams.index');
        }
        $d['exam'] = $exam;
        $d['pending_units'] = PublicationReadiness::pendingUnits($exam);
        $d['total_units'] = PublicationReadiness::totalUnits($exam);
        $d['requester'] = $exam->publish_requested_by ? User::find($exam->publish_requested_by) : null;
        return view('pages.support_team.exams.publication', $d);
    }

    /** Remind the teacher(s) whose marks are still unsubmitted. */
    public function notifyPending(Request $req, $id)
    {
        $exam = $this->exam->find(Qs::resolveId($id));
        if (!$exam) {
            return redirect()->route('exams.index');
        }
        $pending = collect(PublicationReadiness::pendingUnits($exam));
        if ($req->filled('units')) {
            // Bulk remind (owner ask 2026-10-29): the publication
            // page's checkboxes post the picked units as
            // "section_id:subject_id" pairs; remind exactly those.
            $picked = collect((array) $req->input('units'))->map(function ($pair) {
                $parts = explode(':', (string) $pair);
                return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
            });
            $pending = $pending->filter(function ($u) use ($picked) {
                return $picked->contains(function ($pair) use ($u) {
                    return $pair[0] === $u['section_id'] && $pair[1] === $u['subject_id'];
                });
            });
        } elseif (!$req->boolean('all')) {
            $pending = $pending->filter(function ($u) use ($req) {
                return $u['section_id'] === (int) $req->input('section_id')
                    && $u['subject_id'] === (int) $req->input('subject_id');
            });
        }
        $sent = 0;
        $unassigned = 0;
        foreach ($pending as $u) {
            if (!$u['teacher_id']) {
                $unassigned++;
                continue;
            }
            Notify::toUser(
                $u['teacher_id'],
                'marks_pending',
                'Marks not submitted: ' . $u['subject_name'],
                'Please enter and submit the ' . $u['subject_name'] . ' marks for '
                    . $u['class_name'] . ' — ' . $u['section_name'] . ' (' . $exam->name . ') within 2 days, so the results can be published.',
                route('marks.manage', [$exam->id, $u['my_class_id'], $u['section_id'], $u['subject_id']])
            );
            $sent++;
        }
        $msg = 'Reminder sent to ' . $sent . ' teacher' . ($sent === 1 ? '' : 's') . '.';
        if ($unassigned > 0) {
            $msg .= ' ' . $unassigned . ' pending subject' . ($unassigned === 1 ? '' : 's') . ' have no teacher assigned — assign a teacher first.';
        }
        return back()->with('flash_success', $msg);
    }

    /**
     * Admin sends the publication request — allowed only when
     * every unit's marks are submitted (owner order 2026-10-29).
     */
    public function requestPublication($id)
    {
        $exam = $this->exam->find(Qs::resolveId($id));
        if (!$exam) {
            return redirect()->route('exams.index');
        }
        if ($exam->results_published) {
            return back()->with('pop_error', 'These results are already published.');
        }
        if ($exam->publish_requested_at) {
            return back()->with('pop_error', 'A publication request is already waiting with the Principal.');
        }
        if (PublicationReadiness::totalUnits($exam) === 0) {
            return back()->with('pop_error', 'No marks have been entered for this exam yet — there is nothing to publish.');
        }
        $pending = PublicationReadiness::pendingCount($exam);
        if ($pending > 0) {
            return back()->with('pop_error', $pending . ' subject' . ($pending === 1 ? '' : 's') . ' still have unsubmitted marks. Remind the teachers from this page and send the request once every subject is submitted.');
        }
        Exam::where('id', $exam->id)->update([
            'publish_requested_by' => Auth::user()->id,
            'publish_requested_at' => now(),
        ]);
        foreach (User::where('user_type', 'principal')->pluck('id') as $principalId) {
            Notify::toUser(
                $principalId,
                'publication_requested',
                'Result publication requested: ' . $exam->name,
                'All marks for ' . $exam->name . ' are submitted. Please review and publish the results.',
                route('exams.publication', $exam->id)
            );
        }
        Audit::log('exams.publication_requested', null, 'Publication requested for exam ' . $exam->id . ' (' . $exam->name . ') by ' . Auth::user()->name);
        return back()->with('flash_success', 'Publication request sent to the Principal. The results go live only when the Principal publishes them.');
    }

    public function publishResults($id)
    {
        // Owner order 2026-10-29: the Principal alone publishes.
        // The Admin's path is the publication request above.
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal publishes results. When every subject\'s marks are submitted, send a publication request from the Publication page instead.');
        }
        $examId = Qs::resolveId($id);
        $this->exam->update($examId, ['results_published' => true]);
        Exam::where('id', $examId)->update(['publish_requested_by' => null, 'publish_requested_at' => null]);
        // Owner build 2026-10-25: publishing notifies everyone —
        // every active student of the session + linked parents.
        $exam = $this->exam->find($examId);
        $notified = $exam ? Notify::resultsPublished($exam) : 0;
        $msg = $notified > 0
            ? 'Results published. Students and parents have been notified and can now view results for this exam.'
            : 'Results published. Students and parents can now view results for this exam.';
        return back()->with('flash_success', $msg);
    }

    public function unpublishResults($id)
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal unpublishes results.');
        }
        $examId = Qs::resolveId($id);
        $this->exam->update($examId, ['results_published' => false]);
        Exam::where('id', $examId)->update(['publish_requested_by' => null, 'publish_requested_at' => null]);
        return back()->with('flash_success', 'Results unpublished. Students and parents can no longer view results for this exam.');
    }

    /**
     * Admit-card publication (owner order 2026-11-08): the admit
     * card joins results as a published document. The Principal
     * alone publishes/unpublishes, from the exam's Publication
     * page; the student/parent self-service reads the flag
     * server-side (AdmitCardController::myCard + the my-cards
     * list), ahead of the fee gate. Office (teamSA) generation
     * never reads it. Every flip is audit-logged.
     */
    public function publishAdmitCards($id)
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal publishes admit cards.');
        }
        $examId = Qs::resolveId($id);
        $this->exam->update($examId, ['admit_cards_published' => true]);
        $exam = $this->exam->find($examId);
        Audit::log('exams.admit_cards_published', null, 'Admit cards published for exam ' . $examId . ($exam ? ' (' . $exam->name . ')' : '') . ' by ' . Auth::user()->name);
        /* ONE-TIME GENERATION freeze (owner standing rule
           2026-11-08): publishing freezes the card every family
           will see — one snapshot per student in scope, built
           from live data at this moment. Later live edits cannot
           rewrite a released card; unpublishing keeps the
           snapshots hidden, re-publishing re-freezes. */
        if ($exam) {
            \App\Support\AdmitCardFreezer::freeze($exam, Auth::id());
        }
        return back()->with('flash_success', 'Admit cards published. Students and parents can now view and print admit cards for this exam (subject to the usual fee clearance).');
    }

    public function unpublishAdmitCards($id)
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal unpublishes admit cards.');
        }
        $examId = Qs::resolveId($id);
        $this->exam->update($examId, ['admit_cards_published' => false]);
        $exam = $this->exam->find($examId);
        Audit::log('exams.admit_cards_unpublished', null, 'Admit cards unpublished for exam ' . $examId . ($exam ? ' (' . $exam->name . ')' : '') . ' by ' . Auth::user()->name);
        return back()->with('flash_success', 'Admit cards unpublished. Students and parents can no longer view admit cards for this exam.');
    }

}
