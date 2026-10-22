<?php

namespace App\Http\Controllers;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Payment;
use App\User;
use Illuminate\Http\Request;

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
        abort_unless(Qs::userIsTeamSAT(), 403);

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
                ->with('student_record.my_class', 'student_record.section')
                ->take(10)
                ->get();

            $data['staff'] = User::whereIn('user_type', ['teacher', 'admin', 'super_admin', 'accountant', 'librarian'])
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
