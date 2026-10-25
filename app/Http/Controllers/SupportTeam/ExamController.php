<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Exam\ExamCreate;
use App\Http\Requests\Exam\ExamUpdate;
use App\Repositories\ExamRepo;
use App\Support\Notify;
use App\Http\Controllers\Controller;

class ExamController extends Controller
{
    protected $exam;
    public function __construct(ExamRepo $exam)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);

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
    public function publishResults($id)
    {
        $examId = Qs::resolveId($id);
        $this->exam->update($examId, ['results_published' => true]);
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
        $this->exam->update(Qs::resolveId($id), ['results_published' => false]);
        return back()->with('flash_success', 'Results unpublished. Students and parents can no longer view results for this exam.');
    }

}
