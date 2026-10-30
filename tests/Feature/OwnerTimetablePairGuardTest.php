<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Tests\TestCase;

/**
 * Cascade audit 2026-10-30 — the ONE genuinely unguarded
 * class/section/subject pair in the app: a timetable CELL post
 * (TTRequest) checked only that the subject and record exist,
 * never that the subject belongs to the record's class. A Cross-
 * class subject saved silently. Every sibling surface checked
 * its pair already (assignments/materials: subject↔class +
 * section↔class aborts; question bank: ValidationException;
 * PTM: explicit flash guard; timetable records: cleanRecord-
 * Section; lesson-plan writes are closed entirely). TTRequest
 * now carries the pair rule in withValidator, covering both
 * the cell store and the cell update.
 */
class OwnerTimetablePairGuardTest extends TestCase
{
    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /** A record plus one of its slots and a day with no cell yet. */
    protected function fixture(): array
    {
        $record = TimeTableRecord::where('my_class_id', 13)->firstOrFail();
        $slot = TimeSlot::where('ttr_id', $record->id)->orderBy('id')->firstOrFail();
        $day = null;
        foreach (['Saturday', 'Sunday', 'Friday', 'Thursday', 'Wednesday', 'Tuesday', 'Monday'] as $candidate) {
            $from = strtotime($candidate . ' ' . $slot->time_from);
            $to = strtotime($candidate . ' ' . $slot->time_to);
            if (!TimeTable::where('ttr_id', $record->id)
                ->where('timestamp_from', $from)->where('timestamp_to', $to)->exists()) {
                $day = $candidate;
                break;
            }
        }
        $this->assertNotNull($day, 'fixture needs one free slot/day on the Class 12 record');

        return [$record, $slot, $day];
    }

    public function test_cross_class_subject_is_refused_and_saves_nothing()
    {
        [$record, $slot, $day] = $this->fixture();
        $foreign = Subject::where('my_class_id', 7)->firstOrFail(); // a Class 6 subject

        $before = TimeTable::where('ttr_id', $record->id)->count();
        $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'subject_id' => $foreign->id,
            'day' => $day,
        ])->assertSessionHasErrors('subject_id');

        $this->assertSame($before, TimeTable::where('ttr_id', $record->id)->count());
    }

    public function test_matching_subject_still_saves()
    {
        [$record, $slot, $day] = $this->fixture();
        $own = Subject::where('my_class_id', 13)->firstOrFail();

        $before = TimeTable::where('ttr_id', $record->id)->count();
        $this->actingAs($this->admin())->post(route('tt.store'), [
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'subject_id' => $own->id,
            'day' => $day,
        ])->assertSessionHasNoErrors();

        $this->assertSame($before + 1, TimeTable::where('ttr_id', $record->id)->count());
    }
}
