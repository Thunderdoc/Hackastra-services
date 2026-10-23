<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\Allocation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-23 remaining-work round:
 * the TT-02 residual (slot edits must reach placed periods), true
 * subject vacancies (nullable teacher + Allocation surfacing) and
 * the student self-service portal's server-side scoping.
 *
 * Same discipline as ProgramEnginesTest: DatabaseTransactions,
 * self-created probe fixtures only.
 */
class ProgramFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(string $type, string $tag): User
    {
        return User::create([
            'name' => 'Probe ' . $tag,
            'email' => 'probe_' . $tag . '@example.test',
            'username' => 'probe_' . $tag,
            'password' => Hash::make('probe-pass'),
            'user_type' => $type,
            'code' => 'PROBE-' . strtoupper($tag),
        ]);
    }

    /* ------------- TT-02 residual: slot edit syncs cells ------------- */

    public function test_slot_time_edit_recomposes_placed_cell_timestamps_preserving_dates(): void
    {
        $admin = $this->makeUser('admin', 'slot_admin');
        $record = TimeTableRecord::create([
            'name' => 'Probe TT Sync', 'my_class_id' => 1, 'year' => '2098-2099',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id, 'timestamp_from' => '2098-09-03 08:00:00',
            'timestamp_to' => '2098-09-03 08:40:00', 'full' => '8:00 AM - 8:40 AM',
            'time_from' => '8:00 AM', 'time_to' => '8:40 AM',
            'hour_from' => '8', 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => '8', 'min_to' => '40', 'meridian_to' => 'AM',
        ]);
        // A placed period on a LATER date than the slot's base date:
        // the sync must move only the time-of-day, never the date.
        $cell = TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot->id, 'subject_id' => null,
            'day' => 'Wednesday', 'timestamp_from' => '2098-09-05 08:00:00',
            'timestamp_to' => '2098-09-05 08:40:00',
        ]);

        $response = $this->actingAs($admin)->put(route('ts.update', $slot->id), [
            'ttr_id' => $record->id,
            'hour_from' => '9', 'min_from' => '15', 'meridian_from' => 'AM',
            'hour_to' => '9', 'min_to' => '55', 'meridian_to' => 'AM',
        ]);
        $response->assertRedirect(route('ttr.manage', $record->id));

        $this->assertSame('9:15 AM - 9:55 AM', $slot->fresh()->full);
        $cell = $cell->fresh();
        $this->assertSame('2098-09-05 09:15', date('Y-m-d H:i', (int) $cell->timestamp_from));
        $this->assertSame('2098-09-05 09:55', date('Y-m-d H:i', (int) $cell->timestamp_to));
    }

    /* ------------- Vacancies: nullable teacher + Allocation ------------- */

    public function test_vacant_subject_is_stored_and_flagged_by_allocation(): void
    {
        $subject = Subject::create([
            'name' => 'Probe Vacant Subject', 'slug' => 'probe-vacant-subject',
            'my_class_id' => 1, 'teacher_id' => null, 'periods_per_week' => 4,
        ]);
        $this->assertNull($subject->fresh()->teacher_id);
        $this->assertNull($subject->fresh()->teacher);

        $rows = collect(Allocation::recommendations())
            ->where('issue', 'unassigned')
            ->filter(function ($row) use ($subject) {
                return (int) $row['subject']->id === (int) $subject->id;
            });
        $this->assertCount(1, $rows, 'A teacher-less subject must surface as an unassigned problem.');
    }

    /* ------------- Student portal scoping ------------- */

    private function makeStudentWithFees(string $tag): array
    {
        $student = $this->makeUser('student', $tag);
        StudentRecord::create([
            'user_id' => $student->id, 'my_class_id' => 1, 'section_id' => 1,
            'adm_no' => 'PROBE/' . strtoupper($tag), 'session' => '2018-2019', 'year_admitted' => '2018',
        ]);
        $payment = Payment::create([
            'title' => 'Probe Portal Fee', 'amount' => 5000, 'my_class_id' => 1,
            'description' => 'probe', 'year' => '2018-2019', 'ref_no' => 'PROBE-PORTAL-' . strtoupper($tag), 'method' => 'Cash',
        ]);
        $record = PaymentRecord::create([
            'payment_id' => $payment->id, 'student_id' => $student->id,
            'amt_paid' => 5000, 'balance' => 0, 'paid' => 1, 'year' => '2018-2019',
        ]);
        Receipt::create([
            'pr_id' => $record->id, 'year' => '2018-2019', 'balance' => 0,
            'amt_paid' => 5000, 'method' => 'Cash',
        ]);

        return [$student, $record];
    }

    public function test_student_sees_own_fees_and_own_receipt_only(): void
    {
        [$student, $ownRecord] = $this->makeStudentWithFees('portal_a');
        [, $foreignRecord] = $this->makeStudentWithFees('portal_b');

        $this->actingAs($student)->get(route('student.fees'))->assertOk();
        $this->actingAs($student)->get(route('student.library'))->assertOk();
        $this->actingAs($student)->get(route('student.events'))->assertOk();
        $this->actingAs($student)->get(route('student.fees.receipt', $ownRecord->id))->assertOk();
        // Another student's receipt: the PaymentRecord policy refuses.
        $this->actingAs($student)->get(route('student.fees.receipt', $foreignRecord->id))->assertForbidden();
    }

    public function test_non_student_is_redirected_from_student_portal(): void
    {
        $teacher = $this->makeUser('teacher', 'portal_t');
        $this->actingAs($teacher)->get(route('student.fees'))
            ->assertRedirect(route('login'));
    }
}
