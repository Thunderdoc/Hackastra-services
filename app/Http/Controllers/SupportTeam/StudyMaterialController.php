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

    public function index(Request $req)
    {
        $user = Auth::user();

        $query = StudyMaterial::with(['subject', 'my_class', 'section', 'teacher'])
            ->orderBy('my_class_id')->orderBy('subject_id')->latest();

        // Loop 82: the subject universe of the viewer's scope — the
        // grouped page renders an honest empty group for each of
        // these subjects that carries no materials.
        $scopeSubjects = collect();

        if (Qs::userIsTeacher()) {
            $query->where('teacher_id', $user->id);
            $scopeSubjects = Subject::where('teacher_id', $user->id)->orderBy('name')->get();
        } elseif (Qs::userIsTeamSA()) {
            // Admin + Super Admin read everything (SA read-only).
            // Their universe is the whole school — no empty groups.
        } elseif (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr) {
                return $this->deny();
            }
            $query->where('my_class_id', $sr->my_class_id)
                ->where(function ($q) use ($sr) {
                    $q->whereNull('section_id')->orWhere('section_id', $sr->section_id);
                });
            $scopeSubjects = Subject::where('my_class_id', $sr->my_class_id)->orderBy('name')->get();
        } elseif (Qs::userIsParent()) {
            $children = StudentRecord::where('my_parent_id', $user->id)->where('grad', 0)->get();
            if ($children->isEmpty()) {
                $d = [
                    'materials' => collect(),
                    'card_total' => 0, 'card_subjects' => 0, 'card_classes' => 0, 'card_recent' => 0,
                    'filter_subjects' => collect(), 'filter_classes' => collect(),
                    'scope_subjects' => collect(), 'filters_active' => false,
                ];
                return view('pages.support_team.materials.index', $d);
            }
            $query->whereIn('my_class_id', $children->pluck('my_class_id')->unique())
                ->where(function ($q) use ($children) {
                    $q->whereNull('section_id')
                        ->orWhereIn('section_id', $children->pluck('section_id')->unique());
                });
            $scopeSubjects = Subject::whereIn('my_class_id', $children->pluck('my_class_id')->unique())
                ->orderBy('name')->get();
        } else {
            return $this->deny();
        }

        // Summary cards describe the WHOLE scoped set — the filters
        // below narrow the listing, never the cards.
        $d['card_total'] = (clone $query)->count();
        $d['card_subjects'] = (clone $query)->distinct()->count('subject_id');
        $d['card_classes'] = (clone $query)->distinct()->count('my_class_id');
        $d['card_recent'] = (clone $query)->where('created_at', '>=', now()->subDays(7))->count();
        $d['filter_subjects'] = Subject::whereIn('id', (clone $query)->distinct()->pluck('subject_id'))
            ->orderBy('name')->get();
        $d['filter_classes'] = MyClass::whereIn('id', (clone $query)->distinct()->pluck('my_class_id'))
            ->orderBy('name')->get();
        $d['scope_subjects'] = $scopeSubjects;
        $d['filters_active'] = $req->filled('q') || $req->filled('subject_id')
            || $req->filled('class_id') || $req->filled('type');

        // Server-side filters (Loop 82) — they combine.
        if ($req->filled('q')) {
            $term = '%' . trim((string) $req->query('q')) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('subject', function ($s) use ($term) {
                        $s->where('name', 'like', $term);
                    });
            });
        }
        if ($req->filled('subject_id')) {
            $query->where('subject_id', (int) $req->query('subject_id'));
        }
        if ($req->filled('class_id')) {
            $query->where('my_class_id', (int) $req->query('class_id'));
        }
        if ($req->query('type') === 'document') {
            $query->whereNotNull('file_path');
        } elseif ($req->query('type') === 'link') {
            $query->whereNotNull('link_url');
        }
        switch ($req->query('sort')) {
            case 'oldest':
                $query->reorder('created_at', 'asc')->orderBy('id', 'asc');
                break;
            case 'title':
                $query->reorder('title', 'asc');
                break;
            case 'newest':
                $query->reorder('created_at', 'desc')->orderBy('id', 'desc');
                break;
            default:
                // default stays: class, subject, latest
                break;
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
            'files' => 'nullable|array|max:20',
            'files.*' => $this->fileRules,
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

        $uploaded = array_values(array_filter((array) $req->file('files')));
        if (empty($uploaded) && empty($data['link_url'])) {
            return back()->with(['pop_error' => 'Attach files or paste a link for the material.', 'flash_danger' => 'Attach files or paste a link for the material.']);
        }

        unset($data['files']);
        $data['teacher_id'] = Auth::id();

        // Owner ask 2026-10-25: many files in one upload. One material
        // row per file (the model carries a single file_path); the
        // first keeps the exact title, the rest take "Title (2)",
        // "Title (3)"… so students can tell them apart. A pasted link
        // rides on the first row. With no files, the single link-only
        // row behaves exactly as before.
        $created = [];
        if (!empty($uploaded)) {
            foreach ($uploaded as $i => $file) {
                $row = $data;
                if ($i > 0) {
                    $row['title'] = $data['title'] . ' (' . ($i + 1) . ')';
                    // The pasted link belongs to the set once, on the
                    // first row — not duplicated onto every file.
                    unset($row['link_url']);
                }
                $row['file_path'] = $file->store('materials', 'public');
                $created[] = StudyMaterial::create($row);
            }
        } else {
            $created[] = StudyMaterial::create($data);
        }

        $first = $created[0];
        Audit::log('material.created', $first, 'Study material "' . $first->title . '" for class ' . $first->my_class_id . ', subject ' . $first->subject_id . (count($created) > 1 ? ' (' . count($created) . ' files)' : ''));

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
