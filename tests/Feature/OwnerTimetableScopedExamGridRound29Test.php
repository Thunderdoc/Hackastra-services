<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 29 — scoped-exam timetable grids.
 *
 * Follow-up to Agent E round 32's observation: buildSchedule source (1)
 * reads TimeTableRecord exam grids, and E's action-level guard refuses
 * admit CARDS for mismatched exams — but the grid itself was never
 * scope-checked. This round answers the two questions that follow:
 *
 *  (a) CREATION: store_record / update_record (ttr.store / ttr.update,
 *      teamSAWrite, admin-only) apply NO exam-scope check at all.
 *      cleanRecordSection() forces exam records whole-class and
 *      refuses a section from another class, but never compares the
 *      exam's my_class_id with the record's my_class_id. The create
 *      form on tt.index offers EVERY exam in its "Type (Class or
 *      Exam)" dropdown, so a mismatched grid is creatable through the
 *      real UI path, not just a crafted POST. The generator is NOT a
 *      vector: generate() only ever writes exam_id => null.
 *
 *  (b) DISPLAY: the mismatched grid renders on STAFF surfaces only
 *      (admin show/print, and in-scope teacher show/print via the
 *      class-based guardRecordScope) — it never reaches families:
 *      SectionTeacher::recordForClassSection() filters
 *      whereNull('exam_id'), so a student's My Timetable never
 *      returns an exam grid; teacherWeekData() filters
 *      whereNotNull('day'), so exam_date cells never enter a
 *      teacher's week view; and E32's admit-card guard refuses the
 *      card for a mismatched exam whichever schedule source feeds it.
 *
 * Verdict (per the brief's decision rule): created-but-never-displayed
 * to the affected families, so this round reports the creation hole as
 * a data-hygiene OBSERVATION and leaves the code unchanged. The
 * symmetric follow-up — the same scopeMismatchMessage guard in
 * store_record/update_record that E31 put in generate()/save() — is
 * recommended to the parent, not built here unilaterally. One
 * downstream echo IS locked: the mismatched sitting appears in the
 * subject teacher's topbar reminders (TeacherReminders derives
 * sittings from exam-date cells by subject teacher, with no
 * exam-scope check) — staff-only and teacher-filtered, so it does
 * not change the verdict, but a creation guard would silence it too.
 *
 * ROUND 30 ADDENDUM: the parent authorized the guard. TimeTableController
 * now refuses mismatched store/update with the E31 scope message, and
 * the two creation tests in this file assert the refusal (renamed).
 * The display findings (student mine NULL, week view excludes exam-date
 * cells, reminder echo for legacy direct-DB rows) stand unchanged.
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableScopedExamGridRound29Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function freshUser(string $type, string $tag): User
    {
        return User::create([
            'name' => 'R29 ' . $tag,
            'email' => 'r29-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => $type,
            'code' => 'R29' . strtoupper($tag) . uniqid(),
        ]);
    }

    protected function freshClass(string $tag): MyClass
    {
        return MyClass::create(['name' => 'R29 Class ' . $tag . ' ' . uniqid()]);
    }

    /** An exam scoped to $classId (0 = global). Term 9 avoids the seeded global terms 1-3. */
    protected function freshExam(string $tag, int $classId, int $term = 1): Exam
    {
        return Exam::create([
            'name' => 'R29 Exam ' . $tag . ' ' . uniqid(),
            'term' => $term,
            'year' => '2026-2027',
            'my_class_id' => $classId,
            'results_published' => 0,
        ]);
    }

    protected function storeRecord(array $payload)
    {
        return $this->actingAs($this->admin())->post(route('ttr.store'), $payload);
    }

    /** A mismatched grid: class Y record naming an exam scoped to class X. */
    protected function mismatchedPair(): array
    {
        $classX = $this->freshClass('X');
        $classY = $this->freshClass('Y');
        $scoped = $this->freshExam('Scoped', $classX->id, 1);

        return [$classX, $classY, $scoped];
    }

    /**
     * ROUND 30 UPDATE: this test asserted the creation hole in round 29
     * (store succeeded). The round-30 guard now refuses it — the test
     * asserts the refusal instead. The hole-closing itself is covered
     * again by OwnerTimetableScopedExamGuardRound30Test.
     */
    public function test_mismatched_grid_store_now_refused()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $name = 'R29 Mismatch ' . uniqid();

        $response = $this->actingAs($this->admin())
            ->from(route('tt.index'))
            ->post(route('ttr.store'), [
                'name' => $name,
                'my_class_id' => $classY->id,
                'exam_id' => $scoped->id,
            ]);

        $response->assertRedirect(route('tt.index'));
        $response->assertSessionHas('flash_danger',
            'This exam is scoped to ' . $classX->name
            . ' — it cannot carry dates for ' . $classY->name . '.');
        $this->assertDatabaseMissing('time_table_records', ['name' => $name]);
    }

    public function test_scoped_own_and_global_controls_create()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $global = $this->freshExam('Global', 0, 9);
        $ownName = 'R29 Own ' . uniqid();
        $globalName = 'R29 Global ' . uniqid();

        $this->storeRecord([
            'name' => $ownName,
            'my_class_id' => $classX->id,
            'exam_id' => $scoped->id,
        ])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_table_records', [
            'name' => $ownName,
            'my_class_id' => $classX->id,
            'exam_id' => $scoped->id,
        ]);

        $this->storeRecord([
            'name' => $globalName,
            'my_class_id' => $classY->id,
            'exam_id' => $global->id,
        ])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseHas('time_table_records', [
            'name' => $globalName,
            'my_class_id' => $classY->id,
            'exam_id' => $global->id,
        ]);
    }

    /**
     * ROUND 30 UPDATE: round 29 asserted the update hole (re-point
     * succeeded). The round-30 guard now refuses it; the record keeps
     * its old (null) exam. Covered again by the round-30 guard suite.
     */
    public function test_update_record_cannot_repoint_at_scoped_exam()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $record = TimeTableRecord::create([
            'name' => 'R29 RePoint ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => null,
            'year' => '2026-2027',
        ]);

        $response = $this->actingAs($this->admin())
            ->from(route('tt.index'))
            ->put(route('ttr.update', $record->id), [
                'name' => $record->name,
                'my_class_id' => $classY->id,
                'exam_id' => $scoped->id,
            ]);

        $response->assertRedirect(route('tt.index'));
        $response->assertSessionHas('flash_danger',
            'This exam is scoped to ' . $classX->name
            . ' — it cannot carry dates for ' . $classY->name . '.');
        $this->assertNull($record->fresh()->exam_id);
    }

    public function test_admin_show_and_print_render_mismatched_record()
    {
        // Staff-surface rendering only: the admin created the row, so
        // showing it back to the admin is expected, not a family leak.
        // (First draft asserted the exam NAME here and failed: the
        // show/print blades render the record name + the "Exam
        // TimeTable" type label, not the exam's name. That is a blade
        // detail, not a finding.) This test pins that the document
        // exists; the family-facing surfaces are the next tests.
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $record = TimeTableRecord::create([
            'name' => 'R29 StaffView ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => $scoped->id,
            'year' => '2026-2027',
        ]);

        $this->actingAs($this->admin())->get(route('ttr.show', $record->id))
            ->assertStatus(200)->assertSee($record->name)->assertSee('Exam TimeTable');
        $this->actingAs($this->admin())->get(route('ttr.print', $record->id))
            ->assertStatus(200)->assertSee($record->name);
    }

    public function test_teacher_week_view_excludes_exam_date_cells()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $teacher = $this->freshUser('teacher', 'T');
        $subjectName = 'R29 ScopedGrid Subject ' . uniqid();
        $subject = Subject::create([
            'name' => $subjectName,
            'slug' => 'r29-scopedgrid-' . uniqid(),
            'my_class_id' => $classY->id,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R29 WeekView ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => $scoped->id,
            'year' => '2026-2027',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        // A real exam-grid cell: exam_date set, day NULL.
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'exam_date' => '2026-11-02',
            'day' => null,
            'day_num' => null,
            'subject_id' => $subject->id,
            'timestamp_from' => strtotime('2026-11-02 09:00:00'),
            'timestamp_to' => strtotime('2026-11-02 09:40:00'),
        ]);

        // teacherWeekData() filters whereNotNull('day') before any
        // teacher filter, so the exam cell can never enter the week
        // view even though its subject teacher is this teacher.
        // Assert on the WEEK-GRID VIEW DATA, not whole-page text:
        // the first draft's assertDontSee failed spuriously — the
        // subject name appears in the topbar teacher-reminder
        // dropdown (see the reminder-echo test below), not in the
        // week grid. entries and map are the grid itself.
        $response = $this->actingAs($teacher)->get(route('tt.mine'))->assertStatus(200);
        $response->assertViewHas('entries', function ($entries) use ($subjectName) {
            return collect($entries)->where('subject', $subjectName)->isEmpty();
        });
        $response->assertViewHas('map', function ($map) use ($subjectName) {
            foreach ($map as $dayCells) {
                foreach ($dayCells as $cell) {
                    if (($cell['title'] ?? null) === $subjectName) {
                        return false;
                    }
                }
            }

            return true;
        });
    }

    public function test_mismatched_sitting_echoes_in_subject_teacher_reminders_only()
    {
        // OBSERVATION, locked deliberately (not a fix target here):
        // TeacherReminders::forTeacher() derives upcoming exam
        // sittings from exam-date cells filtered by
        // subject.teacher_id, with NO exam-scope check, and the
        // topbar renders them for teachers only. So the mismatched
        // grid echoes to exactly the teacher who teaches that Y
        // subject — a staff-only, teacher-filtered echo of an
        // admin-created row, not a family leak and not a week-view
        // leak. A future exam-scope guard in TeacherReminders (or
        // the recommended creation guard in store_record/
        // update_record) would change this test.
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $teacher = $this->freshUser('teacher', 'TE');
        $foreign = $this->freshUser('teacher', 'TF');
        $subject = Subject::create([
            'name' => 'R29 Echo Subject ' . uniqid(),
            'slug' => 'r29-echo-' . uniqid(),
            'my_class_id' => $classY->id,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'R29 Echo ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => $scoped->id,
            'year' => '2026-2027',
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'exam_date' => '2026-11-02',
            'day' => null,
            'day_num' => null,
            'subject_id' => $subject->id,
            'timestamp_from' => strtotime('2026-11-02 09:00:00'),
            'timestamp_to' => strtotime('2026-11-02 09:40:00'),
        ]);

        // The subject teacher's bell carries the sitting.
        // Reset TeacherReminders' per-request memo first: it is a
        // static cache keyed by user id, and rolled-back
        // transactions free ids for reuse within one PHPUnit
        // process — without the reset, this teacher can inherit a
        // previous test's memo for the same recycled id (this test
        // passes alone and fails in-suite without it; that is a
        // test-isolation artifact, not an app bug — in production
        // the key is a real, distinct user id).
        $this->resetReminderCache();
        $this->actingAs($teacher)->get(route('tt.mine'))
            ->assertStatus(200)->assertSee('Exam: ' . $scoped->name);
        // A teacher with no link to the subject does not see it.
        $this->resetReminderCache();
        $this->actingAs($foreign)->get(route('tt.mine'))
            ->assertStatus(200)->assertDontSee('Exam: ' . $scoped->name);
    }

    protected function resetReminderCache(): void
    {
        $ref = new \ReflectionClass(\App\Support\TeacherReminders::class);
        $prop = $ref->getProperty('cache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_student_mine_never_returns_an_exam_record()
    {
        [$classX, $classY, $scoped] = $this->mismatchedPair();
        $section = Section::create([
            'name' => 'R29 Sec ' . uniqid(),
            'my_class_id' => $classY->id,
            'teacher_id' => $this->freshUser('teacher', 'S')->id,
        ]);
        $student = $this->freshUser('student', 'ST');
        StudentRecord::create([
            'session' => '2026-2027',
            'user_id' => $student->id,
            'my_class_id' => $classY->id,
            'section_id' => $section->id,
        ]);
        // The class has ONLY the mismatched exam grid — no class grid.
        TimeTableRecord::create([
            'name' => 'R29 StudentMine ' . uniqid(),
            'my_class_id' => $classY->id,
            'section_id' => null,
            'exam_id' => $scoped->id,
            'year' => '2026-2027',
        ]);

        // Mechanism: recordForClassSection() filters whereNull('exam_id').
        $this->assertNull(
            SectionTeacher::recordForClassSection($classY->id, $section->id, '2026-2027')
        );
        $this->actingAs($student)->get(route('tt.mine'))
            ->assertStatus(200)->assertDontSee($scoped->name);
    }
}
