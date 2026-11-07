<?php

namespace App\Http\Controllers\MyParent;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\StudentRecord;
use App\Support\StudentProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * F2 — Parent Progress page (design §4.1/§5.1).
 *
 * The child list is the ONLY scope: it is loaded with
 * my_parent_id = the signed-in parent, and ?child= is matched
 * against that loaded list (the AttendanceController@my
 * pattern) — an id belonging to anyone else silently selects
 * the parent's own first child, and no foreign record is ever
 * loaded by request id. All figures come from
 * App\Support\StudentProgress over that scoped list.
 */
class ProgressController extends Controller
{
    public function index(Request $request)
    {
        $session = Qs::getCurrentSession();

        $children = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('my_parent_id', Auth::id())
            ->orderBy('id')
            ->get();

        $progress = StudentProgress::forChildren($children, $session);

        $selected = $children->firstWhere('id', (int) $request->query('child')) ?: $children->first();

        return view('pages.parent.progress', [
            'children' => $children,
            'progress' => $progress,
            'selected' => $selected,
            'selected_progress' => $selected ? ($progress[$selected->id] ?? null) : null,
            'session' => $session,
        ]);
    }
}
