<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 45) — Manage-edit ->
 * duties FOLLOW chain + the P18 delete-without-cascade shape for
 * exam sittings (audit patterns P15 chain handoff, P18 delete
 * without cascade, P10 count-vs-rows).
 *
 * Code read first (quoted, not assumed):
 * - ExamDateController::save() clear branch deletes ONLY the
 *   ExamDate row ("An emptied date is the remove action"); no
 *   ExamDuty row and no duty_assigned bell is touched. The two
 *   ExamDate delete sites in the whole app are both in this
 *   controller: save()'s clear branch and generate()'s
 *   replace-mode orphan cleanup (which drops rows for subjects
 *   no longer in the class). Neither touches ExamDuty.
 * - ExamDutyController::myDuties() loads duties by teacher_id
 *   with NO join to exam_dates, and the my_duties blade is
 *   null-safe (every cell falls back to '—'), so an orphaned
 *   duty renders as a full dash row, never a 500. The duties
 *   INDEX, by contrast, keys duties by the exam's sitting ids,
 *   so the same orphan is invisible there — a P10 divergence:
 *   index says no duty, My Duties shows a (dateless) duty.
 * - Invigilation::assign()'s replacement cleanup is the
 *   precedent for bell hygiene: a replaced duty deletes exactly
 *   the old teacher's bell whose body matches the sitting.
 * - Generate stores start_time as 'HH:MM:00'; Manage save
 *   stores it as posted 'HH:MM'. hasClash() compares the
 *   strings exactly, so clash probes must reuse the stored
 *   format of the row they probe against.
 *
 * Fixtures: fresh classes/subjects/sections/students, all
 * in-transaction; exam 1 is the global Term 1 exam; fixture
 * students carry NO payment records, so the fee block never
 * masks the admit-card question (round-37 rule). myCard takes
 * a RAW user id; the assign route takes a raw teacher id
 * (Qs::resolveId passes numerics through, round-44 rule).
 */
class OwnerExamDateSheetR45Test extends TestCase
{
    use DatabaseTransactions;

