<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\LeaveRequest;
use App\Models\MyClass;
use App\Models\UserNotification;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 24) — two seams never
 * probed before:
 *
 * (a) DATE-SHEET PRINT/PDF FOR A SCOPE WITH ZERO ROWS. Round 8
 * locked the NULL-scope redirect ("There is no date sheet
 * available.") for bookmarked print URLs in empty states (no
 * exams, teacher with no classes, parent with no children). This
 * is the different shape: scope resolves fine (exam + class
 * present) but the class has NO exam_dates rows at all. Two
 * sub-shapes: a class WITH subjects but zero dates (announced
 * = 0 note + every subject as "Not announced"), and a class
 * with ZERO subjects (the round-7 "No subjects are configured"
 * note). Both must render 200 on print and download on PDF —
 * a 500, or a sheet silently showing another class's dates,
 * would be a REAL finding.
 *
 * (b) AUTO-ASSIGN PARTIAL-LEAVE BOUNDARY. Round 5 proved leave
 * excludes a teacher from auto-assign; round 19 proved
 * fewest-first ordering. Neither proved the leave check is a
 * DATE-RANGE check rather than a leave-presence flag: a teacher
 * on approved leave Mon–Wed only must be SKIPPED for a Mon
 * sitting and still FILLABLE for a Thu sitting. The fixture
 * makes the Thu pick deterministic, not name-order luck: every
 * seeded pool teacher carries exactly one existing duty on this
 * exam (load sittings 2026-11-20, already assigned so autoAssign
 * skips them), so the fresh probe teacher at 0 duties is
 * strictly fewest-first; seeded leave covers Mon only, so the
 * Thu candidate set is full and the probe wins on count alone.
 * Seeded pool is captured BEFORE the probe teacher is created
 * (round-19 trap: re-querying after probe creation leaves the
 * probes out of the seeded set too).
 *
 * Dates are relative to the real today (2026-10-28): targets
 * 2026-11-02 (Mon) and 2026-11-05 (Thu) are future, so the
 * past-skip branch (round 2) cannot interfere. Exam 1 is the
 * global Term 1 exam; on a fresh snapshot it holds no sittings
 * except the fixtures below.
 */
