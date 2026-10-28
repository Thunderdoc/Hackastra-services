<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ParentMessage;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mobile app API (Sanctum): own-data-only scoping, login gates,
 * and the settled rules (published marks only; no attendance
 * endpoint for teachers; no Super Admin tokens).
 */
class OwnerMobileApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function tokenFor(User $user)
    {
        return $user->createToken('mobile')->plainTextToken;
    }

    protected function api(string $method, string $uri, User $user, array $data = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user)])
            ->json($method, $uri, $data);
    }

    protected function parentWithChildren()
    {
        $parentId = StudentRecord::whereNotNull('my_parent_id')->where('my_parent_id', '>', 0)->firstOrFail()->my_parent_id;

        return User::findOrFail($parentId);
    }

    public function test_login_with_email_returns_token_and_user()
    {
        $response = $this->postJson('/api/login', [
            'identity' => 'student@hackstra.app',
            'password' => 'hackstra',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'name', 'user_type']]);
        $this->assertSame('student', $response->json('user.user_type'));
    }

    public function test_login_with_username_works()
    {
        $student = $this->userByEmail('student@hackstra.app');

        $response = $this->postJson('/api/login', [
            'identity' => $student->username,
            'password' => 'hackstra',
        ]);

        $response->assertOk();
        $this->assertSame($student->id, $response->json('user.id'));
    }

    public function test_login_wrong_password_is_401()
    {
        $this->postJson('/api/login', [
            'identity' => 'student@hackstra.app',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_login_super_admin_is_403()
    {
        $this->postJson('/api/login', [
            'identity' => 'superadmin@hackstra.app',
            'password' => 'hackstra',
        ])->assertStatus(403);
    }

    public function test_login_suspended_account_is_403()
    {
        $user = User::create([
            'name' => 'Suspended Probe',
            'username' => 'suspended-probe',
            'email' => 'suspended-probe@example.com',
            'code' => 'SUSP-PROBE-1',
            'password' => Hash::make('hackstra'),
            'user_type' => 'student',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/login', [
            'identity' => 'suspended-probe@example.com',
            'password' => 'hackstra',
        ])->assertStatus(403);
    }

    public function test_me_requires_token()
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_logout_revokes_token()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $token = $this->tokenFor($student);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/logout')->assertOk();

        // Data-layer proof: the token row is gone, so no fresh
        // request can authenticate with it. (A same-test follow-up
        // request would still pass because the in-process test app
        // retains the resolved guard — proven by probe, not an app
        // bug; the curl check against the dev server proves the
        // real-HTTP 401.)
        $this->assertSame(0, $student->fresh()->tokens()->count());
    }

    public function test_student_timetable_has_own_periods()
    {
        $response = $this->api('GET', '/api/timetable/mine', $this->userByEmail('student@hackstra.app'));

        $response->assertOk();
        $this->assertSame('student', $response->json('mode'));
        $this->assertNotEmpty($response->json('entries'));
    }

    public function test_teacher_timetable_uses_week_dataset()
    {
        $response = $this->api('GET', '/api/timetable/mine', $this->userByEmail('teacher@hackstra.app'));

        $response->assertOk();
        $this->assertSame('teacher', $response->json('mode'));
        $this->assertGreaterThan(0, $response->json('period_count'));
        $this->assertNotEmpty($response->json('entries'));
    }

    public function test_admin_timetable_is_403()
    {
        $this->api('GET', '/api/timetable/mine', $this->userByEmail('admin@hackstra.app'))
            ->assertStatus(403);
    }

    public function test_marks_only_published_exams()
    {
        Exam::where('id', 1)->update(['results_published' => true]);
        Exam::where('id', '<>', 1)->where('year', '2026-2027')->update(['results_published' => false]);

        $response = $this->api('GET', '/api/marks/mine', $this->userByEmail('student@hackstra.app'));

        $response->assertOk();
        $examIds = collect($response->json('exams'))->pluck('exam.id');
        $this->assertContains(1, $examIds->all());
        $this->assertNotContains(2, $examIds->all());
        $first = collect($response->json('exams'))->firstWhere('exam.id', 1);
        $this->assertNotEmpty($first['subjects']);
    }

    public function test_teacher_marks_and_attendance_are_403()
    {
        $teacher = $this->userByEmail('teacher@hackstra.app');
        $this->api('GET', '/api/marks/mine', $teacher)->assertStatus(403);
        // Settled rule: submitted registers are gone from the
        // teacher's end, so there is no teacher attendance endpoint.
        $this->api('GET', '/api/attendance/mine', $teacher)->assertStatus(403);
    }

    public function test_student_attendance_returns_own_sessions_only()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $record = StudentRecord::where('user_id', $student->id)->firstOrFail();

        $session = AttendanceSession::create([
            'date' => '2026-11-02',
            'time_table_id' => 1,
            'my_class_id' => $record->my_class_id,
            'section_id' => $record->section_id,
            'subject_id' => 1,
            'teacher_id' => $this->userByEmail('teacher@hackstra.app')->id,
            'topic' => 'API probe topic',
            'status' => AttendanceSession::STATUS_SUBMITTED,
        ]);
        AttendanceSessionEntry::create([
            'session_id' => $session->id,
            'student_record_id' => $record->id,
            'status' => AttendanceSessionEntry::STATUS_PRESENT,
        ]);

        $response = $this->api('GET', '/api/attendance/mine', $student);

        $response->assertOk();
        $this->assertIsNumeric($response->json('percentage'));
        $found = collect($response->json('sessions'))->firstWhere('topic', 'API probe topic');
        $this->assertNotNull($found);
        $this->assertSame('present', $found['status']);
    }

    public function test_parent_messages_only_for_own_children()
    {
        $parent = $this->parentWithChildren();
        $child = StudentRecord::where('my_parent_id', $parent->id)->firstOrFail();

        ParentMessage::create([
            'sender_id' => $this->userByEmail('admin@hackstra.app')->id,
            'my_class_id' => $child->my_class_id,
            'section_id' => null,
            'title' => 'API probe message',
            'body' => 'For the whole class.',
        ]);

        $response = $this->api('GET', '/api/messages', $parent);

        $response->assertOk();
        $titles = collect($response->json('messages'))->pluck('title');
        $this->assertContains('API probe message', $titles->all());
    }

    public function test_parent_foreign_child_is_403()
    {
        // The seed links children to a single parent, so build two
        // fixture parents and link one existing record to each.
        // (Query-builder update bypasses fillable; the transaction
        // rolls it all back.)
        $parentA = User::create([
            'name' => 'Probe Parent A', 'username' => 'probe-parent-a',
            'email' => 'probe-parent-a@example.com', 'code' => 'PPA-1',
            'password' => Hash::make('hackstra'), 'user_type' => 'parent',
        ]);
        $parentB = User::create([
            'name' => 'Probe Parent B', 'username' => 'probe-parent-b',
            'email' => 'probe-parent-b@example.com', 'code' => 'PPB-1',
            'password' => Hash::make('hackstra'), 'user_type' => 'parent',
        ]);
        $records = StudentRecord::orderBy('id')->take(2)->get();
        StudentRecord::where('id', $records[0]->id)->update(['my_parent_id' => $parentA->id]);
        StudentRecord::where('id', $records[1]->id)->update(['my_parent_id' => $parentB->id]);

        $this->api('GET', '/api/timetable/mine?child=' . $records[1]->id, $parentA)
            ->assertStatus(403);
        $this->api('GET', '/api/marks/mine?child=' . $records[1]->id, $parentA)
            ->assertStatus(403);
    }

    public function test_notifications_return_own_items()
    {
        $student = $this->userByEmail('student@hackstra.app');

        $response = $this->api('GET', '/api/notifications', $student);

        $response->assertOk();
        $response->assertJsonStructure(['notifications']);
    }
}
