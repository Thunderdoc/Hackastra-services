<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dorm\DormCreate;
use App\Http\Requests\Dorm\DormUpdate;
use App\Repositories\DormRepo;

class DormController extends Controller
{
    protected  $dorm;

    public function __construct(DormRepo $dorm)
    {
        // Workstream CM: reads (index/show) stay teamSA — SA keeps
        // oversight; every write goes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);

        $this->dorm = $dorm;
    }

    public function index()
    {
        $d['dorms'] = $this->dorm->getAll();
        return view('pages.support_team.dorms.index', $d);
    }

    public function store(DormCreate $req)
    {
        $data = $req->only(['name', 'description']);
        $this->dorm->create($data);

        // Loop 112: post-save lands on the dorms index (owner ruling).
        return Qs::storeOk('dorms.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids.
        $d['dorm'] = $dorm = $this->dorm->find(Qs::resolveId($id));

        return !is_null($dorm) ? view('pages.support_team.dorms.edit', $d)
            : Qs::goWithDanger('dorms.index');
    }

    public function update(DormUpdate $req, $id)
    {
        $data = $req->only(['name', 'description']);
        $this->dorm->update(Qs::resolveId($id), $data);

        // Loop 112: post-save lands on the dorms index (owner ruling).
        return Qs::updateOk('dorms.index');
    }

    public function destroy($id)
    {
        $this->dorm->find(Qs::resolveId($id))->delete();
        return back()->with('flash_success', __('msg.delete_ok'));
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
        return redirect()->route('dorms.index');
    }

}
