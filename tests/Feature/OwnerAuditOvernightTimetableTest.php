<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDateSheetState;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\Repositories\MyClassRepo;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * AUDIT Round 1 — timetable / date-sheet overnight batch (AUD-TIMETABLE).
 * Verification only: no app file is touched by this file.
 *
 * Item 12 — timetable index grid redesign: readable columns, no
 *   vertical strips, same View/Print/Validate actions.
 * Item 13 — date-sheet class dropdown order: Prep, Class 1 … Class 12.
 *   Site-wide natural ordering already ships in MyClassRepo::all();
 *   these probes check every date-sheet dropdown path, NOT just all(),
 *   because the teacher read path builds its own list.
 * Item 22 — date-sheet approval reads: an unpublished sheet must be
 *   invisible to student/parent/teacher reads until principal
 *   approval, while admin/principal still see it.
 *   CAVEAT (honest, Round 1): no dedicated date-sheet approval
 *   column exists in the schema (Exam has only results_published,
 *   which the 2026-10-21 migration documents as the RESULTS switch).
 *   These probes therefore use results_published=false as the only
 *   existing "unpublished" switch. If S6 lands a dedicated approval
 *   flag, these probes must be re-pointed at it in the final round —
 *   but the Round 1 conclusion stands either way: today there is
 *   NO gate in ExamDateController::resolveScope(), so dated rows
 *   render to every scoped reader regardless of any publish state.
 */
class OwnerAuditOvernightTimetableTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal(): User
    {
        return User::where('user_type', 'principal')->firstOrFail();
    }

    protected function makeUser(string $type, string $tag): User
    {
        $u = uniqid();
        return User::create([
            'name' => 'AUD-TT ' . $tag . ' ' . $u,
            'email' => 'aud-tt-' . strtolower($type) . '-' . $u . '@test.local',
            'password' => bcrypt($type),
            'user_type' => $type,
            'code' => 'AUDTT' . strtoupper(substr($type, 0, 1)) . $u,
        ]);
    }

    /** Extract the option labels of a <select id=...> from rendered HTML. */
    protected function selectOptions(string $html, string $selectId): array
    {
        $start = strpos($html, 'id="' . $selectId . '"');
        $this->assertNotFalse($start, "Select #{$selectId} missing from page");
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, "Select #{$selectId} never closes");
        $segment = substr($html, $start, $end - $start);
        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $segment, $m);
        return array_map(function ($label) {
            return trim(strip_tags(html_entity_decode($label, ENT_QUOTES, 'UTF-8')));
        }, $m[1]);
    }

    /** Relative order of $wanted names inside $labels (ignores other classes). */
    protected function assertRelativeOrder(array $labels, array $wanted, string $where): void
    {
        $positions = [];
        foreach ($wanted as $name) {
            $pos = array_search($name, $labels, true);
            $this->assertNotFalse($pos, "{$where}: '{$name}' missing from dropdown (got: " . implode(' | ', $labels) . ')');
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, "{$where}: dropdown order is not Prep, 1…12 (got: " . implode(' | ', $labels) . ')');
    }

    protected const WANTED = ['Prep', 'Class 1', 'Class 2', 'Class 10', 'Class 12'];

    // ---------- Item 12: timetable index ----------

    public function test_item12_index_keeps_view_print_validate_and_no_vertical_strip_css()
    {
        $admin = $this->admin();
        $record = TimeTableRecord::where('year', Qs::getCurrentSession())->first();
        $this->assertNotNull($record, 'No current-session timetable record to probe (seeded fixture missing)');

        $html = $this->actingAs($admin)->get(route('tt.index'))->assertOk()->getContent();

        // Same actions as before the redesign, on the record's own row.
        $this->assertStringContainsString(route('ttr.show', $record->id), $html, 'View link missing on tt.index');
        $this->assertStringContainsString(route('ttr.print', $record->id), $html, 'Print link missing on tt.index');
        $this->assertStringContainsString(route('ttr.validate', $record->id), $html, 'Validate link missing on tt.index (admin)');
        $this->assertStringContainsString('> View<', $html);
        $this->assertStringContainsString('> Print<', $html);
        $this->assertStringContainsString('> Validate<', $html);

        // No squeezed/vertical-strip layout markers in the rendered page.
        $this->assertStringNotContainsString('writing-mode', $html, 'tt.index still carries vertical text');
        $this->assertStringContainsString('ttc-grid', $html, 'tt.index no longer renders the class-card grid');

        // The grid CSS itself: capped width + top-aligned cards, so a
        // wide/zoomed-out viewport cannot put all classes in one row
        // of tall empty strips (the reported defect).
        $css = file_get_contents(public_path('assets/css/qs.css'));
        $this->assertStringContainsString('.ttc-grid{display:grid', $css);
        $this->assertStringContainsString('max-width:1400px', $css, 'ttc-grid has no width cap — columns are uncapped on wide viewports');
        $this->assertStringContainsString('align-items:start', $css, 'ttc-grid cards still stretch to the tallest card (empty-strip defect)');
    }

    // ---------- Item 13: dropdown order ----------

    public function test_item13_repo_order_is_prep_then_numeric()
    {
        $names = app(MyClassRepo::class)->all()->pluck('name')->all();
        $this->assertRelativeOrder($names, self::WANTED, 'MyClassRepo::all()');
    }

    public function test_item13_admin_date_sheet_dropdown_order()
    {
        $exam = Exam::where('year', Qs::getCurrentSession())->first();
        $this->assertNotNull($exam, 'No current-session exam to probe');
        $html = $this->actingAs($this->admin())
            ->get(route('exam_dates.show', ['exam_id' => $exam->id]))
            ->assertOk()->getContent();
        $this->assertRelativeOrder($this->selectOptions($html, 'ds_class'), self::WANTED, 'admin date-sheet dropdown');
    }

    public function test_item13_teacher_date_sheet_dropdown_order()
    {
        $teacher = $this->makeUser('teacher', 'Teacher');
        $u = uniqid();
        foreach (self::WANTED as $i => $className) {
            $class = MyClass::where('name', $className)->first();
            $this->assertNotNull($class, "Seeded class '{$className}' missing — fixture broken");
            Subject::create([
                'name' => 'AUDTT' . $u . '-' . $i, 'slug' => 'aud-tt-' . $u . '-' . $i,
                'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
                'periods_per_week' => 6,
            ]);
        }
        $exam = Exam::where('year', Qs::getCurrentSession())->first();
        $this->assertNotNull($exam, 'No current-session exam to probe');
        $html = $this->actingAs($teacher)
            ->get(route('exam_dates.show', ['exam_id' => $exam->id]))
            ->assertOk()->getContent();
        $this->assertRelativeOrder($this->selectOptions($html, 'ds_class'), self::WANTED, 'teacher date-sheet dropdown');
    }

    public function test_item13_principal_date_sheet_has_class_dropdown()
    {
        $exam = Exam::where('year', Qs::getCurrentSession())->first();
        $this->assertNotNull($exam, 'No current-session exam to probe');
        $html = $this->actingAs($this->principal())
            ->get(route('exam_dates.show', ['exam_id' => $exam->id]))
            ->assertOk()->getContent();
        // Principal reads are school-wide (resolveScope principal branch
        // builds $classes = all()), so the page must offer the same
        // class selector other staff readers get — otherwise the
        // principal can only switch class by hand-crafting ?class_id=.
        $this->assertRelativeOrder($this->selectOptions($html, 'ds_class'), self::WANTED, 'principal date-sheet dropdown');
    }

    // ---------- Item 22: approval reads ----------

    protected function unpublishedFixture(): array
    {
        $u = uniqid();
        $class = MyClass::create(['name' => 'AUDTT Class ' . $u]);
        $teacher = $this->makeUser('teacher', 'Teacher');
        $subject = Subject::create([
            'name' => 'AUDTTSubj' . $u, 'slug' => 'aud-tt-subj-' . $u,
            'my_class_id' => $class->id, 'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
        ]);
        $exam = Exam::create([
            'name' => 'AUDTT Exam ' . $u, 'term' => 'AUDTT Term ' . $u,
            'year' => Qs::getCurrentSession(), 'my_class_id' => 0,
            'results_published' => false,
        ]);
        $row = ExamDate::create([
            'exam_id' => $exam->id, 'my_class_id' => $class->id,
            'subject_id' => $subject->id, 'exam_date' => '2026-11-09',
            'start_time' => '09:00:00', 'note' => 'AUDTT-NOTE-' . $u,
        ]);
        $section = Section::create([
            'name' => 'AUDTTSec' . $u, 'my_class_id' => $class->id,
            'active' => 1, 'teacher_id' => $teacher->id, 'stream' => null,
        ]);
        $parent = $this->makeUser('parent', 'Parent');
        $studentUser = $this->makeUser('student', 'Student');
        $student = StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $studentUser->id,
            'my_class_id' => $class->id, 'section_id' => $section->id,
            'my_parent_id' => $parent->id, 'adm_no' => 'AUDTT/' . $u, 'year_admitted' => '2026',
        ]);
        // Date label as the blade renders it for 2026-11-09.
        $label = 'Mon, 09 Nov 2026';
        $this->assertSame($label, \Illuminate\Support\Carbon::parse('2026-11-09')->format('D, d M Y'));
        return compact('class', 'teacher', 'subject', 'exam', 'row', 'parent', 'studentUser', 'student', 'label', 'u');
    }

    public function test_item22_unpublished_sheet_hidden_from_student()
    {
        $f = $this->unpublishedFixture();
        $html = $this->actingAs($f['studentUser'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString($f['label'], $html, 'STUDENT sees unpublished date-sheet date');
        $this->assertStringNotContainsString('AUDTT-NOTE-' . $f['u'], $html, 'STUDENT sees unpublished date-sheet note');
        $this->assertStringContainsString('Dates not announced yet', $html, 'STUDENT should get the honest unpublished empty state');
    }

    public function test_item22_unpublished_sheet_hidden_from_parent()
    {
        $f = $this->unpublishedFixture();
        $html = $this->actingAs($f['parent'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'student_id' => $f['studentUser']->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString($f['label'], $html, 'PARENT sees unpublished date-sheet date');
        $this->assertStringContainsString('Dates not announced yet', $html, 'PARENT should get the honest unpublished empty state');
    }

    public function test_item22_unpublished_sheet_hidden_from_teacher()
    {
        $f = $this->unpublishedFixture();
        $html = $this->actingAs($f['teacher'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'class_id' => $f['class']->id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString($f['label'], $html, 'TEACHER sees unpublished date-sheet date');
        $this->assertStringContainsString('Dates not announced yet', $html, 'TEACHER should get the honest unpublished empty state');
    }

    public function test_item22_unpublished_sheet_hidden_from_student_print()
    {
        $f = $this->unpublishedFixture();
        // Overnight item-22 rule: unpublished print/PDF redirect to the
        // index (the show page renders the honest "not announced" message
        // instead). Either way the date must never leak.
        $this->actingAs($f['studentUser'])
            ->get(route('exam_dates.print', ['exam_id' => $f['exam']->id]))
            ->assertRedirect(route('exam_dates.index'));
    }

    public function test_item22_unpublished_sheet_visible_to_admin_and_principal()
    {
        $f = $this->unpublishedFixture();

        $html = $this->actingAs($this->admin())
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'class_id' => $f['class']->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($f['label'], $html, 'ADMIN must still see the unpublished sheet (management view)');

        $html = $this->actingAs($this->principal())
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'class_id' => $f['class']->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($f['label'], $html, 'PRINCIPAL must still see the unpublished sheet (approver view)');
    }

    public function test_item22_published_sheet_visible_to_student_parent_teacher()
    {
        $f = $this->unpublishedFixture();
        // Publish via the dedicated date-sheet state machine (overnight
        // item 22) — NOT results_published, which publishes marks, not
        // date sheets.
        ExamDateSheetState::create([
            'exam_id' => $f['exam']->id, 'my_class_id' => $f['class']->id,
            'status' => ExamDateSheetState::PUBLISHED,
        ]);

        $html = $this->actingAs($f['studentUser'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($f['label'], $html, 'STUDENT must see the sheet once published/approved');

        $html = $this->actingAs($f['parent'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'student_id' => $f['studentUser']->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($f['label'], $html, 'PARENT must see the sheet once published/approved');

        $html = $this->actingAs($f['teacher'])
            ->get(route('exam_dates.show', ['exam_id' => $f['exam']->id, 'class_id' => $f['class']->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($f['label'], $html, 'TEACHER must see the sheet once published/approved');
    }
}
