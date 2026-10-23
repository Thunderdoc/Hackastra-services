<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TeacherEligibility;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\LedgerCheck;
use App\Support\LibraryFines;
use App\Support\Staffing;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Program Phase 11 (2026-10-23) — regression tests for the engines
 * built during the audit program: the fee-ledger scanner (Phase 6),
 * admission-number minting (ADM-01), the eligibility pool
 * (Scheduling Slice A/C), the timetable clash validator (Slice E)
 * and the library fine rate (LIB-FINE).
 *
 * Every test runs inside a transaction that rolls back
 * (DatabaseTransactions), creating only its own probe fixtures, so
 * the suite is safe against the seeded development database and
 * against an empty migrated one alike. LedgerCheck::scan() reads
 * the WHOLE ledger by design, so its "consistent" assertion also
 * assumes the surrounding database carries no pre-existing drift —
 * true for the seeded demo and for CI's fresh database.
 */
class ProgramEnginesTest extends TestCase
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

    /* ---------------- LedgerCheck (Phase 6) ---------------- */

    public function test_ledger_scan_accepts_a_consistent_record(): void
    {
        $payment = Payment::create([
            'title' => 'Probe Fee', 'amount' => 1000, 'my_class_id' => 1,
            'description' => 'probe', 'year' => '2099-2100', 'ref_no' => 'PROBE-LED-1', 'method' => 'Cash',
        ]);
        PaymentRecord::create([
            'payment_id' => $payment->id, 'student_id' => $this->makeUser('student', 'led1')->id,
            'amt_paid' => 400, 'balance' => 600, 'paid' => 0, 'year' => '2099-2100',
        ]);

        $scan = LedgerCheck::scan();
        $this->assertTrue($scan['consistent'], 'Ledger should reconcile: ' . json_encode($scan['issues']));
    }

    public function test_ledger_scan_flags_a_corrupted_balance(): void
    {
        $payment = Payment::create([
            'title' => 'Probe Fee', 'amount' => 1000, 'my_class_id' => 1,
            'description' => 'probe', 'year' => '2099-2100', 'ref_no' => 'PROBE-LED-2', 'method' => 'Cash',
        ]);
        $record = PaymentRecord::create([
            'payment_id' => $payment->id, 'student_id' => $this->makeUser('student', 'led2')->id,
            'amt_paid' => 400, 'balance' => 999, 'paid' => 0, 'year' => '2099-2100',
        ]);

        $scan = LedgerCheck::scan();
        $this->assertFalse($scan['consistent']);
        $flat = json_encode($scan['issues']);
        $this->assertStringContainsString('Record ' . $record->id, $flat);
    }

    public function test_ledger_scan_flags_overpayment(): void
    {
        $payment = Payment::create([
            'title' => 'Probe Fee', 'amount' => 1000, 'my_class_id' => 1,
            'description' => 'probe', 'year' => '2099-2100', 'ref_no' => 'PROBE-LED-3', 'method' => 'Cash',
        ]);
        PaymentRecord::create([
            'payment_id' => $payment->id, 'student_id' => $this->makeUser('student', 'led3')->id,
            'amt_paid' => 1200, 'balance' => -200, 'paid' => 1, 'year' => '2099-2100',
        ]);

        $scan = LedgerCheck::scan();
        $labels = array_column($scan['issues'], 'label');
        $this->assertContains('Records paid beyond the fee amount', $labels);
    }

    /* ---------------- ADM-01: mintStudent ---------------- */

    public function test_mint_student_allocates_unique_numbers_back_to_back(): void
    {
        $mint = function (string $tag) {
            $user = $this->makeUser('student', $tag);

            return Qs::mintStudent(function ($admNo) use ($user) {
                return StudentRecord::create([
                    'user_id' => $user->id, 'my_class_id' => 1, 'section_id' => 1,
                    'adm_no' => $admNo, 'session' => '2099-2100', 'year_admitted' => '2099',
                ]);
            }, 'PRI', '2099');
        };

        $a = $mint('mint_a');
        $b = $mint('mint_b');

        $this->assertNotSame($a->adm_no, $b->adm_no);
        $this->assertSame(1, StudentRecord::where('adm_no', $a->adm_no)->count());
        $this->assertSame(1, StudentRecord::where('adm_no', $b->adm_no)->count());
    }

    /* ---------------- Scheduling Slice A/C: eligibility ---------------- */

    public function test_eligibility_pool_unions_assigned_and_listed_teachers(): void
    {
        $assigned = $this->makeUser('teacher', 'elig_a');
        $listed = $this->makeUser('teacher', 'elig_b');
        $other = $this->makeUser('teacher', 'elig_c');

        Subject::create([
            'name' => 'Probe Subject XYZ', 'slug' => 'probe-subject-xyz', 'my_class_id' => 1, 'teacher_id' => $assigned->id,
        ]);
        TeacherEligibility::create(['teacher_id' => $listed->id, 'subject_name' => 'Probe Subject XYZ']);

        $pool = Staffing::eligibleTeacherIds('Probe Subject XYZ');
        $this->assertContains($assigned->id, $pool);
        $this->assertContains($listed->id, $pool);
        $this->assertNotContains($other->id, $pool);
    }

    /* ---------------- Scheduling Slice E: clash validator ---------------- */

    public function test_validator_detects_a_cross_record_teacher_clash(): void
    {
        $teacher = $this->makeUser('teacher', 'val_t');
        $year = '2098-2099';

        $mk = function (int $classId, string $subjectName) use ($teacher, $year) {
            $subject = Subject::create([
                'name' => $subjectName, 'slug' => Str::slug($subjectName), 'my_class_id' => $classId, 'teacher_id' => $teacher->id,
            ]);
            $record = TimeTableRecord::create([
                'name' => 'Probe TT ' . $subjectName, 'my_class_id' => $classId, 'year' => $year,
            ]);
            $slot = TimeSlot::create([
                'ttr_id' => $record->id, 'timestamp_from' => '2098-09-03 08:00:00',
                'timestamp_to' => '2098-09-03 08:45:00', 'full' => '8:00 AM - 8:45 AM',
                'time_from' => '8:00 AM', 'time_to' => '8:45 AM',
                'hour_from' => '8', 'min_from' => '00', 'meridian_from' => 'AM',
                'hour_to' => '8', 'min_to' => '45', 'meridian_to' => 'AM',
            ]);
            $cell = TimeTable::create([
                'ttr_id' => $record->id, 'ts_id' => $slot->id, 'subject_id' => $subject->id,
                'day' => 'Monday', 'timestamp_from' => '2098-09-03 08:00:00',
                'timestamp_to' => '2098-09-03 08:45:00',
            ]);
            $cell->day_num = 1;
            $cell->save();
        };

        $mk(1, 'Probe Clash Subject A');
        $mk(2, 'Probe Clash Subject B');

        $errors = TimetableValidator::globalErrors($year);
        $this->assertNotEmpty($errors, 'Same teacher, same day and time in two records must clash.');
        $this->assertStringContainsString($teacher->name, implode(' ', $errors));
    }

    /* ---------------- LIB-FINE: the daily rate ---------------- */

    public function test_library_fine_rate_defaults_to_ten_and_honours_the_setting(): void
    {
        Setting::where('type', 'library_fine_per_day')->delete();
        $this->assertSame(10, LibraryFines::dailyRate());

        Setting::create(['type' => 'library_fine_per_day', 'description' => '25']);
        $this->assertSame(25, LibraryFines::dailyRate());
    }
}