    const VENUE = 'R45 Main Hall';

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name, 'class_type_id' => 4]);
        foreach ($subjects as $i => $subjectName) {
            Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => $this->admin()->id,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return $class;
    }

    protected function makeStudent($name, $classId, $sectionId)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'), 'username' => 'TEST/R45/' . strtoupper(Str::random(6)),
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'adm_no' => 'TEST/R45/' . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0,
        ]);
        return $user;
    }

    protected function generate($classId)
    {
        $payload = [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => $classId,
            'start_date' => '2027-03-01',
            'exam_days' => 3,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'skip_sunday' => '1',
            'mode' => 'replace',
            'venue' => self::VENUE,
        ];
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), $payload);
    }

    protected function autoAssign()
    {
        return $this->actingAs($this->admin())
            ->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
    }

    protected function assign($sittingId, $teacherId)
    {
        return $this->actingAs($this->admin())
            ->from(route('exam_duties.index', ['exam_id' => 1]))
            ->post(route('exam_duties.assign'), ['exam_date_id' => $sittingId, 'teacher_id' => $teacherId]);
    }

    protected function rowsFor($classId)
    {
        return ExamDate::where('exam_id', 1)->where('my_class_id', $classId)
            ->orderBy('exam_date')->orderBy('start_time')->get();
    }

    public function test_manage_move_moves_duty_admit_card_and_frees_old_slot()
    {
        $class = $this->makeClassWithSubjects('R45 Move Class', ['R45 Alpha', 'R45 Beta', 'R45 Gamma'], 'r45-move');
        $section = Section::create(['name' => 'R45-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $student = $this->makeStudent('R45 Move Pupil', $class->id, $section->id);
        $this->generate($class->id)->assertRedirect();

        // Round-29 sequence rule: subjects in NAME order, 2/day.
        $rows = $this->rowsFor($class->id);
        $this->assertCount(3, $rows);
        [$alpha, $beta, $gamma] = $rows->all();
        $this->assertSame('R45 Alpha', $alpha->subject->name);
        $this->assertSame('2027-03-01', $alpha->exam_date);
        $this->assertSame('09:00:00', $alpha->start_time);
        $this->assertSame('2027-03-01', $beta->exam_date);
        $this->assertSame('13:00:00', $beta->start_time);
        $this->assertSame('2027-03-02', $gamma->exam_date);

        // (a) Auto-assign fills all three sittings.
        $this->autoAssign()->assertRedirect()
            ->assertSessionHas('flash_success', 'Auto-assignment complete: 3 sitting(s) got an invigilator.');
        $alphaDuty = ExamDuty::where('exam_date_id', $alpha->id)->firstOrFail();
        $alphaTeacher = User::findOrFail($alphaDuty->teacher_id);
        $this->assertSame(3, ExamDuty::whereIn('exam_date_id', $rows->pluck('id'))->count());

        // (b) Manage MOVE: Alpha Mon 09:00 -> Wed 09:00, nothing else touched.
        $examName = Exam::find(1)->name;
        $this->actingAs($this->admin())
            ->post(route('exam_dates.save'), [
                'exam_id' => 1,
                'class_id' => $class->id,
                'dates' => [
                    $alpha->subject_id => ['exam_date' => '2027-03-03', 'start_time' => '09:00', 'note' => self::VENUE],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Date sheet saved for ' . $examName . ' — ' . $class->name . ': 1 date(s) set.');
        $moved = ExamDate::findOrFail($alpha->id);
        $this->assertSame('2027-03-03', $moved->exam_date);
        $this->assertSame('09:00', $moved->start_time);
        // Same row id (a move, not a delete+recreate): the duty follows it.
        $this->assertSame($alphaDuty->id, ExamDuty::where('exam_date_id', $alpha->id)->firstOrFail()->id);
        // Siblings byte-identical.
        $this->assertSame('2027-03-01', ExamDate::findOrFail($beta->id)->exam_date);
        $this->assertSame('13:00:00', ExamDate::findOrFail($beta->id)->start_time);
        $this->assertSame('2027-03-02', ExamDate::findOrFail($gamma->id)->exam_date);

        // Duties index + My Duties show the NEW slot for the same duty.
        $indexDuties = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()
            ->viewData('duties');
        $this->assertTrue($indexDuties->has($alpha->id));
        $this->assertSame('2027-03-03', $indexDuties[$alpha->id]->exam_date->exam_date);
        $myDuties = $this->actingAs($alphaTeacher)
            ->get(route('exam_duties.my'))->assertOk()->viewData('duties');
        $mine = $myDuties->firstWhere('exam_date_id', $alpha->id);
        $this->assertNotNull($mine);
        $this->assertSame('2027-03-03', $mine->exam_date->exam_date);

        // The moved paper's admit card shows the NEW slot.
        $this->actingAs($student)
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $student->id]))
            ->assertOk()->assertSee('R45 Alpha')->assertSee('Wed, 03 Mar 2027')->assertSee('9:00 AM');

        // (c) OLD slot freed: a new sitting at Mon 09:00 takes Alpha's teacher.
        $class2 = $this->makeClassWithSubjects('R45 Old Slot Class', ['R45 Old Slot Subject'], 'r45-old');
        $oldSlot = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => $class2->id,
            'subject_id' => Subject::where('my_class_id', $class2->id)->firstOrFail()->id,
            'exam_date' => '2027-03-01', 'start_time' => '09:00:00', 'note' => null,
        ]);
        $this->assign($oldSlot->id, $alphaTeacher->id)->assertRedirect()
            ->assertSessionHas('flash_success', 'Invigilator saved.');
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $oldSlot->id, 'teacher_id' => $alphaTeacher->id]);

        // NEW slot still occupied: the same teacher is clash-refused there.
        $class3 = $this->makeClassWithSubjects('R45 New Slot Class', ['R45 New Slot Subject'], 'r45-new');
        $newSlot = ExamDate::create([
            'exam_id' => 1, 'my_class_id' => $class3->id,
            'subject_id' => Subject::where('my_class_id', $class3->id)->firstOrFail()->id,
            'exam_date' => '2027-03-03', 'start_time' => '09:00', 'note' => null,
        ]);
        $this->assign($newSlot->id, $alphaTeacher->id)->assertRedirect()
            ->assertSessionHas('flash_danger', 'That teacher already has a duty at this date and time.');
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $newSlot->id, 'teacher_id' => $alphaTeacher->id]);
    }

    public function test_manage_clear_deletes_duty_and_bell_with_the_paper()
    {
        $class = $this->makeClassWithSubjects('R45 Clear Class', ['R45 Alpha', 'R45 Beta', 'R45 Gamma'], 'r45-clear');
        $this->generate($class->id)->assertRedirect();
        $this->autoAssign()->assertRedirect()
            ->assertSessionHas('flash_success', 'Auto-assignment complete: 3 sitting(s) got an invigilator.');
        $rows = $this->rowsFor($class->id);
        $gamma = $rows->firstWhere('subject.name', 'R45 Gamma');
        // Re-fetch with relations for the bell body (computed BEFORE the clear).
        $gamma = ExamDate::with(['my_class', 'subject'])->findOrFail($gamma->id);
        $gammaDuty = ExamDuty::where('exam_date_id', $gamma->id)->firstOrFail();
        $gammaTeacher = User::findOrFail($gammaDuty->teacher_id);
        $gammaBellBody = Invigilation::dutyBellBody($gamma);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $gammaTeacher->id, 'type' => 'duty_assigned', 'body' => $gammaBellBody,
        ]);

        // (d) Manage CLEAR: blank date = remove action (round-34 rule).
        $examName = Exam::find(1)->name;
        $this->actingAs($this->admin())
            ->post(route('exam_dates.save'), [
                'exam_id' => 1,
                'class_id' => $class->id,
                'dates' => [
                    $gamma->subject_id => ['exam_date' => '', 'start_time' => '', 'note' => ''],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Date sheet saved for ' . $examName . ' — ' . $class->name . ': 0 date(s) set, 1 cleared.');
        $this->assertDatabaseMissing('exam_dates', ['id' => $gamma->id]);
        // Siblings untouched.
        $this->assertSame(2, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());

        // P18: the duty and its bell must go WITH the paper — a
        // cleared paper has no sitting to invigilate, and a dash
        // row on My Duties plus a bell naming the cleared paper
        // are stale records, not history.
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $gamma->id]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $gammaTeacher->id, 'type' => 'duty_assigned', 'body' => $gammaBellBody,
        ]);
        $myDuties = $this->actingAs($gammaTeacher)
            ->get(route('exam_duties.my'))->assertOk()->viewData('duties');
        $this->assertNull($myDuties->firstWhere('exam_date_id', $gamma->id));
        $indexDuties = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()
            ->viewData('duties');
        $this->assertFalse($indexDuties->has($gamma->id));
    }

    public function test_generate_replace_removed_subject_deletes_its_duty_and_bell()
    {
        $class = $this->makeClassWithSubjects('R45 Replace Class', ['R45 Keep', 'R45 Drop'], 'r45-rep');
        $this->generate($class->id)->assertRedirect();
        $this->autoAssign()->assertRedirect()
            ->assertSessionHas('flash_success', 'Auto-assignment complete: 2 sitting(s) got an invigilator.');
        $rows = $this->rowsFor($class->id);
        $drop = ExamDate::with(['my_class', 'subject'])->findOrFail($rows->firstWhere('subject.name', 'R45 Drop')->id);
        $dropDuty = ExamDuty::where('exam_date_id', $drop->id)->firstOrFail();
        $dropTeacher = User::findOrFail($dropDuty->teacher_id);
        $dropBellBody = Invigilation::dutyBellBody($drop);

        // Remove the subject through the REAL subjects.destroy route.
        $this->actingAs($this->admin())
            ->from(route('subjects.index'))
            ->delete(route('subjects.destroy', $drop->subject_id))
            ->assertRedirect()->assertSessionHas('flash_success', 'Record Deleted Successfully');
        $this->assertDatabaseMissing('subjects', ['id' => $drop->subject_id]);

        // Regenerate replace: the orphan rule drops the Drop row.
        $this->generate($class->id)->assertRedirect();
        $this->assertDatabaseMissing('exam_dates', ['id' => $drop->id]);
        $this->assertSame(1, ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->count());

        // Same P18 shape as the Manage clear: duty + bell go with it.
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $drop->id]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $dropTeacher->id, 'type' => 'duty_assigned', 'body' => $dropBellBody,
        ]);
        $myDuties = $this->actingAs($dropTeacher)
            ->get(route('exam_duties.my'))->assertOk()->viewData('duties');
        $this->assertNull($myDuties->firstWhere('exam_date_id', $drop->id));
    }
}
