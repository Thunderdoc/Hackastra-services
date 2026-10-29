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
        $this->middleware('teamSAWrite', ['except' => ['index', 'show', 'publishResults', 'unpublishResults', 'publication', 'notifyPending']]);
        $this->middleware('teamOrPrincipal:SAWrite', ['only' => ['publishResults', 'unpublishResults', 'notifyPending']]);

        $this->exam = $exam;
    }

    public function index()
    {
        $d['exams'] = $this->exam->all();
        $d['my_classes'] = \App\Models\MyClass::orderBy('id')->get();
        return view('pages.support_team.exams.index', $d);
    }

    public function store(ExamCreate $req)
    {
        $data = $req->only(['name', 'term', 'my_class_id']);
        $data['year'] = Qs::getSetting('current_session');
        // Class scope (owner build 2026-10-25): 0 = every class
        // (the historical global exam); anything else = one class.
        $data['my_class_id'] = !empty($data['my_class_id']) ? (int) $data['my_class_id'] : 0;

        if ($data['my_class_id'] !== 0 && !\App\Models\MyClass::find($data['my_class_id'])) {
            return back()->with('flash_danger', 'Unknown class.')->withInput();
        }

        // Partition rule: for one (term, session), a class is
        // covered by at most one exam — a global exam blocks class
        // exams for that term and vice versa. The DB unique
        // (term, year, my_class_id) is the backstop; this refusal
        // is the polite message instead of an exception.
        if ($conflict = $this->examScopeConflict($data['term'], $data['year'], $data['my_class_id'])) {
            return back()->with('flash_danger', $conflict)->withInput();
        }

        $this->exam->create($data);
        // Loop 112: post-save lands on the exams index (owner ruling).
        return Qs::storeOk('exams.index');
    }

    /**
     * The partition refusal for one (term, session, scope): null
     * when the exam may be created, else the message. A NULL
     * my_class_id (pre-migration rows) counts as global.
     */
    protected function examScopeConflict($term, $year, $scope): ?string
    {
        $termExams = $this->exam->getExam(['term' => $term, 'year' => $year]);
        if ($scope === 0) {
            if ($termExams->isNotEmpty()) {
                return 'An exam already exists for this term in the current session ('.$year.'). A global exam covers every class — edit the existing exam instead of adding another.';
            }
            return null;
        }
        $global = $termExams->filter(function ($e) { return empty($e->my_class_id); });
        if ($global->isNotEmpty()) {
            return 'A global exam ('.$global->first()->name.') already covers this term for every class — a class exam cannot be added for the same term.';
        }
        $same = $termExams->filter(function ($e) use ($scope) { return (int) $e->my_class_id === $scope; });
        if ($same->isNotEmpty()) {
            return 'An exam for this class and term already exists in the current session ('.$year.'). Edit the existing exam instead.';
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

        return is_null($ex) ? Qs::goWithDanger('exams.index') : view('pages.support_team.exams.edit', $d);
    }

    public function update(ExamUpdate $req, $id)
    {
        $data = $req->only(['name', 'term']);

        $this->exam->update(Qs::resolveId($id), $data);
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

}
