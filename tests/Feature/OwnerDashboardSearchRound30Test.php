<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 30 — dashboard search re-sweep.
 *
 * Round 18 locked the two real fixes (school staff excludes
 * super_admin; teacher student search is TeacherScope-scoped) and
 * the SA platform-only shape. This round re-sweeps search after the
 * F-R24-1 / E31-E32 / F-R31-1 landscape and probes the surfaces
 * round 18 never touched: SA search per query, librarian/student/
 * parent gates, and hostile/empty query shapes.
 *
 * BRIEF-PREMISE CORRECTION, proven from the controller before any
 * test was written: the school-side STAFF query carries NO
 * TeacherScope filter — only the STUDENTS query is scoped. A
 * teacher searching staff gets the whole matching staff directory
 * (admins, out-of-scope teachers, principals included), by the same
 * design round 18 documented ("only teachers are scoped" refers to
 * the student set). Scoping staff search to a teacher's classes
 * would break the staff directory and is NOT built here; the
 * teacher-student scoping (the actual privacy boundary) is
 * re-locked below instead.
 *
 * Second documented asymmetry (not a leak): the SA branch's staff
 * types are teacher/admin/super_admin/accountant/librarian —
 * principal is NOT among them, so SA search for the principal's
 * name returns nothing, while SA search for an admin or a teacher
 * returns that row. No student, class, payment, marks, or fee
 * figure ever surfaces in SA search — that boundary is what the
 * numbers-not-records rule requires, and it holds on every query.
 */
class OwnerDashboardSearchRound30Test extends TestCase
{
    use DatabaseTransactions;

    protected function sa()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    /** A real student in a class Ananya does NOT teach (class 2). */
    protected function foreignStudent()
    {
        $record = StudentRecord::where('my_class_id', 2)->firstOrFail();

        return User::findOrFail($record->user_id);
    }

    public function test_admin_search_finds_student_classes_and_payments()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $response->assertOk();
        $response->assertViewHas('students', function ($students) {
            return $students->contains('id', $this->student()->id);
        });

