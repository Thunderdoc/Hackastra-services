<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\StudentDocument;
use App\Models\StudentRecord;
use App\Support\RolePermission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Student Documents Vault (2026-10-28).
 *
 * Upload is ADMIN-ONLY: teachers, students and parents get 403 on
 * create/store. Destroy is admin OR a holder of the
 * documents.destroy custom grant (grant add-on 2026-10-28, see
 * App\Support\RolePermission). Listing and download are scoped:
 * admin sees/downloads everything, a student sees/downloads only
 * their own record's documents, a parent sees/downloads only their
 * own children's documents (StudentRecord.my_parent_id). Everyone
 * else — including teachers — gets 403 on every action here.
 *
 * Files: pdf, doc, docx, jpg, jpeg, png only, max 10 MB, validated
 * server-side by extension AND detected mime type (never the
 * extension alone). Stored on the public disk under documents/ with
 * a random stored name; downloads go through this controller, never
 * a raw /storage URL.
 */
class StudentDocumentController extends Controller
{
    protected $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    protected $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip', // docx files are zip containers; mime guessers report zip
        'image/jpeg',
        'image/png',
    ];

    /** True when the viewer may see/download this document's record. */
    protected function canAccess(StudentRecord $sr, $user): bool
    {
        if (Qs::userIsAdmin()) {
            return true;
        }
        if (Qs::userIsStudent()) {
            return (int) $sr->user_id === (int) $user->id;
        }
        if (Qs::userIsParent()) {
            return (int) $sr->my_parent_id === (int) $user->id;
        }
        return false;
    }

    /** Scoped list query for the current viewer; null = no access. */
    protected function scopedQuery($user)
    {
        if (Qs::userIsAdmin()) {
            return StudentDocument::with(['studentRecord.user']);
        }
        if (Qs::userIsStudent()) {
            $sr = Qs::getSRByUserID($user->id);
            return $sr
                ? StudentDocument::with(['studentRecord.user'])->where('student_record_id', $sr->id)
                : StudentDocument::with(['studentRecord.user'])->whereRaw('1 = 0');
        }
        if (Qs::userIsParent()) {
            $childIds = StudentRecord::where('my_parent_id', $user->id)->pluck('id');
            return StudentDocument::with(['studentRecord.user'])->whereIn('student_record_id', $childIds);
        }
        return null;
    }

    public function index()
    {
        $query = $this->scopedQuery(Auth::user());
        if ($query === null) {
            abort(403, __('msg.denied'));
        }
        return view('pages.support_team.documents.index', [
            'documents' => $query->orderByDesc('id')->paginate(25),
            'is_admin' => Qs::userIsAdmin(),
        ]);
    }

    public function create()
    {
        if (!Qs::userIsAdmin()) {
            abort(403, __('msg.denied'));
        }
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)->orderBy('id')->get();
        return view('pages.support_team.documents.create', ['students' => $students]);
    }

    public function store(Request $request)
    {
        if (!Qs::userIsAdmin()) {
            abort(403, __('msg.denied'));
        }

        $data = $request->validate([
            'student_record_id' => 'required|integer|exists:student_records,id',
            'title' => 'required|string|max:255',
            'document' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $file = $request->file('document');
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $file->getMimeType();
        // Do not trust the extension alone: the detected mime must
        // also be one of the allowed types for that family.
        if (!in_array($ext, $this->allowedExtensions, true)
            || !in_array($mime, $this->allowedMimes, true)) {
            return back()->withInput()->withErrors([
                'document' => 'File type not allowed. Upload a PDF, Word document or image (jpg/png) up to 10 MB.',
            ]);
        }

        $storedName = Str::random(40) . '.' . $ext;
        $storedPath = 'documents/' . $storedName;
        Storage::disk('public')->putFileAs('documents', $file, $storedName);

        StudentDocument::create([
            'student_record_id' => $data['student_record_id'],
            'uploaded_by' => Auth::id(),
            'title' => $data['title'],
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
        ]);

        return redirect()->route('documents.index')
            ->with(['flash_success' => 'Document uploaded.']);
    }

    public function download(StudentDocument $document)
    {
        $sr = $document->studentRecord;
        if (!$sr || !$this->canAccess($sr, Auth::user())) {
            abort(403, __('msg.denied'));
        }
        // Defence in depth: only paths we minted under documents/.
        if (!Str::startsWith($document->stored_path, 'documents/')
            || !Storage::disk('public')->exists($document->stored_path)) {
            abort(404);
        }
        return Storage::disk('public')->download($document->stored_path, $document->original_name);
    }

    public function destroy(StudentDocument $document)
    {
        // Grant add-on (2026-10-28): admin OR grant — base rule unchanged.
        if (!Qs::userIsAdmin() && !RolePermission::allows(Auth::user(), 'documents.destroy')) {
            abort(403, __('msg.denied'));
        }

        $path = $document->stored_path;
        $fileExisted = Str::startsWith($path, 'documents/')
            && Storage::disk('public')->exists($path);
        $fileDeleted = true;
        if ($fileExisted) {
            $fileDeleted = Storage::disk('public')->delete($path);
        }
        $document->delete();

        // Both failure directions are reported, never silently
        // swallowed: a failed file delete leaves an orphan file
        // (row gone), a missing file just means the row was the
        // only remaining trace.
        if ($fileExisted && !$fileDeleted) {
            Log::warning('StudentDocument destroy: file delete failed but row deleted', [
                'stored_path' => $path, 'deleted_by' => Auth::id(),
            ]);
            return redirect()->route('documents.index')->with([
                'flash_success' => 'Document record deleted, but its file could not be removed from storage — please check storage manually.',
            ]);
        }
        return redirect()->route('documents.index')
            ->with(['flash_success' => 'Document deleted.']);
    }
}
