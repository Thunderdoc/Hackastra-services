<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IdCardController extends Controller
{
    /**
     * "My ID Card" — a student sees their OWN card (identity derived
     * server-side from their student record; there is deliberately no
     * student-id parameter to tamper with). A parent sees a card per
     * linked child, with a ?child= switcher validated against their
     * OWN children — the same tamper pattern as TimeTableController@mine.
     * Staff use the per-student route (students.id_card) instead.
     */
    public function mine(Request $request)
    {
        $user = Auth::user();
        $d = ['mode' => null, 'children' => collect(), 'sr' => null];

        if (Qs::userIsStudent()) {
            $d['mode'] = 'student';
            $d['sr'] = Qs::findStudentRecord($user->id);
            if ($d['sr']) {
                $d['sr']->loadMissing(['user', 'my_class', 'section']);
            }
            return view('pages.support_team.students.id_card_mine', $d);
        }

        if (Qs::userIsParent()) {
            $d['mode'] = 'parent';
            $d['children'] = $children = Qs::findMyChildren($user->id);
            // Tamper guard: only an id from the parent's OWN children is
            // honoured; anything else falls back to the first child.
            $sr = $children->firstWhere('id', (int) $request->query('child')) ?: $children->first();
            if ($sr) {
                $sr->loadMissing(['user', 'my_class', 'section']);
            }
            $d['sr'] = $sr;
            return view('pages.support_team.students.id_card_mine', $d);
        }

        return redirect()->route('dashboard');
    }

    /**
     * Staff view (teamSA, enforced by route middleware): render the
     * same card for any student, from the student profile.
     */
    public function show($sr_id)
    {
        $sr_id = Qs::decodeHash($sr_id);
        if (!$sr_id) {
            return Qs::goWithDanger();
        }

        $sr = StudentRecord::with(['user', 'my_class', 'section'])->find($sr_id);
        if (!$sr) {
            return Qs::goWithDanger();
        }

        return view('pages.support_team.students.id_card', ['sr' => $sr]);
    }
}
