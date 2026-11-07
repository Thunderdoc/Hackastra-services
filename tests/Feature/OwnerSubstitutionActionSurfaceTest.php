<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\SubstitutionController;
use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\LeaveCover;
use App\Support\UncoveredPeriods;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round 3 Phase 1, Item 2 (owner order 2026-11-07): the
 * substitution ACTION surface for uncovered periods — Admin and
 * Principal allot a free teacher or cancel the class; cancelled
 * periods are resolved everywhere (enumeration, leave-cover
 * counts, auto-cover, period attendance). Anchors resolve from
 * the seed like the neighbour substitution suites, on the Monday
 * 2026-11-02 so weekday and cell always agree.
 */
class OwnerSubstitutionActionSurfaceTest extends TestCase
{
    use DatabaseTransactions;

    protected string $monday = '2026-11-02';

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
    }

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

    /** The given teacher's Monday cells with NO substitution row
     *  of any kind on the fixture Monday yet. */
    protected function freeCellsOf(User $teacher)
    {
        $overrides = $this->overrideMap();
        $taken = Substitution::whereDate('date', $this->monday)->pluck('time_table_id')
            ->map(function ($i) { return (int) $i; })->all();

        return $this->mondayCells()->filter(function ($cell) use ($teacher, $overrides, $taken) {
            return $this->effectiveTeacherId($cell, $overrides) === (int) $teacher->id
                && !in_array((int) $cell->id, $taken, true);
        })->values();
    }

    protected function approvedLeave(User $teacher): LeaveRequest
    {
        return LeaveRequest::create([
            'user_id' => $teacher->id,
            'start_date' => $this->monday,
            'end_date' => $this->monday,
            'reason' => 'Action surface fixture',
            'leave_type' => 'normal',
            'status' => 'approved',
            'decided_by' => $this->admin()->id,
            'decided_at' => now(),
        ]);
    }

    protected function uncoveredTtIds(): array
    {
        return collect(UncoveredPeriods::forDate($this->monday))
            ->pluck('time_table_id')->map(function ($i) { return (int) $i; })->all();
    }

    /** First teacher the board itself offers for this cell — the
     *  allot path validates against this exact list. */
    protected function offeredFreeTeacher(TimeTable $cell, int $absentId)
    {
        $synthetic = new Substitution([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $absentId,
        ]);
        $synthetic->setRelation('time_table', $cell);
        $free = (new SubstitutionController())->freeTeachersFor($synthetic);
        $this->assertTrue($free->isNotEmpty(), 'No free teacher offered for cell ' . $cell->id);

        return $free->first();
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

    /** A teacher whose own Monday cell overlaps the given cell —
     *  never free for it. */
    protected function clashingTeacher(TimeTable $cell)
    {
        $overrides = $this->overrideMap();
        $window = $this->window($cell);
        $this->assertNotNull($window);
        $absentId = $this->effectiveTeacherId($cell, $overrides);
        foreach ($this->mondayCells() as $other) {
            if ((int) $other->id === (int) $cell->id) {
                continue;
            }
            $otherTeacher = $this->effectiveTeacherId($other, $overrides);
            if (!$otherTeacher || (int) $otherTeacher === (int) $absentId) {
                continue;
            }
            $otherWindow = $this->window($other);
            if ($otherWindow && $window[0]->lt($otherWindow[1]) && $otherWindow[0]->lt($window[1])) {
                return User::findOrFail($otherTeacher);
            }
        }
        $this->fail('No clashing teacher found for cell ' . $cell->id);
    }

    /** One of the teacher's uncovered Monday cells whose class /
     *  section actually has students (so student bells can land). */
    protected function cellWithStudents(User $teacher)
    {
        foreach ($this->freeCellsOf($teacher) as $cell) {
            $record = $cell->tt_record;
            $q = StudentRecord::where('grad', 0);
            if ($record && $record->section_id) {
                $q->where('section_id', $record->section_id);
            } elseif ($record) {
                $q->where('my_class_id', $record->my_class_id);
            } else {
                continue;
            }
            if ($q->count() > 0) {
                return $cell;
            }
        }
        $this->fail('No Monday cell with students found for ' . $teacher->email);
    }

    public function test_enumeration_shows_uncovered_and_hides_covered()
    {
        $teacher = $this->teacher();
        $this->approvedLeave($teacher);
        $cells = $this->freeCellsOf($teacher);
        $this->assertGreaterThanOrEqual(2, $cells->count());
        [$gap, $coveredCell] = [$cells[0], $cells[1]];

        $this->assertContains((int) $gap->id, $this->uncoveredTtIds());

        Substitution::create([
            'time_table_id' => $coveredCell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => $this->otherTeacher()->id,
            'created_by' => $this->admin()->id,
            'status' => 'confirmed',
        ]);

        $ids = $this->uncoveredTtIds();
        $this->assertContains((int) $gap->id, $ids);
        $this->assertNotContains((int) $coveredCell->id, $ids);
    }

    public function test_cancelled_period_disappears_from_enumeration_and_leave_cover_counts()
    {
        $teacher = $this->teacher();
        $this->approvedLeave($teacher);
        $cell = $this->freeCellsOf($teacher)->first();
        $this->assertNotNull($cell);

        $countBefore = LeaveCover::uncoveredCount($teacher->id, $this->monday, $this->monday);
        $impactBefore = LeaveCover::impactSummary($teacher->id, $this->monday, $this->monday);
        $this->assertContains((int) $cell->id, $this->uncoveredTtIds());

        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => null,
            'created_by' => $this->admin()->id,
            'reason' => 'Class cancelled by the office',
            'status' => 'cancelled',
        ]);

        $this->assertNotContains((int) $cell->id, $this->uncoveredTtIds());
        // Cancelled = resolved: neither uncovered NOR affected.
        $this->assertSame($countBefore - 1, LeaveCover::uncoveredCount($teacher->id, $this->monday, $this->monday));
        $impactAfter = LeaveCover::impactSummary($teacher->id, $this->monday, $this->monday);
        $this->assertSame($impactBefore['affected'] - 1, $impactAfter['affected']);
        $this->assertSame($impactBefore['uncovered'] - 1, $impactAfter['uncovered']);
        $this->assertSame($impactBefore['covered'], $impactAfter['covered']);
    }

    public function test_allot_happy_path_confirms_and_bells()
    {
        $teacher = $this->teacher();
        $this->approvedLeave($teacher);
        $cell = $this->cellWithStudents($teacher);
        $free = $this->offeredFreeTeacher($cell, (int) $teacher->id);

        $response = $this->actingAs($this->admin())->post(route('substitutions.allot'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $free->id,
        ]);

        $response->assertRedirect(route('substitutions.uncovered', ['date' => $this->monday]));
        $response->assertSessionHas('flash_success');
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cell->id,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => $free->id,
            'status' => 'confirmed',
            'reason' => 'Manual allotment',
        ]);
        $this->assertTrue(UserNotification::where('user_id', $free->id)
            ->where('type', 'substitution_assigned')->exists(), 'substitute bell missing');
        $this->assertGreaterThan(0, UserNotification::where('type', 'period_substitute')->count(),
            'student bells missing');
        $this->assertNotContains((int) $cell->id, $this->uncoveredTtIds());
    }

    public function test_allot_refuses_a_teacher_who_is_not_free()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        $busy = $this->clashingTeacher($cell);

        $response = $this->actingAs($this->admin())->post(route('substitutions.allot'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $busy->id,
        ]);

        $response->assertSessionHasErrors('substitute_teacher_id');
        $this->assertDatabaseMissing('substitutions', [
            'time_table_id' => $cell->id,
        ]);
    }

    public function test_allot_revives_a_cancelled_row()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        $cancelled = Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => null,
            'created_by' => $this->admin()->id,
            'reason' => 'Class cancelled by the office',
            'status' => 'cancelled',
        ]);
        $free = $this->offeredFreeTeacher($cell, (int) $teacher->id);

        $response = $this->actingAs($this->admin())->post(route('substitutions.allot'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $free->id,
        ]);

        $response->assertSessionHas('flash_success');
        $row = Substitution::findOrFail($cancelled->id);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame((int) $free->id, (int) $row->substitute_teacher_id);
        $this->assertSame(1, Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', $this->monday)->count());
    }

    public function test_cancel_happy_path_bells_students()
    {
        $teacher = $this->teacher();
        $cell = $this->cellWithStudents($teacher);

        $response = $this->actingAs($this->admin())->post(route('substitutions.cancel_class'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
        ]);

        $response->assertRedirect(route('substitutions.uncovered', ['date' => $this->monday]));
        $response->assertSessionHas('flash_success');
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cell->id,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => null,
            'status' => 'cancelled',
        ]);
        $this->assertGreaterThan(0, UserNotification::where('type', 'period_cancelled')->count(),
            'student cancelled bells missing');
    }

    public function test_cancel_refuses_when_a_substitute_is_assigned()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => $this->otherTeacher()->id,
            'created_by' => $this->admin()->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->admin())->post(route('substitutions.cancel_class'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
        ]);

        $response->assertSessionHasErrors('time_table_id');
        $row = Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->firstOrFail();
        $this->assertSame('confirmed', $row->status);
        $this->assertSame((int) $this->otherTeacher()->id, (int) $row->substitute_teacher_id);
    }

    public function test_principal_can_view_allot_and_cancel()
    {
        $teacher = $this->teacher();
        $principal = $this->principal();
        $this->approvedLeave($teacher);
        $cells = $this->freeCellsOf($teacher);
        $this->assertGreaterThanOrEqual(2, $cells->count());

        $response = $this->actingAs($principal)
            ->get(route('substitutions.uncovered', ['date' => $this->monday]));
        $response->assertOk();
        $response->assertSee($teacher->name);

        $free = $this->offeredFreeTeacher($cells[0], (int) $teacher->id);
        $this->actingAs($principal)->post(route('substitutions.allot'), [
            'date' => $this->monday,
            'time_table_id' => $cells[0]->id,
            'substitute_teacher_id' => $free->id,
        ])->assertSessionHas('flash_success');
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cells[0]->id,
            'substitute_teacher_id' => $free->id,
            'status' => 'confirmed',
            'created_by' => $principal->id,
        ]);

        $this->actingAs($principal)->post(route('substitutions.cancel_class'), [
            'date' => $this->monday,
            'time_table_id' => $cells[1]->id,
        ])->assertSessionHas('flash_success');
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cells[1]->id,
            'status' => 'cancelled',
            'created_by' => $principal->id,
        ]);
    }

    public function test_teacher_and_super_admin_are_refused_everywhere()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        $payload = [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $this->otherTeacher()->id,
        ];

        foreach ([$teacher, $this->superAdmin()] as $actor) {
            $this->actingAs($actor)->post(route('substitutions.allot'), $payload)
                ->assertForbidden();
            $this->actingAs($actor)->post(route('substitutions.cancel_class'), [
                'date' => $this->monday,
                'time_table_id' => $cell->id,
            ])->assertForbidden();
        }
        $this->actingAs($teacher)->get(route('substitutions.uncovered', ['date' => $this->monday]))
            ->assertForbidden();
        // The Super Admin never reaches ANY substitutions page:
        // the app's SA boundary redirects him to his platform
        // dashboard first — the pre-existing board behaves exactly
        // the same, so the new page pins refusal as a redirect,
        // never a 200.
        $this->actingAs($this->superAdmin())
            ->get(route('substitutions.uncovered', ['date' => $this->monday]))
            ->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('substitutions', ['time_table_id' => $cell->id]);
    }

    public function test_board_renders_the_cancelled_badge()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => null,
            'created_by' => $this->admin()->id,
            'reason' => 'Class cancelled by the office',
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee('Cancelled');
        $response->assertSee('— Class cancelled —');
    }

    public function test_cancelled_period_renders_cancelled_in_period_attendance_and_take_is_refused()
    {
        $teacher = $this->teacher();
        $cell = $this->freeCellsOf($teacher)->first();
        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $teacher->id,
            'substitute_teacher_id' => null,
            'created_by' => $this->admin()->id,
            'reason' => 'Class cancelled by the office',
            'status' => 'cancelled',
        ]);

        $response = $this->actingAs($teacher)->get(route('attendance.periods.index', [
            'date' => $this->monday,
        ]));
        $response->assertOk();
        $response->assertSee('Cancelled');
        $response->assertSee('No register expected');

        $response = $this->actingAs($teacher)->get(route('attendance.periods.take', [
            $cell->id, 'date' => $this->monday,
        ]));
        $response->assertRedirect(route('attendance.periods.index', ['date' => $this->monday]));
        $response->assertSessionHas('flash_danger');
    }
}
