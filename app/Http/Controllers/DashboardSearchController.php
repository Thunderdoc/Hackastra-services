<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Support\DashboardSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permission-aware global search for the top bar (audit §9).
 * The search box used to be decorative. This is the real thing:
 * Team/SA/T users search students, staff, classes and payments;
 * everyone else is redirected to their dashboard.
 *
 * Round 5 Slice 4 (P3): the data queries now live in
 * App\Support\DashboardSearch, shared with the command palette's
 * JSON endpoint (palette() below) so the HTML page and the
 * palette can never drift on scoping. The shared query also
 * closes the audit's two holes — teacher classes are
 * TeacherScope-scoped and the payments group is excluded for
 * teachers — on BOTH surfaces, this page included.
 */
class DashboardSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        // Limited principal role (owner order 2026-10-28): the
        // principal searches school-wide like the school team.
        abort_unless(Qs::userIsTeamSAT() || Qs::userIsPrincipal(), 403);

        $term = trim((string) $request->query('q', ''));

        $data = ['term' => $term] + DashboardSearch::results(Auth::user(), $term);

        return view('pages.support_team.search_results', $data);
    }

    /**
     * Command palette endpoint (Round 5 Slice 4, P3):
     * GET /dashboard/search.json?q= →
     * {pages:[], actions:[], students:[], staff:[], classes:[], payments:[]}
     *
     * Deliberately NOT gated like __invoke: roles the HTML page
     * 403s (student, parent, accountant, librarian, nurse) still
     * get their own Pages/Actions catalogue — navigation only,
     * every data group empty. The data gate is NOT widened;
     * DashboardSearch::palettePayload enforces it server-side.
     */
    public function palette(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        return response()->json(DashboardSearch::palettePayload(Auth::user(), $term));
    }
}
