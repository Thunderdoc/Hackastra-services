<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Http\Requests\SchoolEvent\SchoolEventSave;
use App\Models\SchoolEvent;

/**
 * School events management (audit §9) — powers the dashboard's
 * School Calendar and Upcoming Events from real, editable records.
 */
class SchoolEventController extends Controller
{
    public function __construct()
    {
        // Workstream CM: reads stay teamSA; writes to the Admin write team.
        $this->middleware('teamSA', ['only' => ['index', 'show']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show']]);
    }

    public function index()
    {
        $d['events'] = SchoolEvent::orderByDesc('starts_at')->paginate(15);
        $d['types'] = SchoolEvent::types();

        /* Owner redesign (2026-10-23): summary chips over the WHOLE
           set, not the visible page — total, upcoming, this month,
           and the next event coming up. */
        $today = now()->startOfDay();
        $d['ev_stats'] = [
            'total' => SchoolEvent::count(),
            'upcoming' => SchoolEvent::where('starts_at', '>=', $today)->count(),
            'this_month' => SchoolEvent::whereYear('starts_at', $today->year)
                ->whereMonth('starts_at', $today->month)->count(),
            'next' => SchoolEvent::where('starts_at', '>=', $today)
                ->orderBy('starts_at')->first(),
        ];

        return view('pages.support_team.events.index', $d);
    }

    public function create()
    {
        $d['types'] = SchoolEvent::types();

        return view('pages.support_team.events.create', $d);
    }

    public function store(SchoolEventSave $request)
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;
        SchoolEvent::create($data);

        return redirect()->route('events.index')->with('flash_success', 'Event created.');
    }

    public function edit($id)
    {
        // Audit Fix Wave 1 (X-2): resolve raw OR Qs-hashed ids — the
        // un-resolved hash 404'd a real record.
        $d['event'] = SchoolEvent::findOrFail(Qs::resolveId($id));
        $d['types'] = SchoolEvent::types();

        return view('pages.support_team.events.edit', $d);
    }

    public function update(SchoolEventSave $request, $id)
    {
        $event = SchoolEvent::findOrFail(Qs::resolveId($id));
        $event->update($request->validated());

        return redirect()->route('events.index')->with('flash_success', 'Event updated.');
    }

    public function destroy($id)
    {
        SchoolEvent::findOrFail(Qs::resolveId($id))->delete();

        return back()->with('flash_success', __('msg.del_ok'));
    }

    public function show($id)
    {
        // No detail page exists for this module; land on its index
        // instead of returning 500.
        return redirect()->route('events.index');
    }

}
