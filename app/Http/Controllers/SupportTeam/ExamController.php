<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Exam\ExamCreate;
use App\Http\Requests\Exam\ExamUpdate;
use App\Repositories\ExamRepo;
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
        return view('pages.support_team.exams.index', $d);
    }

    public function store(ExamCreate $req)
    {
        $data = $req->only(['name', 'term']);
        $data['year'] = Qs::getSetting('current_session');

        // Integration fix: (term, year) is unique — a duplicate used to
        // surface as an unhandled database exception. Refuse politely.
        if ($this->exam->getExam(['term' => $data['term'], 'year' => $data['year']])->isNotEmpty()) {
            return back()->with('flash_danger', 'An exam for this term already exists in the current session ('.$data['year'].'). Edit the existing exam instead.');
        }

        $this->exam->create($data);
        return back()->with('flash_success', __('msg.store_ok'));
    }

    public function edit($id)
    {
        $d['ex'] = $this->exam->find($id);
        return view('pages.support_team.exams.edit', $d);
    }

    public function update(ExamUpdate $req, $id)
    {
        $data = $req->only(['name', 'term']);

        $this->exam->update($id, $data);
        return back()->with('flash_success', __('msg.update_ok'));
    }

    public function destroy($id)
    {
        $this->exam->delete($id);
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
        $this->exam->update($id, ['results_published' => true]);
        return back()->with('flash_success', 'Results published. Students and parents can now view results for this exam.');
    }

    public function unpublishResults($id)
    {
        $this->exam->update($id, ['results_published' => false]);
        return back()->with('flash_success', 'Results unpublished. Students and parents can no longer view results for this exam.');
    }

}
