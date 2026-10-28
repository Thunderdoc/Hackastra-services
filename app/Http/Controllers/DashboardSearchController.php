<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Payment;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permission-aware global search for the top bar (audit §9).
 * The search box used to be decorative. This is the real thing:
 * Team/SA/T users search students, staff, classes and payments;
 * everyone else is redirected to their dashboard.
 */
class DashboardSearchController extends Controller
{
    public function __invoke(Request $request)
    {
        // Limited principal role (owner order 2026-10-28): the
        // principal searches school-wide like the school team.
        abort_unless(Qs::userIsTeamSAT() || Qs::userIsPrincipal(), 403);

        $term = trim((string) $request->query('q', ''));

        $data = [
            'term' => $term,
            'students' => collect(),
            'staff' => collect(),
            'classes' => collect(),
            'payments' => collect(),
        ];

        if (Qs::userIsSuperAdmin()) {
            // Platform-only model (owner ruling 2026-10-22,
            // Workstream DD): the Super Admin's search covers
            // platform accounts (staff types) only — never student
            // or parent records, classes or payments.
            if (strlen($term) >= 2) {
                $like = '%' . $term . '%';

                $data['staff'] = User::whereIn('user_type', ['teacher', 'admin', 'super_admin', 'accountant', 'librarian'])
                    ->where(function ($q) use ($like) {
                        $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
                    })
                    ->take(10)
                    ->get();
            }

            return view('pages.support_team.search_results', $data);
        }

        if (strlen($term) >= 2) {
            $like = '%' . $term . '%';

            $data['students'] = User::where('user_type', 'student')
                ->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
                })
                // Roles audit round 18: a teacher sees only their
                // assigned students (TeacherScope rule) — the search
                // must not hand them every class's roster. Admin and
                // principal search school-wide by design.
                ->when(Qs::userIsTeacher(), function ($q) {
                    $q->whereHas('student_record', function ($sq) {
                        $sq->whereIn('my_class_id', TeacherScope::classIds(Auth::user()));
                    });
                })
                ->with('student_record.my_class', 'student_record.section')
                ->take(10)
                ->get();

            // Roles audit round 18: the platform boundary holds both
            // ways — school roles never see the platform account in
            // search (the SA users list already hides school roles
            // from the SA side). 'super_admin' stays in the SA's own
            // branch above, which is platform-only by design.
            $data['staff'] = User::whereIn('user_type', ['teacher', 'admin', 'accountant', 'librarian', 'principal'])
                ->where(function ($q) use ($like) {
                    $q->where('name', 'like', $like)->orWhere('email', 'like', $like);
                })
                ->take(10)
                ->get();

            $data['classes'] = MyClass::where('name', 'like', $like)->take(10)->get();

            $data['payments'] = Payment::where('title', 'like', $like)
                ->orWhere('ref_no', 'like', $like)
                ->take(10)
                ->get();
        }

        return view('pages.support_team.search_results', $data);
    }
}
