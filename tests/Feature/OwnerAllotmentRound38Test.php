<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\ParentMessage;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Models\UserNotification;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 38 — CLASS-OVERVIEW VISIBILITY CHAIN (P15).
 *
 * CODE READING FIRST (the round-30 tension, now as a chain):
 *   - ClassOverviewController::visibleClasses is SUBJECT-based: a
 *     teacher sees a class iff she directly teaches a subject in
 *     it OR holds a section override for a subject in it. Leading
 *     a section alone grants NOTHING on this surface — even though
 *     TeacherScope::classIds() (messaging + PTM, class-scoped)
 *     DOES include led sections. That is the deliberate P12
 *     gate-by-surface shape, not a bug to uniformise.
 *   - Two paths grant overview, with different gates: the matrix
 *     override (SectionController::updateTeachers — liveness only,
 *     F-R31-1) and the direct assign (SubjectController::update —
 *     liveness FIRST, then eligibilityRefusal, F-R32-1 + round 37).
 *     The eligibility pool is eligibility rows + live direct
 *     assignees, so the reassign-away leg needs eligibility rows
 *     for BOTH teachers, or the return trip is refused.
 *
 * CHAIN (one fixture, one continuous story, real routes):
 *   pure leader (overview absent, messaging + PTM open) ->
 *   override gain (overview appears) -> override cleared
 *   (overview gone) -> direct-assign gain (overview appears)
 *   -> reassigned away (overview gone, sent message + PTM slot
 *   identity persist, booking still cancellable by the leader).
 * Foreign teacher is refused at every state.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * L leads section S of class K, teaches NO subject in K, holds NO
 * override in K (all three negatives asserted from DB). D directly
 * teaches K's subject. L DOES directly teach class T's subject
 * (taught control: T stays visible throughout). F has no link.
 */
