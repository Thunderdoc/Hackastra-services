<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\StudentRecord;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 11 — the substitution × override intersection.
 *
 * Round 7 proved an override-only teacher can be ABSENT (create for
 * her own override period, index shows absent + substitute rows,
 * delete own-only) and can appear as SUBSTITUTE in the index. What
 * was never proven is the take/submit side for an override teacher
 * AS SUBSTITUTE: the settled Wave 1 rule says a substitute CANNOT
 * take attendance (display only). This file locks that rule for the
 * override-substitute shape, plus the absent teacher's unchanged
 * access and the periods-index substitute marker.
 *
 * Anchor (resolved dynamically, never hardcoded): Ananya Sharma
 * (teacher@, id 4) effectively teaches a Friday cell that Ritu
 * Sharma (teacher20@, id 44, ZERO direct subjects) does not overlap
 * on Friday — Ritu's Friday load is only 9:40–10:20 and 2:00–2:40,
 * so a mid-morning Friday cell is clash-free for her. Date is the
 * past Friday 2026-10-23 (weekday matches the cell, no seeded
 * session exists for it, so take/store reach the guard instead of
 * the already-submitted redirect).
 *
 * Structural note: guardCanTake compares teacherIdFor(section,
 * subject) to the caller. A substitute is never the effective
 * teacher (store refuses substitute == absent), so the 403 below
 * is structural, not incidental — the test locks it against any
 * future "substitutes may take attendance" drift.
 */
class OwnerAllotmentRound11Test extends TestCase
{
    use DatabaseTransactions;

    protected string $friday = '2026-10-23';

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

    protected function fridayCells()
    {
        return TimeTable::with(['subject', 'time_slot', 'tt_record'])
            ->where('day', 'Friday')
            ->whereNotNull('subject_id')
            ->orderBy('id')
            ->get();
    }

    /** Minute-based window; 12-hour strings must never be compared raw. */
    protected function window(TimeTable $cell): ?array
    {
        $slot = $cell->time_slot;
        if (!$slot || !$slot->time_from || !$slot->time_to) {
            return null;
        }
        $start = Carbon::parse($this->friday . ' ' . $slot->time_from);
        $end = Carbon::parse($this->friday . ' ' . $slot->time_to);
        return $end->gt($start) ? [$start, $end] : null;
    }

    protected function overlaps(array $a, array $b): bool
    {
        return $a[0]->lt($b[1]) && $b[0]->lt($a[1]);
    }

    /** First Friday cell Ananya teaches that Ritu does not overlap. */
    protected function freeCellForRitu()
    {
        $overrides = $this->overrideMap();
        foreach ($this->fridayCells() as $cell) {
            if ($this->effectiveTeacherId($cell, $overrides) !== (int) $this->teacher()->id) {
                continue;
            }
            $window = $this->window($cell);
            if (!$window) {
                continue;
            }
            $clash = false;
            foreach ($this->fridayCells() as $other) {
                if ((int) $other->id === (int) $cell->id) {
                    continue;
                }
                if ($this->effectiveTeacherId($other, $overrides) !== (int) $this->ritu()->id) {
                    continue;
                }
                $otherWindow = $this->window($other);
                if ($otherWindow && $this->overlaps($window, $otherWindow)) {
                    $clash = true;
                    break;
                }
            }
            if ($clash) {
                continue;
            }
            $this->assertSame(0, AttendanceSession::whereDate('date', $this->friday)
                ->where('time_table_id', $cell->id)->count(),
                'Test cell must have no session on the probe date');
            return $cell;
        }
        $this->fail('No clash-free Friday cell for Ritu as substitute');
    }

