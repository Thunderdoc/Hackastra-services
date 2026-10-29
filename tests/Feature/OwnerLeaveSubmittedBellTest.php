<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use App\Support\Notify;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner live report 2026-10-29: a teacher applied for leave on the
 * live site and the Admin got NO notification. Root cause: the
 * normal-leave path of LeaveRequestController::store() belled
 * nobody (only the emergency path did), and the staff topbar bell
 * never listed per-user notifications nor marked them read, so
 * staff could neither see nor clear them.
 *
 * These tests lock the fix:
 *  - a normal staff leave bells every Admin and the Principal
 *    (the people who can decide it), never the applicant;
 *  - a normal student leave bells the section teacher plus Admin
 *    and Principal;
 *  - the staff bell menu renders the caller's own notifications;
 *  - the mark-read endpoint clears the caller's unread count.
 */
class OwnerLeaveSubmittedBellTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function principal(): User
    {
        return User::where('user_type', 'principal')->firstOrFail();
    }

    public function test_staff_normal_leave_bells_admin_and_principal_not_applicant()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $admin = $this->userByEmail('admin@hackstra.app');
        $principal = $this->principal();

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => now()->addDays(14)->format('Y-m-d'),
            'end_date' => now()->addDays(15)->format('Y-m-d'),
            'reason' => 'Family function',
            'leave_type' => 'normal',
        ])->assertRedirect();

        foreach ([$admin, $principal] as $approver) {
            $note = UserNotification::where('user_id', $approver->id)
                ->where('type', 'leave_submitted')->latest('id')->first();
            $this->assertNotNull($note, 'Approver ' . $approver->email . ' must be belled on a normal leave submission');
            $this->assertStringContainsString('New leave request', $note->title);
            $this->assertStringContainsString($teacher->name, $note->title);
        }
        $this->assertSame(0, UserNotification::where('user_id', $teacher->id)
            ->where('type', 'leave_submitted')->count(),
            'The applicant must not be belled about their own leave');
    }

    public function test_student_normal_leave_bells_section_teacher_admin_and_principal()
    {
        $student = $this->userByEmail('student@hackstra.app');
        // student@ is Prep Section A (section 1); its section teacher
        // is teacher@ (Ananya Sharma).
        $sectionTeacher = $this->userByEmail('teacher@hackstra.app');
        $admin = $this->userByEmail('admin@hackstra.app');
        $principal = $this->principal();

        $this->actingAs($student)->post(route('leave.store'), [
            'start_date' => now()->addDays(20)->format('Y-m-d'),
            'end_date' => now()->addDays(21)->format('Y-m-d'),
            'reason' => 'Fever',
            'leave_type' => 'normal',
        ])->assertRedirect();

        foreach ([$sectionTeacher, $admin, $principal] as $approver) {
            $this->assertSame(1, UserNotification::where('user_id', $approver->id)
                ->where('type', 'leave_submitted')->count(),
                'Approver ' . $approver->email . ' must be belled on a student leave submission');
        }
    }

    public function test_staff_bell_lists_own_notifications_and_mark_read_clears()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $admin = $this->userByEmail('admin@hackstra.app');

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => now()->addDays(30)->format('Y-m-d'),
            'end_date' => now()->addDays(31)->format('Y-m-d'),
            'reason' => 'Personal work',
            'leave_type' => 'normal',
        ])->assertRedirect();

        $this->assertGreaterThan(0, Notify::unreadCount($admin->id));

        // The admin's own bell menu (rendered in the topbar of every
        // page) must list the notification — before the fix the
        // staff menu showed only the school-wide feed.
        $page = $this->actingAs($admin)->get(route('dashboard'));
        $page->assertOk();
        $page->assertSee('New leave request: ' . $teacher->name);

        // Opening the bell posts to notifications.read; the unread
        // count must fall to zero.
        $this->actingAs($admin)->post(route('notifications.read'))->assertOk();
        $this->assertSame(0, Notify::unreadCount($admin->id));
    }
}
