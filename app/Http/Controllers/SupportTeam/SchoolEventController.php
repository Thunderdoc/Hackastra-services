<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\SchoolEvent;
use Illuminate\Http\Request;

/**
 * School events management (audit §9) — powers the dashboard's
 * School Calendar and Upcoming Events from real, editable records.
 */
class SchoolEventController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA');
        $this->middleware('super_admin', ['only' => ['destroy']]);
    }

    public function index()
    {
        $d['events'] = SchoolEvent::orderByDesc('starts_at')->paginate(15);
        $d['types'] = SchoolEvent::types();

        return view('pages.support_team.events.index', $d);
    }

    public function create()
    {
        $d['types'] = SchoolEvent::types();

        return view('pages.support_team.events.create', $d);
    }

    public function store(Request $request)
    {
        $data = $this->validateEvent($request);
        $data['created_by'] = $request->user()->id;
        SchoolEvent::create($data);

        return redirect()->route('events.index')->with('flash_success', 'Event created.');
    }

    public function edit($id)
    {
        $d['event'] = SchoolEvent::findOrFail($id);
        $d['types'] = SchoolEvent::types();

        return view('pages.support_team.events.edit', $d);
    }

    public function update(Request $request, $id)
    {
        $event = SchoolEvent::findOrFail($id);
        $event->update($this->validateEvent($request));

        return redirect()->route('events.index')->with('flash_success', 'Event updated.');
    }

    public function destroy($id)
    {
        SchoolEvent::findOrFail($id)->delete();

        return back()->with('flash_success', __('msg.del_ok'));
    }

    protected function validateEvent(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:' . implode(',', array_keys(SchoolEvent::types())),
            'description' => 'nullable|string|max:2000',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'all_day' => 'nullable|boolean',
        ]);
    }
}
