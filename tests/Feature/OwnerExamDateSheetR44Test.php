<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Models\MyClass;
use App\Models\Subject;
use App\Support\Invigilation;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 44) — date-sheet artifact
 * CONSISTENCY chain + invigilation pool CHURN (audit patterns P10
 * count-vs-rows and P15 chain handoff).
 *
 * Code under test (quoted, not assumed):
 * - ExamDateSheet::rows() is the ONE assembly feeding the on-screen
 *   date sheet, the print document and the PDF (ExamDateSheet.php
 *   docblock: "the three can never disagree on a date"). Show,
 *   printSheet and pdf all run the same resolveScope() and render
 *   the same date_sheet_print blade for print/PDF (for_pdf only
 *   switches the font family and hides the print toolbar).
 * - Duties index renders the sitting's note under a column headed
 *   "Venue" (duties.blade.php:54,63) — a FOURTH artifact fed by
 *   the same exam_dates rows, not by the rows() assembly.
 * - Invigilation::pool() = user_type teacher AND (status null OR
 *   'active'), ordered by name. ExamDutyController::assign checks
 *   pool membership FIRST ('That teacher is not in the invigilator
 *   pool (must be an active teacher).'), so a suspended teacher is
 *   refused at the gate. autoAssign() builds its candidate list
 *   from the same pool() and skips already-assigned sittings, so
 *   suspension mid-chain changes FUTURE picks only: existing duty
 *   rows are stored-row semantics (like the substitution round-23
 *   shape), never auto-cleared.
 * - PDF text cannot be extracted in-process (round 33, locked):
 *   the PDF-side in-test assertion is the download itself; the
 *   PDF TEXT parity (venue + dates + subjects via pdftotext) is
 *   proven by the agent's external snapshot-server check and
 *   reported alongside this suite, not inside it.
 *
 * Fixtures: real generate POST for the consistency chain (so the
 * storage path itself is under test, round-33 style), direct
 * ExamDate rows for the churn chain (dates computed from today so
 * the future property never rots; auto-assign skips past sittings).
 * Each test runs inside its own transaction.
 */
class OwnerExamDateSheetR44Test extends TestCase
{
    use DatabaseTransactions;

    const VENUE = 'R44 Main Hall';

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

    protected function makeTeacher($name, $email, $code)
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('teacher'),
            'code' => $code,
            'user_type' => 'teacher',
            'status' => 'active',
        ]);
    }

    protected function generate($classId, $venue)
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
            'venue' => $venue,
        ];
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), $payload);
    }

    /** The generate run's exact slot plan (round-29 sequence rule: subjects in NAME order, 2 papers/day, sessions 09:00/13:00, start Mon 2027-03-01, no Sunday in range). */
    protected function expectedSlots()
    {
        return [
            'R44 Alpha' => ['Mon, 01 Mar 2027', '9:00 AM'],
            'R44 Beta' => ['Mon, 01 Mar 2027', '1:00 PM'],
            'R44 Gamma' => ['Tue, 02 Mar 2027', '9:00 AM'],
        ];
    }

    public function test_sheet_show_print_duties_and_pdf_agree_on_dates_times_venue()
    {
        $class = $this->makeClassWithSubjects(
            'R44 Consistency Class',
            ['R44 Alpha', 'R44 Beta', 'R44 Gamma'],
            'r44-cons'
        );
        $this->generate($class->id, self::VENUE)->assertRedirect();

        $rows = ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(self::VENUE, $row->note);
        }

        $params = ['exam_id' => 1, 'class_id' => $class->id];
        $examName = Exam::find(1)->name;

        // (1) Show page: every date label, time label, subject and the venue.
        $show = $this->actingAs($this->admin())->get(route('exam_dates.index', $params))->assertOk();
        foreach ($this->expectedSlots() as $subject => [$date, $time]) {
            $show->assertSee($subject)->assertSee($date)->assertSee($time);
        }
        $show->assertSee(self::VENUE);
        // Order on the page follows the slot plan, not insertion order.
        $showHtml = $show->getContent();
        $this->assertLessThan(strpos($showHtml, 'R44 Beta'), strpos($showHtml, 'R44 Alpha'));
        $this->assertLessThan(strpos($showHtml, 'R44 Gamma'), strpos($showHtml, 'R44 Beta'));

        // (2) Print page: the SAME values (same blade family, same resolveScope data).
        $print = $this->actingAs($this->admin())->get(route('exam_dates.print', $params))->assertOk();
        foreach ($this->expectedSlots() as $subject => [$date, $time]) {
            $print->assertSee($subject)->assertSee($date)->assertSee($time);
        }
        $print->assertSee(self::VENUE);

        // (3) PDF: download itself (text extraction unavailable in-process, round 33).
        $this->actingAs($this->admin())
            ->get(route('exam_dates.pdf', $params))
            ->assertDownload('exam-date-sheet-' . Str::slug($examName) . '-' . Str::slug($class->name) . '.pdf');

        // (4) Duties index: the same three sittings, venue under the Venue column.
        $duties = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk();
        foreach (['R44 Alpha', 'R44 Beta', 'R44 Gamma'] as $subject) {
            $duties->assertSee($subject);
        }
        $this->assertSame(3, substr_count($duties->getContent(), self::VENUE));
    }

    public function test_undated_subject_is_on_sheet_but_not_on_duties()
    {
        // Universe difference, locked not papered over: the sheet lists
        // EVERY subject of the class (dated or not); duties lists only
        // sittings. A subject added after generate is therefore on the
        // sheet as "Not announced" and absent from duties entirely.
        $class = $this->makeClassWithSubjects(
            'R44 Undated Class',
            ['R44 Alpha', 'R44 Beta', 'R44 Gamma'],
            'r44-und'
        );
        $this->generate($class->id, self::VENUE)->assertRedirect();
        Subject::create([
            'name' => 'R44 Delta',
            'my_class_id' => $class->id,
            'teacher_id' => $this->admin()->id,
            'slug' => 'r44-und-' . $class->id . '-4',
            'periods_per_week' => 6,
        ]);

        $params = ['exam_id' => 1, 'class_id' => $class->id];
        $this->actingAs($this->admin())->get(route('exam_dates.index', $params))
            ->assertOk()->assertSee('R44 Delta')->assertSee('Not announced');
        $this->actingAs($this->admin())->get(route('exam_dates.print', $params))
            ->assertOk()->assertSee('R44 Delta')->assertSee('Not announced');
        $dutiesHtml = $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))->assertOk()->getContent();
        $this->assertStringNotContainsString('R44 Delta', $dutiesHtml);
    }

    public function test_pool_churn_suspension_changes_future_picks_only()
    {
        $admin = $this->admin();
        $exam = Exam::create(['name' => 'R44 Churn Exam', 'term' => 2, 'year' => '2026-2027', 'my_class_id' => 0]);
        // Five subjects: the UNIQUE(exam_id, my_class_id, subject_id)
        // index forbids two sittings of one subject for one exam+class,
        // so S4/S5 need their own subjects (test-craft, not app behaviour).
        $class = $this->makeClassWithSubjects('R44 Churn Class', ['R44 Churn A', 'R44 Churn B', 'R44 Churn C', 'R44 Churn D', 'R44 Churn E'], 'r44-churn');
        $subjects = Subject::where('my_class_id', $class->id)->orderBy('name')->get();
        $base = Carbon::today()->addDays(30);

        $sittings = [];
        foreach (array_slice($subjects->all(), 0, 3) as $i => $subject) {
            $sittings[] = ExamDate::create([
                'exam_id' => $exam->id,
                'my_class_id' => $class->id,
                'subject_id' => $subject->id,
                'exam_date' => $base->copy()->addDays($i)->toDateString(),
                'start_time' => '09:00',
                'note' => null,
            ]);
        }

        $w = $this->makeTeacher('R44 Churn W', 'r44w@teacher.com', 'R44W');
        $x = $this->makeTeacher('R44 Churn X', 'r44x@teacher.com', 'R44X');
        $y = $this->makeTeacher('R44 Churn Y', 'r44y@teacher.com', 'R44Y');

        $assign = function ($sittingId, $teacherId) use ($exam) {
            return $this->actingAs($this->admin())
                ->from(route('exam_duties.index', ['exam_id' => $exam->id]))
                ->post(route('exam_duties.assign'), ['exam_date_id' => $sittingId, 'teacher_id' => $teacherId]);
        };
        $assign($sittings[0]->id, $w->id)->assertRedirect()->assertSessionHas('flash_success', 'Invigilator saved.');
        $assign($sittings[1]->id, $x->id)->assertRedirect();
        $assign($sittings[2]->id, $y->id)->assertRedirect();
        $this->assertSame(3, ExamDuty::whereIn('exam_date_id', collect($sittings)->pluck('id'))->count());

        // Suspend W: pool and duties-index dropdown lose her, her S1 row stays.
        $w->update(['status' => 'suspended']);
        $this->assertFalse(Invigilation::pool()->contains('id', $w->id));
        $indexTeachers = $this->actingAs($admin)
            ->get(route('exam_duties.index', ['exam_id' => $exam->id]))->assertOk()
            ->viewData('teachers')->pluck('id')->all();
        $this->assertNotContains($w->id, $indexTeachers);
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $sittings[0]->id, 'teacher_id' => $w->id]);

        // A fresh sitting auto-assigns to someone OTHER than W.
        $s4 = ExamDate::create([
            'exam_id' => $exam->id, 'my_class_id' => $class->id, 'subject_id' => $subjects[3]->id,
            'exam_date' => $base->copy()->addDays(10)->toDateString(), 'start_time' => '09:00', 'note' => null,
        ]);
        $this->actingAs($admin)
            ->from(route('exam_duties.index', ['exam_id' => $exam->id]))
            ->post(route('exam_duties.auto'), ['exam_id' => $exam->id])
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Auto-assignment complete: 1 sitting(s) got an invigilator.');
        $s4Duty = ExamDuty::where('exam_date_id', $s4->id)->firstOrFail();
        $this->assertNotSame($w->id, (int) $s4Duty->teacher_id);
        // W's original duty is untouched by the auto run.
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $sittings[0]->id, 'teacher_id' => $w->id]);

        // Manual re-assign of S4 to suspended W is refused at the pool gate.
        $assign($s4->id, $w->id)->assertRedirect()
            ->assertSessionHas('flash_danger', 'That teacher is not in the invigilator pool (must be an active teacher).');
        $this->assertSame((int) $s4Duty->teacher_id, (int) ExamDuty::where('exam_date_id', $s4->id)->first()->teacher_id);

        // Reactivate: pool regains her, and a manual assign to her succeeds.
        $w->update(['status' => 'active']);
        $this->assertTrue(Invigilation::pool()->contains('id', $w->id));
        $s5 = ExamDate::create([
            'exam_id' => $exam->id, 'my_class_id' => $class->id, 'subject_id' => $subjects[4]->id,
            'exam_date' => $base->copy()->addDays(11)->toDateString(), 'start_time' => '09:00', 'note' => null,
        ]);
        $assign($s5->id, $w->id)->assertRedirect()->assertSessionHas('flash_success', 'Invigilator saved.');
        $this->assertDatabaseHas('exam_duties', ['exam_date_id' => $s5->id, 'teacher_id' => $w->id]);
    }
}
