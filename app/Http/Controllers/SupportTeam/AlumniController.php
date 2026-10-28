<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Alumni Directory — READ-ONLY.
 *
 * Routes (already wired in routes/web.php, controller-guarded, no team
 * middleware): GET /alumni -> index, GET /alumni/{alumnus} -> show.
 * Route params are never {id}, so the Qs::decodeHash binding on {id}
 * does not apply; raw ids are resolved here with findOrFail.
 *
 * Source of truth: StudentRecord rows with grad = 1. There is no
 * dedicated graduation-year column: the table carries session (the
 * academic session string, e.g. "2026-2027") and grad_date (a free
 * string, nullable). The directory therefore shows both, filters by
 * class and by session, and treats grad_date as the graduation
 * date/year display, falling back to the session when it is empty.
 *
 * Guards: ADMIN ONLY. Teachers, students, parents, accountants,
 * librarians and the Super Admin all get 403 from this controller.
 * The Super Admin is deliberately NOT added to any allowlist: the
 * PlatformOnly middleware already keeps it out of school pages, and
 * this guard is the second wall. No create/edit/delete route exists
 * and no method here writes anything — graduated records are never
 * modified by this feature.
 */
class AlumniController extends Controller
{
    protected function guardAdmin()
    {
        $user = Auth::user();
        if (!$user || $user->user_type !== 'admin') {
            abort(403);
        }
    }

    public function index(Request $request)
    {
        $this->guardAdmin();

        $query = StudentRecord::where('grad', 1)
            ->with(['user', 'my_class', 'section']);

        if ($request->filled('class_id')) {
            $query->where('my_class_id', (int) $request->input('class_id'));
        }

        if ($request->filled('session')) {
            $query->where('session', $request->input('session'));
        }

        if ($request->filled('q')) {
            $q = trim($request->input('q'));
            $query->where(function ($inner) use ($q) {
                $inner->where('adm_no', 'like', '%' . $q . '%')
                    ->orWhereHas('user', function ($userQuery) use ($q) {
                        $userQuery->where('name', 'like', '%' . $q . '%');
                    });
            });
        }

        $alumni = $query->orderBy('session', 'desc')
            ->orderBy('id')
            ->paginate(25)
            ->appends($request->only(['class_id', 'session', 'q']));

        $classes = MyClass::orderBy('id')->get();
        $sessions = StudentRecord::where('grad', 1)
            ->select('session')
            ->distinct()
            ->orderBy('session', 'desc')
            ->pluck('session');

        return view('pages.support_team.alumni.index', [
            'alumni' => $alumni,
            'classes' => $classes,
            'sessions' => $sessions,
            'filters' => $request->only(['class_id', 'session', 'q']),
        ]);
    }

    public function show($alumnus)
    {
        $this->guardAdmin();

        // Scoped to grad = 1: a current (non-graduated) record id is a
        // 404 here, never a readable profile.
        $record = StudentRecord::where('grad', 1)
            ->with(['user', 'my_class', 'section'])
            ->findOrFail($alumnus);

        return view('pages.support_team.alumni.show', [
            'alumnus' => $record,
        ]);
    }
}
