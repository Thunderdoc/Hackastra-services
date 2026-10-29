<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Mark;
use App\Models\MyClass;
use App\Models\ParentMessage;
use App\Models\PtmBooking;
use App\Models\PtmSlot;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 30 — SECTION-LEADER GATE MATRIX.
 *
 * CODE READING FIRST (three gates, three different granularities):
 *   - MARKS ENTRY (MarkController::denyUnlessEffectiveTeacher, called
 *     in selector/manage/update, + MarkComponentUpdate::authorize):
 *     SUBJECT-scoped. A teacher may enter marks only when the
 *     subject belongs to the class AND SectionTeacher::teacherIdFor
 *     (section, subject) — override wins, else the subject's direct
 *     teacher — equals her. A pure section leader fails this even
 *     though the class is in her TeacherScope (F-R24-1, C round 24).
 *   - PARENT MESSAGE (ParentMessageController::teacherClassIds):
 *     CLASS-scoped. TeacherScope::classIds() = direct subjects +
 *     led sections + overrides, so a leader's led class IS in
 *     scope; store() then aborts 403 'You do not teach this
 *     class.' only when the class is outside that set. The section
 *     is a recipient filter, not a second gate.
 *   - PTM SLOT (PtmController::store): CLASS-scoped the same way —
 *     a teacher creates slots only in her own name for classes in
 *     TeacherScope::classIds(). Booking/cancel then follow SLOT
 *     identity (round 29), not current teaching.
 *
 * EXPECTATION: the matrix differs DELIBERATELY by surface — marks
 * refused for a pure leader, messaging + PTM open for her led
 * class — because messaging parents and holding PTMs are plausibly
 * exactly the class teacher's job, while writing marks is a
 * subject teacher's job. This suite locks that shape; it does NOT
 * 'fix' it into uniformity (that would be a product call). Any
 * surface behaving opposite to its gate's documented intent would
 * be a finding instead.
 *
 * Fixtures are fully fresh and roll back (DatabaseTransactions).
 * L leads section S of class K, teaches NO subject in K, holds NO
 * override in K (all three negatives asserted from DB, not
 * assumed). D directly teaches K's subject. L DOES directly teach
 * class T's subject (taught control). F has no link to K or T.
 * Parents are linked through StudentRecord.my_parent_id so
 * recipient sets are exactly assertable.
 */
