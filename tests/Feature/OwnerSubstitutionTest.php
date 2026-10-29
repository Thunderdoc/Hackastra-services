<?php

namespace Tests\Feature;

use App\Models\Substitution;
use App\Models\TimeTable;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 1 feature 4 (Substitution): admin / absent-teacher create,
 * clash + duplicate refusal, per-role index scoping, and the
 * attendance page's "Sub:" marker. Anchors are resolved from the
 * seed (emails, weekday cells, effective teachers) — never
 * hardcoded ids — and every test uses the Monday 2026-11-02 so the
 * period's weekday always matches.
 */
class OwnerSubstitutionTest extends TestCase
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

    protected function otherTeacher()
    {
        return User::where('email', 'teacher1@teacher.com')->firstOrFail();
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

    /** First active teacher with no Monday cell overlapping the given cell. */
    protected function freeSubstitute(TimeTable $cell)
    {
        $overrides = $this->overrideMap();
        $window = $this->window($cell);
        $this->assertNotNull($window, 'Test cell must have a time slot window');
        $absentId = $this->effectiveTeacherId($cell, $overrides);

        foreach (User::where('user_type', 'teacher')->orderBy('id')->get() as $candidate) {
            if ((int) $candidate->id === (int) $absentId || !$candidate->isActiveAccount()) {
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

    /** A teacher effectively teaching another Monday cell overlapping the given cell. */
    protected function clashingSubstitute(TimeTable $cell)
    {
        $overrides = $this->overrideMap();
        $window = $this->window($cell);
        $this->assertNotNull($window, 'Test cell must have a time slot window');
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
            if ($otherWindow && $this->overlaps($window, $otherWindow)) {
                return User::findOrFail($otherTeacher);
            }
        }
        $this->fail('No clashing substitute found for cell ' . $cell->id);
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

    public function test_admin_creates_a_substitution()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseHas('substitutions', [
            'time_table_id' => $cell->id,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
        ]);
        $this->assertTrue(Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', $this->monday)->exists());
    }

    public function test_clash_is_refused()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->clashingSubstitute($cell);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertSessionHasErrors('substitute_teacher_id');
        $this->assertDatabaseMissing('substitutions', [
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $substitute->id,
        ]);
    }

    public function test_absent_teacher_create_is_retired_teacher_is_refused()
    {
        // Overnight menus 2026-10-29, item 19: absent-teacher
        // self-create is retired — substitutions are admin-only.
        // The teacher POST is refused and writes nothing; the
        // admin path (test_admin_creates_a_substitution) is the
        // only create path left.
        $teacher = $this->teacher();
        $cell = $this->ownCell($teacher);
        $substitute = $this->freeSubstitute($cell);

        $response = $this->actingAs($teacher)
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertForbidden();
        $this->assertDatabaseMissing('substitutions', [
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $substitute->id,
        ]);
    }

    public function test_another_teacher_creating_for_foreign_period_is_forbidden()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        $response = $this->actingAs($this->otherTeacher())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertForbidden();
        $this->assertDatabaseMissing('substitutions', [
            'time_table_id' => $cell->id,
        ]);
    }

    public function test_duplicate_same_period_and_date_is_refused()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertSessionHasErrors('time_table_id');
        $this->assertEquals(1, Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', $this->monday)->count());
    }

    public function test_index_lists_substitutions()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);

        $response = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($substitute->name);
        $response->assertSee($this->teacher()->name);

        // Overnight menus 2026-10-29, item 19: teachers no
        // longer see the board at all — the substitute themself
        // is refused too. The principal reads it for monitoring.
        $response = $this->actingAs($substitute)->get(route('substitutions.index'));
        $response->assertForbidden();
        $principal = User::where('email', 'principal@hackstra.app')->firstOrFail();
        $response = $this->actingAs($principal)->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($this->teacher()->name);
    }

    public function test_attendance_index_for_that_date_shows_the_substitute_name()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);

        $classId = optional($cell->tt_record)->my_class_id;
        $this->assertNotNull($classId, 'Test cell must belong to a class');

        $response = $this->actingAs($this->admin())->get(route('attendance.periods.index', [
            'date' => $this->monday,
            'class_id' => $classId,
        ]));
        $response->assertOk();
        $response->assertSee('Sub: ' . $substitute->name);
    }
}
