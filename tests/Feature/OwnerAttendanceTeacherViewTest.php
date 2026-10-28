<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner rule: submitted attendance registers are gone from the
 * teacher's end. Once a teacher submits, they cannot view the
 * register back — not from the period list, not from the teacher
 * home, not by direct URL (show/print/PDF/CSV all read through
 * registerData). Admin / Super Admin oversight keeps full view.
 */
class OwnerAttendanceTeacherViewTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ownSession(): AttendanceSession
    {
        return AttendanceSession::where('teacher_id', $this->teacher()->id)->orderBy('id')->firstOrFail();
    }

    public function test_teacher_cannot_view_own_submitted_register()
    {
        $session = $this->ownSession();
        $this->actingAs($this->teacher())
            ->get(route('attendance.periods.show', $session->id))
            ->assertForbidden();
    }

    public function test_teacher_cannot_export_own_submitted_register()
    {
        $session = $this->ownSession();
        $this->actingAs($this->teacher())
            ->get(route('attendance.periods.export_register', $session->id))
            ->assertForbidden();
    }

    public function test_teacher_take_for_submitted_period_redirects_to_index()
    {
        $session = $this->ownSession();
        $date = $session->date->format('Y-m-d');
        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.take', [$session->time_table_id, 'date' => $date]));
        $response->assertRedirect(route('attendance.periods.index', ['date' => $date]));
    }

    public function test_teacher_index_shows_no_view_links_for_taken_periods()
    {
        $session = $this->ownSession();
        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.index', ['date' => $session->date->format('Y-m-d')]));
        $response->assertOk();
        $response->assertDontSee('/attendance/periods/session/');
    }

    public function test_admin_keeps_view_link_and_register_access()
    {
        $session = $this->ownSession();
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.periods.index', [
                'date' => $session->date->format('Y-m-d'),
                'class_id' => $session->my_class_id,
            ]));
        $response->assertOk();
        $response->assertSee('/attendance/periods/session/'.$session->id);
        $this->actingAs($this->admin())
            ->get(route('attendance.periods.show', $session->id))
            ->assertOk();
    }
}
