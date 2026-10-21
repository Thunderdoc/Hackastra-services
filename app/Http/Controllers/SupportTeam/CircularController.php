<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\CircularSave;
use App\Models\Circular;
use App\Support\Audit;
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
 * Mutating actions (deletion included) are gated by the teamSA middleware
 * below, mirroring SchoolEventController: the school Admin runs the school
 * day to day, the Super Admin keeps platform-level oversight.
 * Circulars live in-app only — no mail/SMS is sent.
 */
class CircularController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA', ['except' => ['index', 'show']]);
        $this->middleware('teamSA', ['only' => ['destroy']]);
    }

    public function index()
    {
        if (Qs::userIsTeamSA()) {
            $d['circulars'] = Circular::with('publisher')->latest()->paginate(15);
            $d['audiences'] = Circular::audiences();
            $d['priorities'] = Circular::priorities();

            return view('pages.support_team.circulars.index', $d);
        }

        $d['circulars'] = Circular::with('publisher')
            ->published()
            ->forAudience(Auth::user()->user_type)
            ->latest()
            ->paginate(10);
        $d['priorities'] = Circular::priorities();

        return view('pages.support_team.circulars.feed', $d);
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