class OwnerExamDateSheetR24Test extends TestCase
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

    protected function seededPool()
    {
        return User::where('user_type', 'teacher')
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->get();
    }

    protected function examName(int $examId): string
    {
        return DB::table('exams')->where('id', $examId)->value('name');
    }

    public function test_teacher_own_class_print_with_subjects_but_no_dates_renders_announced_note()
    {
        // Prep (class 1) is in Ananya's scope; the snapshot holds
        // zero exam_dates rows, so every Prep subject is undated.
        $this->assertSame(0, ExamDate::where('exam_id', 1)->count());

        $response = $this->actingAs($this->teacher())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => 1]));
        $response->assertStatus(200);
        $response->assertSee('Dates for this exam have not been announced yet', false);
        $response->assertSee('English Language', false);
        $response->assertSee('Mathematics', false);
        $response->assertSee('Not announced', false);
        $response->assertDontSee('No subjects are configured');
    }

    public function test_teacher_own_class_pdf_with_subjects_but_no_dates_downloads()
    {
        $this->assertSame(0, ExamDate::where('exam_id', 1)->count());

        $response = $this->actingAs($this->teacher())
            ->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => 1]));
        $response->assertDownload('exam-date-sheet-' . Str::slug($this->examName(1)) . '-prep.pdf');
    }

    public function test_zero_subject_class_print_and_pdf_render_empty_state()
    {
        $class = MyClass::create(['name' => 'R24 Empty ' . uniqid()]);
        $this->assertSame(0, DB::table('subjects')->where('my_class_id', $class->id)->count());

        $print = $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]));
        $print->assertStatus(200);
        $print->assertSee('No subjects are configured for this class yet', false);
        $print->assertDontSee('Dates for this exam have not been announced yet');

        $pdf = $this->actingAs($this->admin())
            ->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]));
        $pdf->assertDownload('exam-date-sheet-' . Str::slug($this->examName(1)) . '-' . Str::slug($class->name) . '.pdf');
    }

    public function test_auto_assign_partial_leave_skips_mon_only_and_fills_thu()
    {
        $seeded = $this->seededPool();

        // Every seeded pool teacher carries exactly one existing
        // duty on exam 1 (load sitting 2026-11-20, already
        // assigned, so autoAssign skips it but counts it).
        // Subjects 1 and 2 are reserved for the target sittings
        // below — reusing them here would violate the UNIQUE
        // (exam_id, my_class_id, subject_id) index.
        $subjects = DB::table('subjects')->whereNotIn('id', [1, 2])->orderBy('id')->get();
        $i = 0;
        foreach ($seeded as $teacher) {
            $subject = $subjects[$i % $subjects->count()];
            $i++;
            $load = ExamDate::create([
                'exam_id' => 1,
                'my_class_id' => $subject->my_class_id,
                'subject_id' => $subject->id,
                'exam_date' => '2026-11-20',
                'start_time' => '09:00',
            ]);
            ExamDuty::create([
                'exam_date_id' => $load->id,
                'teacher_id' => $teacher->id,
                'assigned_by' => $this->admin()->id,
            ]);
            // Seeded leave covers Mon 2026-11-02 ONLY.
            LeaveRequest::create([
                'user_id' => $teacher->id,
                'start_date' => '2026-11-02',
                'end_date' => '2026-11-02',
                'reason' => 'R24 seeded Mon leave',
                'status' => 'approved',
            ]);
        }

        // Probe teacher: 0 duties, leave Mon–Wed only.
        $probe = User::create([
            'name' => 'R24 Partial',
            'username' => 'R24PART',
            'email' => 'r24partial@teacher.com',
            'password' => 'secret',
            'code' => 'R24PART',
            'user_type' => 'teacher',
        ]);
        LeaveRequest::create([
            'user_id' => $probe->id,
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-04',
            'reason' => 'R24 probe Mon-Wed leave',
            'status' => 'approved',
        ]);

        // Direct range check, both ends.
        $this->assertTrue(Invigilation::isOnApprovedLeave($probe->id, '2026-11-02'));
        $this->assertFalse(Invigilation::isOnApprovedLeave($probe->id, '2026-11-05'));

        // Target sittings: Prep English Mon, Prep Maths Thu.
        $mon = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 1,
            'exam_date' => '2026-11-02',
            'start_time' => '09:00',
        ]);
        $thu = ExamDate::create([
            'exam_id' => 1,
            'my_class_id' => 1,
            'subject_id' => 2,
            'exam_date' => '2026-11-05',
            'start_time' => '09:00',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('exam_duties.index'))
            ->post(route('exam_duties.auto'), ['exam_id' => 1]);
        $response->assertRedirect(route('exam_duties.index'));
        $response->assertSessionHas('flash_danger',
            'Auto-assignment complete: 1 sitting(s) got an invigilator.'
            . ' Could not fill: 2026-11-02 09:00 — every teacher already has a duty at this time (or no teachers exist).'
            . ' Teachers on approved leave were not assigned.');

        // Mon sitting: NO duty at all (every pool teacher on
        // leave that day, probe included). Thu sitting: the probe
        // teacher, and nobody else.
        $this->assertDatabaseMissing('exam_duties', ['exam_date_id' => $mon->id]);
        $this->assertDatabaseHas('exam_duties', [
            'exam_date_id' => $thu->id,
            'teacher_id' => $probe->id,
        ]);
        $this->assertSame(1, ExamDuty::whereIn('exam_date_id', [$mon->id, $thu->id])->count());

        // The probe is belled for Thu only — no Mon bell exists.
        $bells = UserNotification::where('user_id', $probe->id)
            ->where('type', 'duty_assigned')->get();
        $this->assertSame(1, $bells->count());
        $this->assertStringContainsString('2026-11-05', $bells->first()->body);
    }
}