    protected function makeSubstitution(TimeTable $cell)
    {
        return Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->friday,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $this->ritu()->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);
    }

    public function test_admin_can_name_override_teacher_as_substitute_via_store()
    {
        $ritu = $this->ritu();
        $this->assertSame(0, \App\Models\Subject::where('teacher_id', $ritu->id)->count(),
            'Ritu must be override-only for this test to discriminate');
        $cell = $this->freeCellForRitu();

        $response = $this->actingAs($this->admin())->post(route('substitutions.store'), [
            'date' => $this->friday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $ritu->id,
            'reason' => 'Sick leave',
        ]);

        $response->assertRedirect(route('substitutions.index'));
        // The date column stores a datetime ("2026-10-23 00:00:00"),
        // so assertDatabaseHas on the bare date string never matches
        // — match the date via whereDate instead (row 1 proves store).
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cell->id,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $ritu->id,
        ]);
        $this->assertSame(1, Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', $this->friday)->count());
    }

    public function test_admin_and_principal_see_row_teachers_refused()
    {
        // Overnight menus 2026-10-29, item 19: teacher board
        // visibility is gone — per-teacher row scoping (substitute
        // sees, absent sees, unrelated does not) is retired. The
        // admin sees the row, the principal reads it for
        // monitoring, and every teacher — substitute, absent and
        // unrelated alike — is refused.
        $cell = $this->freeCellForRitu();
        $this->makeSubstitution($cell);
        $used = [(int) $this->teacher()->id, (int) $this->ritu()->id, (int) $this->admin()->id];
        $other = User::where('user_type', 'teacher')->orderBy('id')->get()
            ->first(function ($u) use ($used) {
                return !in_array((int) $u->id, $used, true) && $u->isActiveAccount();
            });
        $this->assertNotNull($other, 'An unused active teacher must exist');

        $response = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($this->teacher()->name);
        $response->assertSee($this->ritu()->name);

        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $response = $this->actingAs($principal)->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($this->teacher()->name);

        $this->actingAs($this->ritu())->get(route('substitutions.index'))->assertForbidden();
        $this->actingAs($this->teacher())->get(route('substitutions.index'))->assertForbidden();
        $this->actingAs($other)->get(route('substitutions.index'))->assertForbidden();
    }

    public function test_override_substitute_cannot_take_period()
    {
        $cell = $this->freeCellForRitu();
        $this->makeSubstitution($cell);

        $response = $this->actingAs($this->ritu())
            ->get(route('attendance.periods.take', ['tt_id' => $cell->id, 'date' => $this->friday]));

        $response->assertForbidden();
        $this->assertSame(0, AttendanceSession::whereDate('date', $this->friday)
            ->where('time_table_id', $cell->id)->count(),
            'A refused take must not create a session');
    }

    public function test_override_substitute_cannot_submit_register()
    {
        $cell = $this->freeCellForRitu();
        $this->makeSubstitution($cell);
        $classId = optional($cell->tt_record)->my_class_id ?? $cell->subject->my_class_id;
        $sectionId = optional($cell->tt_record)->section_id;
        $roster = StudentRecord::where('grad', 0)->where('my_class_id', $classId)
            ->when($sectionId, function ($q) use ($sectionId) {
                $q->where('section_id', $sectionId);
            })->orderBy('id')->get();
        $this->assertNotEmpty($roster, 'Test class must have a roster');
        $statuses = [];
        foreach ($roster as $student) {
            $statuses[$student->id] = 'present';
        }

        $response = $this->actingAs($this->ritu())->post(route('attendance.periods.store', $cell->id), [
            'topic' => 'Cover lesson',
            'date' => $this->friday,
            'statuses' => $statuses,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, AttendanceSession::whereDate('date', $this->friday)
            ->where('time_table_id', $cell->id)->count(),
            'A refused submit must not create a session');
        $this->assertSame(0, DB::table('attendance_session_entries')
            ->whereIn('session_id', function ($q) use ($cell) {
                $q->select('id')->from('attendance_sessions')
                    ->whereDate('date', $this->friday)->where('time_table_id', $cell->id);
            })->count(),
            'A refused submit must not write entries');
    }

    public function test_absent_teacher_keeps_take_access_and_periods_index_names_substitute()
    {
        $cell = $this->freeCellForRitu();
        $classId = optional($cell->tt_record)->my_class_id ?? $cell->subject->my_class_id;

        // Before the substitution exists, the marker is absent.
        $response = $this->actingAs($this->admin())->get(route('attendance.periods.index', [
            'date' => $this->friday,
            'class_id' => $classId,
        ]));
        $response->assertOk();
        $response->assertDontSee($this->ritu()->name);

        $this->makeSubstitution($cell);

        // The absent teacher can still open her own take page.
        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.take', ['tt_id' => $cell->id, 'date' => $this->friday]));
        $response->assertOk();

        // The periods index now names the substitute for that period.
        $response = $this->actingAs($this->admin())->get(route('attendance.periods.index', [
            'date' => $this->friday,
            'class_id' => $classId,
        ]));
        $response->assertOk();
        $response->assertSee($this->ritu()->name);
    }
}
