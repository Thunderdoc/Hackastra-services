<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Room;
use App\Models\SeatingPlan;
use App\Models\SeatingSeat;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\SeatingPlanner;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * F3 (Round 4) — Exam Seating-Plan Generator.
 *
 * Covers the design's test plan: planner determinism (byte-identical
 * re-generation), capacity HARD refusal (zero rows + named message),
 * fewest-halls packing, stream filtering, adm_no natural order
 * (numeric-only adm nos), mixed on/off, the no-rooms blocking state,
 * the slot-grain lifecycle (Admin generate/submit, Principal alone
 * publishes, others refused, teacher read-only own published
 * sheets), snapshot immutability, the source_hash staleness
 * fingerprint, the hall-sheet PDF (branding + invigilator label),
 * and the read-only ExamDuty contract (duties never written).
 * Every fixture is fresh; DatabaseTransactions throughout.
 */
class OwnerSeatingPlanF3Test extends TestCase
{
    use DatabaseTransactions;

    /* ---------------- helpers ---------------- */

    protected function userByEmail($email)
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function admin() { return $this->userByEmail('admin@hackstra.app'); }
    protected function principal() { return $this->userByEmail('principal@hackstra.app'); }
    protected function superAdmin() { return $this->userByEmail('superadmin@hackstra.app'); }
    protected function teacher() { return $this->userByEmail('teacher@hackstra.app'); }

    protected function makeUser($type, $tag, $name = null)
    {
        return User::create([
            'name' => $name ?: ('F3 ' . ucfirst($tag)),
            'email' => 'f3_' . $tag . '@example.test',
            'username' => 'f3_' . $tag,
            'password' => Hash::make('f3-pass'),
            'user_type' => $type,
            'code' => 'F3-' . strtoupper($tag),
        ]);
    }

    protected function makeExam($tag = 'main')
    {
        return Exam::create([
            'name' => 'F3 Exam ' . $tag,
            'term' => 'Term 1',
            'year' => Qs::getCurrentSession(),
        ]);
    }

    protected function makeClass($tag, array $sections = [['name' => 'A', 'stream' => null]])
    {
        $class = MyClass::create(['name' => 'F3 Class ' . $tag]);
        $secs = [];
        foreach ($sections as $s) {
            $secs[] = Section::create([
                'name' => $s['name'], 'my_class_id' => $class->id,
                'teacher_id' => $this->teacher()->id, 'stream' => $s['stream'],
            ]);
        }

        return [$class, $secs];
    }

    protected function makeSubject($class, $name, $stream = null)
    {
        return Subject::create([
            'name' => $name, 'my_class_id' => $class->id,
            'teacher_id' => $this->teacher()->id,
            'slug' => 'f3-' . $class->id . '-' . strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(),
            'periods_per_week' => 5, 'stream' => $stream,
        ]);
    }

