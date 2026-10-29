<?php

namespace Tests\Feature;

use App\Models\TimeTableRecord;
use App\Models\UserNotification;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Timetable approval flow (owner order 2026-10-29).
 *
 * The Admin generates a timetable and reviews it (manage page);
 * nothing is in force at that point. The Admin OKs it (submit) —
 * refused while the validator reports problems — and the
 * Principal's approval is what puts it in force for teachers,
 * students and attendance. Existing records are grandfathered
 * 'approved' by the migration default.
 */
class OwnerTimetableApprovalTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function setStatus($recordId, $status)
    {
        TimeTableRecord::where('id', $recordId)->update(['approval_status' => $status]);
    }

    public function test_new_record_starts_as_draft_and_is_hidden_from_teachers()
    {
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => 'Approval Probe Grid', 'my_class_id' => 13, 'section_id' => 68,
        ])->assertOk();
        $record = TimeTableRecord::where('name', 'Approval Probe Grid')->firstOrFail();
        $this->assertSame('draft', $record->approval_status);

        // Admin sees it (index + record page); the teacher does not.
        $this->actingAs($this->admin())->get(route('tt.index'))->assertSee('Approval Probe Grid');
        $this->actingAs($this->teacher())->get(route('tt.index'))->assertDontSee('Approval Probe Grid');
        $this->actingAs($this->teacher())->get(route('ttr.show', $record->id))->assertForbidden();
        $this->actingAs($this->admin())->get(route('ttr.show', $record->id))->assertOk();

        // The manage page is the review step and says so.
        $page = $this->actingAs($this->admin())->get(route('ttr.manage', $record->id));
        $page->assertOk();
        $page->assertSee('Draft — not in force yet');
        $page->assertSee('OK — Send to Principal for Approval');
    }

    public function test_submit_approve_and_send_back_chain()
    {
        $cleanIds = TimetableValidator::cleanRecordIds('2026-2027');
        $this->assertNotEmpty($cleanIds, 'fixture: seed must contain a validator-clean record');
        $record = TimeTableRecord::findOrFail($cleanIds[0]);
        $this->setStatus($record->id, 'draft');

        $admin = $this->admin();
        $principal = $this->principal();

        // Admin OKs it — the Principal is belled and the queue fills.
        $this->actingAs($admin)->post(route('ttr.submit_approval', $record->id))
            ->assertRedirect();
        $this->assertSame('submitted', $record->fresh()->approval_status);
        $this->assertTrue(UserNotification::where('user_id', $principal->id)
            ->where('type', 'timetable_submitted')->exists());
        $queue = $this->actingAs($principal)->get(route('tt.approvals'));
        $queue->assertOk();
        $queue->assertSee($record->name);

        // The Admin cannot approve his own submission.
        $this->actingAs($admin)->post(route('ttr.approve', $record->id))->assertForbidden();
        $this->assertSame('submitted', $record->fresh()->approval_status);

        // The Principal sends it back once — draft again, Admin belled.
        $this->actingAs($principal)->post(route('ttr.send_back', $record->id))
            ->assertRedirect();
        $this->assertSame('draft', $record->fresh()->approval_status);
        $this->assertTrue(UserNotification::where('user_id', $admin->id)
            ->where('type', 'timetable_sent_back')->exists());

        // Resubmit, Principal approves — in force, Admin belled.
        $this->actingAs($admin)->post(route('ttr.submit_approval', $record->id))
            ->assertRedirect();
        $this->actingAs($principal)->post(route('ttr.approve', $record->id))
            ->assertRedirect();
        $record = $record->fresh();
        $this->assertSame('approved', $record->approval_status);
        $this->assertSame((int) $principal->id, (int) $record->approved_by);
        $this->assertTrue(UserNotification::where('user_id', $admin->id)
            ->where('type', 'timetable_approved')->exists());

        // The queue is empty again (the empty-state text is the
        // precise signal — the record's name also lives in the
        // principal's bell history, so a bare name check would lie).
        $queue = $this->actingAs($principal)->get(route('tt.approvals'));
        $queue->assertSee('No timetables are waiting for approval');
    }

    public function test_submit_is_refused_while_validator_reports_errors()
    {
        // Construct a broken record: a Class 11 grid holding a
        // Class 12 subject — a broken cell the validator must flag.
        $this->actingAs($this->admin())->post(route('ttr.store'), [
            'name' => 'Approval Broken Grid', 'my_class_id' => 12, 'section_id' => 55,
        ])->assertOk();
        $record = TimeTableRecord::where('name', 'Approval Broken Grid')->firstOrFail();
        $slot = \App\Models\TimeSlot::create([
            'ttr_id' => $record->id, 'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM', 'full' => '9:00 AM - 9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
        ]);
        \App\Models\TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot->id, 'day' => 'Monday', 'day_num' => 1,
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'subject_id' => 79,
        ]);
        $this->assertNotEmpty(TimetableValidator::report($record)['errors'],
            'fixture: the mismatched cell must produce validator errors');

        $this->actingAs($this->admin())->post(route('ttr.submit_approval', $record->id))
            ->assertRedirect();
        $this->assertSame('draft', $record->fresh()->approval_status);
        $this->assertNotNull(session('flash_danger'));
    }

    public function test_seeded_records_are_grandfathered_in_force()
    {
        $record = TimeTableRecord::where('year', '2026-2027')->firstOrFail();
        $this->assertSame('approved', $record->approval_status);
        $this->assertTrue($record->isInForce());
    }
}
