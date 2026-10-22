<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\CircularSave;
use App\Models\Circular;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;

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
        $this->middleware('teamSAWrite', ['except' => ['index', 'show', 'print', 'pdf']]);
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

        $base = Circular::with('publisher');
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
        $data['is_published'] = $request->boolean('is_published');
        $data['published_by'] = $request->user()->id;

        $circular = Circular::create($data);

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

        $d['circular'] = $circular->load('publisher');
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

        $d['circular'] = $circular->load('publisher');
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

        $d['circular'] = $circular->load('publisher');
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return Pdf::loadView('pages.support_team.circulars.pdf', $d)
            ->download('circular-' . $circular->id . '.pdf');
    }

    public function edit(Circular $circular)
    {
        $d['circular'] = $circular;
        $d['audiences'] = Circular::audiences();
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.edit', $d);
    }

    public function update(CircularSave $request, Circular $circular)
    {
        $data = $request->validated();
        $data['is_published'] = $request->boolean('is_published');

        $wasPublished = $circular->is_published;
        $circular->update($data);

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
        $circular->delete();

        return back()->with('flash_success', __('msg.del_ok'));
    }
}
