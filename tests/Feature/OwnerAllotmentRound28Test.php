<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\ParentMessage;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 28 — PARENT-MESSAGE COMPOSE after override removal.
 *
 * CODE READING FIRST (ParentMessageController):
 *   - create(): admin/teacher only (else 403); the class picker is
 *     composableClasses() — all classes for admin, teacherClassIds()
 *     for a teacher.
 *   - teacherClassIds(): TeacherScope::classIds() (direct subjects +
 *     led sections + overrides, F round 1) PLUS an override join
 *     over section_subject_teachers. Both legs drop a class the
 *     moment its only override is removed.
 *   - store(): validates (class exists, section belongs to the
 *     class, title/body) FIRST, then for teachers aborts 403
 *     'You do not teach this class.' unless teacherCanMessageClass()
 *     (class-level only — a teacher who teaches ANY section of a
 *     class may message ANY section of it; the section is a
 *     recipient filter, not a second gate).
 *   - Recipients: parents found through StudentRecord.my_parent_id
 *     for the class (and section, when set); in-app bells only.
 *   - index(): a sender sees messages they SENT; a parent sees
 *     messages addressed to their children's classes. Neither read
 *     rule keys off current teaching.
 *
 * EXPECTATION, therefore the opposite of the round-26 push freeze
 * and unlike the round-27 grading/submission persistence: sending
 * is CURRENT-TEACHING gated, so removal must CLOSE the channel
 * (form drops the class, store 403s, zero rows, zero bells), while
 * already-sent messages stay visible to sender and recipients
 * (sent mail stays sent). If compose/store stayed open after
 * removal, a removed teacher would keep a live channel to
 * parents of students she no longer teaches — that would be a
 * REAL finding in a different class from the content-row freezes
 * (communication, not ownership), and the brief requires it to be
 * reported with a proposed store-side gate rather than silently
 * "fixed". No fix is expected: the gate already exists.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions):
 * class K (section S led by D, subject direct-taught by D, O's
 * override on S/subject) + a second section S2 of K (led by D)
 * + class K2 (section led by O — O's still-taught control) +
 * a foreign class K3 (no link to O) + a foreign teacher F.
 * Parents are linked through StudentRecord.my_parent_id so the
 * recipient sets are exactly assertable (seeded linkage is NULL
 * for 3039/3041 records, so fresh linkage is required).
 */
class OwnerAllotmentRound28Test extends TestCase
{
    use DatabaseTransactions;

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R28',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ]);
    }

    protected function makeParent(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Parent R28',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'parent',
        ]);
    }

    protected function makeStudent(string $code, string $email, MyClass $class, Section $section, ?User $parent = null): User
    {
        $student = User::create([
            'name' => 'Probe Student R28',
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'student',
        ]);
        StudentRecord::create([
            'session' => Qs::getCurrentSession(),
            'user_id' => $student->id,
            'my_class_id' => $class->id,
            'section_id' => $section->id,
            'my_parent_id' => $parent ? $parent->id : null,
            'adm_no' => 'R28-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /**
     * Returns [O, D, F, K, S, S2, subject, K2, S2k2, K3, P1, P2, P3, P4].
     * O teaches K ONLY via the override on (S, subject). O leads a
     * section of K2 (still-taught control). F teaches nothing here.
     * P1/P2 have children in S; P3's child is in S2 (same class K);
     * P4's child is in K2's section.
     */
    protected function fixture(string $suffix): array
    {
        $o = $this->makeTeacher('PROBE-R28-O' . $suffix, 'probe-r28-o' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R28-D' . $suffix, 'probe-r28-d' . strtolower($suffix) . '@example.com');
        $f = $this->makeTeacher('PROBE-R28-F' . $suffix, 'probe-r28-f' . strtolower($suffix) . '@example.com');

        $k = MyClass::create(['name' => 'ProbeR28Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R28 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $s2 = Section::create(['name' => 'R28 S2 ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $subject = Subject::create([
            'name' => 'Probe Message Subject R28' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r28-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        DB::table('section_subject_teachers')->insert([
            'section_id' => $s->id,
            'subject_id' => $subject->id,
            'teacher_id' => $o->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $k2 = MyClass::create(['name' => 'ProbeR28Class2 ' . $suffix, 'class_type_id' => 4]);
        $sk2 = Section::create(['name' => 'R28 K2 ' . $suffix, 'my_class_id' => $k2->id, 'teacher_id' => $o->id]);

        $k3 = MyClass::create(['name' => 'ProbeR28Class3 ' . $suffix, 'class_type_id' => 4]);
        Section::create(['name' => 'R28 K3 ' . $suffix, 'my_class_id' => $k3->id, 'teacher_id' => $d->id]);

        $p1 = $this->makeParent('PROBE-R28-P1' . $suffix, 'probe-r28-p1' . strtolower($suffix) . '@example.com');
        $p2 = $this->makeParent('PROBE-R28-P2' . $suffix, 'probe-r28-p2' . strtolower($suffix) . '@example.com');
        $p3 = $this->makeParent('PROBE-R28-P3' . $suffix, 'probe-r28-p3' . strtolower($suffix) . '@example.com');
        $p4 = $this->makeParent('PROBE-R28-P4' . $suffix, 'probe-r28-p4' . strtolower($suffix) . '@example.com');
        $this->makeStudent('PROBE-R28-S1' . $suffix, 'probe-r28-s1' . strtolower($suffix) . '@example.com', $k, $s, $p1);
        $this->makeStudent('PROBE-R28-S2' . $suffix, 'probe-r28-s2' . strtolower($suffix) . '@example.com', $k, $s, $p2);
        $this->makeStudent('PROBE-R28-S3' . $suffix, 'probe-r28-s3' . strtolower($suffix) . '@example.com', $k, $s2, $p3);
        $this->makeStudent('PROBE-R28-S4' . $suffix, 'probe-r28-s4' . strtolower($suffix) . '@example.com', $k2, $sk2, $p4);

        $this->assertSame($o->id, SectionTeacher::teacherIdFor($s->id, $subject),
            'Fixture must start with O as the effective teacher via the override.');
        $this->assertContains($k->id, TeacherScope::classIds($o),
            'Fixture must start with K inside O\'s teacher scope.');

        return [$o, $d, $f, $k, $s, $s2, $subject, $k2, $sk2, $k3, $p1, $p2, $p3, $p4];
    }

    protected function storeAs(User $teacher, int $classId, ?int $sectionId, string $title)
    {
        $payload = ['my_class_id' => $classId, 'title' => $title, 'body' => 'probe body'];
        if ($sectionId !== null) {
            $payload['section_id'] = $sectionId;
        }

        return $this->actingAs($teacher)
            ->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), $payload);
    }

    protected function messageCount(User $sender, int $classId): int
    {
        return (int) ParentMessage::where('sender_id', $sender->id)->where('my_class_id', $classId)->count();
    }

    protected function bellCount(User $parent, string $title): int
    {
        return (int) UserNotification::where('user_id', $parent->id)
            ->where('type', 'parent_message')->where('title', $title)->count();
    }

    protected function removeOverrideViaMatrix(Section $section, Subject $subject): void
    {
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($section->id)), ['teachers' => [$subject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertFalse(DB::table('section_subject_teachers')
            ->where('section_id', $section->id)->where('subject_id', $subject->id)->exists(),
            'The matrix save must actually remove the override.');
    }

    public function test_compose_form_and_store_work_with_override_intact()
    {
        [$o, $d, $f, $k, $s, $s2, $subject, $k2, $sk2, $k3, $p1, $p2, $p3, $p4] = $this->fixture('A');

        // Compose form offers K (override-taught) and K2 (led), not K3.
        $this->actingAs($o)->get(route('parent_messages.create'))
            ->assertOk()
            ->assertSee($k->name)
            ->assertSee($k2->name)
            ->assertDontSee($k3->name);

        // Whole-class message to K: row written, every linked parent
        // of the class belled (P1, P2 in S; P3 in S2), K2's parent not.
        $this->storeAs($o, $k->id, null, 'R28 whole class A')
            ->assertRedirect(route('parent_messages.index'))
            ->assertSessionHas('flash_success', 'Message sent to parents.');
        $this->assertSame(1, $this->messageCount($o, $k->id));
        $this->assertSame(1, $this->bellCount($p1, 'R28 whole class A'));
        $this->assertSame(1, $this->bellCount($p2, 'R28 whole class A'));
        $this->assertSame(1, $this->bellCount($p3, 'R28 whole class A'));
        $this->assertSame(0, $this->bellCount($p4, 'R28 whole class A'));

        // Section-scoped message to K+S: only S's parents belled.
        $this->storeAs($o, $k->id, $s->id, 'R28 section A')
            ->assertRedirect(route('parent_messages.index'));
        $this->assertSame(2, $this->messageCount($o, $k->id));
        $this->assertSame(1, $this->bellCount($p1, 'R28 section A'));
        $this->assertSame(1, $this->bellCount($p2, 'R28 section A'));
        $this->assertSame(0, $this->bellCount($p3, 'R28 section A'));
    }

    public function test_compose_and_store_close_after_override_removal_but_sent_mail_stays()
    {
        [$o, $d, $f, $k, $s, $s2, $subject, $k2, $sk2, $k3, $p1, $p2, $p3, $p4] = $this->fixture('B');

        // A message sent while O still teaches K — sent mail.
        $this->storeAs($o, $k->id, null, 'R28 before removal B')->assertRedirect();
        $this->assertSame(1, $this->messageCount($o, $k->id));
        $this->assertSame(1, $this->bellCount($p1, 'R28 before removal B'));

        $this->removeOverrideViaMatrix($s, $subject);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $subject));
        $this->assertNotContains($k->id, TeacherScope::classIds($o),
            'K must leave O\'s scope the moment its only override is gone.');

        // Compose form drops K but keeps K2 (still led by O).
        $this->actingAs($o)->get(route('parent_messages.create'))
            ->assertOk()
            ->assertDontSee($k->name)
            ->assertSee($k2->name);

        // Store to K, whole-class and section-scoped alike: 403,
        // no row, no bell. The channel is closed, not frozen for
        // one party and open for another — nobody new can send.
        $this->storeAs($o, $k->id, null, 'R28 after removal B')->assertForbidden();
        $this->storeAs($o, $k->id, $s->id, 'R28 after removal section B')->assertForbidden();
        $this->assertSame(1, $this->messageCount($o, $k->id));
        $this->assertSame(0, $this->bellCount($p1, 'R28 after removal B'));
        $this->assertSame(0, $this->bellCount($p2, 'R28 after removal B'));

        // Sent mail stays sent: the sender still lists it, and the
        // addressed parent still sees it. Read rules never keyed
        // off current teaching, and removal does not retract mail.
        $this->actingAs($o)->get(route('parent_messages.index'))
            ->assertOk()->assertSee('R28 before removal B');
        $this->actingAs($p1)->get(route('parent_messages.index'))
            ->assertOk()->assertSee('R28 before removal B');
    }

    public function test_new_teacher_still_taught_class_and_foreign_teacher_controls()
    {
        [$o, $d, $f, $k, $s, $s2, $subject, $k2, $sk2, $k3, $p1, $p2, $p3, $p4] = $this->fixture('C');

        // Foreign teacher baseline (before any removal): F teaches
        // nothing here, so the form offers neither K nor K2 and
        // both stores 403. The surface was never ungated.
        $this->actingAs($f)->get(route('parent_messages.create'))
            ->assertOk()
            ->assertDontSee($k->name)
            ->assertDontSee($k2->name);
        $this->storeAs($f, $k->id, null, 'R28 foreign C')->assertForbidden();
        $this->storeAs($f, $k2->id, null, 'R28 foreign K2 C')->assertForbidden();
        $this->assertSame(0, $this->messageCount($f, $k->id));

        $this->removeOverrideViaMatrix($s, $subject);

        // The NEW effective teacher can message K after removal.
        $this->actingAs($d)->get(route('parent_messages.create'))
            ->assertOk()->assertSee($k->name);
        $this->storeAs($d, $k->id, null, 'R28 new teacher C')
            ->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, $this->messageCount($d, $k->id));
        $this->assertSame(1, $this->bellCount($p1, 'R28 new teacher C'));

        // O still teaches K2 (leads its section): her channel to
        // K2 is untouched by losing K.
        $this->storeAs($o, $k2->id, null, 'R28 still taught C')
            ->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, $this->messageCount($o, $k2->id));
        $this->assertSame(1, $this->bellCount($p4, 'R28 still taught C'));
    }

    public function test_admin_compose_unaffected_and_non_teachers_refused()
    {
        [$o, $d, $f, $k, $s, $s2, $subject, $k2, $sk2, $k3, $p1, $p2, $p3, $p4] = $this->fixture('D');

        $this->removeOverrideViaMatrix($s, $subject);

        // Admin is scope-free by design: form offers K, store works.
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('parent_messages.create'))
            ->assertOk()->assertSee($k->name);
        $this->storeAs($admin, $k->id, null, 'R28 admin D')
            ->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, $this->messageCount($admin, $k->id));
        $this->assertSame(1, $this->bellCount($p1, 'R28 admin D'));

        // Non-teachers never reach compose: student and parent 403
        // on both create and store (controller guard, not scope).
        $student = User::findOrFail(StudentRecord::where('my_class_id', $k->id)
            ->where('section_id', $s->id)->value('user_id'));
        $this->actingAs($student)->get(route('parent_messages.create'))->assertForbidden();
        $this->storeAs($student, $k->id, null, 'R28 student D')->assertForbidden();
        $this->actingAs($p1)->get(route('parent_messages.create'))->assertForbidden();
        $this->storeAs($p1, $k->id, null, 'R28 parent D')->assertForbidden();
        $this->assertSame(1, $this->messageCount($admin, $k->id),
            'Refused attempts must not write rows.');
    }
}
