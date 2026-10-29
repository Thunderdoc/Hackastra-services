<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Room;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\RoomAssigner;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * T3 — rooms and labs (master prompt, owner order 2026-10-29).
 * The doctrine under test: a room hosts one period at a time;
 * a needs_lab subject only ever lands in a lab; generation
 * assigns rooms clash-free and REPORTS the periods it could
 * not room and the classes bigger than their room — it never
 * double-books a room silently.
 */
class OwnerRoomTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    public function test_rooms_crud_admin_only_and_delete_guard()
    {
        $this->actingAs($this->admin())->post(route('rooms.store'), [
            'name' => 'Room X1', 'kind' => 'classroom', 'capacity' => 40,
        ])->assertRedirect(route('rooms.index'));
        $room = Room::where('name', 'Room X1')->firstOrFail();

        $this->actingAs($this->teacher())->post(route('rooms.store'), [
            'name' => 'Room Sneak', 'kind' => 'lab', 'capacity' => 10,
        ]);
        $this->assertNull(Room::where('name', 'Room Sneak')->first(),
            'rooms are maintained by the Admin, not teachers');

        $cell = TimeTable::whereNotNull('subject_id')->firstOrFail();
        $cell->room_id = $room->id;
        $cell->save();
        $this->actingAs($this->admin())->delete(route('rooms.destroy', $room->id))
            ->assertSessionHas('flash_danger');
        $this->assertNotNull(Room::find($room->id), 'a room in use must not be deletable');

        $cell->room_id = null;
        $cell->save();
        $this->actingAs($this->admin())->delete(route('rooms.destroy', $room->id))
            ->assertSessionHas('flash_success');
        $this->assertNull(Room::find($room->id));
    }

    public function test_generator_assigns_rooms_clash_free_and_labs_only_in_labs()
    {
        Room::create(['name' => 'Gen Classroom', 'kind' => 'classroom', 'capacity' => 300]);
        $lab = Room::create(['name' => 'Gen Lab', 'kind' => 'lab', 'capacity' => 300]);

        $class = MyClass::create(['name' => 'Room Class']);
        $t1 = User::create(['name' => 'Room Teacher One', 'email' => 'room-t1@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'RMT1']);
        $t2 = User::create(['name' => 'Room Teacher Two', 'email' => 'room-t2@test.local', 'password' => bcrypt('teacher'), 'user_type' => 'teacher', 'code' => 'RMT2']);
        Subject::create(['name' => 'Gen Theory', 'slug' => 'gen-theory', 'my_class_id' => $class->id, 'teacher_id' => $t1->id, 'periods_per_week' => 6]);
        $practical = Subject::create(['name' => 'Gen Practical', 'slug' => 'gen-practical', 'my_class_id' => $class->id, 'teacher_id' => $t2->id, 'periods_per_week' => 6, 'needs_lab' => true]);

        $response = $this->actingAs($this->admin())->post(route('tt.generate'), [
            'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 8,
            'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
            'days' => 5, 'scope' => 'one', 'class_id' => $class->id, 'mode' => 'replace',
        ]);
        $response->assertRedirect(route('tt.index'));
        $this->assertStringContainsString('Rooms assigned', (string) session('flash_success'));

        $record = TimeTableRecord::where('my_class_id', $class->id)->firstOrFail();
        $labCells = TimeTable::where('ttr_id', $record->id)->where('subject_id', $practical->id)->get();
        $this->assertGreaterThan(0, $labCells->count());
        foreach ($labCells as $cell) {
            $this->assertSame($lab->id, (int) $cell->room_id,
                'a needs_lab period must land in the lab, never a classroom');
        }

        // The school-wide invariant: no room is ever double-booked.
        $roomed = TimeTable::whereNotNull('room_id')->get();
        $seen = [];
        foreach ($roomed as $cell) {
            $key = $cell->room_id . '|' . $cell->day;
            $from = ((int) $cell->timestamp_from) % 86400;
            $to = ((int) $cell->timestamp_to) % 86400;
            foreach ($seen[$key] ?? [] as $iv) {
                $this->assertFalse($from < $iv[1] && $iv[0] < $to,
                    'room double-booked on ' . $cell->day);
            }
            $seen[$key][] = [$from, $to];
        }
    }

    public function test_cell_save_room_clash_and_lab_rule_are_refused()
    {
        $classroom = Room::create(['name' => 'Cell Classroom', 'kind' => 'classroom', 'capacity' => 50]);
        $lab = Room::create(['name' => 'Cell Lab', 'kind' => 'lab', 'capacity' => 50]);

        // Two cells in DIFFERENT records sharing a Monday slot.
        $cells = TimeTable::where('day', 'Monday')->whereNotNull('subject_id')
            ->get()->groupBy('timestamp_from')
            ->first(function ($g) {
                return $g->pluck('ttr_id')->unique()->count() > 1;
            });
        $this->assertNotNull($cells, 'fixture: two records must share a Monday slot');
        $a = $cells->first();
        $b = $cells->first(function ($c) use ($a) {
            return (int) $c->ttr_id !== (int) $a->ttr_id;
        });

        $post = function (TimeTable $cell, Room $room) {
            return $this->actingAs($this->admin())->put(route('tt.update', $cell->id), [
                'day' => $cell->day,
                'subject_id' => $cell->subject_id,
                'ttr_id' => $cell->ttr_id,
                'ts_id' => $cell->ts_id,
                'room_id' => $room->id,
            ]);
        };

        $post($a, $classroom)->assertSessionHas('flash_success');
        $post($b, $classroom)->assertSessionHas('flash_danger');
        $this->assertStringContainsString('Room clash', (string) session('flash_danger'));

        Subject::where('id', $b->subject_id)->update(['needs_lab' => true]);
        $post($b, $classroom)->assertSessionHas('flash_danger');
        $this->assertStringContainsString('needs a lab', (string) session('flash_danger'));

        $post($b, $lab)->assertSessionHas('flash_success');
        $this->assertSame($lab->id, (int) $b->fresh()->room_id);
    }

    public function test_assigner_reports_classes_bigger_than_their_room()
    {
        Room::create(['name' => 'Tiny Room', 'kind' => 'classroom', 'capacity' => 5]);
        $record = TimeTableRecord::findOrFail(12); // Class 11-A section grid, 40 students
        $summary = RoomAssigner::assignRecord($record);
        $this->assertGreaterThan(0, $summary['assigned']);
        $this->assertNotEmpty($summary['over_capacity']);
        $this->assertStringContainsString('40 students', $summary['over_capacity'][0]);
    }

    public function test_tiers_are_named_and_validator_warns_on_a_small_room()
    {
        // T5: the generate screen names the two tiers honestly.
        $this->actingAs($this->admin())->get(route('tt.generate.form'))
            ->assertOk()
            ->assertSee('How generation decides')
            ->assertSee('Mandatory — never broken')
            ->assertSee('Preferences — best effort, always reported');

        Room::create(['name' => 'Tiny Room', 'kind' => 'classroom', 'capacity' => 5]);
        $record = TimeTableRecord::findOrFail(12);
        RoomAssigner::assignRecord($record);
        $report = \App\Support\TimetableValidator::report($record);
        $hits = array_filter($report['warnings'], function ($w) {
            return strpos($w, 'bigger than its room') !== false;
        });
        $this->assertNotEmpty($hits, 'a too-small room must surface as a preference warning');

        // A manual double-booking of the room is a mandatory
        // error: point another record's Monday cell at the same
        // room and the same clock window (its own slot row and
        // day stay untouched, so no unique key is strained).
        $cellA = TimeTable::where('ttr_id', 12)->whereNotNull('room_id')
            ->where('day', 'Monday')->first();
        $this->assertNotNull($cellA);
        $cellB = TimeTable::where('ttr_id', '!=', 12)->where('day', 'Monday')
            ->whereNotNull('subject_id')->firstOrFail();
        $cellB->update([
            'room_id' => $cellA->room_id,
            'timestamp_from' => $cellA->timestamp_from,
            'timestamp_to' => $cellA->timestamp_to,
        ]);
        $report = \App\Support\TimetableValidator::report($record);
        $clashes = array_filter($report['errors'], function ($e) {
            return strpos($e, 'Room clash') !== false;
        });
        $this->assertNotEmpty($clashes, 'a double-booked room must be a blocking error');
    }
}