class OwnerAllotmentRound30Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email): User
    {
        return User::create([
            'name' => 'Probe Teacher R30',
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
            'name' => 'Probe Parent R30',
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
            'name' => 'Probe Student R30',
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
            'adm_no' => 'R30-' . $code,
            'grad' => 0,
        ]);

        return $student;
    }

    /**
     * Returns [L, D, F, K, S, S2, kSubject, T, ST, tSubject, PK, PK2, PT, SK, SK2, STu].
     * L leads S (class K) and directly teaches T's subject. D
     * directly teaches K's subject. F teaches nothing here.
     */
    protected function fixture(string $suffix): array
    {
        $l = $this->makeTeacher('PROBE-R30-L' . $suffix, 'probe-r30-l' . strtolower($suffix) . '@example.com');
        $d = $this->makeTeacher('PROBE-R30-D' . $suffix, 'probe-r30-d' . strtolower($suffix) . '@example.com');
        $f = $this->makeTeacher('PROBE-R30-F' . $suffix, 'probe-r30-f' . strtolower($suffix) . '@example.com');

        $k = MyClass::create(['name' => 'ProbeR30Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R30 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $l->id]);
        $s2 = Section::create(['name' => 'R30 S2 ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $kSubject = Subject::create([
            'name' => 'Probe Leader Subject R30' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r30-k-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        $t = MyClass::create(['name' => 'ProbeR30Taught ' . $suffix, 'class_type_id' => 4]);
        $st = Section::create(['name' => 'R30 ST ' . $suffix, 'my_class_id' => $t->id, 'teacher_id' => $d->id]);
        $tSubject = Subject::create([
            'name' => 'Probe Taught Subject R30' . $suffix,
            'my_class_id' => $t->id,
            'teacher_id' => $l->id,
            'slug' => 'probe-r30-t-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);

        $pk = $this->makeParent('PROBE-R30-PK' . $suffix, 'probe-r30-pk' . strtolower($suffix) . '@example.com');
        $pk2 = $this->makeParent('PROBE-R30-PK2' . $suffix, 'probe-r30-pk2' . strtolower($suffix) . '@example.com');
        $pt = $this->makeParent('PROBE-R30-PT' . $suffix, 'probe-r30-pt' . strtolower($suffix) . '@example.com');
        $sk = $this->makeStudent('PROBE-R30-SK' . $suffix, 'probe-r30-sk' . strtolower($suffix) . '@example.com', $k, $s, $pk);
        $sk2 = $this->makeStudent('PROBE-R30-SK2' . $suffix, 'probe-r30-sk2' . strtolower($suffix) . '@example.com', $k, $s2, $pk2);
        $stu = $this->makeStudent('PROBE-R30-ST' . $suffix, 'probe-r30-st' . strtolower($suffix) . '@example.com', $t, $st, $pt);

        // The three negatives that make L a PURE leader for K,
        // asserted from DB — never assumed from the fixture shape.
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
        // And yet K IS in L's class scope via the led section —
        // that tension is exactly what this round probes.
        $this->assertContains($k->id, TeacherScope::classIds($l),
            'K must be inside L\'s TeacherScope via the led section.');
        $this->assertContains($t->id, TeacherScope::classIds($l),
            'T must be inside L\'s TeacherScope via direct teaching.');

        return [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt, $sk, $sk2, $stu];
    }

    protected function comboRow(int $classId, int $sectionId, int $subjectId): array
    {
        return ['exam_id' => 1, 'my_class_id' => $classId, 'section_id' => $sectionId, 'subject_id' => $subjectId];
    }

    protected function bellCount(User $parent, string $type, string $title): int
    {
        return (int) UserNotification::where('user_id', $parent->id)
            ->where('type', $type)->where('title', $title)->count();
    }

    protected function classIdsFromCreate(User $teacher, string $route): array
    {
        // Assert on view DATA, not raw HTML: the section dropdown
        // renders every section as "Section (Class)", so a class
        // name legitimately appears in HTML even when its class
        // option is gone (F round 29 test-craft lesson).
        $response = $this->actingAs($teacher)->get(route($route));
        $response->assertOk();

        return $response->viewData('classes')->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
    }

    public function test_leader_marks_manage_update_selector_are_forbidden()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject] = array_slice($this->fixture('A'), 0, 7);
        $combo = [1, $k->id, $s->id, $kSubject->id];

        $this->assertSame(0, (int) Mark::where($this->comboRow($k->id, $s->id, $kSubject->id))->count(),
            'Probe must start with zero mark rows for the leader combo.');

        // Subject-scoped gate (F-R24-1): class scope passes via the
        // led section, the effective-teacher leg refuses.
        $this->actingAs($l)->get(route('marks.manage', $combo))->assertForbidden();
        $this->actingAs($l)->put(route('marks.update', $combo), [])->assertForbidden();
        $this->actingAs($l)->post(route('marks.selector'), $this->comboRow($k->id, $s->id, $kSubject->id))
            ->assertForbidden();
        $this->assertSame(0, (int) Mark::where($this->comboRow($k->id, $s->id, $kSubject->id))->count(),
            'Refused marks entry must create no mark rows.');
    }

    public function test_leader_parent_message_compose_and_store_work()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt] = $this->fixture('B');

        // Class-scoped gate: the led class IS offered to the leader.
        $this->assertContains($k->id, $this->classIdsFromCreate($l, 'parent_messages.create'),
            'Compose form must offer the led class K to L.');

        $this->actingAs($l)->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'title' => 'R30 leader K', 'body' => 'probe body',
            ])->assertRedirect(route('parent_messages.index'))
            ->assertSessionHas('flash_success', 'Message sent to parents.');
        $this->assertSame(1, (int) ParentMessage::where('sender_id', $l->id)
            ->where('my_class_id', $k->id)->whereNull('section_id')->count(),
            'Leader message to K must be stored.');
        $this->assertSame(1, $this->bellCount($pk, 'parent_message', 'R30 leader K'),
            'K parent must be belled.');
        $this->assertSame(0, $this->bellCount($pt, 'parent_message', 'R30 leader K'),
            'Taught-class parent must not be belled for a K message.');
    }

    public function test_leader_section_scoped_message_reaches_only_that_section()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2] = array_slice($this->fixture('C'), 0, 12);

        $this->actingAs($l)->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'section_id' => $s->id,
                'title' => 'R30 leader K-S', 'body' => 'probe body',
            ])->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, (int) ParentMessage::where('sender_id', $l->id)
            ->where('my_class_id', $k->id)->where('section_id', $s->id)->count(),
            'Section-scoped leader message must be stored with the section.');
        $this->assertSame(1, $this->bellCount($pk, 'parent_message', 'R30 leader K-S'),
            'Section S parent must be belled.');
        $this->assertSame(0, $this->bellCount($pk2, 'parent_message', 'R30 leader K-S'),
            'Other-section parent must NOT be belled for a section-scoped message.');
    }

    public function test_leader_ptm_slot_create_book_cancel_work()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt, $sk] = array_slice($this->fixture('D'), 0, 14);

        // Overnight menus 2026-10-29, item 18: PTM scheduling is
        // admin-only — the leader no longer gets the create form
        // or store (both 403); the ADMIN creates the slot in the
        // leader's name. Parent booking + leader cancel by slot
        // identity (round 29 shape) are unaffected.
        $this->actingAs($l)->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($l)->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => Carbon::today()->addDays(14)->format('Y-m-d'),
                'start_time' => '10:00', 'end_time' => '10:30',
                'teacher_id' => $l->id, 'my_class_id' => $k->id,
            ])->assertForbidden();

        $date = Carbon::today()->addDays(14)->format('Y-m-d');
        $this->actingAs($this->admin())->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => $date, 'start_time' => '10:00', 'end_time' => '10:30',
                'teacher_id' => $l->id, 'my_class_id' => $k->id,
            ])->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'PTM slot created.');
        $slot = PtmSlot::where('teacher_id', $l->id)->where('my_class_id', $k->id)->firstOrFail();

        // Parent books the leader's slot; leader cancels by slot
        // identity (round 29 shape) and the bell is cleaned.
        $this->actingAs($pk)->from(route('ptm.index'))
            ->post(route('ptm.book', $slot->id), ['student_user_id' => $sk->id])
            ->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Slot booked.');
        $booking = PtmBooking::where('ptm_slot_id', $slot->id)->firstOrFail();
        $this->assertSame(1, (int) UserNotification::where('user_id', $l->id)
            ->where('type', 'ptm_booked')->count(), 'Leader must be belled on booking.');

        $this->actingAs($l)->delete(route('ptm.cancel', $booking->id))
            ->assertRedirect(route('ptm.index'))
            ->assertSessionHas('flash_success', 'Booking cancelled. The slot is free again.');
        $this->assertSame(0, (int) PtmBooking::where('ptm_slot_id', $slot->id)->count(),
            'Cancelled booking must be gone.');
        $this->assertSame(0, (int) UserNotification::where('user_id', $l->id)
            ->where('type', 'ptm_booked')->count(), 'Cancel must clean the booking bell.');
    }

    public function test_foreign_teacher_refused_on_all_three_surfaces()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject] = array_slice($this->fixture('E'), 0, 10);

        // Marks: class scope itself fails for the foreign teacher.
        $this->actingAs($f)->get(route('marks.manage', [1, $k->id, $s->id, $kSubject->id]))->assertForbidden();
        $this->actingAs($f)->put(route('marks.update', [1, $k->id, $s->id, $kSubject->id]), [])->assertForbidden();

        // Messaging: neither K nor T is offered; store refuses.
        $offered = $this->classIdsFromCreate($f, 'parent_messages.create');
        $this->assertNotContains($k->id, $offered, 'Foreign teacher must not be offered K.');
        $this->assertNotContains($t->id, $offered, 'Foreign teacher must not be offered T.');
        $this->actingAs($f)->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $k->id, 'title' => 'R30 foreign', 'body' => 'probe',
            ])->assertForbidden();
        $this->assertSame(0, (int) ParentMessage::where('sender_id', $f->id)->count(),
            'Foreign message store must write nothing.');

        // PTM: the create form itself is refused for every
        // teacher now (overnight menus, item 18 — admin-only
        // scheduling); store refuses too.
        $this->actingAs($f)->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($f)->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => Carbon::today()->addDays(14)->format('Y-m-d'),
                'start_time' => '10:00', 'end_time' => '10:30',
                'teacher_id' => $f->id, 'my_class_id' => $k->id,
            ])->assertForbidden();
        $this->assertSame(0, (int) PtmSlot::where('teacher_id', $f->id)->count(),
            'Foreign slot store must write nothing.');
    }

    public function test_leader_taught_class_works_on_all_three_surfaces()
    {
        [$l, $d, $f, $k, $s, $s2, $kSubject, $t, $st, $tSubject, $pk, $pk2, $pt] = $this->fixture('F');

        // Marks: selector creates rows, manage renders, update saves.
        $this->actingAs($l)->post(route('marks.selector'), $this->comboRow($t->id, $st->id, $tSubject->id))
            ->assertRedirect(route('marks.manage', [1, $t->id, $st->id, $tSubject->id]));
        $this->actingAs($l)->get(route('marks.manage', [1, $t->id, $st->id, $tSubject->id]))->assertOk();
        $marks = Mark::where($this->comboRow($t->id, $st->id, $tSubject->id))->get();
        $this->assertNotEmpty($marks, 'Selector must create mark rows for the taught combo.');
        $payload = [];
        foreach ($marks as $mk) {
            // Fresh subject has no configured scheme: legacy path.
            $payload['t1_' . $mk->id] = '';
            $payload['t2_' . $mk->id] = '';
            $payload['exm_' . $mk->id] = '';
        }
        $this->actingAs($l)->put(route('marks.update', [1, $t->id, $st->id, $tSubject->id]), $payload)->assertOk();

        // Messaging to the taught class works and bells its parent.
        $this->actingAs($l)->from(route('parent_messages.create'))
            ->post(route('parent_messages.store'), [
                'my_class_id' => $t->id, 'title' => 'R30 leader T', 'body' => 'probe body',
            ])->assertRedirect(route('parent_messages.index'));
        $this->assertSame(1, $this->bellCount($pt, 'parent_message', 'R30 leader T'),
            'Taught-class parent must be belled.');

        // PTM slot for the taught class works — created by the
        // admin in the leader's name (overnight menus, item 18:
        // teacher scheduling is admin-only; the leader's own
        // store is refused).
        $this->actingAs($l)->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => Carbon::today()->addDays(14)->format('Y-m-d'),
                'start_time' => '11:00', 'end_time' => '11:30',
                'teacher_id' => $l->id, 'my_class_id' => $t->id,
            ])->assertForbidden();
        $this->actingAs($this->admin())->from(route('ptm.create'))
            ->post(route('ptm.store'), [
                'date' => Carbon::today()->addDays(14)->format('Y-m-d'),
                'start_time' => '11:00', 'end_time' => '11:30',
                'teacher_id' => $l->id, 'my_class_id' => $t->id,
            ])->assertRedirect(route('ptm.index'));
        $this->assertSame(1, (int) PtmSlot::where('teacher_id', $l->id)->where('my_class_id', $t->id)->count(),
            'Taught-class slot must be created.');
    }
}
