<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Section\SectionCreate;
use App\Http\Requests\Section\SectionUpdate;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepo;

class SectionController extends Controller
{
    protected $my_class, $user;

    public function __construct(MyClassRepo $my_class, UserRepo $user)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);

        $this->my_class = $my_class;
        $this->user = $user;
    }

    public function index()
    {
        $d['my_classes'] = $this->my_class->all();
        $d['teachers'] = $this->user->getUserByType('teacher');

        // Batch 4: one class at a time via ?class_id= (unknown/absent
        // id falls back to the first class); sections are filtered
        // server-side instead of rendering every class's table.
        $d['selected_class'] = request('class_id')
            ? $d['my_classes']->firstWhere('id', (int) request('class_id')) ?? $d['my_classes']->first()
            : $d['my_classes']->first();
        $d['sections'] = $d['selected_class']
            ? $this->my_class->getClassSections($d['selected_class']->id)
            : collect();

        return view('pages.support_team.sections.index', $d);
    }

    public function store(SectionCreate $req)
    {
        $data = $req->all();
        $this->my_class->createSection($data);

        return Qs::jsonStoreOk();
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids.
        $d['s'] = $s = $this->my_class->findSection(Qs::resolveId($id));
        $d['teachers'] = $this->user->getUserByType('teacher');

        return is_null($s) ? Qs::goWithDanger('sections.index') :view('pages.support_team.sections.edit', $d);
    }

    public function update(SectionUpdate $req, $id)
    {
        $data = $req->only(['name', 'teacher_id']);
        $this->my_class->updateSection(Qs::resolveId($id), $data);

        return Qs::jsonUpdateOk();
    }

    public function destroy($id)
    {
        $id = Qs::resolveId($id);
        if($this->my_class->isActiveSection($id)){
            return back()->with('pop_warning', 'Every class must have a default section, You Cannot Delete It');
        }

        $this->my_class->deleteSection($id);
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
        return redirect()->route('sections.index');
    }

}
