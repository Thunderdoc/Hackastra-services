<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimetableSnapshot;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T4 — grid snapshots (master prompt, owner order 2026-10-29).
 * Approval freezes the grid that went into force; a regenerate
 * freezes the outgoing grid first; a restore rebuilds it as a
 * NEW draft, cell for cell — regenerating is never a point of
 * no return.
 */
class OwnerTimetableSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function principal(): User
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    public function test_approval_freezes_the_grid()
    {
        $record = TimeTableRecord::findOrFail(12);
        $cells = TimeTable::where('ttr_id', 12)->count();
        $this->assertGreaterThan(0, $cells);
        TimeTableRecord::where('id', 12)->update([
            'approval_status' => 'submitted',
            'approval_submitted_by' => $this->admin()->id,
            'approval_submitted_at' => now(),
        ]);

        $this->actingAs($this->principal())
            ->post(route('ttr.approve', 12))
            ->assertSessionHas('flash_success');

        $snap = TimetableSnapshot::where('ttr_id', 12)
            ->where('label', 'like', 'Approved%')->first();
        $this->assertNotNull($snap, 'approval must freeze the grid');
        $this->assertSame($cells, $snap->cellCount());
    }

    public function test_regenerate_snapshots_first_and_restore_rebuilds_the_grid()
    {
        $class = MyClass::create(['name' => 'Snap Class']);
        $t1 = User::create(['name' => 'Snap Teacher', 'email' => 'snap-t1@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'SNPT1']);
        Subject::create(['name' => 'Snap Subject', 'slug' => 'snap-a', 'my_class_id' => $class->id, 'teacher_id' => $t1->id, 'periods_per_week' => 6]);

        $classId = $class->id;
        $generate = function () use ($classId) {
            return $this->actingAs($this->admin())->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 8,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 5, 'scope' => 'one', 'class_id' => $classId, 'mode' => 'replace',
            ]);
        };
        $generate();
        $record = TimeTableRecord::where('my_class_id', $class->id)->firstOrFail();
        $firstCells = TimeTable::where('ttr_id', $record->id)->get()
            ->map(function ($c) {
                return $c->day . '|' . $c->subject_id . '|' . (((int) $c->timestamp_from) % 86400);
            })->sort()->values()->all();
        $this->assertNotEmpty($firstCells);

        $generate(); // the replace must freeze the outgoing grid
        $snap = TimetableSnapshot::where('ttr_id', $record->id)
            ->where('label', 'like', 'Before regenerate%')->first();
        $this->assertNotNull($snap, 'a regenerate must snapshot the outgoing grid first');
        $this->assertSame(count($firstCells), $snap->cellCount());

        // Only the Admin restores.
        $this->actingAs($this->principal())
            ->post(route('tt.snapshot.restore', $snap->id))->assertForbidden();

        $response = $this->actingAs($this->admin())
            ->post(route('tt.snapshot.restore', $snap->id));
        $restored = TimeTableRecord::where('my_class_id', $class->id)
            ->where('name', 'like', '%(restored)')->firstOrFail();
        $response->assertRedirect(route('ttr.manage', $restored->id));
        $this->assertSame('draft', $restored->approval_status);

        $restoredCells = TimeTable::where('ttr_id', $restored->id)->get()
            ->map(function ($c) {
                return $c->day . '|' . $c->subject_id . '|' . (((int) $c->timestamp_from) % 86400);
            })->sort()->values()->all();
        $this->assertSame($firstCells, $restoredCells,
            'the restored grid must reproduce the snapshot cell for cell');
    }
}
