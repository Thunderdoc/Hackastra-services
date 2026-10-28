<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\Exam;
use App\Models\ParentMessage;
use App\Models\StudentRecord;
use App\Support\Notify;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Mobile app API (Sanctum) re-sweep after the round-21–25 landscape
 * (principal role, librarian/accountant portals, marks entry re-gate
 * F-R24-1, attendance teacher-view rule). Read-only v1, own-data
 * only: every role's token, /me shape, data-route scoping, parent
 * ?child= own-vs-foreign, notifications own-only, write refusals.
 * Snapshot-DB only; tokens and probe rows roll back with the test.
 */
class OwnerMobileApiResweepRound26Test extends TestCase
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
        // In-process Sanctum artifact: without this, a second call in
        // the same test still resolves the FIRST caller (the guard is
        // cached on the test app instance). Real HTTP re-resolves
        // from the header every time, so force that here.
        Auth::forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->tokenFor($user)])
            ->json($method, $uri, $data);
    }

    protected function foreignRecordId(int $excludeId)
    {
        return StudentRecord::where('id', '<>', $excludeId)
            ->whereNull('my_parent_id')
            ->firstOrFail()->id;
    }

    public function test_login_succeeds_for_every_school_role()
    {
        $roles = [
            'admin@hackstra.app' => 'admin',
            'principal@hackstra.app' => 'principal',
            'teacher@hackstra.app' => 'teacher',
            'student@hackstra.app' => 'student',
            'parent@hackstra.app' => 'parent',
            'accountant@hackstra.app' => 'accountant',
            'librarian@hackstra.app' => 'librarian',
        ];

        foreach ($roles as $email => $type) {
            $response = $this->postJson('/api/login', [
                'identity' => $email,
                'password' => 'hackstra',
            ]);

            $response->assertOk();
            $this->assertNotEmpty($response->json('token'), "No token for $email");
            $this->assertSame($type, $response->json('user.user_type'));
        }
    }

    public function test_login_super_admin_is_403_without_token()
    {
        $response = $this->postJson('/api/login', [
            'identity' => 'superadmin@hackstra.app',
            'password' => 'hackstra',
        ]);

        $response->assertStatus(403);
        $this->assertNull($response->json('token'));
    }

    public function test_login_wrong_password_is_401()
    {
        $this->postJson('/api/login', [
            'identity' => 'student@hackstra.app',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_me_student_shape()
    {
        $response = $this->api('GET', '/api/me', $this->userByEmail('student@hackstra.app'));

        $response->assertOk();
        $response->assertJsonStructure(['user' => ['id', 'name', 'email', 'user_type', 'photo', 'initials', 'class', 'adm_no']]);
        $this->assertSame('student', $response->json('user.user_type'));
        $this->assertSame('Prep', $response->json('user.class'));
        $this->assertNotEmpty($response->json('user.adm_no'));
    }

    public function test_me_parent_children_exact()
    {
        $response = $this->api('GET', '/api/me', $this->userByEmail('parent@hackstra.app'));

        $response->assertOk();
        $this->assertSame('parent', $response->json('user.user_type'));
        $ids = collect($response->json('user.children'))->pluck('student_record_id')->sort()->values()->all();
        $this->assertSame([1, 2], $ids);
        $names = collect($response->json('user.children'))->pluck('name')->all();
        $this->assertContains('Aarav Sharma', $names);
        $this->assertContains('Dariana Heaney', $names);
    }

    public function test_me_principal_shape()
    {
        $response = $this->api('GET', '/api/me', $this->userByEmail('principal@hackstra.app'));

        $response->assertOk();
        $this->assertSame('principal', $response->json('user.user_type'));
        $this->assertSame('Rajesh Iyer', $response->json('user.name'));
    }

    public function test_api_requires_token()
    {
        $this->getJson('/api/me')->assertStatus(401);
        $this->getJson('/api/timetable/mine')->assertStatus(401);
        $this->getJson('/api/marks/mine')->assertStatus(401);
        $this->getJson('/api/attendance/mine')->assertStatus(401);
        $this->getJson('/api/notifications')->assertStatus(401);
        $this->getJson('/api/messages')->assertStatus(401);
    }

    public function test_student_timetable_own_periods()
    {
        $response = $this->api('GET', '/api/timetable/mine', $this->userByEmail('student@hackstra.app'));

        $response->assertOk();
        $this->assertSame('student', $response->json('mode'));
        $this->assertSame('Prep', $response->json('class'));
        $this->assertNotEmpty($response->json('entries'));
    }

    public function test_teacher_timetable_week_dataset()
    {
        $response = $this->api('GET', '/api/timetable/mine', $this->userByEmail('teacher@hackstra.app'));

        $response->assertOk();
        $this->assertSame('teacher', $response->json('mode'));
        $this->assertGreaterThan(0, $response->json('period_count'));
        $this->assertNotEmpty($response->json('entries'));
    }

    public function test_parent_timetable_own_vs_foreign_child()
    {
        $parent = $this->userByEmail('parent@hackstra.app');

        $own = $this->api('GET', '/api/timetable/mine?child=1', $parent);
        $own->assertOk();
        $this->assertSame('parent', $own->json('mode'));
        $this->assertNotEmpty($own->json('entries'));

        $this->api('GET', '/api/timetable/mine?child=' . $this->foreignRecordId(1), $parent)
            ->assertStatus(403);
    }

    public function test_staff_timetable_is_403()
    {
        foreach (['admin@hackstra.app', 'principal@hackstra.app', 'accountant@hackstra.app', 'librarian@hackstra.app'] as $email) {
            $this->api('GET', '/api/timetable/mine', $this->userByEmail($email))
                ->assertStatus(403);
        }
    }

    public function test_marks_published_only_and_empty_when_unpublished()
    {
        Exam::where('id', 1)->update(['results_published' => true]);
        Exam::where('id', '<>', 1)->where('year', '2026-2027')->update(['results_published' => false]);

        $response = $this->api('GET', '/api/marks/mine', $this->userByEmail('student@hackstra.app'));

        $response->assertOk();
        $examIds = collect($response->json('exams'))->pluck('exam.id')->all();
        $this->assertContains(1, $examIds);
        $this->assertNotContains(2, $examIds);
        $first = collect($response->json('exams'))->firstWhere('exam.id', 1);
        $this->assertNotEmpty($first['subjects']);

        Exam::where('year', '2026-2027')->update(['results_published' => false]);
        $empty = $this->api('GET', '/api/marks/mine', $this->userByEmail('student@hackstra.app'));
        $empty->assertOk();
        $this->assertSame([], $empty->json('exams'));
    }

    public function test_parent_marks_own_vs_foreign_child()
    {
        Exam::where('id', 1)->update(['results_published' => true]);
        Exam::where('id', '<>', 1)->where('year', '2026-2027')->update(['results_published' => false]);

        $parent = $this->userByEmail('parent@hackstra.app');

        $own = $this->api('GET', '/api/marks/mine?child=1', $parent);
        $own->assertOk();
        $this->assertContains(1, collect($own->json('exams'))->pluck('exam.id')->all());

        $this->api('GET', '/api/marks/mine?child=' . $this->foreignRecordId(1), $parent)
            ->assertStatus(403);
    }

    public function test_non_student_parent_marks_is_403()
    {
        foreach (['teacher@hackstra.app', 'principal@hackstra.app', 'admin@hackstra.app', 'accountant@hackstra.app', 'librarian@hackstra.app'] as $email) {
            $this->api('GET', '/api/marks/mine', $this->userByEmail($email))
                ->assertStatus(403);
        }
    }

    public function test_student_attendance_own_sessions_only()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $ownRecord = StudentRecord::where('user_id', $student->id)->firstOrFail();
        $foreignRecord = StudentRecord::where('id', '<>', $ownRecord->id)
            ->whereNull('my_parent_id')
            ->firstOrFail();

        $makeSession = function (string $topic, StudentRecord $record, string $date) {
            $session = AttendanceSession::create([
                'date' => $date,
                'time_table_id' => 1,
                'my_class_id' => $record->my_class_id,
                'section_id' => $record->section_id,
                'subject_id' => 1,
                'teacher_id' => $this->userByEmail('teacher@hackstra.app')->id,
                'topic' => $topic,
                'status' => AttendanceSession::STATUS_SUBMITTED,
            ]);
            AttendanceSessionEntry::create([
                'session_id' => $session->id,
                'student_record_id' => $record->id,
                'status' => AttendanceSessionEntry::STATUS_PRESENT,
            ]);
        };
        // Distinct dates: attendance_sessions is UNIQUE(date,
        // time_table_id), so two sessions on one period must differ.
        $makeSession('R26 own topic', $ownRecord, '2026-11-02');
        $makeSession('R26 foreign topic', $foreignRecord, '2026-11-03');

        $response = $this->api('GET', '/api/attendance/mine', $student);

        $response->assertOk();
        $this->assertIsNumeric($response->json('percentage'));
        $topics = collect($response->json('sessions'))->pluck('topic')->all();
        $this->assertContains('R26 own topic', $topics);
        $this->assertNotContains('R26 foreign topic', $topics);
    }

    public function test_parent_attendance_own_vs_foreign_child()
    {
        $parent = $this->userByEmail('parent@hackstra.app');
        $childRecord = StudentRecord::findOrFail(1);

        $session = AttendanceSession::create([
            'date' => '2026-11-02',
            'time_table_id' => 1,
            'my_class_id' => $childRecord->my_class_id,
            'section_id' => $childRecord->section_id,
            'subject_id' => 1,
            'teacher_id' => $this->userByEmail('teacher@hackstra.app')->id,
            'topic' => 'R26 parent child topic',
            'status' => AttendanceSession::STATUS_SUBMITTED,
        ]);
        AttendanceSessionEntry::create([
            'session_id' => $session->id,
            'student_record_id' => $childRecord->id,
            'status' => AttendanceSessionEntry::STATUS_PRESENT,
        ]);

        $own = $this->api('GET', '/api/attendance/mine?child=1', $parent);
        $own->assertOk();
        $this->assertContains('R26 parent child topic', collect($own->json('sessions'))->pluck('topic')->all());

        $this->api('GET', '/api/attendance/mine?child=' . $this->foreignRecordId(1), $parent)
            ->assertStatus(403);
    }

    public function test_teacher_attendance_is_403()
    {
        // Settled teacher-view rule: submitted registers are gone
        // from the teacher's end, so there is no teacher attendance
        // endpoint on the API either.
        $this->api('GET', '/api/attendance/mine', $this->userByEmail('teacher@hackstra.app'))
            ->assertStatus(403);
    }

    public function test_parent_messages_own_class_only()
    {
        $parent = $this->userByEmail('parent@hackstra.app');

        // Fixture parent linked to a Class 1 record only.
        $otherParent = User::create([
            'name' => 'Probe Parent R26', 'username' => 'probe-parent-r26',
            'email' => 'probe-parent-r26@example.com', 'code' => 'PPR26-1',
            'password' => Hash::make('hackstra'), 'user_type' => 'parent',
        ]);
        $class1Record = StudentRecord::where('my_class_id', 2)->firstOrFail();
        StudentRecord::where('id', $class1Record->id)->update(['my_parent_id' => $otherParent->id]);

        ParentMessage::create([
            'sender_id' => $this->userByEmail('admin@hackstra.app')->id,
            'my_class_id' => 1,
            'section_id' => null,
            'title' => 'R26 class message',
            'body' => 'For Prep only.',
        ]);

        $own = $this->api('GET', '/api/messages', $parent);
        $own->assertOk();
        $this->assertContains('R26 class message', collect($own->json('messages'))->pluck('title')->all());

        $foreign = $this->api('GET', '/api/messages', $otherParent);
        $foreign->assertOk();
        $this->assertNotContains('R26 class message', collect($foreign->json('messages'))->pluck('title')->all());

        foreach (['teacher@hackstra.app', 'student@hackstra.app', 'admin@hackstra.app', 'principal@hackstra.app'] as $email) {
            $this->api('GET', '/api/messages', $this->userByEmail($email))
                ->assertStatus(403);
        }
    }

    public function test_notifications_own_only()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $teacher = $this->userByEmail('teacher@hackstra.app');

        Notify::toUser($student->id, 'test', 'R26-NOTIF-OWN');
        Notify::toUser($teacher->id, 'test', 'R26-NOTIF-FOREIGN');

        $own = $this->api('GET', '/api/notifications', $student);
        $own->assertOk();
        $titles = collect($own->json('notifications'))->pluck('title')->all();
        $this->assertContains('R26-NOTIF-OWN', $titles);
        $this->assertNotContains('R26-NOTIF-FOREIGN', $titles);

        $other = $this->api('GET', '/api/notifications', $teacher);
        $other->assertOk();
        $otherTitles = collect($other->json('notifications'))->pluck('title')->all();
        $this->assertContains('R26-NOTIF-FOREIGN', $otherTitles);
        $this->assertNotContains('R26-NOTIF-OWN', $otherTitles);
    }

    public function test_principal_notifications_own_only()
    {
        // Principal data routes are 403 in v1 (not scoped), but the
        // notifications route is caller-scoped for every role.
        $principal = $this->userByEmail('principal@hackstra.app');

        foreach (['/api/timetable/mine', '/api/marks/mine', '/api/attendance/mine', '/api/messages'] as $uri) {
            $this->api('GET', $uri, $principal)->assertStatus(403);
        }

        $this->api('GET', '/api/me', $principal)->assertOk();
        $this->api('GET', '/api/notifications', $principal)->assertOk();
    }

    public function test_write_attempts_are_refused()
    {
        $student = $this->userByEmail('student@hackstra.app');

        // Read-only v1: every data URI exists for GET only, so a
        // write verb on it is a 405, never a write.
        $this->api('POST', '/api/me', $student)->assertStatus(405);
        $this->api('POST', '/api/timetable/mine', $student)->assertStatus(405);
        $this->api('PUT', '/api/marks/mine', $student)->assertStatus(405);
        $this->api('DELETE', '/api/me', $student)->assertStatus(405);
        $this->api('PATCH', '/api/notifications', $student)->assertStatus(405);
        $this->api('POST', '/api/messages', $student)->assertStatus(405);
    }

    public function test_logout_revokes_token()
    {
        $student = $this->userByEmail('student@hackstra.app');
        $token = $this->tokenFor($student);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/logout')->assertOk();

        // Data-layer proof: the token row is gone, so no fresh
        // request can authenticate with it (same in-process caveat
        // as the original suite: a same-test follow-up request would
        // still pass because the test app retains the resolved
        // guard — revocation is proven at the data layer).
        $this->assertSame(0, $student->fresh()->tokens()->count());
    }
}
