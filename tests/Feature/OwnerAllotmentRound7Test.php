<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\ExamSubjectComponent;
use App\Models\Mark;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 7 — the remaining F1-cousin surfaces: substitutions
 * and class overview (+ dashboard attention strip) for override-only
 * teachers. Anchor: Ritu Sharma (teacher20@teacher.com, id 44) has
 * ZERO direct subjects and ZERO led sections — every period she
 * teaches comes from a section override (Class 11-A/D/G English 67,
 * Class 12-B/H English 79). Under any raw-subject-teacher scope she
 * would see NOTHING on these surfaces; under the effective-teacher
 * scope she sees exactly her override work. Negative case: Class
 * 11-B English (section 56, subject 67) is overridden to another
 * teacher, so Ritu must be refused there. Monday 2026-11-02 is used
 * so each period's weekday always matches.
 */
class OwnerAllotmentRound7Test extends TestCase
{
    use DatabaseTransactions;

    protected string $monday = '2026-11-02';

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** Override-only teacher: no direct subjects, no led sections. */
    protected function ritu()
    {
        return User::where('email', 'teacher20@teacher.com')->firstOrFail();
    }

    /** section_id|subject_id => override teacher id, mirroring SectionTeacher. */
    protected function overrideMap(): array
    {
        $map = [];
        foreach (DB::table('section_subject_teachers')->get() as $row) {
            $map[$row->section_id . '|' . $row->subject_id] = (int) $row->teacher_id;
        }
        return $map;
    }

    protected function effectiveTeacherId(TimeTable $cell, array $overrides): ?int
    {
        $sectionId = optional($cell->tt_record)->section_id;
        if ($sectionId && isset($overrides[$sectionId . '|' . $cell->subject_id])) {
            return $overrides[$sectionId . '|' . $cell->subject_id];
        }
        return $cell->subject ? (int) $cell->subject->teacher_id : null;
    }

    protected function mondayCells()
    {
        return TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', 'Monday')
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->get();
    }

    /** First Monday cell effectively taught by the given teacher. */
    protected function ownCell(User $teacher)
    {
        $overrides = $this->overrideMap();
        foreach ($this->mondayCells() as $cell) {
            if ($this->effectiveTeacherId($cell, $overrides) === (int) $teacher->id) {
                return $cell;
            }
        }
        $this->fail('No Monday cell found for ' . $teacher->email);
    }

    /** Monday cell for Class 11-B English (section 56, subject 67). */
    protected function foreignSectionCell()
    {
        $cell = TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', 'Monday')
            ->whereHas('tt_record', function ($q) {
                $q->where('section_id', 56);
            })
            ->where('subject_id', 67)
            ->orderBy('id')
            ->first();
        $this->assertNotNull($cell, 'Section 56 English Monday cell must exist');
        $this->assertNotSame(
            (int) $this->ritu()->id,
            $this->effectiveTeacherId($cell, $this->overrideMap()),
            'Section 56 English must not be Ritu\'s override'
        );

        return $cell;
    }

    protected function window(TimeTable $cell): ?array
    {
        $slot = $cell->time_slot;
        if (!$slot || !$slot->time_from || !$slot->time_to) {
            return null;
        }
        $start = Carbon::parse($this->monday . ' ' . $slot->time_from);
        $end = Carbon::parse($this->monday . ' ' . $slot->time_to);
        return $end->gt($start) ? [$start, $end] : null;
    }

    protected function overlaps(array $a, array $b): bool
    {
        return $a[0]->lt($b[1]) && $b[0]->lt($a[1]);
    }

