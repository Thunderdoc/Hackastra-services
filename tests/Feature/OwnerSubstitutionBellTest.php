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
 * Roles audit round 6 — notification recipient audit. Substitutions
 * were the one "you must be somewhere" assignment with no bell:
 * duties have rung the teacher's bell since the 2026-10-25 batch
 * fix, but a substitute only learned by opening the index. Store
 * now bells the substitute (only the substitute — not the absent
 * teacher, not the creator), and destroy cleans that bell item
 * (PTM-cancel precedent, round 4). Anchors resolve from the seed;
 * every test uses the Monday 2026-11-02 so the weekday matches.
 */
class OwnerSubstitutionBellTest extends TestCase
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

    protected function payload(TimeTable $cell, User $substitute): array
    {
        return [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $substitute->id,
            'reason' => 'Sick leave',
        ];
    }

    protected function bellTitle(TimeTable $cell): string
    {
        return 'Substitution assigned: ' . $cell->subject->name . ' on 02 Nov 2026';
    }

    public function test_store_bells_the_substitute_only()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
            'title' => $this->bellTitle($cell),
            'link' => route('substitutions.index'),
        ]);
        // The absent teacher is covered, not an actor — no bell.
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->teacher()->id,
            'type' => 'substitution_assigned',
        ]);
        // The creator already knows — no self-bell.
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->admin()->id,
            'type' => 'substitution_assigned',
        ]);
    }

    public function test_admin_created_substitution_still_bells_substitute()
    {
        // Overnight menus 2026-10-29, item 19: absent-teacher
        // self-create is retired, so the bell case is re-pointed
        // to the admin creator — the substitute is still belled,
        // and only the substitute.
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
            'title' => $this->bellTitle($cell),
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $this->teacher()->id,
            'type' => 'substitution_assigned',
        ]);
    }

    public function test_destroy_cleans_the_substitute_bell()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));
        $sub = Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', $this->monday)->firstOrFail();
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
        ]);

        $response = $this->actingAs($this->admin())
            ->delete(route('substitutions.destroy', $sub->id));

        $response->assertRedirect(route('substitutions.index'));
        $this->assertDatabaseMissing('substitutions', ['id' => $sub->id]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
            'title' => $this->bellTitle($cell),
        ]);
    }

    public function test_refused_substitution_bells_nobody()
    {
        $cell = $this->ownCell($this->teacher());
        $substitute = $this->freeSubstitute($cell);

        // A duplicate for the same cell+date is refused by the
        // unique guard — create the first row directly, then POST.
        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $this->teacher()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
        ]);

        $response = $this->actingAs($this->admin())
            ->post(route('substitutions.store'), $this->payload($cell, $substitute));

        $response->assertSessionHasErrors('time_table_id');
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $substitute->id,
            'type' => 'substitution_assigned',
        ]);
    }
}
