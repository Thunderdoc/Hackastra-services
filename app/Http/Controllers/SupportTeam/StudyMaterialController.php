<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\StudyMaterial;
use App\Models\Subject;
use App\Support\Audit;
use App\Support\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * LMS — Study materials (Workstream CS1, 2026-10-22).
 *
 * Same access model as assignments: teachers publish for the classes
 * and subjects they teach; students see their class (+ section)
 * materials; parents see their children's; Admin reads all and may
 * moderate-delete; Super Admin is read-only (publishing is a school
 * write, never a platform one).
 */
class StudyMaterialController extends Controller
{
    protected $fileRules = 'file|mimes:pdf,doc,docx,zip,txt,jpg,jpeg,png,gif,webp|max:10240';

    public function index()
    {
        $user = Auth::user();

        $query = StudyMaterial::with(['subject', 'my_class', 'section', 'teacher'])
            ->orderBy('my_class_id')->orderBy('subject_id')->latest();

        if (Qs::userIsTeacher()) {
            $query->where('teacher_id', $user->id);
        } elseif (Qs::userIsTeamSA()) {
            // Admin + Super Admin read everything (SA read-only).
        } elseif (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr) {
                return $this->deny();
            }
            $query->where('my_class_id', $sr->my_class_id)
                ->where(function ($q) use ($sr) {
                    $q->whereNull('section_id')->orWhere('section_id', $sr->section_id);
                });
        } elseif (Qs::userIsParent()) {
            $children = StudentRecord::where('my_parent_id', $user->id)->where('grad', 0)->get();
            if ($children->isEmpty()) {
                $d['materials'] = collect();
                return view('pages.support_team.materials.index', $d);
            }
            $query->whereIn('my_class_id', $children->pluck('my_class_id')->unique())
                ->where(function ($q) use ($children) {
                    $q->whereNull('section_id')
                        ->orWhereIn('section_id', $children->pluck('section_id')->unique());
                });
        } else {
            return $this->deny();
        }

        $d['materials'] = $query->get();

        return view('pages.support_team.materials.index', $d);
    }

    public function create()
    {
        if (!Qs::userIsTeacher()) {
            return $this->deny();
        }

        $subjects = Subject::with('my_class')->where('teacher_id', Auth::id())->get();
        $classIds = $subjects->pluck('my_class_id')->unique();

        $d = [
            'subjects' => $subjects,
            'classes' => MyClass::whereIn('id', $classIds)->get(),
            'sections' => Section::whereIn('my_class_id', $classIds)->get(),
        ];

        return view('pages.support_team.materials.create', $d);
    }

    public function store(Request $req)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }

        $data = $req->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'my_class_id' => 'required|integer|exists:my_classes,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'subject_id' => 'required|integer|exists:subjects,id',
            'link_url' => 'nullable|url|max:500',
            'file' => 'nullable|' . $this->fileRules,
        ]);

        $subject = Subject::where('id', $data['subject_id'])
            ->where('teacher_id', Auth::id())->first();
        if (!$subject || $subject->my_class_id != $data['my_class_id']) {
            abort(403, 'You do not teach this subject for this class.');
        }
        if (!empty($data['section_id'])
            && !Section::where('id', $data['section_id'])->where('my_class_id', $data['my_class_id'])->exists()) {
            abort(422, 'The chosen section does not belong to this class.');
        }

        if (!$req->hasFile('file') && empty($data['link_url'])) {
            return back()->with(['pop_error' => 'Attach a file or paste a link for the material.', 'flash_danger' => 'Attach a file or paste a link for the material.']);
        }

        unset($data['file']);
        $data['teacher_id'] = Auth::id();
        if ($req->hasFile('file')) {
            $data['file_path'] = $req->file('file')->store('materials', 'public');
        }

        $material = StudyMaterial::create($data);

        Audit::log('material.created', $material, 'Study material "' . $material->title . '" for class ' . $material->my_class_id . ', subject ' . $material->subject_id);

        return redirect()->route('materials.index')->with(['pop_success' => __('msg.store_ok'), 'flash_success' => __('msg.store_ok')]);
    }

    /** Teacher deletes own; Admin may moderate-delete any. SA never. */
    public function destroy($material_id)
    {
        $material = StudyMaterial::findOrFail($material_id);
        $user = Auth::user();

        $ownTeacher = Qs::userIsTeacher() && $material->teacher_id == $user->id;
        if (!$ownTeacher && !Qs::userIsAdmin()) {
            abort(403, __('msg.denied'));
        }

        if ($material->file_path) {
            Storage::disk('public')->delete($material->file_path);
        }
        $title = $material->title;
        $material->delete();

        Audit::log('material.deleted', null, 'Study material "' . $title . '" deleted by ' . $user->name);

        return redirect()->route('materials.index')->with(['pop_success' => __('msg.del_ok'), 'flash_success' => __('msg.del_ok')]);
    }

    /**
     * Authorized download of a material's file (public disk, but the
     * download still goes through the scope check so a link alone is
     * never enough for an out-of-scope user).
     */
    public function download($material_id)
    {
        $material = StudyMaterial::find($material_id);
        $user = Auth::user();

        if (!$material || !$material->file_path) {
            return back()->with(['pop_error' => __('msg.rnf'), 'flash_danger' => __('msg.rnf')]);
        }

        $allowed = Qs::userIsTeamSA()
            || $material->teacher_id == $user->id
            || (Qs::userIsTeacher() && TeacherScope::canTeachClass($user, $material->my_class_id));

        if (!$allowed && Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            $allowed = $sr && $material->my_class_id == $sr->my_class_id
                && ($material->section_id === null || $material->section_id == $sr->section_id);
        }

        if (!$allowed && Qs::userIsParent()) {
            $allowed = StudentRecord::where('my_parent_id', $user->id)->where('grad', 0)->get()
                ->contains(function ($child) use ($material) {
                    return $material->my_class_id == $child->my_class_id
                        && ($material->section_id === null || $material->section_id == $child->section_id);
                });
        }

        if (!$allowed) {
            abort(403, __('msg.denied'));
        }

        if (!Storage::disk('public')->exists($material->file_path)) {
            return back()->with(['pop_error' => __('msg.rnf'), 'flash_danger' => __('msg.rnf')]);
        }

        return Storage::disk('public')->download($material->file_path);
    }

    protected function deny()
    {
        return redirect()->route('dashboard')->with(['pop_error' => __('msg.denied'), 'flash_danger' => __('msg.denied')]);
    }
}
