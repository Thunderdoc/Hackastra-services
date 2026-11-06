<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\MyClass\ClassCreate;
use App\Http\Requests\MyClass\ClassUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;

class MyClassController extends Controller
{
    protected $my_class, $user;

    public function __construct(MyClassRepo $my_class, UserRepo $user)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        // Limited principal role (owner order 2026-10-28): reads
        // are also open to the principal (school-wide monitoring).
        $this->middleware('teamOrPrincipal:SA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);

        $this->my_class = $my_class;
        $this->user = $user;
    }

    public function index()
    {
        $d['my_classes'] = $this->my_class->all();

        // Item 21: the table carries Sections and Students counts —
        // one aggregate query keyed by class id, not per-row lookups.
        $counts = \App\Models\MyClass::withCount([
            'section',
            'student_record' => function ($q) { $q->where('grad', 0); },
        ])->get()->keyBy('id');
        foreach ($d['my_classes'] as $c) {
            $row = $counts->get($c->id);
            $c->section_count = $row ? $row->section_count : 0;
            $c->student_record_count = $row ? $row->student_record_count : 0;
        }
        $d['class_types'] = $this->my_class->getTypes();

        return view('pages.support_team.classes.index', $d);
    }

    public function store(ClassCreate $req)
    {
        $data = $req->all();
        $mc = $this->my_class->create($data);

        // Create Default Section
        $s =['my_class_id' => $mc->id,
            'name' => 'A',
            'active' => 1,
            'teacher_id' => NULL,
        ];

        $this->my_class->createSection($s);

        // Loop 112: post-save lands on the classes index (owner ruling) —
        // was Qs::jsonStoreOk() (AJAX stay-on-form).
        return Qs::storeOk('classes.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids — the
        // un-resolved hash silently fell back to the index.
        $d['c'] = $c = $this->my_class->find(Qs::resolveId($id));

        return is_null($c) ? Qs::goWithDanger('classes.index') : view('pages.support_team.classes.edit', $d) ;
    }

    public function update(ClassUpdate $req, $id)
    {
        $data = $req->only(['name']);
        $this->my_class->update(Qs::resolveId($id), $data);

        // Loop 112: post-save lands on the classes index (owner ruling) —
        // was Qs::jsonUpdateOk() (AJAX stay-on-form).
        return Qs::updateOk('classes.index');
    }

    public function destroy($id)
    {
        $this->my_class->delete(Qs::resolveId($id));
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
        return redirect()->route('classes.index');
    }

}