class OwnerAllotmentRound38Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R38',
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
            'name' => 'Probe Parent R38',
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
            'name' => 'Probe Student R38',
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
            'adm_no' => 'R38-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    /**
     * Returns [L, D, F, K, S, S2, kSubject, T, ST, tSubject, PK, PK2, PT, SK, SK2, STu].
     */
    protected function fixture(string $suffix): array
    {
        $l = $this->makeTeacher('PROBE-R38-L' . $suffix, 'probe-r38-l' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R38-D' . $suffix, 'probe-r38-d' . strtolower($suffix) . '@example.com');
        $f = $this->makeTeacher('PROBE-R38-F' . $suffix, 'probe-r38-f' . strtolower($suffix) . '@example.com');

        $k = MyClass::create(['name' => 'ProbeR38Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R38 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $l->id]);
        $s2 = Section::create(['name' => 'R38 S2 ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $kSubject = Subject::create([
            'name' => 'Probe Leader Subject R38' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r38-k-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        $t = MyClass::create(['name' => 'ProbeR38Taught ' . $suffix, 'class_type_id' => 4]);
        $st = Section::create(['name' => 'R38 ST ' . $suffix, 'my_class_id' => $t->id, 'teacher_id' => $d->id]);
        $tSubject = Subject::create([
            'name' => 'Probe Taught Subject R38' . $suffix,
            'my_class_id' => $t->id,
            'teacher_id' => $l->id,
            'slug' => 'probe-r38-t-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        $pk = $this->makeParent('PROBE-R38-PK' . $suffix, 'probe-r38-pk' . strtolower($suffix) . '@example.com');
        $pk2 = $this->makeParent('PROBE-R38-PK2' . $suffix, 'probe-r38-pk2' . strtolower($suffix) . '@example.com');
        $pt = $this->makeParent('PROBE-R38-PT' . $suffix, 'probe-r38-pt' . strtolower($suffix) . '@example.com');
        $sk = $this->makeStudent('PROBE-R38-SK' . $suffix, 'probe-r38-sk' . strtolower($suffix) . '@example.com', $k, $s, $pk);
        $sk2 = $this->makeStudent('PROBE-R38-SK2' . $suffix, 'probe-r38-sk2' . strtolower($suffix) . '@example.com', $k, $s2, $pk2);
        $stu = $this->makeStudent('PROBE-R38-ST' . $suffix, 'probe-r38-st' . strtolower($suffix) . '@example.com', $t, $st, $pt);

        $this->assertSame($l->id, (int) $s->teacher_id, 'L must lead section S.');
        $this->assertSame(0, (int) Subject::where('teacher_id', $l->id)->where('my_class_id', $k->id)->count(),
            'L must directly teach NO subject in K.');
        $this->assertSame(0, (int) DB::table('section_subject_teachers')
            ->join('sections', 'sections.id', '=', 'section_subject_teachers.section_id')
            ->where('section_subject_teachers.teacher_id', $l->id)
            ->where('sections.my_class_id', $k->id)->count(),
            'L must hold NO override in K.');
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $kSubject),
            'K subject effective teacher for S must be D, not L.');
        $this->assertContains($k->id, TeacherScope::classIds($l),
            'K must be inside L\'s TeacherScope via the led section.');
        $this->assertContains($t->id, TeacherScope::classIds($l),
            'T must be inside L\'s TeacherScope via direct teaching.');

        return [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt, $sk, $sk2, $stu];
    }

    protected function overviewClassIds(User $teacher): array
    {
        $response = $this->actingAs($teacher)->get(route('class_overview.index'));
        $response->assertOk();

        return $response->viewData('overviews')->map(function ($row) {
            return (int) $row['class']->id;
        })->all();
    }

    protected function assertOverviewAbsent(User $teacher, MyClass $class, string $className): void
    {
        $this->assertNotContains($class->id, $this->overviewClassIds($teacher),
            'Overview index must not list the class.');
        $this->actingAs($teacher)->get(route('class_overview.index'))
            ->assertOk()->assertDontSee($className);
        $this->actingAs($teacher)->get(route('class_overview.index', ['class_id' => $class->id]))
            ->assertForbidden();
    }

    protected function assertOverviewPresent(User $teacher, MyClass $class, string $className): void
    {
        $this->assertContains($class->id, $this->overviewClassIds($teacher),
            'Overview index must list the class.');
        $this->actingAs($teacher)->get(route('class_overview.index', ['class_id' => $class->id]))
            ->assertOk()->assertSee($className);
    }

    protected function bellCount(User $user, string $type, string $title): int
    {
        return (int) UserNotification::where('user_id', $user->id)
            ->where('type', $type)->where('title', $title)->count();
    }

    public function test_leader_overview_chain_override_then_direct_then_loss()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt, $sk] = array_slice($this->fixture('A'), 0, 14);
        $kName = 'ProbeR38Class A';
        $tName = 'ProbeR38Taught A';

        // (a) PURE LEADER: overview absent for K, taught control T present.
        $this->assertOverviewAbsent($l, $k, $kName);
        $this->assertContains($t->id, $this->overviewClassIds($l), 'Taught class T must stay visible throughout.');
        $this->actingAs($f)->get(route('class_overview.index', ['class_id' => $k->id]))->assertForbidden();

        // Messaging is OPEN for the led class (round-30 matrix, re-confirmed).
        $offered = $this->actingAs($l)->get(route('parent_messages.create'))->assertOk()
            ->viewData('classes')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($k->id, $offered, 'Compose form must offer the led class K to L.');
        $this->actingAs($l)->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'section_id' => $s->id,
                'title' => 'R38 leader K-S', 'body' => 'probe body',
            ])->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, (int) ParentMessage::where('sender_id', $l->id)
            ->where('my_class_id', $k->id)->where('section_id', $s->id)->count(),
            'Section-scoped leader message must be stored.');
        $this->assertSame(1, $this->bellCount($pk, 'parent_message', 'R38 leader K-S'),
            'Section S parent must be belled.');
        $this->assertSame(0, $this->bellCount($pk2, 'parent_message', 'R38 leader K-S'),
            'Other-section parent must NOT be belled.');

        // PTM slot creation is OPEN for the led class; parent books it.
        $ptmOffered = $this->actingAs($l)->get(route('ptm.create'))->assertOk()
            ->viewData('classes')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($k->id, $ptmOffered, 'PTM create form must offer the led class K to L.');
        $this->actingAs($l)->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => Carbon::today()->addDays(14)->format('Y-m-d'),
                'start_time' => '10:00', 'end_time' => '10:30',
                'teacher_id' => $l->id, 'my_class_id' => $k->id,
            ])->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'PTM slot created.');
        $slot = PtmSlot::where('teacher_id', $l->id)->where('my_class_id', $k->id)->firstOrFail();
        $this->actingAs($pk)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $sk->id])
            ->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Slot booked.');
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $this->assertSame(1, (int) UserNotification::where('user_id', $l->id)
            ->where('type', 'ptm_booked')->count(), 'Leader must be belled on booking.');

        // (b) GAIN VIA OVERRIDE: matrix save grants the overview.
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($s->id)), ['teachers' => [$kSubject->id => Qs::hash($l->id)]])
            ->assertRedirect(route('sections.index'));
        $this->assertSame($l->id, SectionTeacher::teacherIdFor($s->id, $kSubject));
        $this->assertOverviewPresent($l, $k, $kName);

        // (c) OVERRIDE CLEARED: blank matrix save takes the overview away again.
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($s->id)), ['teachers' => [$kSubject->id => '']])
            ->assertRedirect(route('sections.index'));
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, $kSubject));
        $this->assertOverviewAbsent($l, $k, $kName);

        // (d) GAIN VIA DIRECT ASSIGN: eligibility rows for BOTH teachers
        // first — the pool is eligibility rows + live direct assignees,
        // so without D's row the return trip below would be refused.
        TeacherEligibility::create(['teacher_id' => $l->id, 'subject_name' => $kSubject->name]);
        TeacherEligibility::create(['teacher_id' => $d->id, 'subject_name' => $kSubject->name]);
        $this->actingAs($this->admin())
            ->put(route('subjects.update', $kSubject->id), [
                'name' => $kSubject->name, 'my_class_id' => $k->id,
                'periods_per_week' => 6, 'teacher_id' => Qs::hash($l->id),
            ])->assertRedirect(route('subjects.index'));
        $this->assertSame($l->id, Subject::find($kSubject->id)->teacher_id);
        // teacherIdFor reads teacher_id off the PASSED model, so it
        // needs the fresh row — the stale fixture model still says D.
        $this->assertSame($l->id, SectionTeacher::teacherIdFor($s->id, Subject::find($kSubject->id)));
        $this->assertOverviewPresent($l, $k, $kName);

        // (e) REASSIGNED AWAY: overview gone, taught control intact,
        // foreign teacher refused throughout.
        $this->actingAs($this->admin())
            ->put(route('subjects.update', $kSubject->id), [
                'name' => $kSubject->name, 'my_class_id' => $k->id,
                'periods_per_week' => 6, 'teacher_id' => Qs::hash($d->id),
            ])->assertRedirect(route('subjects.index'));
        $this->assertSame($d->id, Subject::find($kSubject->id)->teacher_id);
        $this->assertSame($d->id, SectionTeacher::teacherIdFor($s->id, Subject::find($kSubject->id)));
        $this->assertOverviewAbsent($l, $k, $kName);
        $this->assertContains($t->id, $this->overviewClassIds($l), 'Taught class T must still be visible after losing K.');
        $this->actingAs($f)->get(route('class_overview.index', ['class_id' => $k->id]))->assertForbidden();

        // (f) PERSISTENCE AFTER LOSS (round-29 slot identity + sent-mail
        // stays sent): the message row + bell survive, and the leader
        // can still cancel her own slot's booking by slot identity.
        $this->assertSame(1, (int) ParentMessage::where('sender_id', $l->id)
            ->where('my_class_id', $k->id)->where('section_id', $s->id)->count(),
            'Sent leader message must survive losing the subject.');
        $this->assertSame(1, $this->bellCount($pk, 'parent_message', 'R38 leader K-S'),
            'Section parent bell must survive losing the subject.');
        $this->assertTrue(PtmSlot::where('id', $slot->id)->exists(), 'PTM slot must survive losing the subject.');
        $this->assertTrue(PtmBooking::where('id', $booking->id)->exists(), 'Booking must survive losing the subject.');
        $this->actingAs($l)->delete(route('ptm.cancel', $booking->id))
            ->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Booking cancelled. The slot is free again.');
        $this->assertSame(0, (int) PtmBooking::where('ptm_slot_id', $slot->id)->count(),
            'Cancelled booking must be gone.');
        $this->assertSame(0, (int) UserNotification::where('user_id', $l->id)
            ->where('type', 'ptm_booked')->count(), 'Cancel must clean the booking bell.');
        $this->assertTrue(PtmSlot::where('id', $slot->id)->exists(), 'Slot itself must remain, free again.');
    }

    public function test_foreign_teacher_overview_empty_before_and_after_gain()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t] = array_slice($this->fixture('B'), 0, 8);

        $this->assertSame([], $this->overviewClassIds($f), 'Foreign teacher overview index must be empty.');
        $this->actingAs($f)->get(route('class_overview.index'))
            ->assertOk()->assertDontSee('ProbeR38Class B')->assertDontSee('ProbeR38Taught B');
        $this->actingAs($f)->get(route('class_overview.index', ['class_id' => $k->id]))->assertForbidden();
        $this->actingAs($f)->get(route('class_overview.index', ['class_id' => $t->id]))->assertForbidden();

        // Leader gains the subject via override — foreign teacher still refused.
        $this->actingAs($this->admin())
            ->put(route('sections.teachers.update', Qs::hash($s->id)), ['teachers' => [$kSubject->id => Qs::hash($l->id)]])
            ->assertRedirect(route('sections.index'));
        $this->assertOverviewPresent($l, $k, 'ProbeR38Class B');
        $this->actingAs($f)->get(route('class_overview.index', ['class_id' => $k->id]))->assertForbidden();
        $this->assertSame([], $this->overviewClassIds($f), 'Foreign teacher overview index must stay empty after the gain.');
    }
}
