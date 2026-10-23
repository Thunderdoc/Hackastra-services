<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Grade\GradeCreate;
use App\Http\Requests\Grade\GradeUpdate;
use App\Repositories\ExamRepo;
use App\Http\Controllers\Controller;
use App\Repositories\MyClassRepo;

class GradeController extends Controller
{
    protected $exam, $my_class;

    public function __construct(ExamRepo $exam, MyClassRepo $my_class)
    {
        $this->exam = $exam;
        $this->my_class = $my_class;

        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);
    }

    public function index()
    {
         $d['grades'] = $this->exam->allGrades();
         $d['class_types'] = $this->my_class->getTypes();
        return view('pages.support_team.grades.index', $d);
    }

    public function store(GradeCreate $req)
    {
        $data = $req->all();

        $this->exam->createGrade($data);
        // Loop 112: post-save lands on the grades index (owner ruling).
        return Qs::storeOk('grades.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids (the
        // un-resolved hash missed the lookup and the view 500'd on
        // null); a missing grade lands on the index like siblings.
        $d['class_types'] = $this->my_class->getTypes();
        $d['gr'] = $gr = $this->exam->findGrade(Qs::resolveId($id));

        return is_null($gr) ? Qs::goWithDanger('grades.index') : view('pages.support_team.grades.edit', $d);
    }

    public function update(GradeUpdate $req, $id)
    {
        $data = $req->all();

        $this->exam->updateGrade(Qs::resolveId($id), $data);
        // Loop 112: post-save lands on the grades index (owner ruling) —
        // was back() to the edit form.
        return Qs::updateOk('grades.index');
    }

    public function destroy($id)
    {
        $this->exam->deleteGrade(Qs::resolveId($id));
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
        return redirect()->route('grades.index');
    }

}