    /** First active teacher clash-free at the cell's window, minus exclusions. */
    protected function freeSubstitute(TimeTable $cell, array $excludeIds = [])
    {
        $overrides = $this->overrideMap();
        $window = $this->window($cell);
        $this->assertNotNull($window, 'Test cell must have a time slot window');
        $absentId = $this->effectiveTeacherId($cell, $overrides);

        foreach (User::where('user_type', 'teacher')->orderBy('id')->get() as $candidate) {
            if ((int) $candidate->id === (int) $absentId
                || in_array((int) $candidate->id, $excludeIds, true)
                || !$candidate->isActiveAccount()) {
                continue;
            }
            $busy = false;
            foreach ($this->mondayCells() as $other) {
                if ((int) $other->id === (int) $cell->id) {
                    continue;
                }
                if ($this->effectiveTeacherId($other, $overrides) !== (int) $candidate->id) {
                    continue;
                }
                $otherWindow = $this->window($other);
                if ($otherWindow && $this->overlaps($window, $otherWindow)) {
                    $busy = true;
                    break;
                }
            }
            if (!$busy) {
                return $candidate;
            }
        }
        $this->fail('No clash-free substitute found for cell ' . $cell->id);
    }

    protected function payload(TimeTable $cell, User $substitute): array
    {
        return [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $substitute->id,
            'reason' => 'Sick leave',
        ];
    }

    /** The <select id="sub_period"> block of a create-page response. */
    protected function periodSelectBlock(string $html): string
    {
        $start = strpos($html, 'id="sub_period"');
        $this->assertNotFalse($start, 'Period select must render');
        $end = strpos($html, '</select>', $start);
        $this->assertNotFalse($end, 'Period select must close');

        return substr($html, $start, $end - $start);
    }