        $classResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'Prep']));

        $classResponse->assertOk();
        $classResponse->assertViewHas('classes', function ($classes) {
            return $classes->contains('name', 'Prep');
        });

        $paymentResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'Tuition']));

        $paymentResponse->assertOk();
        $paymentResponse->assertViewHas('payments', function ($payments) {
            return $payments->contains('title', 'Term 1 Tuition Fee');
        });
    }

    public function test_admin_search_excludes_super_admin()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $response->assertOk();
        $response->assertViewHas('staff', function ($staff) {
            return $staff->where('user_type', 'super_admin')->isEmpty();
        });
        $response->assertDontSee('superadmin@hackstra.app');
    }

    public function test_teacher_staff_search_is_unscoped_directory()
    {
        // Admin row visible to a teacher: staff is NOT TeacherScope
        // filtered (see class docblock — brief premise corrected).
        $adminResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Kavita']));

        $adminResponse->assertOk();
        $adminResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'admin@hackstra.app');
        });

        // Out-of-scope teacher (Priya Nair teaches classes 2, 9 —
        // none of Ananya's) is likewise visible in staff search.
        $teacherResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Priya']));

        $teacherResponse->assertOk();
        $teacherResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'teacher2@teacher.com');
        });

        // And the principal row is visible to a teacher too.
        $principalResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Rajesh']));

        $principalResponse->assertOk();
        $principalResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'principal@hackstra.app');
        });
    }

    public function test_teacher_student_search_still_scoped()
    {
        $foreign = $this->foreignStudent();

        $foreignResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => $foreign->name]));

        $foreignResponse->assertOk();
        $foreignResponse->assertViewHas('students', function ($students) use ($foreign) {
            return ! $students->contains('id', $foreign->id);
        });

        $ownResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $ownResponse->assertOk();
        $ownResponse->assertViewHas('students', function ($students) {
            return $students->contains('id', $this->student()->id);
        });
    }

    public function test_teacher_search_returns_classes_and_payments()
    {
        // As coded, classes and payments are school-wide for every
        // search-allowed role (no teacher filter on either query).
        // Payment TITLES are fee-structure names, not per-student
        // figures — recorded here as the as-coded scope.
        $classResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Prep']));

        $classResponse->assertOk();
        $classResponse->assertViewHas('classes', function ($classes) {
            return $classes->contains('name', 'Prep');
        });

        $paymentResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Tuition']));

        $paymentResponse->assertOk();
        $paymentResponse->assertViewHas('payments', function ($payments) {
            return $payments->contains('title', 'Term 1 Tuition Fee');
        });
    }

    public function test_librarian_student_parent_search_refused()
    {
        $this->actingAs($this->librarian())
            ->get(route('dashboard.search', ['q' => 'Aarav']))
            ->assertForbidden();

        $this->actingAs($this->student())
            ->get(route('dashboard.search', ['q' => 'Aarav']))
            ->assertForbidden();

        $this->actingAs($this->parent())
            ->get(route('dashboard.search', ['q' => 'Aarav']))
            ->assertForbidden();
    }

    public function test_sa_search_for_student_returns_nothing()
    {
        $response = $this->actingAs($this->sa())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $response->assertOk();
        $response->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
        $response->assertViewHas('classes', function ($classes) {
            return $classes->isEmpty();
        });
        $response->assertViewHas('payments', function ($payments) {
            return $payments->isEmpty();
        });
        $response->assertViewHas('staff', function ($staff) {
            return $staff->isEmpty();
        });
        $response->assertDontSee('Aarav Sharma');
    }

    public function test_sa_search_finds_admin_and_self()
    {
        $adminResponse = $this->actingAs($this->sa())
            ->get(route('dashboard.search', ['q' => 'Kavita']));

        $adminResponse->assertOk();
        $adminResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'admin@hackstra.app');
        });
        $adminResponse->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });

        $selfResponse = $this->actingAs($this->sa())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $selfResponse->assertOk();
        $selfResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('user_type', 'super_admin');
        });
    }

    public function test_sa_search_excludes_principal()
    {
        // SA branch staff types omit principal (see docblock).
        $response = $this->actingAs($this->sa())
            ->get(route('dashboard.search', ['q' => 'Rajesh']));

        $response->assertOk();
        $response->assertViewHas('staff', function ($staff) {
            return $staff->isEmpty();
        });
        $response->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
    }

    public function test_principal_search_finds_self_and_school_rows()
    {
        $selfResponse = $this->actingAs($this->principal())
            ->get(route('dashboard.search', ['q' => 'Rajesh']));

        $selfResponse->assertOk();
        $selfResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'principal@hackstra.app');
        });

        $studentResponse = $this->actingAs($this->principal())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $studentResponse->assertOk();
        $studentResponse->assertViewHas('students', function ($students) {
            return $students->contains('id', $this->student()->id);
        });
    }

    public function test_empty_and_short_queries_return_empty_results()
    {
        $emptyResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => '']));

        $emptyResponse->assertOk();
        $emptyResponse->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
        $emptyResponse->assertViewHas('staff', function ($staff) {
            return $staff->isEmpty();
        });

        // Single character: below the 2-char threshold, no query runs.
        $shortResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'A']));

        $shortResponse->assertOk();
        $shortResponse->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
        $shortResponse->assertViewHas('staff', function ($staff) {
            return $staff->isEmpty();
        });

        // No q param at all: same empty shape, still 200.
        $missingResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search'));

        $missingResponse->assertOk();
        $missingResponse->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
    }

    public function test_no_match_query_returns_empty_200()
    {
        $response = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => 'ZzzNoSuchName']));

        $response->assertOk();
        $response->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
        $response->assertViewHas('staff', function ($staff) {
            return $staff->isEmpty();
        });
        $response->assertViewHas('classes', function ($classes) {
            return $classes->isEmpty();
        });
        $response->assertViewHas('payments', function ($payments) {
            return $payments->isEmpty();
        });
    }

    public function test_quote_and_wildcard_queries_do_not_500()
    {
        // Parameterised LIKE: a quote is data, never a break-out.
        $quoteResponse = $this->actingAs($this->admin())
            ->get(route('dashboard.search', ['q' => "O'Brien"]));

        $quoteResponse->assertOk();

        // '%%' is a 2-char term, so the query runs and matches
        // broadly — but bounded to 10 rows and still role-scoped:
        // a teacher's wildcard student set stays inside her scope.
        $wildcardResponse = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => '%%']));

        $wildcardResponse->assertOk();
        $wildcardResponse->assertViewHas('students', function ($students) {
            return $students->count() <= 10
                && $students->every(function ($student) {
                    return in_array(
                        $student->student_record->my_class_id ?? null,
                        \App\Support\TeacherScope::classIds($this->teacher()),
                        true
                    );
                });
        });
    }
}
