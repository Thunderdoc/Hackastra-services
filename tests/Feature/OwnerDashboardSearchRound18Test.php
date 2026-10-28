<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 18 — dashboard search × platform boundary.
 *
 * DashboardSearchController gained a principal branch in round 2,
 * but the result SETS were never probed. The school-side staff query
 * listed ['teacher','admin','super_admin','accountant','librarian',
 * 'principal'] — so every teacher and principal searching the
 * platform owner's name got the Super Admin row (name + "Super
 * Admin" role + email) rendered in the staff table, even though the
 * page itself promises "students, staff, classes and payments you
 * are allowed to see" and the SA users list (round 15) hides school
 * roles from the SA side. The platform boundary must hold both
 * ways: school roles never see platform accounts in search.
 *
 * Second surface in the same controller: the students query had NO
 * TeacherScope filter at all, while the owner's role rule (stated
 * in TeacherScope's own docblock) is "a teacher sees only their
 * assigned students — never every class". A teacher searching a
 * foreign-class student's name must not get that row.
 *
 * Admin and principal search school-wide by design; only teachers
 * are scoped, and only the super_admin type is removed from the
 * school-side staff list (the SA's own branch keeps it).
 */
class OwnerDashboardSearchRound18Test extends TestCase
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

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    /** A real student in a class Ananya does NOT teach (class 2). */
    protected function foreignStudent()
    {
        $record = StudentRecord::where('my_class_id', 2)->firstOrFail();

        return User::findOrFail($record->user_id);
    }

    /** Aarav Sharma, user 57 — Prep (class 1), inside Ananya's scope. */
    protected function ownStudent()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    public function test_principal_search_excludes_super_admin()
    {
        $response = $this->actingAs($this->principal())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $response->assertOk();
        $response->assertViewHas('staff', function ($staff) {
            return $staff->where('user_type', 'super_admin')->isEmpty();
        });
        $response->assertDontSee('superadmin@hackstra.app');
    }

    public function test_teacher_search_excludes_super_admin()
    {
        $response = $this->actingAs($this->teacher())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $response->assertOk();
        $response->assertViewHas('staff', function ($staff) {
            return $staff->where('user_type', 'super_admin')->isEmpty();
        });
        $response->assertDontSee('superadmin@hackstra.app');
    }

    public function test_principal_search_finds_school_staff_and_students()
    {
        $staffResponse = $this->actingAs($this->principal())
            ->get(route('dashboard.search', ['q' => 'Ananya']));

        $staffResponse->assertOk();
        $staffResponse->assertViewHas('staff', function ($staff) {
            return $staff->contains('email', 'teacher@hackstra.app');
        });

        $studentResponse = $this->actingAs($this->principal())
            ->get(route('dashboard.search', ['q' => 'Aarav']));

        $studentResponse->assertOk();
        $studentResponse->assertViewHas('students', function ($students) {
            return $students->contains('id', $this->ownStudent()->id);
        });
    }

    public function test_teacher_search_scoped_to_own_classes()
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
            return $students->contains('id', $this->ownStudent()->id);
        });
    }

    public function test_accountant_search_refused()
    {
        $response = $this->actingAs($this->accountant())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $response->assertForbidden();
    }

    public function test_sa_search_returns_platform_staff_only()
    {
        $response = $this->actingAs($this->sa())
            ->get(route('dashboard.search', ['q' => 'Ankit']));

        $response->assertOk();
        $response->assertViewHas('staff', function ($staff) {
            return $staff->contains('user_type', 'super_admin');
        });
        $response->assertViewHas('students', function ($students) {
            return $students->isEmpty();
        });
        $response->assertViewHas('classes', function ($classes) {
            return $classes->isEmpty();
        });
        $response->assertViewHas('payments', function ($payments) {
            return $payments->isEmpty();
        });
    }
}
