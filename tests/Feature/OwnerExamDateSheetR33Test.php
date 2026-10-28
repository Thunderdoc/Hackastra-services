<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 33) — venue propagation.
 *
 * Background: the generate form promises the venue is
 * "written on every paper" (date_sheet_generate.blade.php:56:
 * label "Venue (written on every paper)"), and the Manage
 * table calls the same field "Note (venue, instructions…)"
 * (date_sheet_manage.blade.php:66). There is NO venue column:
 * generate() stores the venue into exam_dates.note on every
 * row (ExamDateController:294), and Manage edits that same
 * note per subject. So "venue propagation" really means
 * "note propagation" — and the question is which surfaces
 * render exam_dates.note.
 *
 * Proven here, through the REAL generate POST (not planted
 * rows, so the storage path itself is under test):
 *  (1) Generate with a venue stores it in note on EVERY row.
 *  (2) The venue renders on the date-sheet show page, the
 *      date-sheet print page, the date-sheet PDF (download
 *      filename asserted; PDF text extraction is unavailable
 *      in-process, and print/PDF share the SAME blade with the
 *      SAME resolveScope data — parity proven in round 21 —
 *      so the print-page assertion is the text proof for both),
 *      and the duties index (whose column is literally headed
 *      "Venue" and renders sitting->note).
 *  (3) The venue does NOT reach admit cards — bulk print or
 *      self-service myCard. The card table (admit_card.blade)
 *      has S/N, Subject, Date, Time, Invigilator's Sign and no
 *      venue/note column, and buildSchedule() rows carry only
 *      subject/date/sort/time: the exam_dates fallback branch
 *      drops $e->note, and the timetable-grid branch has no
 *      venue source at all (TimeTable rows carry no venue).
 *      That gap is reported as a FINDING (partial propagation,
 *      not a dead field — the venue renders on three surfaces),
 *      with the precise hook, and NO display UI is changed here:
 *      adding a card column is a product-visible layout change
 *      (5 -> 6 columns, widths), so it stays an owner call.
 *      Proposed hook, for the record: buildSchedule()'s
 *      exam_dates branch adds 'note' => $e->note; the
 *      timetable-grid branch needs a venue lookup against the
 *      matching exam_dates row by subject (no venue lives on
 *      TimeTable rows); admit_card.blade gains a Venue column.
 *  (4) Control: generate WITHOUT a venue stores NULL notes
 *      and renders cleanly (no venue string anywhere, dash
 *      cells) — no leakage from the venue run.
 *
 * No app file is changed by this round: the venue gap on
 * cards is a display addition, not a gate fix, and the brief
 * forbids unilateral display UI.
 */
class OwnerExamDateSheetR33Test extends TestCase
{
    use DatabaseTransactions;

    const VENUE = 'R33 Main Hall';

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name]);
        foreach ($subjects as $i => $subjectName) {
            Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return $class;
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
        ];
        if ($venue !== null) {
            $payload['venue'] = $venue;
        }
        return $this->actingAs($this->admin())->post(route('exam_dates.generate'), $payload);
    }

    public function test_generate_with_venue_stores_venue_in_note_on_every_row()
    {
        $class = $this->makeClassWithSubjects(
            'R33 Venue Class', ['R33-VENUE-A', 'R33-VENUE-B', 'R33-VENUE-C'], 'r33-venue'
        );

        $resp = $this->generate($class->id, self::VENUE);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => 1]));

        $rows = ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(self::VENUE, $row->note);
        }
    }

    public function test_venue_renders_on_show_print_pdf_and_duties()
    {
        $class = $this->makeClassWithSubjects(
            'R33 Venue Class', ['R33-VENUE-A', 'R33-VENUE-B', 'R33-VENUE-C'], 'r33-venue'
        );
        $this->generate($class->id, self::VENUE);

        $this->actingAs($this->admin())
            ->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertOk()
            ->assertSee(self::VENUE);

        $this->actingAs($this->admin())
            ->get(route('exam_dates.print', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertOk()
            ->assertSee(self::VENUE);

        // Same blade + same resolveScope data as print (round 21
        // parity); PDF text cannot be extracted in-process, so
        // the download itself is the PDF-side assertion.
        $this->actingAs($this->admin())
            ->get(route('exam_dates.pdf', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertDownload('exam-date-sheet-term-1-examination-r33-venue-class.pdf');

        $this->actingAs($this->admin())
            ->get(route('exam_duties.index', ['exam_id' => 1]))
            ->assertOk()
            ->assertSee(self::VENUE);
    }

    public function test_venue_absent_from_bulk_admit_print()
    {
        // Prep (class 1) has real students; section 1 holds 41.
        $this->generate(1, self::VENUE);

        $this->actingAs($this->admin())
            ->get(route('admit_cards.print', [1, 1]) . '?section_id=1')
            ->assertOk()
            ->assertSee('English Language')
            ->assertDontSee(self::VENUE);
    }

    public function test_venue_absent_from_self_service_my_card()
    {
        $this->generate(1, self::VENUE);

        // Student 57 is Aarav Sharma, Prep Section A; admin is
        // teamSA so no fee block stands between us and the card.
        $this->actingAs($this->admin())
            ->get(route('admit_cards.my_card', [1, 57]))
            ->assertOk()
            ->assertSee('Aarav Sharma')
            ->assertDontSee(self::VENUE);
    }

    public function test_generate_without_venue_renders_cleanly()
    {
        $class = $this->makeClassWithSubjects(
            'R33 No Venue Class', ['R33-NOVENUE-A', 'R33-NOVENUE-B', 'R33-NOVENUE-C'], 'r33-novenue'
        );
        $this->generate($class->id, null);

        $rows = ExamDate::where('exam_id', 1)->where('my_class_id', $class->id)->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->note);
        }

        $this->actingAs($this->admin())
            ->get(route('exam_dates.index', ['exam_id' => 1, 'class_id' => $class->id]))
            ->assertOk()
            ->assertDontSee(self::VENUE);
    }
}
