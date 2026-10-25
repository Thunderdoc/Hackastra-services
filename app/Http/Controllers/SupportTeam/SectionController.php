<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\Section\SectionCreate;
use App\Http\Requests\Section\SectionUpdate;
use App\Models\Subject;
use App\Repositories\MyClassRepo;
use App\Http\Controllers\Controller;
use App\Repositories\UserRepo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Loop 112: post-save lands on the sections index (owner ruling).
        return Qs::storeOk('sections.index');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids.
        $d['s'] = $s = $this->my_class->findSection(Qs::resolveId($id));
        $d['teachers'] = $this->user->getUserByType('teacher');
        // Section build (2026-10-25): the class's subjects plus this
        // section's teacher overrides, for the matrix below the form.
        if ($s) {
            $d['subjects'] = Subject::where('my_class_id', $s->my_class_id)->orderBy('id')->get();
            $d['overrides'] = DB::table('section_subject_teachers')
                ->where('section_id', $s->id)->pluck('teacher_id', 'subject_id');
        }

        return is_null($s) ? Qs::goWithDanger('sections.index') :view('pages.support_team.sections.edit', $d);
    }

    public function update(SectionUpdate $req, $id)
    {
        $data = $req->only(['name', 'teacher_id']);
        $this->my_class->updateSection(Qs::resolveId($id), $data);

        // Loop 112: post-save lands on the sections index (owner ruling).
        return Qs::updateOk('sections.index');
    }

    /**
     * Section build (2026-10-25): per-section subject teachers.
     * Blank = no override (the subject's class teacher teaches this
     * section); a chosen teacher teaches that subject in THIS
     * section only. Only the section's own class subjects are
     * honoured, so foreign keys in the request are ignored.
     */
    public function updateTeachers(Request $req, $id)
    {
        $section = $this->my_class->findSection(Qs::resolveId($id));
        if (!$section) {
            return Qs::goWithDanger('sections.index');
        }

        $input = (array) $req->input('teachers', []);
        $subjects = Subject::where('my_class_id', $section->my_class_id)->get();
        foreach ($subjects as $subject) {
            $raw = $input[$subject->id] ?? '';
            if ($raw === '' || $raw === null) {
                DB::table('section_subject_teachers')
                    ->where('section_id', $section->id)
                    ->where('subject_id', $subject->id)->delete();
                continue;
            }
            $teacherId = Qs::decodeHash($raw);
            $teacher = $teacherId ? $this->user->find($teacherId) : null;
            if (!$teacher || $teacher->user_type !== 'teacher') {
                continue; // forged or non-teacher value: ignore, never store
            }
            $exists = DB::table('section_subject_teachers')
                ->where('section_id', $section->id)
                ->where('subject_id', $subject->id)->exists();
            if ($exists) {
                DB::table('section_subject_teachers')
                    ->where('section_id', $section->id)
                    ->where('subject_id', $subject->id)
                    ->update(['teacher_id' => $teacher->id, 'updated_at' => now()]);
            } else {
                DB::table('section_subject_teachers')->insert([
                    'section_id' => $section->id, 'subject_id' => $subject->id,
                    'teacher_id' => $teacher->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return redirect()->route('sections.index')
            ->with('flash_success', 'Subject teachers saved for ' . $section->name . '.');
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
