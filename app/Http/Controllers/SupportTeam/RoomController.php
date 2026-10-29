<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\TimeTable;
use Illuminate\Http\Request;

/**
 * T3 — rooms and labs (master prompt, owner order 2026-10-29).
 * The school's physical teaching spaces. Admin maintains them
 * here; the timetable generator assigns them clash-free
 * (App\Support\RoomAssigner) and a needs_lab subject only ever
 * lands in a lab. Deleting a room that periods still use is
 * refused — unassign it on the timetable first, exactly like
 * the substitution guards refuse to strand a cover.
 */
class RoomController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA', ['only' => ['index']]);
        $this->middleware('teamSAWrite', ['except' => ['index']]);
    }

    public function index()
    {
        $d['rooms'] = Room::orderBy('kind')->orderBy('name')->get();
        $d['kinds'] = Room::kinds();
        $d['used_room_ids'] = TimeTable::whereNotNull('room_id')
            ->pluck('room_id')->unique()->all();

        return view('pages.support_team.rooms.index', $d);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'kind' => 'required|in:classroom,lab',
            'capacity' => 'required|integer|min:0|max:500',
        ]);
        Room::create($data);

        return redirect()->route('rooms.index')->with('flash_success', 'Room added.');
    }

    public function update(Request $request, $id)
    {
        $room = Room::findOrFail($id);
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'kind' => 'required|in:classroom,lab',
            'capacity' => 'required|integer|min:0|max:500',
        ]);
        $room->update($data);

        return redirect()->route('rooms.index')->with('flash_success', 'Room updated.');
    }

    public function destroy($id)
    {
        $room = Room::findOrFail($id);
        if (TimeTable::where('room_id', $room->id)->exists()) {
            return redirect()->route('rooms.index')
                ->with('flash_danger', 'This room is assigned to timetable periods. Unassign it on the timetable first, then delete it.');
        }
        $room->delete();

        return redirect()->route('rooms.index')->with('flash_success', 'Room deleted.');
    }
}