    protected function makeStudent($class, $section, $tag, $admNo, $name = null)
    {
        $user = $this->makeUser('student', $tag, $name ?: ('F3 Student ' . $tag));
        $sr = StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $class->id,
            'section_id' => $section ? $section->id : null,
            'adm_no' => $admNo, 'session' => Qs::getCurrentSession(),
            'year_admitted' => '2026', 'grad' => 0,
        ]);

        return [$user, $sr];
    }

    protected function makeRoom($name, $capacity, $kind = 'classroom')
    {
        return Room::create(['name' => $name, 'kind' => $kind, 'capacity' => $capacity]);
    }

    protected function makeSitting($exam, $class, $subject, $date = '2026-12-10', $time = '09:00:00')
    {
        return ExamDate::create([
            'exam_id' => $exam->id, 'my_class_id' => $class->id, 'subject_id' => $subject->id,
            'exam_date' => $date, 'start_time' => $time,
        ]);
    }

    /** Small world: 1 exam, 1 class (section A), 1 subject, $n students, 1 sitting. */
    protected function world($students = 3, $tag = 'w')
    {
        $exam = $this->makeExam($tag);
        [$class, $secs] = $this->makeClass($tag);
        $subject = $this->makeSubject($class, 'F3 Maths ' . $tag);
        $records = [];
        for ($i = 1; $i <= $students; $i++) {
            [, $sr] = $this->makeStudent($class, $secs[0], $tag . '_s' . $i, (string) (1000 + $i));
            $records[] = $sr;
        }
        $sitting = $this->makeSitting($exam, $class, $subject);

        return [$exam, $class, $secs[0], $subject, $records, $sitting];
    }

    protected function generateViaHttp($exam, array $roomIds, $mode = 'separate', $date = '2026-12-10', $timeKey = '09:00')
    {
        return $this->actingAs($this->admin())->post(route('exam_seating.generate'), [
            'exam_id' => $exam->id, 'exam_date' => $date, 'time_key' => $timeKey,
            'mode' => $mode, 'room_ids' => $roomIds,
        ]);
    }

    /* ---------------- planner: determinism & order ---------------- */

    public function test_planner_is_deterministic_byte_identical()
    {
        [$exam, , , , , ] = $this->world(5, 'det');
        $r1 = $this->makeRoom('F3 Hall D1', 3);
        $r2 = $this->makeRoom('F3 Hall D2', 4);

        [$ok1, , $plan1] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$r1->id, $r2->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok1);
        $dump1 = SeatingSeat::where('plan_id', $plan1->id)->orderBy('id')
            ->get(['room_id', 'exam_date_id', 'student_record_id', 'seat_no', 'adm_no', 'student_name'])->toJson();
        $hash1 = $plan1->source_hash;

        [$ok2, , $plan2] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$r1->id, $r2->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok2);
        $this->assertSame($plan1->id, $plan2->id);
        $dump2 = SeatingSeat::where('plan_id', $plan2->id)->orderBy('id')
            ->get(['room_id', 'exam_date_id', 'student_record_id', 'seat_no', 'adm_no', 'student_name'])->toJson();
        $this->assertSame($dump1, $dump2, 'Same inputs must produce a byte-identical plan');
        $this->assertSame($hash1, $plan2->fresh()->source_hash);
    }

    public function test_adm_no_natural_order_numeric_only_and_blank_last()
    {
        $exam = $this->makeExam('ord');
        [$class, $secs] = $this->makeClass('ord');
        $subject = $this->makeSubject($class, 'F3 Order Sub');
        // Deliberately scrambled creation order + numeric-only adm nos.
        [, $a] = $this->makeStudent($class, $secs[0], 'ord_a', '1001');
        [, $b] = $this->makeStudent($class, $secs[0], 'ord_b', '999');
        [, $c] = $this->makeStudent($class, $secs[0], 'ord_c', '42');
        [, $blank] = $this->makeStudent($class, $secs[0], 'ord_blank', null);
        $sitting = $this->makeSitting($exam, $class, $subject);

        $roster = SeatingPlanner::rosterForSitting($sitting);
        $this->assertSame(
            [$c->id, $b->id, $a->id, $blank->id],
            array_column($roster, 'student_record_id'),
            'Numeric adm nos sort naturally (42 < 999 < 1001); blank adm no sorts last'
        );
        $this->assertNull($roster[3]['adm_no']);
    }

    public function test_stream_filtering_pcm_student_never_seated_for_pcb_sitting()
    {
        $exam = $this->makeExam('str');
        [$class, $secs] = $this->makeClass('str', [
            ['name' => 'PCM', 'stream' => 'pcm'],
            ['name' => 'PCB', 'stream' => 'pcb'],
        ]);
        $bio = $this->makeSubject($class, 'F3 Biology', 'pcb');
        [, $pcmStudent] = $this->makeStudent($class, $secs[0], 'str_pcm', '2001');
        [, $pcbStudent] = $this->makeStudent($class, $secs[1], 'str_pcb', '2002');
        $sitting = $this->makeSitting($exam, $class, $bio);
        $room = $this->makeRoom('F3 Hall S', 40);

        [$ok, $msg, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok, $msg);
        $seated = SeatingSeat::where('plan_id', $plan->id)->pluck('student_record_id')->all();
        $this->assertSame([$pcbStudent->id], $seated, 'Only the PCB student sits the PCB-only paper');
        $this->assertSame(1, $plan->total_students);
    }

    /* ---------------- capacity: HARD ---------------- */

    public function test_capacity_shortfall_is_named_refusal_and_writes_zero_rows()
    {
        [$exam] = $this->world(5, 'cap');
        $room = $this->makeRoom('F3 Tiny Hall', 3);

        [$ok, $message, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertFalse($ok);
        $this->assertNull($plan);
        $this->assertStringContainsString('5 students', $message);
        $this->assertStringContainsString('3', $message);
        $this->assertStringContainsString('short by 2', $message);
        $this->assertSame(0, SeatingPlan::count(), 'Refusal must write zero plan rows');
        $this->assertSame(0, SeatingSeat::count(), 'Refusal must write zero seat rows');
    }

    public function test_fewest_halls_packing_best_fit_single_hall()
    {
        [$exam] = $this->world(25, 'few');
        $small = $this->makeRoom('F3 Hall 20', 20);
        $big = $this->makeRoom('F3 Hall 30', 30);

        [$ok, $msg, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$small->id, $big->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok, $msg);
        $usedRooms = SeatingSeat::where('plan_id', $plan->id)->pluck('room_id')->unique()->all();
        $this->assertSame([$big->id], array_map('intval', $usedRooms), '25 students must use the single 30-seat hall, not 20+30');
        $this->assertSame(30, $plan->total_seats);
    }

    public function test_no_room_ever_exceeds_capacity_when_splitting()
    {
        [$exam] = $this->world(50, 'split');
        $a = $this->makeRoom('F3 Split A', 20);
        $b = $this->makeRoom('F3 Split B', 20);
        $c = $this->makeRoom('F3 Split C', 20);

        [$ok, $msg, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$a->id, $b->id, $c->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok, $msg);
        foreach (SeatingSeat::where('plan_id', $plan->id)->get()->groupBy('room_id') as $roomId => $seats) {
            $this->assertLessThanOrEqual(20, $seats->count(), 'Capacity is a hard physical limit');
            $this->assertSame(range(1, $seats->count()), $seats->sortBy('seat_no')->pluck('seat_no')->map(fn ($n) => (int) $n)->values()->all());
        }
        $this->assertSame(50, SeatingSeat::where('plan_id', $plan->id)->count());
    }

    /* ---------------- mixed mode ---------------- */

    public function test_mixed_interleaves_classes_and_separate_keeps_them_together()
    {
        $exam = $this->makeExam('mix');
        [$classA, $secsA] = $this->makeClass('mixA');
        [$classB, $secsB] = $this->makeClass('mixB');
        $subA = $this->makeSubject($classA, 'F3 Mix Sub A');
        $subB = $this->makeSubject($classB, 'F3 Mix Sub B');
        for ($i = 1; $i <= 4; $i++) {
            $this->makeStudent($classA, $secsA[0], 'mix_a' . $i, 'A' . $i);
            $this->makeStudent($classB, $secsB[0], 'mix_b' . $i, 'B' . $i);
        }
        $this->makeSitting($exam, $classA, $subA);
        $this->makeSitting($exam, $classB, $subB);
        $room = $this->makeRoom('F3 Mix Hall', 40);

        [$ok, $msg, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'mixed', $this->admin()->id);
        $this->assertTrue($ok, $msg);
        $mixedOrder = SeatingSeat::where('plan_id', $plan->id)->orderBy('seat_no')->pluck('class_name')->all();
        $this->assertSame('F3 Class mixA', $mixedOrder[0]);
        $this->assertSame('F3 Class mixB', $mixedOrder[1], 'Mixed mode interleaves sittings seat by seat');
        $this->assertSame('F3 Class mixA', $mixedOrder[2]);

        [$ok2, $msg2, $plan2] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok2, $msg2);
        $sepOrder = SeatingSeat::where('plan_id', $plan2->id)->orderBy('seat_no')->pluck('class_name')->all();
        $this->assertSame(
            ['F3 Class mixA', 'F3 Class mixA', 'F3 Class mixA', 'F3 Class mixA', 'F3 Class mixB', 'F3 Class mixB', 'F3 Class mixB', 'F3 Class mixB'],
            $sepOrder,
            'Separate mode keeps each sitting together'
        );
    }

    /* ---------------- blocking states ---------------- */

    public function test_no_rooms_is_a_first_class_blocking_state()
    {
        [$exam] = $this->world(2, 'noroom');
        $this->assertSame(0, Room::count());

        [$ok, $message, $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [], 'separate', $this->admin()->id);
        $this->assertFalse($ok);
        $this->assertNull($plan);
        $this->assertStringContainsString('No rooms are defined yet', $message);
        $this->assertSame(0, SeatingPlan::count());

        // The index page says the same thing and links to Rooms.
        $response = $this->actingAs($this->admin())->get(route('exam_seating.index', ['exam_id' => $exam->id]));
        $response->assertOk();
        $response->assertSee('No rooms are defined yet');
        $response->assertSee('Exam Seating');
    }

    public function test_index_without_exam_and_with_exam_renders()
    {
        $this->actingAs($this->admin())->get(route('exam_seating.index'))->assertOk();
        [$exam] = $this->world(2, 'idx');
        $this->makeRoom('F3 Idx Hall', 30);
        $response = $this->actingAs($this->admin())->get(route('exam_seating.index', ['exam_id' => $exam->id]));
        $response->assertOk();
        $response->assertSee('Not generated');
    }

    /* ---------------- lifecycle ---------------- */

    protected function publishedWorld($tag = 'life')
    {
        [$exam, $class, $section, $subject, $records, $sitting] = $this->world(3, $tag);
        $room = $this->makeRoom('F3 Life Hall ' . $tag, 30);
        $this->generateViaHttp($exam, [$room->id])->assertRedirect();
        $plan = SeatingPlan::where('exam_id', $exam->id)->firstOrFail();

        return [$exam, $plan, $room, $sitting];
    }

    public function test_lifecycle_admin_generates_and_submits_principal_alone_publishes()
    {
        [$exam, $plan] = $this->publishedWorld('life1');
        $this->assertSame(SeatingPlan::DRAFT, $plan->status);

        // Principal cannot submit (submit is teamSAWrite = Admin only).
        $this->actingAs($this->principal())->post(route('exam_seating.submit', $plan->id));
        $this->assertSame(SeatingPlan::DRAFT, $plan->fresh()->status);

        // Admin cannot publish.
        $this->actingAs($this->admin())->post(route('exam_seating.approve'), ['plan_id' => $plan->id]);
        $this->assertSame(SeatingPlan::DRAFT, $plan->fresh()->status);

        // Admin submits.
        $this->actingAs($this->admin())->post(route('exam_seating.submit', $plan->id))
            ->assertSessionHas('flash_success');
        $this->assertSame(SeatingPlan::SUBMITTED, $plan->fresh()->status);
        $this->assertNotNull($plan->fresh()->submitted_at);

        // Teacher cannot publish either.
        $this->actingAs($this->teacher())->post(route('exam_seating.approve'), ['plan_id' => $plan->id]);
        $this->assertSame(SeatingPlan::SUBMITTED, $plan->fresh()->status);

        // Principal publishes.
        $this->actingAs($this->principal())->post(route('exam_seating.approve'), ['plan_id' => $plan->id])
            ->assertSessionHas('flash_success');
        $plan->refresh();
        $this->assertSame(SeatingPlan::PUBLISHED, $plan->status);
        $this->assertSame($this->principal()->id, (int) $plan->approved_by);
    }

    public function test_super_admin_cannot_generate_or_submit()
    {
        [$exam] = $this->world(2, 'sa');
        $room = $this->makeRoom('F3 SA Hall', 30);

        $this->actingAs($this->superAdmin())->post(route('exam_seating.generate'), [
            'exam_id' => $exam->id, 'exam_date' => '2026-12-10', 'time_key' => '09:00',
            'mode' => 'separate', 'room_ids' => [$room->id],
        ]);
        $this->assertSame(0, SeatingPlan::count(), 'Super Admin is view-only inside a school — no seating writes');
    }

    /* ONE-TIME GENERATION (owner standing rule 2026-11-08) —
       re-expressed 2026-11-08: this pin used to assert the old
       doctrine "regenerating a published plan returns it to
       draft". That silent revert is exactly what the rule bans:
       a published plan now REFUSES regeneration (plan + seats
       untouched) until the Principal returns it for correction,
       after which regeneration works again as a draft. */
    public function test_regeneration_of_published_plan_is_refused_until_principal_returns_it()
    {
        [$exam, $plan, $room] = $this->publishedWorld('life2');
        $this->actingAs($this->admin())->post(route('exam_seating.submit', $plan->id));
        $this->actingAs($this->principal())->post(route('exam_seating.approve'), ['plan_id' => $plan->id]);
        $this->assertSame(SeatingPlan::PUBLISHED, $plan->fresh()->status);
        $seatsBefore = \App\Models\SeatingSeat::where('plan_id', $plan->id)->count();

        // Regeneration is refused: status, stamps and seats stand.
        $this->generateViaHttp($exam, [$room->id])->assertSessionHas('flash_danger');
        $plan->refresh();
        $this->assertSame(SeatingPlan::PUBLISHED, $plan->status, 'A published plan can no longer be regenerated over');
        $this->assertNotNull($plan->approved_by);
        $this->assertSame($seatsBefore, \App\Models\SeatingSeat::where('plan_id', $plan->id)->count());

        // The Principal return is the only way back — audited.
        $this->actingAs($this->principal())->post(route('exam_seating.return'), ['plan_id' => $plan->id])
            ->assertSessionHas('flash_success');
        $plan->refresh();
        $this->assertSame(SeatingPlan::DRAFT, $plan->status);
        $this->assertNull($plan->approved_by);
        $this->assertNull($plan->submitted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.seating_returned']);

        // And now regeneration lands again, as a draft.
        $this->generateViaHttp($exam, [$room->id])->assertRedirect();
        $this->assertSame(SeatingPlan::DRAFT, $plan->fresh()->status);
    }

    public function test_principal_approvals_queue_lists_submitted_only()
    {
        [, $plan] = $this->publishedWorld('life3');
        $this->actingAs($this->admin())->post(route('exam_seating.submit', $plan->id));

        $response = $this->actingAs($this->principal())->get(route('exam_seating.approvals'));
        $response->assertOk();
        $response->assertSee('Publish');

        // Admin is bounced off the principal queue.
        $this->actingAs($this->admin())->get(route('exam_seating.approvals'))
            ->assertRedirect(route('dashboard'));
    }

    /* ---------------- snapshot & staleness ---------------- */

    public function test_snapshot_immutable_after_roster_edit()
    {
        [$exam, $class, $section, $subject, $records, $sitting] = $this->world(2, 'snap');
        $room = $this->makeRoom('F3 Snap Hall', 30);
        [$ok, , $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok);
        $before = SeatingSeat::where('plan_id', $plan->id)->orderBy('seat_no')->first();
        $originalName = $before->student_name;

        // Roster edit AFTER generation: rename the student + change adm no.
        $user = User::find($records[0]->user_id);
        $user->update(['name' => 'Renamed After Generation']);
        $records[0]->update(['adm_no' => 'ZZZ-999']);

        $after = SeatingSeat::where('plan_id', $plan->id)->orderBy('seat_no')->first();
        $this->assertSame($originalName, $after->student_name, 'Printed sheet must not silently rewrite');
        $this->assertNotSame('ZZZ-999', $after->adm_no);
    }

    public function test_source_hash_changes_when_inputs_change_and_blocks_submit()
    {
        [$exam, $class, $section, $subject, $records, $sitting] = $this->world(2, 'stale');
        $room = $this->makeRoom('F3 Stale Hall', 30);
        [$ok, , $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok);
        $this->assertFalse(SeatingPlanner::isStale($plan));

        // A new student joins the class -> inputs changed.
        $this->makeStudent($class, $section, 'stale_new', '7777');
        $this->assertTrue(SeatingPlanner::isStale($plan->fresh()), 'Hash must change when the roster changes');

        // And a stale plan cannot be submitted.
        $this->actingAs($this->admin())->post(route('exam_seating.submit', $plan->id))
            ->assertSessionHas('flash_danger');
        $this->assertSame(SeatingPlan::DRAFT, $plan->fresh()->status);

        // Room capacity change also changes the fingerprint.
        [$ok2, , $plan2] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok2);
        $this->assertFalse(SeatingPlanner::isStale($plan2->fresh()));
        $room->update(['capacity' => 31]);
        $this->assertTrue(SeatingPlanner::isStale($plan2->fresh()), 'Hash must change when a hall changes');
    }

    /* ---------------- teacher surface ---------------- */

    public function test_teacher_sees_only_own_published_hall_sheets()
    {
        [$exam, $class, $section, $subject, $records, $sitting] = $this->world(3, 'teach');
        $room = $this->makeRoom('F3 Teach Hall', 30);
        $otherTeacher = $this->makeUser('teacher', 'teach_other');
        ExamDuty::create(['exam_date_id' => $sitting->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);
        $dutiesBefore = ExamDuty::count();

        [$ok, , $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok);

        // Draft: invisible to the teacher.
        $response = $this->actingAs($this->teacher())->get(route('exam_seating.my_halls'));
        $response->assertOk();
        $response->assertSee('No published hall sheets');
        $response->assertDontSee('F3 Teach Hall');

        // Publish, then the invigilating teacher sees the sheet…
        $this->actingAs($this->admin())->post(route('exam_seating.submit', $plan->id));
        $this->actingAs($this->principal())->post(route('exam_seating.approve'), ['plan_id' => $plan->id]);
        $response = $this->actingAs($this->teacher())->get(route('exam_seating.my_halls'));
        $response->assertSee('F3 Teach Hall');
        $this->actingAs($this->teacher())
            ->get(route('exam_seating.my_hall_sheet', ['plan_id' => $plan->id, 'room_id' => $room->id]))
            ->assertOk()->assertSee('Exam Hall Sheet')->assertSee($this->teacher()->name);

        // …but another teacher (no duty on this sitting) does not.
        $response = $this->actingAs($otherTeacher)->get(route('exam_seating.my_halls'));
        $response->assertDontSee('F3 Teach Hall');
        $this->actingAs($otherTeacher)
            ->get(route('exam_seating.my_hall_sheet', ['plan_id' => $plan->id, 'room_id' => $room->id]))
            ->assertRedirect(route('exam_seating.my_halls'));

        // Teachers cannot reach the office surfaces.
        $this->actingAs($this->teacher())->get(route('exam_seating.index'))->assertRedirect();
        $this->actingAs($this->teacher())->get(route('exam_seating.show', $plan->id))
            ->assertRedirect(route('dashboard'));

        // Students have no surface at all.
        $studentUser = User::find($records[0]->user_id);
        $this->actingAs($studentUser)->get(route('exam_seating.my_halls'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($dutiesBefore, ExamDuty::count());
    }

    /* ---------------- hall sheet + PDF + duties read-only ---------------- */

    public function test_hall_sheet_pdf_renders_with_branding_and_invigilator()
    {
        [$exam, $class, $section, $subject, $records, $sitting] = $this->world(3, 'pdf');
        $room = $this->makeRoom('F3 PDF Hall', 30);
        ExamDuty::create(['exam_date_id' => $sitting->id, 'teacher_id' => $this->teacher()->id, 'assigned_by' => $this->admin()->id]);
        $dutiesBefore = ExamDuty::count();

        [$ok, , $plan] = SeatingPlanner::generate($exam->id, '2026-12-10', '09:00', [$room->id], 'separate', $this->admin()->id);
        $this->assertTrue($ok);

        // Screen hall sheet: branding + invigilator label + students.
        $screen = $this->actingAs($this->admin())
            ->get(route('exam_seating.hall_sheet', ['plan_id' => $plan->id, 'room_id' => $room->id]));
        $screen->assertOk();
        $screen->assertSee('Hackstra Academy');
        $screen->assertSee('Exam Hall Sheet');
        $screen->assertSee($this->teacher()->name);
        $screen->assertSee('F3 PDF Hall');

        // PDF: real bytes, parses as PDF, carries the same content.
        $pdf = $this->actingAs($this->admin())
            ->get(route('exam_seating.hall_sheet_pdf', ['plan_id' => $plan->id, 'room_id' => $room->id]));
        $pdf->assertOk();
        $bytes = $pdf->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $tmp = tempnam(sys_get_temp_dir(), 'f3hall') . '.pdf';
        file_put_contents($tmp, $bytes);
        $text = shell_exec('pdftotext ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        unlink($tmp);
        $this->assertStringContainsString('HACKSTRA ACADEMY', strtoupper((string) $text));
        $this->assertStringContainsString('F3 PDF Hall', (string) $text);
        $this->assertStringContainsString($this->teacher()->name, (string) $text);

        // Generating + rendering seating NEVER writes duties.
        $this->assertSame($dutiesBefore, ExamDuty::count(), 'Seating must never write exam_duties');
    }

    public function test_hall_sheet_without_invigilator_prints_dash_not_a_guess()
    {
        [$exam, $plan, $room] = $this->publishedWorld('noduty');
        $halls = SeatingPlanner::hallsForPlan($plan);
        $this->assertSame('—', $halls[0]['invigilator_label']);
        $screen = $this->actingAs($this->admin())
            ->get(route('exam_seating.hall_sheet', ['plan_id' => $plan->id, 'room_id' => $room->id]));
        $screen->assertOk();
        $screen->assertSeeText('Invigilator: —');
    }

    public function test_room_delete_guarded_by_seating_reference()
    {
        [$exam, $plan, $room] = $this->publishedWorld('roomguard');
        $response = $this->actingAs($this->admin())->delete(route('rooms.destroy', $room->id));
        $response->assertSessionHas('flash_danger');
        $this->assertNotNull(Room::find($room->id), 'A room used by a seating plan cannot be deleted');

        $free = $this->makeRoom('F3 Free Hall', 10);
        $this->actingAs($this->admin())->delete(route('rooms.destroy', $free->id))
            ->assertSessionHas('flash_success');
        $this->assertNull(Room::find($free->id));
    }

    public function test_generate_via_http_capacity_refusal_keeps_zero_rows()
    {
        [$exam] = $this->world(4, 'httpcap');
        $room = $this->makeRoom('F3 Http Tiny', 2);
        $this->generateViaHttp($exam, [$room->id])->assertSessionHas('flash_danger');
        $this->assertSame(0, SeatingPlan::count());
        $this->assertSame(0, SeatingSeat::count());
    }

    public function test_show_page_renders_halls_and_students()
    {
        [$exam, $plan] = $this->publishedWorld('showpg');
        $response = $this->actingAs($this->admin())->get(route('exam_seating.show', $plan->id));
        $response->assertOk();
        $response->assertSee('F3 Life Hall showpg');
        $response->assertSee('hk-std-seating');
        $response->assertSee('Submit for Principal Approval');
    }
}
