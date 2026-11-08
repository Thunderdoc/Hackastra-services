<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * ONE-TIME GENERATION (owner standing rule 2026-11-08): exam date
 * sheets, timetables, hall tickets / admit cards are one-time
 * organisational artefacts — generate ONCE, review, send to the
 * Principal, the Principal publishes. After send/publish, casual
 * regeneration is LOCKED; corrections go through the audited
 * Principal return path. Draft-stage iteration stays free.
 *
 * Pins here:
 *  - date sheets: draft save/generate free; SUBMITTED and
 *    PUBLISHED sheets refuse save + generate with the dates and
 *    the state untouched; the Principal's return (the only way
 *    back) reopens the sheet and is audit-logged.
 *  - timetables: a draft record regenerates in place; a
 *    human-signed SUBMITTED or APPROVED record refuses
 *    regeneration (grid byte-identical afterwards), refuses
 *    delete and record edits; the Principal's send-back of an
 *    APPROVED record reopens it (audited, submitter belled);
 *    grandfathered seeded records (approved by default, no human
 *    approver) are NOT locked.
 */
class OwnerOneTimeGenerationTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    /* ------------------------- date sheets ------------------------- */

    /** One dated row per class-1 subject for exam 1; no state row (draft). */
    protected function seedSheet()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 1)->delete();
        ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 1)->delete();
        $day = 1;
        foreach (Subject::where('my_class_id', 1)->orderBy('id')->get() as $subject) {
            ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => 1,
                'subject_id' => $subject->id,
                'exam_date' => '2027-02-' . str_pad($day, 2, '0', STR_PAD_LEFT),
                'start_time' => '09:00:00',
            ]);
            $day++;
        }
    }

    protected function sheetDates()
    {
        return ExamDate::where('exam_id', 1)->where('my_class_id', 1)
            ->pluck('exam_date', 'subject_id')->all();
    }

    protected function sheetState()
    {
        return ExamDateSheetState::where('exam_id', 1)->where('my_class_id', 1)->first();
    }

    protected function generateSheet()
    {
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'one',
            'class_id' => 1,
            'start_date' => '2027-03-01',
            'exam_days' => 6,
            'papers_per_day' => 1,
            'session1_time' => '09:00',
            'mode' => 'replace',
        ]);
    }

    protected function saveSheet()
    {
        $firstSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        return $this->actingAs($this->admin())->post(route('exam_dates.save'), [
            'exam_id' => 1,
            'class_id' => 1,
            'dates' => [$firstSubject->id => ['exam_date' => '2027-03-05', 'start_time' => '09:00']],
        ]);
    }

    public function test_draft_date_sheet_saves_and_regenerates_freely()
    {
        $this->seedSheet();

        $this->saveSheet()->assertRedirect()->assertSessionHas('flash_success');
        $dates = $this->sheetDates();
        $firstSubject = Subject::where('my_class_id', 1)->orderBy('id')->firstOrFail();
        $this->assertSame('2027-03-05', (string) $dates[$firstSubject->id]);

        $this->generateSheet()->assertRedirect();
        $this->assertNotEmpty($this->sheetDates());
        $this->assertSame('2027-03-01', (string) collect($this->sheetDates())->min());
        $this->assertNull($this->sheetState()); // still a draft — no state row
    }

    public function test_submitted_date_sheet_refuses_save_and_generate()
    {
        $this->seedSheet();
        $this->actingAs($this->admin())->post(route('exam_dates.submit'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect();
        $this->assertSame('submitted', $this->sheetState()->status);
        $before = $this->sheetDates();

        $this->saveSheet()->assertRedirect()->assertSessionHas('flash_danger');
        $this->assertEquals($before, $this->sheetDates());
        $this->assertSame('submitted', $this->sheetState()->status);

        $this->generateSheet()->assertRedirect();
        $this->assertEquals($before, $this->sheetDates());
        $this->assertSame('submitted', $this->sheetState()->status);
    }

    public function test_published_date_sheet_refuses_save_and_generate()
    {
        $this->seedSheet();
        $this->actingAs($this->admin())->post(route('exam_dates.submit'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect();
        $this->actingAs($this->principal())->post(route('exam_dates.approve'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect();
        $this->assertSame('published', $this->sheetState()->status);
        $before = $this->sheetDates();

        $this->saveSheet()->assertRedirect()->assertSessionHas('flash_danger');
        $this->generateSheet()->assertRedirect();
        $this->assertEquals($before, $this->sheetDates());
        $this->assertSame('published', $this->sheetState()->status);
    }

    public function test_principal_return_reopens_sheet_and_is_audited()
    {
        $this->seedSheet();
        $this->actingAs($this->admin())->post(route('exam_dates.submit'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect();
        $this->actingAs($this->principal())->post(route('exam_dates.approve'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect();

        // A non-principal cannot return it.
        $this->actingAs($this->admin())->post(route('exam_dates.return'), ['exam_id' => 1, 'class_id' => 1]);
        $this->assertSame('published', $this->sheetState()->status);

        // The Principal returns it: draft again, signature fields
        // cleared, and the return is on the audit record.
        $this->actingAs($this->principal())->post(route('exam_dates.return'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect()->assertSessionHas('flash_success');
        $state = $this->sheetState();
        $this->assertSame('draft', $state->status);
        $this->assertNull($state->submitted_by);
        $this->assertNull($state->approved_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exams.date_sheet_returned']);

        // And the Admin can correct the draft again.
        $this->saveSheet()->assertRedirect()->assertSessionHas('flash_success');

        // Returning a draft is refused — there is nothing to return.
        $this->actingAs($this->principal())->post(route('exam_dates.return'), ['exam_id' => 1, 'class_id' => 1])
            ->assertRedirect()->assertSessionHas('flash_danger');
    }

    /* ------------------------- timetables ------------------------- */

    protected function generateTimetable($classId, $sectionId = null, $mode = 'replace')
    {
        return $this->actingAs($this->admin())->post(route('tt.generate'), [
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'one',
            'class_id' => $classId,
            'section_id' => $sectionId,
            'mode' => $mode,
        ]);
    }

    /** Cell ids are the grid's fingerprint: an in-place regenerate replaces them. */
    protected function cellIds(TimeTableRecord $record)
    {
        return TimeTable::where('ttr_id', $record->id)->orderBy('id')->pluck('id')->all();
    }

    /**
     * A self-contained grid with NO history: a fresh class with
     * three taught subjects, generated once by the real generator.
     * (Every SEEDED record carries attendance or substitutions on
     * its cells, so a seeded fixture could never separate the
     * approval lock from the attendance lock.)
     */
    protected function freshGrid($tag)
    {
        $class = \App\Models\MyClass::create(['name' => 'OneTime Probe ' . $tag]);
        $mkTeacher = function ($code) {
            return User::create([
                'name' => 'OneTime Teacher ' . $code,
                'email' => 'onetime-' . strtolower($code) . '@test.local',
                'password' => bcrypt('teacher'),
                'user_type' => 'teacher',
                'code' => $code,
            ]);
        };
        $t1 = $mkTeacher('OT1' . $tag);
        $t2 = $mkTeacher('OT2' . $tag);
        foreach ([['Alpha', $t1->id], ['Beta', $t2->id], ['Gamma', $t1->id]] as [$name, $tid]) {
            \App\Models\Subject::create([
                'name' => 'OneTime ' . $name . ' ' . $tag,
                'slug' => 'onetime-' . strtolower($name) . '-' . strtolower($tag),
                'my_class_id' => $class->id,
                'teacher_id' => $tid,
                'periods_per_week' => 4,
            ]);
        }
        $this->generateTimetable($class->id)->assertRedirect();
        $record = TimeTableRecord::where('my_class_id', $class->id)
            ->whereNull('exam_id')->where('year', '2026-2027')->firstOrFail();
        $this->assertSame('draft', $record->approval_status);
        $this->assertNotEmpty($this->cellIds($record), 'fixture: generated grid must have cells');
        return $record;
    }

    protected function submitAndApprove(TimeTableRecord $record)
    {
        $this->actingAs($this->admin())->post(route('ttr.submit_approval', $record->id))->assertRedirect();
        $this->assertSame('submitted', $record->fresh()->approval_status);
        $this->actingAs($this->principal())->post(route('ttr.approve', $record->id))->assertRedirect();
        $this->assertSame('approved', $record->fresh()->approval_status);
        $this->assertNotNull($record->fresh()->approved_by);
        return $record->fresh();
    }

    public function test_draft_timetable_regenerates_in_place()
    {
        $record = $this->freshGrid('DR');
        $idsBefore = $this->cellIds($record);

        $this->generateTimetable($record->my_class_id)->assertRedirect();

        $record = $record->fresh();
        $this->assertSame('draft', $record->approval_status);
        $idsAfter = $this->cellIds($record);
        $this->assertNotEmpty($idsAfter);
        $this->assertNotEquals($idsBefore, $idsAfter); // rebuilt — new cells
    }

    public function test_approved_timetable_regenerate_is_refused_and_grid_untouched()
    {
        $record = $this->submitAndApprove($this->freshGrid('AP'));
        $idsBefore = $this->cellIds($record);

        $this->generateTimetable($record->my_class_id)->assertRedirect();

        $record = $record->fresh();
        $this->assertSame('approved', $record->approval_status);
        $this->assertNotNull($record->approved_by);
        $this->assertSame($idsBefore, $this->cellIds($record)); // grid byte-identical
    }

    public function test_submitted_timetable_regenerate_is_refused()
    {
        $record = $this->freshGrid('SB');
        $this->actingAs($this->admin())->post(route('ttr.submit_approval', $record->id))->assertRedirect();
        $this->assertSame('submitted', $record->fresh()->approval_status);
        $idsBefore = $this->cellIds($record);

        $this->generateTimetable($record->my_class_id)->assertRedirect();

        $record = $record->fresh();
        $this->assertSame('submitted', $record->approval_status);
        $this->assertSame($idsBefore, $this->cellIds($record));
    }

    public function test_approved_timetable_refuses_delete_and_record_edit()
    {
        $record = $this->submitAndApprove($this->freshGrid('DL'));

        $this->actingAs($this->admin())->delete(route('ttr.destroy', $record->id))
            ->assertRedirect()->assertSessionHas('flash_danger');
        $this->assertNotNull(TimeTableRecord::find($record->id));

        $this->actingAs($this->admin())->put(route('ttr.update', $record->id), [
            'name' => $record->name,
            'my_class_id' => $record->my_class_id,
            'section_id' => $record->section_id,
        ])->assertRedirect()->assertSessionHas('flash_danger');
        $this->assertSame('approved', $record->fresh()->approval_status);
    }

    public function test_principal_send_back_of_approved_record_reopens_it()
    {
        $record = $this->submitAndApprove($this->freshGrid('BK'));
        $adminId = $this->admin()->id;

        $this->actingAs($this->principal())->post(route('ttr.send_back', $record->id))
            ->assertRedirect()->assertSessionHas('flash_success');

        $record = $record->fresh();
        $this->assertSame('draft', $record->approval_status);
        $this->assertNull($record->approved_by);
        $this->assertNull($record->approval_submitted_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'timetable.sent_back']);
        $this->assertTrue(\App\Models\UserNotification::where('user_id', $adminId)
            ->where('type', 'timetable_sent_back')->exists());

        // As a draft it regenerates freely again.
        $idsBefore = $this->cellIds($record);
        $this->generateTimetable($record->my_class_id)->assertRedirect();
        $this->assertNotEquals($idsBefore, $this->cellIds($record->fresh()));
    }

    public function test_grandfathered_record_is_not_locked()
    {
        // The grandfathered DB state, set directly on a fresh
        // grid: approved status with NO human submitter/approver
        // (exactly how the seeded records carry it, from the
        // migration default). The lock must not bite — the record
        // regenerates and resets to draft, as before the rule.
        $record = $this->freshGrid('GF');
        TimeTableRecord::where('id', $record->id)->update([
            'approval_status' => 'approved',
            'approval_submitted_by' => null,
            'approval_submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);
        $idsBefore = $this->cellIds($record);

        $this->generateTimetable($record->my_class_id)->assertRedirect();

        $record = $record->fresh();
        $this->assertSame('draft', $record->approval_status);
        $this->assertNotEquals($idsBefore, $this->cellIds($record));
    }
}
