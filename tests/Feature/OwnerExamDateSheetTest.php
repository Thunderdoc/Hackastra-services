<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\ExamDateSheet;
use App\Support\SchoolLevel;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam timetable, owner report): classes with
 * different subject counts (3-subject UKG vs 6-subject Class 10)
 * showed the SAME exam timetable. Proven cause: the generator
 * shares (day, session) slots within a level group, and the level
 * rule (first 6 classes by id) classified a later-added early-
 * years class as Secondary, so UKG's papers landed on Class 10's
 * exact slots. The display path itself was proven correct (each
 * sheet lists only its own class's subjects). This file locks
 * the fixes: early-years name classification, generate
 * validation (past start, equal sessions, orphan cleanup on
 * replace), manual-save validation (past dates, same-session
 * clashes, both grandfathering stored data), and admit-card
 * source priority (exam grid beats date sheet; section filter
 * cannot pick another section's grid — the schema's UNIQUE
 * (my_class_id, exam_id, year) index allows only one exam grid
 * per class, so the controller's section preference is
 * defensive only).
 */
class OwnerExamDateSheetTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeClassWithSubjects($name, array $subjects)
    {
        $class = MyClass::create(['name' => $name]);
        $created = [];
        foreach ($subjects as $i => $subjectName) {
            $created[] = Subject::create([
                'name' => $subjectName,
                'my_class_id' => $class->id,
                'teacher_id' => 4,
                'slug' => 'test-' . $class->id . '-' . ($i + 1),
                'periods_per_week' => 6,
            ]);
        }
        return [$class, $created];
    }

    public function test_late_created_early_years_class_is_primary()
    {
        $names = ['Nursery', 'LKG', 'UKG', 'KG-2', 'Pre-Primary'];
        $ids = [];
        foreach ($names as $name) {
            $class = MyClass::create(['name' => $name]);
            $ids[] = $class->id;
            $this->assertSame('primary', SchoolLevel::levelForClassId($class->id), $name . ' must be primary despite a late id');
        }
        $senior = MyClass::create(['name' => 'Class 13']);
        $this->assertSame('secondary', SchoolLevel::levelForClassId($senior->id));

        // levelsForClasses agrees with levelForClassId.
        $levels = SchoolLevel::levelsForClasses(MyClass::whereIn('id', $ids)->get());
        foreach ($ids as $id) {
            $this->assertSame('primary', $levels[$id]);
        }
        // Seeded classes are unchanged: Prep primary, Class 10 secondary.
        $this->assertSame('primary', SchoolLevel::levelForClassId(1));
        $this->assertSame('secondary', SchoolLevel::levelForClassId(11));
    }

    public function test_generate_separates_early_years_from_seniors()
    {
        [$ukg] = $this->makeClassWithSubjects('UKG', ['UKG English', 'UKG Number Work', 'UKG Rhymes']);
        ExamDate::where('exam_id', 1)->delete();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'all',
            'start_date' => '2026-12-01',
            'exam_days' => 10,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'venue' => 'Main Hall',
            'skip_sunday' => '1',
            'gap_every' => 2,
            'gap_days' => 1,
            'skip_dates' => '2026-12-05',
            'mode' => 'replace',
        ]);
        $resp->assertRedirect();

        $slots = function ($classId) {
            return ExamDate::where('exam_id', 1)->where('my_class_id', $classId)
                ->get()->map(function ($r) { return $r->exam_date . '|' . substr((string) $r->start_time, 0, 5); })->all();
        };
        $ukgSlots = $slots($ukg->id);
        $c10Slots = $slots(11);
        $prepSlots = $slots(1);
        $this->assertCount(3, $ukgSlots);
        $this->assertCount(6, $c10Slots);
        $this->assertSame([], array_intersect($ukgSlots, $c10Slots), 'UKG must share no slot with Class 10');
        $this->assertNotEmpty(array_intersect($ukgSlots, $prepSlots), 'UKG shares the primary group days with Prep');
    }

    public function test_sheets_list_only_their_own_class_subjects()
    {
        [$ukg, $ukgSubjects] = $this->makeClassWithSubjects('UKG', ['UKG English', 'UKG Number Work', 'UKG Rhymes']);
        ExamDate::where('exam_id', 1)->delete();
        $dates = ['2026-12-01', '2026-12-02', '2026-12-03'];
        foreach ($ukgSubjects as $i => $subject) {
            ExamDate::create(['exam_id' => 1, 'my_class_id' => $ukg->id, 'subject_id' => $subject->id, 'exam_date' => $dates[$i], 'start_time' => '09:00:00']);
        }
        $c10 = Subject::where('my_class_id', 11)->orderBy('id')->get();
        $d = 7;
        foreach ($c10 as $subject) {
            ExamDate::create(['exam_id' => 1, 'my_class_id' => 11, 'subject_id' => $subject->id, 'exam_date' => '2026-12-' . str_pad($d, 2, '0', STR_PAD_LEFT), 'start_time' => '09:00:00']);
            $d++;
        }

        $ukgRows = ExamDateSheet::rows(1, $ukg->id);
        $c10Rows = ExamDateSheet::rows(1, 11);
        $this->assertCount(3, $ukgRows);
        $this->assertCount(6, $c10Rows);
        $ukgNames = $ukgRows->map(function ($r) { return $r['subject']->name; })->all();
        $c10Names = $c10Rows->map(function ($r) { return $r['subject']->name; })->all();
        $this->assertSame([], array_intersect($ukgNames, $c10Names));
        $this->assertSame(3, ExamDateSheet::announcedCount(1, $ukg->id));
        $this->assertSame(6, ExamDateSheet::announcedCount(1, 11));
    }

    public function test_save_refuses_same_session_clash()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 11)->delete();
        $subjects = Subject::where('my_class_id', 11)->orderBy('id')->get();
        $dates = [];
        foreach ($subjects as $i => $subject) {
            // First two subjects share one session — the clash;
            // the rest sit on their own later days.
            $dates[$subject->id] = [
                'exam_date' => '2026-12-' . str_pad($i < 2 ? 7 : 6 + $i, 2, '0', STR_PAD_LEFT),
                'start_time' => '09:00',
                'note' => '',
            ];
        }

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), ['exam_id' => 1, 'class_id' => 11, 'dates' => $dates]);
        $resp->assertRedirect();
        $this->assertStringContainsString('share one session', (string) $resp->getSession()->get('flash_danger'));
        $this->assertSame(0, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());
    }

    public function test_save_refuses_new_past_date_but_allows_unchanged()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 11)->delete();
        $subjects = Subject::where('my_class_id', 11)->orderBy('id')->get();
        $first = $subjects->first();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => 11,
            'dates' => [$first->id => ['exam_date' => '2020-01-06', 'start_time' => '09:00', 'note' => '']],
        ]);
        $this->assertStringContainsString('has already passed', (string) $resp->getSession()->get('flash_danger'));
        $this->assertSame(0, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());

        // Grandfathering: a stored past date, left unchanged, never
        // blocks an unrelated edit on the same sheet.
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 11, 'subject_id' => $first->id, 'exam_date' => '2020-01-06', 'start_time' => '09:00:00']);
        $second = $subjects->skip(1)->first();
        $resp = $this->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => 11,
            'dates' => [
                $first->id => ['exam_date' => '2020-01-06', 'start_time' => '09:00', 'note' => ''],
                $second->id => ['exam_date' => '2026-12-07', 'start_time' => '09:00', 'note' => ''],
            ],
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 11]));
        $this->assertSame(2, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());
    }

    public function test_save_is_idempotent()
    {
        ExamDate::where('exam_id', 1)->where('my_class_id', 11)->delete();
        $subjects = Subject::where('my_class_id', 11)->orderBy('id')->get();
        $dates = [];
        $day = 7;
        foreach ($subjects as $subject) {
            $dates[$subject->id] = ['exam_date' => '2026-12-' . str_pad($day, 2, '0', STR_PAD_LEFT), 'start_time' => '09:00', 'note' => ''];
            $day++;
        }

        $this->actingAs($this->admin());
        $payload = ['exam_id' => 1, 'class_id' => 11, 'dates' => $dates];
        $this->post(route('exam_dates.save'), $payload)->assertRedirect();
        $this->assertSame(6, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());
        $this->post(route('exam_dates.save'), $payload)->assertRedirect();
        $this->assertSame(6, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());
    }

    public function test_generate_refuses_past_start_and_equal_sessions()
    {
        ExamDate::where('exam_id', 1)->delete();
        $this->actingAs($this->admin());

        $base = [
            'exam_id' => 1, 'scope' => 'one', 'class_id' => 11,
            'exam_days' => 3, 'papers_per_day' => 2,
            'session1_time' => '09:00', 'session2_time' => '13:00',
            'mode' => 'replace',
        ];
        $resp = $this->post(route('exam_dates.generate'), array_merge($base, ['start_date' => '2020-01-06']));
        $resp->assertSessionHasErrors('start_date');
        $this->assertSame(0, ExamDate::where('exam_id', 1)->count());

        $resp = $this->post(route('exam_dates.generate'), array_merge($base, ['start_date' => '2026-12-01', 'session2_time' => '09:00']));
        $this->assertStringContainsString('must differ', (string) $resp->getSession()->get('flash_danger'));

        // session2 omitted defaults to 13:00 — colliding with a
        // 13:00 first session is refused the same way.
        $noSecond = $base;
        unset($noSecond['session2_time']);
        $noSecond['session1_time'] = '13:00';
        $noSecond['start_date'] = '2026-12-01';
        $resp = $this->post(route('exam_dates.generate'), $noSecond);
        $this->assertStringContainsString('must differ', (string) $resp->getSession()->get('flash_danger'));
        $this->assertSame(0, ExamDate::where('exam_id', 1)->count());
    }

    public function test_generate_replace_clears_orphan_rows()
    {
        // Whole-exam wipe: a scope=one generate still preloads
        // every class's rows for slot separation, so leftover rows
        // from other classes would steal this class's slots.
        ExamDate::where('exam_id', 1)->delete();
        $foreignSubject = Subject::where('my_class_id', 10)->orderBy('id')->firstOrFail();
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 11, 'subject_id' => $foreignSubject->id, 'exam_date' => '2026-12-07', 'start_time' => '09:00:00']);

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => 1, 'scope' => 'one', 'class_id' => 11,
            'start_date' => '2026-12-01', 'exam_days' => 3, 'papers_per_day' => 2,
            'session1_time' => '09:00', 'session2_time' => '13:00',
            'mode' => 'replace',
        ]);
        $resp->assertRedirect();
        $this->assertNull(ExamDate::where('exam_id', 1)->where('my_class_id', 11)->where('subject_id', $foreignSubject->id)->first());
        $this->assertSame(6, ExamDate::where('exam_id', 1)->where('my_class_id', 11)->count());
    }

    public function test_admit_card_uses_exam_grid_over_date_sheet()
    {
        // Schema fact (verified 2026-10-28): time_table_records has
        // a UNIQUE index on (my_class_id, exam_id, year) with NO
        // section_id — so at most ONE exam grid exists per class
        // per exam, and a "wrong section grid" pick is impossible
        // today. What the card must still get right: source (1),
        // the exam grid, beats source (2), the date sheet, and a
        // section filter must not break that or invent another
        // section's data.
        $subject = Subject::where('my_class_id', 11)->orderBy('id')->firstOrFail();
        ExamDate::where('exam_id', 1)->where('my_class_id', 11)->delete();
        ExamDate::create(['exam_id' => 1, 'my_class_id' => 11, 'subject_id' => $subject->id, 'exam_date' => '2026-12-15', 'start_time' => '09:00:00']);
        $ttr = TimeTableRecord::create(['name' => 'T1', 'my_class_id' => 11, 'section_id' => null, 'exam_id' => 1, 'year' => '2026-2027']);
        TimeTable::create(['ttr_id' => $ttr->id, 'ts_id' => 1, 'subject_id' => $subject->id, 'exam_date' => '2026-12-07', 'day' => 1, 'timestamp_from' => '09:00:00', 'timestamp_to' => '09:40:00']);

        $this->actingAs($this->admin());
        $resp = $this->get(route('admit_cards.print', ['exam' => 1, 'class' => 11]));
        $resp->assertOk();
        $resp->assertSee('Mon, 07 Dec 2026', false);
        $resp->assertDontSee('Tue, 15 Dec 2026', false);

        // Section-filtered print reads the same single grid.
        $resp = $this->get(route('admit_cards.print', ['exam' => 1, 'class' => 11]) . '?section_id=48');
        $resp->assertOk();
        $resp->assertSee('Mon, 07 Dec 2026', false);
        $resp->assertDontSee('Tue, 15 Dec 2026', false);
    }
}
