<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\CircularSave;
use App\Models\Circular;
use App\Models\CircularAttachment;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Circulars / notices (research gap: the app had no communication layer).
 *
 * One index serves both sides of the module:
 *  - teamSA (admin / super_admin) get the management table with every
 *    circular — drafts, scheduled and expired included — plus actions.
 *  - every other role gets a read-only feed of the circulars that are
 *    published, inside their display window, and addressed to them.
 *
 * Mutating actions (deletion included) are gated by the teamSAWrite
 * middleware below (Workstream CM), mirroring SchoolEventController:
 * the school Admin runs the school day to day, the Super Admin views
 * but never writes school records.
 * Circulars live in-app only — no mail/SMS is sent.
 */
class CircularController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSAWrite', ['except' => ['index', 'show', 'print', 'pdf', 'downloadAttachment']]);
        $this->middleware('teamSAWrite', ['only' => ['destroy']]);
    }

    public function index()
    {
        // Loop 75 (circulars list redesign, owner addendum 2026-10-22):
        // one browsable list design for both sides of the module.
        // Audience scoping is unchanged and applied FIRST: managers
        // (teamSA) see every circular; every other role sees only
        // published circulars addressed to them (drafts never leak).
        // The summary chips count the viewer's whole visible set;
        // the filters below only narrow that same set and persist
        // across pagination.
        $isManager = Qs::userIsTeamSA();
        $userType = Auth::user()->user_type;
        $today = now()->toDateString();

        $base = Circular::with(['publisher', 'attachments']);
        if (!$isManager) {
            $base->where('is_published', true)->forAudience($userType);
        }

        $window = function ($q) use ($today) {
            $q->where(function ($w) use ($today) {
                $w->whereNull('starts_at')->orWhere('starts_at', '<=', $today);
            })->where(function ($w) use ($today) {
                $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today);
            });
        };

        $d['counts'] = [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('is_published', true)->tap($window)->count(),
            'upcoming' => (clone $base)->where('is_published', true)->where('starts_at', '>', $today)->count(),
            'expired' => (clone $base)->where('is_published', true)->where('expires_at', '<', $today)->count(),
        ];

        $filters = array_merge(
            ['q' => '', 'audience' => '', 'priority' => '', 'status' => '', 'from' => '', 'to' => ''],
            request()->only(['q', 'audience', 'priority', 'status', 'from', 'to'])
        );

        $query = clone $base;
        if ($filters['q'] !== '') {
            $term = $filters['q'];
            $query->where(function ($w) use ($term) {
                $w->where('title', 'like', '%' . $term . '%')
                    ->orWhere('body', 'like', '%' . $term . '%')
                    ->orWhereHas('publisher', function ($p) use ($term) {
                        $p->where('name', 'like', '%' . $term . '%');
                    });
            });
        }
        if ($filters['audience'] !== '') {
            // For readers this can only narrow inside the audiences
            // the base query already scoped them to.
            $query->where('audience', $filters['audience']);
        }
        if ($filters['priority'] !== '') {
            $query->where('priority', $filters['priority']);
        }
        if ($filters['status'] === 'active') {
            $query->where('is_published', true)->tap($window);
        } elseif ($filters['status'] === 'upcoming') {
            $query->where('is_published', true)->where('starts_at', '>', $today);
        } elseif ($filters['status'] === 'expired') {
            $query->where('is_published', true)->where('expires_at', '<', $today);
        } elseif ($filters['status'] === 'draft' && $isManager) {
            $query->where('is_published', false);
        }
        if ($filters['from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if ($filters['to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $d['circulars'] = $query->latest()->paginate($isManager ? 15 : 10)->appends(request()->query());
        $d['filters'] = $filters;
        $d['is_manager'] = $isManager;
        $d['can_write'] = Qs::userIsTeamSAWrite();
        $d['priorities'] = Circular::priorities();
        $d['audiences'] = $isManager
            ? Circular::audiences()
            : array_intersect_key(Circular::audiences(), array_flip(Circular::audiencesFor($userType)));

        return view($isManager ? 'pages.support_team.circulars.index' : 'pages.support_team.circulars.feed', $d);
    }

    public function create()
    {
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.create', $d);
    }

    public function store(CircularSave $request)
    {
        $data = $request->validated();
        unset($data['attachments'], $data['remove_attachments']);
        $data['is_published'] = $request->boolean('is_published');
        $data['published_by'] = $request->user()->id;

        $circular = Circular::create($data);
        $this->storeAttachments($request, $circular);

        if ($circular->is_published) {
            Audit::log('circular.published', $circular, 'Published circular "' . $circular->title . '" to ' . $circular->audience);
        }

        return redirect()->route('circulars.index')->with('flash_success', 'Circular created.');
    }

    public function show(Circular $circular)
    {
        if (!Qs::userIsTeamSA() && !$circular->isVisibleTo(Auth::user()->user_type)) {
            abort(404);
        }

        $d['circular'] = $circular->load(['publisher', 'attachments']);
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.show', $d);
    }

    /**
     * Printable document for one circular (Loop 75 detail addendum):
     * same audience gate as show() — managers see everything, other
     * roles only circulars currently visible to them.
     */
    public function print(Circular $circular)
    {
        if (!Qs::userIsTeamSA() && !$circular->isVisibleTo(Auth::user()->user_type)) {
            abort(404);
        }

        $d['circular'] = $circular->load(['publisher', 'attachments']);
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.print', $d);
    }

    /** The same document as a PDF download (dompdf is in the tree). */
    public function pdf(Circular $circular)
    {
        if (!Qs::userIsTeamSA() && !$circular->isVisibleTo(Auth::user()->user_type)) {
            abort(404);
        }

        $d['circular'] = $circular->load(['publisher', 'attachments']);
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        // dompdf cannot fetch the Settings asset URLs (the same
        // limitation the annual report PDF works around in
        // MarkController), so the PDF render gets the letterhead,
        // signature and seal as data URIs; the browser print view
        // keeps the plain URLs. Both render the one _document
        // partial, so the documents cannot diverge.
        $d['doc_logo'] = $this->pdfImageDataUri(Qs::getSetting('logo'));
        $d['doc_signature'] = $this->pdfImageDataUri(Qs::getSetting('principal_signature'));
        $d['doc_stamp'] = $this->pdfImageDataUri(Qs::getSetting('school_stamp'));

        return Pdf::loadView('pages.support_team.circulars.pdf', $d)
            ->download('circular-' . $circular->id . '.pdf');
    }

    public function edit(Circular $circular)
    {
        $d['circular'] = $circular->load('attachments');
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.edit', $d);
    }

    public function update(CircularSave $request, Circular $circular)
    {
        $data = $request->validated();
        unset($data['attachments'], $data['remove_attachments']);
        $data['is_published'] = $request->boolean('is_published');

        $wasPublished = $circular->is_published;
        $circular->update($data);

        $this->removeAttachments($request, $circular);
        $this->storeAttachments($request, $circular);

        if (!$wasPublished && $circular->is_published) {
            Audit::log('circular.published', $circular, 'Published circular "' . $circular->title . '" to ' . $circular->audience);
        }

        return redirect()->route('circulars.index')->with('flash_success', 'Circular updated.');
    }

    /** Publish / unpublish in one click from the management table. */
    public function togglePublish(Circular $circular)
    {
        $circular->update(['is_published' => !$circular->is_published]);

        if ($circular->is_published) {
            Audit::log('circular.published', $circular, 'Published circular "' . $circular->title . '" to ' . $circular->audience);
        }

        return back()->with('flash_success', $circular->is_published ? 'Circular published.' : 'Circular unpublished.');
    }

    public function destroy(Circular $circular)
    {
        foreach ($circular->attachments as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->delete();
        }

        $circular->delete();

        return back()->with('flash_success', __('msg.del_ok'));
    }

    /**
     * Download one attachment (Loop 105). The gate is the circular's
     * own: managers reach everything they manage; every other role
     * only a circular currently visible to them — the same rule as
     * show(), so a file is never more public than its circular.
     * Files live on the local disk precisely so this check cannot
     * be bypassed by a bare storage URL.
     */
    public function downloadAttachment(Circular $circular, CircularAttachment $attachment)
    {
        if ($attachment->circular_id !== $circular->id) {
            abort(404);
        }

        if (!Qs::userIsTeamSA() && !$circular->isVisibleTo(Auth::user()->user_type)) {
            abort(404);
        }

        if (!Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    /**
     * Store newly uploaded attachments, mirroring the feedback
     * system's approach (FeedbackController, Workstream CW):
     * a sniffed-type file stored under a random name with its
     * original name + mime kept on the row. Two deliberate
     * differences, both forced by the audience model: the files go
     * to the LOCAL disk (feedback uses the public disk) because
     * circular downloads are audience-gated, and the extension is
     * clamped to the validated allowlist including pdf (the
     * feedback helper clamps everything non-image to jpg).
     */
    protected function storeAttachments(CircularSave $request, Circular $circular): void
    {
        foreach ($request->file('attachments', []) as $file) {
            if (!$file) {
                continue;
            }
            $ext = strtolower((string) $file->guessExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'], true)) {
                $ext = 'pdf';
            }
            $name = 'circular_' . $circular->id . '_' . Str::random(8) . '.' . $ext;
            $path = $file->storeAs('uploads/circulars', $name, 'local');
            $circular->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
            ]);
        }
    }

    /** Delete the attachments the edit form marked for removal. */
    protected function removeAttachments(CircularSave $request, Circular $circular): void
    {
        $ids = collect($request->input('remove_attachments', []))
            ->map(function ($id) { return (int) $id; })
            ->all();
        if (!$ids) {
            return;
        }

        foreach ($circular->attachments()->whereIn('id', $ids)->get() as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $attachment->delete();
        }
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI
       read from the local file, for renderers that cannot fetch
       URLs (dompdf). Anything that is not a local storage file is
       returned unchanged. Mirrors MarkController::pdfImageDataUri. */
    protected function pdfImageDataUri($url)
    {
        if (!$url) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }
}
