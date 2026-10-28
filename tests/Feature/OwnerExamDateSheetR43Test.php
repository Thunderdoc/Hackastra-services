<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SchoolLevel;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 43) — admit-card schedule
 * SOURCE chain + SchoolLevel preset propagation (audit patterns P15
 * chain handoff, P2 scope, P16 settings-as-config).
 *
 * BRIEF-PREMISE CORRECTIONS (code-read first, locked by test):
 * (1) buildSchedule() source order is GRID FIRST, sheet second —
 * the docblock says it plainly ("(1) the exam timetable for the
 * class ... (2) the generated date sheet"), and the sheet branch
 * runs only `if (empty($rows))`. A grid with ANY dated cell wins
 * the WHOLE card; the sheet is not consulted per subject.
 * (2) ExamDateController::generate() NEVER reads tt_gen_* presets.
 * It reads ONLY the form (start_date, exam_days, papers_per_day,
 * session1/2_time, venue, gaps, skip dates) plus the LEVEL
 * classification (SchoolLevel::levelsForClasses) for slot-group
 * separation. tt_gen_primary/secondary are consumed by
 * TimeTableController (timetable generation + its form prefill),
 * not by exam-date generation. Changing the presets therefore
 * cannot change an exam-date sheet — the preset test below
 * locks that non-effect, and the classification test locks what
 * actually drives slot separation.
 *
 * Fixtures: fresh global exam + fresh classes (class_type_id 4,
 * as in R37/R41/R42) + a section + one student per card class
 * (fee block never fires: a fixture student has no payment
 * records, so FeeDefaulter::outstandingFor returns 0 — R37 note).
 * Grid cells copy the R29 pattern (exam_date set, day NULL,
 * slot full '9:00 AM - 9:40 AM'). All tests run in transactions.
 */
class OwnerExamDateSheetR43Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClass($name, array $subjects, $slugPrefix)
    {
        $class = MyClass::create(['name' => $name, 'class_type_id' => 4]);
        $section = Section::create(['name' => 'R43-A', 'my_class_id' => $class->id, 'teacher_id' => 4]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName, 'my_class_id' => $class->id,
                'teacher_id' => 4, 'slug' => $slugPrefix . '-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $section, $created];
    }

    protected function makeStudent($name, $classId, $sectionId)
    {
        $user = User::create([
            'name' => $name, 'email' => null, 'phone' => null,
            'dob' => null, 'gender' => 'Female', 'address' => 'Test Address',
            'user_type' => 'student', 'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'), 'username' => 'TEST/R43/' . strtoupper(Str::random(6)),
        ]);
        StudentRecord::create([
            'user_id' => $user->id, 'my_class_id' => $classId, 'section_id' => $sectionId,
            'adm_no' => 'TEST/R43/' . $user->id, 'session' => '2026-2027',
            'year_admitted' => '2026', 'grad' => 0,
        ]);
        return $user;
    }

    protected function makeExam($name, $term)
    {
        return Exam::create(['name' => $name, 'term' => $term, 'year' => '2026-2027', 'my_class_id' => 0]);
    }

    protected function makeGrid($exam, $classId, array $cells)
    {
        $record = TimeTableRecord::create([
            'name' => 'R43 Grid ' . uniqid(), 'my_class_id' => $classId,
            'section_id' => null, 'exam_id' => $exam->id, 'year' => '2026-2027',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        foreach ($cells as [$subjectId, $date]) {
            TimeTable::create([
                'ttr_id' => $record->id, 'ts_id' => $slot->id,
                'exam_date' => $date, 'day' => null, 'day_num' => null,
                'subject_id' => $subjectId,
                'timestamp_from' => strtotime($date . ' 09:00:00'),
                'timestamp_to' => strtotime($date . ' 09:40:00'),
            ]);
        }
        return $record;
    }

    protected function cardHtml($exam, $classId)
    {
        $resp = $this->actingAs($this->admin())->get(route('admit_cards.print', [$exam->id, $classId]));
        $resp->assertStatus(200);
        return $resp->getContent();
    }

    /** (1) Grid wins whole-card over a disagreeing sheet; sheet venue is dropped. */
    public function test_grid_source_wins_over_sheet_and_sheet_venue_is_dropped()
    {
        $exam = $this->makeExam('R43 Source Exam', 98);
        [$class, $section, $subjects] = $this->makeClass(
            'R43 Source Class', ['R43-SRC-A', 'R43-SRC-B', 'R43-SRC-C'], 'r43-src');
        $this->makeStudent('R43 Source Pupil', $class->id, $section->id);

        // Sheet: Tue 01 / Wed 02 / Thu 03 Dec 2026, venue on every row.
        foreach ([['2026-12-01', 0], ['2026-12-02', 1], ['2026-12-03', 2]] as [$date, $i]) {
            ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $class->id,
                'subject_id' => $subjects[$i]->id, 'exam_date' => $date,
                'start_time' => '09:00:00', 'note' => 'R43 Sheet Hall']);
        }
        // Grid: Thu 10 / Fri 11 Dec 2026 for A/B, C absent from the grid.
        $this->makeGrid($exam, $class->id, [
            [$subjects[0]->id, '2026-12-10'],
            [$subjects[1]->id, '2026-12-11'],
        ]);

        $html = $this->cardHtml($exam, $class->id);
        // Grid dates render; sheet dates do not appear anywhere on the card.
        $this->assertStringContainsString('10 Dec 2026', $html);
        $this->assertStringContainsString('11 Dec 2026', $html);
        $this->assertStringContainsString('9:00 AM - 9:40 AM', $html);
        $this->assertStringNotContainsString('01 Dec 2026', $html);
        $this->assertStringNotContainsString('02 Dec 2026', $html);
        $this->assertStringNotContainsString('03 Dec 2026', $html);
        // The venue EXISTS in the DB (sheet notes) yet never reaches the card:
        // the winning grid branch carries no note, and the losing sheet branch
        // drops $e->note (R33 gap, re-confirmed on the winning branch).
        $this->assertSame(3, ExamDate::where('exam_id', $exam->id)
            ->where('note', 'R43 Sheet Hall')->count());
        $this->assertStringNotContainsString('R43 Sheet Hall', $html);
        $this->assertStringNotContainsString('Venue', $html);
        // C is unioned in dateless (fallback stays false: a schedule exists).
        $this->assertStringContainsString('R43-SRC-C', $html);
        $this->assertStringNotContainsString('will be announced', $html);
    }

    /** (2) Sheet-only card: sheet dates/times render, venue still absent. */
    public function test_sheet_only_card_renders_sheet_dates_but_no_venue()
    {
        $exam = $this->makeExam('R43 Sheet Exam', 99);
        [$class, $section, $subjects] = $this->makeClass(
            'R43 Sheet Class', ['R43-SHT-A', 'R43-SHT-B'], 'r43-sht');
        $this->makeStudent('R43 Sheet Pupil', $class->id, $section->id);

        foreach ([['2026-12-01', 0], ['2026-12-02', 1]] as [$date, $i]) {
            ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $class->id,
                'subject_id' => $subjects[$i]->id, 'exam_date' => $date,
                'start_time' => '13:00:00', 'note' => 'R43 Only Hall']);
        }
        $this->assertSame(0, TimeTableRecord::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->count(), 'Precondition: no grid at all');

        $html = $this->cardHtml($exam, $class->id);
        $this->assertStringContainsString('01 Dec 2026', $html);
        $this->assertStringContainsString('02 Dec 2026', $html);
        $this->assertStringContainsString('1:00 PM', $html);
        $this->assertStringNotContainsString('R43 Only Hall', $html);
        $this->assertStringNotContainsString('Venue', $html);
    }

    /** (3) Grid-only card: no exam_dates row exists to look a venue up from. */
    public function test_grid_only_card_has_no_venue_source_at_all()
    {
        $exam = $this->makeExam('R43 Grid Exam', 100);
        [$class, $section, $subjects] = $this->makeClass(
            'R43 Grid Class', ['R43-GRD-A', 'R43-GRD-B'], 'r43-grd');
        $this->makeStudent('R43 Grid Pupil', $class->id, $section->id);

        $this->makeGrid($exam, $class->id, [
            [$subjects[0]->id, '2026-12-10'],
            [$subjects[1]->id, '2026-12-11'],
        ]);
        $this->assertSame(0, ExamDate::where('exam_id', $exam->id)
            ->where('my_class_id', $class->id)->count(),
            'Precondition: zero sheet rows — a venue lookup against exam_dates '
            . 'would find nothing even if the grid branch gained one; the grid '
            . 'record and its cells carry no venue/note field at all');

        $html = $this->cardHtml($exam, $class->id);
        $this->assertStringContainsString('10 Dec 2026', $html);
        $this->assertStringContainsString('11 Dec 2026', $html);
        $this->assertStringNotContainsString('Venue', $html);
    }

    /** (4) Partial grid suppresses sheet dates for missing subjects (whole-schedule win). */
    public function test_partial_grid_suppresses_sheet_dates_for_missing_subjects()
    {
        $exam = $this->makeExam('R43 Partial Exam', 101);
        [$class, $section, $subjects] = $this->makeClass(
            'R43 Partial Class', ['R43-PAR-A', 'R43-PAR-B', 'R43-PAR-C'], 'r43-par');
        $this->makeStudent('R43 Partial Pupil', $class->id, $section->id);

        // Sheet dates every subject; grid dates only A.
        foreach ([['2026-12-01', 0], ['2026-12-02', 1], ['2026-12-03', 2]] as [$date, $i]) {
            ExamDate::create(['exam_id' => $exam->id, 'my_class_id' => $class->id,
                'subject_id' => $subjects[$i]->id, 'exam_date' => $date, 'start_time' => '09:00:00']);
        }
        $this->makeGrid($exam, $class->id, [[$subjects[0]->id, '2026-12-10']]);

        $html = $this->cardHtml($exam, $class->id);
        $this->assertStringContainsString('10 Dec 2026', $html);
        // B and C do NOT fall back to their sheet dates: the sheet branch
        // runs only when grid rows are EMPTY, so the union fills them dateless.
        $this->assertStringNotContainsString('02 Dec 2026', $html);
        $this->assertStringNotContainsString('03 Dec 2026', $html);
        $this->assertStringContainsString('R43-PAR-B', $html);
        $this->assertStringContainsString('R43-PAR-C', $html);
        $this->assertStringNotContainsString('will be announced', $html,
            'Fallback note is only for the fully-dateless list, not a partial grid');
    }

    /** (5) loadConfig: defaults, malformed-JSON fallback, partial merge — exact values. */
    public function test_load_config_defaults_malformed_fallback_and_partial_merge()
    {
        Setting::where('type', 'tt_gen_primary')->delete();
        Setting::where('type', 'tt_gen_secondary')->delete();

        $this->assertSame([
            'start_time' => '09:00', 'period_minutes' => 40,
            'lunch_after' => 4, 'lunch_minutes' => 45,
            'break_after' => 2, 'break_minutes' => 15, 'days' => 5,
            'periods_per_day' => 6,
        ], SchoolLevel::loadConfig('primary'));
        $this->assertSame(8, SchoolLevel::loadConfig('secondary')['periods_per_day']);

        // Malformed JSON (the G r1 shape): silent fallback to defaults, never null/500.
        Setting::updateOrCreate(['type' => 'tt_gen_primary'], ['description' => 'not-json']);
        $this->assertSame(6, SchoolLevel::loadConfig('primary')['periods_per_day']);
        $this->assertSame('09:00', SchoolLevel::loadConfig('primary')['start_time']);

        // Partial valid JSON merges over defaults, key by key.
        Setting::updateOrCreate(['type' => 'tt_gen_primary'],
            ['description' => json_encode(['periods_per_day' => 4, 'start_time' => '08:30'])]);
        $cfg = SchoolLevel::loadConfig('primary');
        $this->assertSame(4, $cfg['periods_per_day']);
        $this->assertSame('08:30', $cfg['start_time']);
        $this->assertSame(40, $cfg['period_minutes']);
        $this->assertSame(5, $cfg['days']);
    }

    protected function generatePayload($examId, $classId, $start)
    {
        return [
            'exam_id' => $examId, 'scope' => 'one', 'class_id' => $classId,
            'start_date' => $start, 'exam_days' => 5, 'papers_per_day' => 2,
            'session1_time' => '09:00', 'session2_time' => '13:00',
            'venue' => 'R43 Hall', 'skip_sunday' => '0',
            'gap_every' => 0, 'gap_days' => 0, 'skip_dates' => '',
            'mode' => 'replace',
        ];
    }

    protected function sheetRows($examId, $classId)
    {
        return ExamDate::where('exam_dates.exam_id', $examId)->where('exam_dates.my_class_id', $classId)
            ->join('subjects', 'subjects.id', '=', 'exam_dates.subject_id')
            ->orderBy('subjects.name')
            ->get(['subjects.name as subject', 'exam_dates.exam_date', 'exam_dates.start_time'])
            ->map(function ($r) {
                return [$r->subject, $r->exam_date, substr((string) $r->start_time, 0, 5)];
            })->all();
    }

    /** (6) Exam-date generate IGNORES tt_gen presets — form times win, byte-identical. */
    public function test_exam_date_generate_ignores_tt_gen_presets()
    {
        $exam = $this->makeExam('R43 Preset Exam', 102);
        [$class, $section, $subjects] = $this->makeClass(
            'R43 UKG Preset', ['R43-PRE-A', 'R43-PRE-B'], 'r43-pre');
        $this->assertSame('primary', SchoolLevel::levelForClassId($class->id),
            'UKG name rule makes the late-created class Primary regardless of id');

        $start = Carbon::today()->addDays(40)->toDateString();
        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'),
            $this->generatePayload($exam->id, $class->id, $start));
        $resp->assertRedirect();
        $baseline = $this->sheetRows($exam->id, $class->id);
        $this->assertCount(2, $baseline);
        $this->assertSame('09:00', $baseline[0][2]);
        $this->assertSame('13:00', $baseline[1][2]);

        // Wildly different presets, then regenerate the same sheet.
        Setting::updateOrCreate(['type' => 'tt_gen_primary'],
            ['description' => json_encode(['periods_per_day' => 2, 'start_time' => '07:00',
                'period_minutes' => 20, 'days' => 2])]);
        Setting::updateOrCreate(['type' => 'tt_gen_secondary'],
            ['description' => json_encode(['periods_per_day' => 12, 'start_time' => '06:00'])]);
        $resp = $this->post(route('exam_dates.generate'),
            $this->generatePayload($exam->id, $class->id, $start));
        $resp->assertRedirect();
        $this->assertSame($baseline, $this->sheetRows($exam->id, $class->id),
            'Preset change must not move a single exam-date row: generate reads '
            . 'the form (09:00/13:00), never tt_gen_*');
    }

    /** (7) LEVEL classification — not presets — drives slot separation in generate. */
    public function test_level_classification_drives_slot_separation_not_presets()
    {
        $exam = $this->makeExam('R43 Level Exam', 103);
        [$classA] = $this->makeClass('R43 UKG Level', ['R43-LVL-A1', 'R43-LVL-A2'], 'r43-lvla');
        [$classB] = $this->makeClass('R43 Senior Level', ['R43-LVL-B1', 'R43-LVL-B2'], 'r43-lvlb');
        [$classC] = $this->makeClass('R43 KG Level', ['R43-LVL-C1', 'R43-LVL-C2'], 'r43-lvlc');
        $this->assertSame('primary', SchoolLevel::levelForClassId($classA->id));
        $this->assertSame('secondary', SchoolLevel::levelForClassId($classB->id));
        $this->assertSame('primary', SchoolLevel::levelForClassId($classC->id));

        $start = Carbon::today()->addDays(50)->toDateString();
        $this->actingAs($this->admin());
        // Sequential scope=one runs: the second run preloads the first run's
        // rows into $occupied, so cross-group separation is observable here
        // without a scope=all run over every seeded class.
        $this->post(route('exam_dates.generate'), $this->generatePayload($exam->id, $classA->id, $start));
        $this->post(route('exam_dates.generate'), $this->generatePayload($exam->id, $classB->id, $start));
        $this->post(route('exam_dates.generate'), $this->generatePayload($exam->id, $classC->id, $start));
        $rowsA = $this->sheetRows($exam->id, $classA->id);
        $rowsB = $this->sheetRows($exam->id, $classB->id);
        $rowsC = $this->sheetRows($exam->id, $classC->id);

        $this->assertCount(2, $rowsA);
        $this->assertCount(2, $rowsB);
        $this->assertCount(2, $rowsC);
        // A (primary) takes day-1 09:00 + 13:00.
        $this->assertSame($start, $rowsA[0][1]);
        $this->assertSame('09:00', $rowsA[0][2]);
        $this->assertSame('13:00', $rowsA[1][2]);
        // B (secondary) shares NO slot with A: its walk skips both day-1
        // slots (held by the other group) and lands on day-2.
        $slotsA = array_map(function ($r) { return $r[1] . '|' . $r[2]; }, $rowsA);
        $slotsB = array_map(function ($r) { return $r[1] . '|' . $r[2]; }, $rowsB);
        $this->assertSame([], array_intersect($slotsA, $slotsB));
        $this->assertSame(Carbon::parse($start)->addDay()->toDateString(), $rowsB[0][1]);
        // C (primary, same group as A) SHARES A's exact slots.
        $slotsC = array_map(function ($r) { return $r[1] . '|' . $r[2]; }, $rowsC);
        $this->assertSame($slotsA, $slotsC);
    }
}
