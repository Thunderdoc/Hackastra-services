<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\Staffing;
use App\Http\Requests\Subject\SubjectCreate;
use App\Http\Requests\Subject\SubjectUpdate;
use App\Repositories\MyClassRepo;
use App\Repositories\UserRepo;
use App\Http\Controllers\Controller;

class SubjectController extends Controller
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

        // Batch 4: the index shows ONE class at a time — ?class_id=
        // picks it (unknown/absent id falls back to the first class,
        // the same behaviour as the personal-timetable child switcher).
        // Subjects are filtered server-side; the page no longer renders
        // every class's table.
        $d['selected_class'] = request('class_id')
            ? $d['my_classes']->firstWhere('id', (int) request('class_id')) ?? $d['my_classes']->first()
            : $d['my_classes']->first();
        $d['subjects'] = $d['selected_class']
            ? $this->my_class->findSubjectByClass($d['selected_class']->id)
            : collect();

        return view('pages.support_team.subjects.index', $d);
    }


    /**
     * Slice C parity rule: a manual teacher assignment must pass
     * the same eligibility check the allocation engine uses — but
     * only once the school has started recording eligibility for
     * that subject name (a non-empty pool). With an empty pool the
     * assignment stays free (adoption path) and the staffing
     * dashboard flags the subject as having no eligible teacher
     * recorded; the pool can never be bypassed once it exists.
     */
    protected function eligibilityRefusal($teacherId, $subjectName)
    {
        if (!$teacherId || !$subjectName) {
            return null;
        }
        $pool = Staffing::eligibleTeacherIds($subjectName);
        if (!empty($pool) && !in_array((int) $teacherId, $pool, true)) {
            $teacher = \App\User::find($teacherId);
            return back()->with('flash_danger', ($teacher ? $teacher->name : 'That teacher')
                . ' is not eligible to teach ' . $subjectName
                . ' — record their eligibility first (Teacher Eligibility page).')->withInput();
        }

        return null;
    }

    public function store(SubjectCreate $req)
    {
        $data = $req->all();
        // Stream tags post as checkboxes; the column stores CSV.
        $data['stream'] = implode(',', array_filter((array) $req->input('stream_tags', []))) ?: null;
        // Slice A: an unchecked "needs double period" box posts
        // nothing — normalize it so create/update mean the same.
        $data['needs_double'] = $req->boolean('needs_double');
        if ($refusal = $this->eligibilityRefusal(Qs::resolveId($data['teacher_id'] ?? null), $data['name'] ?? null)) {
            return $refusal;
        }
        $this->my_class->createSubject($data);

        // Loop 112: post-save lands on the subjects index (owner ruling).
        return Qs::storeOk('subjects.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids.
        $d['s'] = $sub = $this->my_class->findSubject(Qs::resolveId($id));
        $d['my_classes'] = $this->my_class->all();
        $d['teachers'] = $this->user->getUserByType('teacher');

        return is_null($sub) ? Qs::goWithDanger('subjects.index') : view('pages.support_team.subjects.edit', $d);
    }

    public function update(SubjectUpdate $req, $id)
    {
        $data = $req->all();
        $data['stream'] = implode(',', array_filter((array) $req->input('stream_tags', []))) ?: null;
        $data['needs_double'] = $req->boolean('needs_double');
        if (!empty($data['teacher_id'])) {
            $existing = $this->my_class->findSubject(Qs::resolveId($id));
            $name = $data['name'] ?? ($existing ? $existing->name : null);
            if ($refusal = $this->eligibilityRefusal(Qs::resolveId($data['teacher_id']), $name)) {
                return $refusal;
            }
        }
        $this->my_class->updateSubject(Qs::resolveId($id), $data);

        // Loop 112: post-save lands on the subjects index (owner ruling).
        return Qs::updateOk('subjects.index');
    }

    public function destroy($id)
    {
        $this->my_class->deleteSubject(Qs::resolveId($id));
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
        return redirect()->route('subjects.index');
    }

}