    public function test_override_teacher_creates_substitution_for_own_override_period()
    {
        $ritu = $this->ritu();
        $this->assertSame(0, Subject::where('teacher_id', $ritu->id)->count(),
            'Ritu must be override-only for this test to discriminate');
        $cell = $this->ownCell($ritu);
        $substitute = $this->freeSubstitute($cell, [(int) $this->teacher()->id]);

        $response = $this->actingAs($ritu)
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cell->id,
            'absent_teacher_id' => $ritu->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $ritu->id,
        ]);
    }

    public function test_override_teacher_create_form_lists_only_own_periods()
    {
        $ritu = $this->ritu();
        $own = $this->ownCell($ritu);
        $foreign = $this->foreignSectionCell();

        $response = $this->actingAs($ritu)->get(route('substitutions.create'));
        $response->assertOk();
        $block = $this->periodSelectBlock($response->getContent());

        $this->assertStringContainsString('value="' . $own->id . '"', $block,
            'Ritu\'s override period must be offered in the picker');
        $this->assertStringNotContainsString('value="' . $foreign->id . '"', $block,
            'Another section\'s period must not be offered to Ritu');
    }

    public function test_override_teacher_cannot_create_for_foreign_section_period()
    {
        $ritu = $this->ritu();
        $foreign = $this->foreignSectionCell();
        $substitute = $this->freeSubstitute($foreign, [(int) $this->teacher()->id]);

        $response = $this->actingAs($ritu)
            ->post(route('substitutions.store'), $this->payload($foreign, $substitute));

        $response->assertForbidden();
        $this->assertDatabaseMissing('substitutions', [
            'time_table_id' => $foreign->id,
        ]);
    }

    public function test_override_teacher_index_shows_absent_and_substitute_rows_and_hides_others()
    {
        $ritu = $this->ritu();
        $teacher = $this->teacher();
        $own = $this->ownCell($ritu);
        $sub1 = $this->freeSubstitute($own, [(int) $teacher->id]);

        // Row 1: Ritu is absent. Row 3: Ritu is neither absent nor
        // substitute (absent = a teacher used nowhere else here).
        $used = [(int) $ritu->id, (int) $sub1->id, (int) $teacher->id];
        $other = User::where('user_type', 'teacher')->orderBy('id')->get()
            ->first(function ($u) use ($used) {
                return !in_array((int) $u->id, $used, true) && $u->isActiveAccount();
            });
        $this->assertNotNull($other, 'An unused active teacher must exist');

        Substitution::create([
            'time_table_id' => $own->id,
            'date' => $this->monday,
            'absent_teacher_id' => $ritu->id,
            'substitute_teacher_id' => $sub1->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);
        Substitution::create([
            'time_table_id' => $own->id,
            'date' => '2026-11-09',
            'absent_teacher_id' => $other->id,
            'substitute_teacher_id' => $teacher->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Workshop',
        ]);

        $response = $this->actingAs($ritu)->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($sub1->name);
        $response->assertDontSee($other->name);

        // Row 2: Ritu is the substitute — now she must see it too.
        Substitution::create([
            'time_table_id' => $this->ownCell($teacher)->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => $ritu->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);

        $response = $this->actingAs($ritu)->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($teacher->name);
    }

    public function test_override_teacher_can_delete_own_substitution_and_not_others()
    {
        $ritu = $this->ritu();
        $own = $this->ownCell($ritu);
        $sub1 = $this->freeSubstitute($own, [(int) $this->teacher()->id]);

        $mine = Substitution::create([
            'time_table_id' => $own->id,
            'date' => $this->monday,
            'absent_teacher_id' => $ritu->id,
            'substitute_teacher_id' => $sub1->id,
            'created_by' => $ritu->id,
            'reason' => 'Sick leave',
        ]);
        $response = $this->actingAs($ritu)
            ->delete(route('substitutions.destroy', $mine->id));
        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseMissing('substitutions', ['id' => $mine->id]);

        $others = Substitution::create([
            'time_table_id' => $own->id,
            'date' => '2026-11-09',
            'absent_teacher_id' => $ritu->id,
            'substitute_teacher_id' => $sub1->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);
        $response = $this->actingAs($ritu)
            ->delete(route('substitutions.destroy', $others->id));
        $response->assertForbidden();
        $this->assertDatabaseHas('substitutions', ['id' => $others->id]);
    }

    /** Ritu's override subject ids inside one class. */
    protected function rituSubjectIdsInClass(int $classId): array
    {
        $ids = DB::table('section_subject_teachers')
            ->where('teacher_id', $this->ritu()->id)
            ->pluck('subject_id');
        return Subject::whereIn('id', $ids)->where('my_class_id', $classId)
            ->pluck('id')->all();
    }

    public function test_override_teacher_class_overview_shows_override_classes()
    {
        $ritu = $this->ritu();
        $this->assertSame(0, Subject::where('teacher_id', $ritu->id)->count(),
            'Ritu must be override-only for this test to discriminate');

        $response = $this->actingAs($ritu)->get(route('class_overview.index'));
        $response->assertOk();
        // Under any raw-subject scope this page would be empty for her.
        $response->assertSee('Class 11');
        $response->assertSee('Class 12');
        $response->assertDontSee('Prep');
    }

    public function test_override_teacher_overview_detail_uses_override_subject_figures()
    {
        $ritu = $this->ritu();
        $classId = 12; // Class 11
        $subjectIds = $this->rituSubjectIdsInClass($classId);
        $this->assertNotEmpty($subjectIds, 'Ritu must hold overrides in Class 11');

        $strength = StudentRecord::where('grad', 0)->where('my_class_id', $classId)->count();

        // Mirror ClassOverviewController::snapshot for this teacher+class.
        $sessionIds = AttendanceSession::where('my_class_id', $classId)
            ->where('teacher_id', $ritu->id)
            ->whereIn('subject_id', $subjectIds)
            ->pluck('id');
        $recordIdToUserId = StudentRecord::where('grad', 0)->where('my_class_id', $classId)
            ->pluck('user_id', 'id');
        $attByUser = [];
        if ($sessionIds->isNotEmpty()) {
            $rows = DB::table('attendance_session_entries')
                ->whereIn('session_id', $sessionIds)
                ->select('student_record_id',
                    DB::raw("sum(case when status in ('present','late') then 1 else 0 end) as ok"),
                    DB::raw('count(*) as total'))
                ->groupBy('student_record_id')
                ->get();
            foreach ($rows as $row) {
                $userId = $recordIdToUserId[$row->student_record_id] ?? null;
                if ($userId && (int) $row->total > 0) {
                    $attByUser[$userId] = ((int) $row->ok / (int) $row->total) * 100;
                }
            }
        }
        $marksAvg = null;
        $topperName = null;
        $marksByUser = [];
        $latestExamId = Mark::where('my_class_id', $classId)
            ->whereIn('subject_id', $subjectIds)->max('exam_id');
        if ($latestExamId) {
            $totals = ExamSubjectComponent::where('exam_id', $latestExamId)
                ->whereIn('subject_id', $subjectIds)
                ->select('subject_id', DB::raw('sum(max_marks) as total'))
                ->groupBy('subject_id')
                ->pluck('total', 'subject_id');
            $markRows = Mark::where('my_class_id', $classId)
                ->where('exam_id', $latestExamId)
                ->whereIn('subject_id', $subjectIds)
                ->get();
            $perStudent = [];
            foreach ($markRows as $mark) {
                $total = (int) ($totals[$mark->subject_id] ?? 100);
                if ($total <= 0) {
                    continue;
                }
                $score = ((int) ($mark->tca ?? 0)) + ((int) ($mark->exm ?? 0));
                $perStudent[$mark->student_id][] = ($score / $total) * 100;
            }
            foreach ($perStudent as $studentId => $percents) {
                $marksByUser[$studentId] = array_sum($percents) / count($percents);
            }
            if (!empty($marksByUser)) {
                $marksAvg = array_sum($marksByUser) / count($marksByUser);
                $topperId = collect($marksByUser)->sortDesc()->keys()->first();
                $topperName = User::findOrFail($topperId)->name;
            }
        }
        $nameByUserId = StudentRecord::where('grad', 0)->where('my_class_id', $classId)
            ->with('user')->get()->mapWithKeys(function ($record) {
                return [$record->user_id => optional($record->user)->name ?: 'Student'];
            });
        $atRisk = 0;
        foreach ($nameByUserId as $studentId => $name) {
            $lowAttendance = isset($attByUser[$studentId]) && $attByUser[$studentId] < 50;
            $lowMarks = isset($marksByUser[$studentId]) && $marksByUser[$studentId] < 40;
            if ($lowAttendance || $lowMarks) {
                $atRisk++;
            }
        }

        $response = $this->actingAs($ritu)
            ->get(route('class_overview.index', ['class_id' => $classId]));
        $response->assertOk();
        $response->assertSee('Class 11 — Detail');
        $response->assertSee('Strength: ' . number_format($strength) . ' students.');
        if ($marksAvg !== null) {
            $response->assertSee(number_format($marksAvg, 1) . '%');
            $response->assertSee($topperName);
        }
        $response->assertSee('Students needing attention (' . $atRisk . ')');
    }

    public function test_override_teacher_overview_foreign_class_forbidden()
    {
        $this->actingAs($this->ritu())
            ->get(route('class_overview.index', ['class_id' => 1]))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('class_overview.index', ['class_id' => 1]))
            ->assertOk();
    }

    public function test_override_teacher_dashboard_attention_strip_uses_override_periods()
    {
        $ritu = $this->ritu();
        $today = Carbon::now();
        $periods = TimeTable::where('day', $today->format('l'));
        SectionTeacher::applyTeacherFilter($periods, (int) $ritu->id);
        $periodIds = $periods->pluck('id');
        $submitted = AttendanceSession::whereDate('date', $today->toDateString())
            ->whereIn('time_table_id', $periodIds)
            ->pluck('time_table_id');
        $untaken = $periodIds->diff($submitted)->count();

        $response = $this->actingAs($ritu)->get('/dashboard');
        $response->assertOk();
        if ($untaken > 0) {
            // Under any raw-subject scope Ritu would have no periods
            // today and no strip at all.
            $response->assertSee('Needs attention');
            $response->assertSee(number_format($untaken));
            $response->assertSee('still');
            $response->assertSee('English Language');
        }
    }
}
