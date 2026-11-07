<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\Support\Staffing;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
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
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    /**
     * A record, one of its slots, a day with no cell yet, and a
     * subject of $subjectClassId whose effective teacher is under
     * their caps on that day.
     */
    protected function fixture(int $subjectClassId): array
    {
        // Fixture hardening (2026-11-01, gate #2): this class saves
        // REAL cells (no transactions), so repeated suite runs fill
        // the first slot's days one by one — after seven runs the
        // old first-slot-only search found no free day and the
        // fixture failed on ambient state, not on the guard. The
        // search now spans every slot x day of the record; the two
        // tests' assertions are unchanged.
        //
        // Caps reconciliation (2026-11-06): TTRequest ALSO carries
        // the G4 per-teacher cap guard, which runs before the pair
        // guard and refuses on its own when the subject's effective
        // teacher is at their day/week cap. These two tests are
        // about subject<->class matching, not about load — the old
        // "first subject of the class" picks (Class 6 English,
        // Class 12 English) happen to sit with teachers who are at
        // their daily cap on the first free day, so the cap guard
        // fired and the pair scenarios never ran. The search now
        // also requires the picked subject's teacher to have cap
        // room on the chosen day (same arithmetic as
        // TTRequest::checkTeacherCaps), keeping load out of the
        // pair scenarios without touching either guard.
        $record = TimeTableRecord::where('my_class_id', 13)->firstOrFail();
        $slots = TimeSlot::where('ttr_id', $record->id)->orderBy('id')->get();
        foreach ($slots as $candidateSlot) {
            foreach (['Saturday', 'Sunday', 'Friday', 'Thursday', 'Wednesday', 'Tuesday', 'Monday'] as $candidate) {
                $from = strtotime($candidate . ' ' . $candidateSlot->time_from);
                $to = strtotime($candidate . ' ' . $candidateSlot->time_to);
                if (TimeTable::where('ttr_id', $record->id)
                    ->where('timestamp_from', $from)->where('timestamp_to', $to)->exists()) {
                    continue;
                }
                $subject = $this->subjectWithCapRoom($record, $subjectClassId, $candidate);
                if ($subject) {
                    return [$record, $candidateSlot, $candidate, $subject];
                }
            }
        }
        $this->fail('fixture needs one free slot/day on the Class 12 record with a subject whose teacher is under their caps');
    }

    /**
     * The first subject of $classId (by id) that TTRequest's cap
     * guard would let through on $day for $record: no effective
     * teacher (nothing to cap), or the teacher's day AND week
     * loads leave room for one more period under
     * Staffing::capsFor. Mirrors checkTeacherCaps' counting —
     * loads across the non-exam records of the record's year via
     * SectionTeacher::effectiveDayLoads.
     */
    protected function subjectWithCapRoom(TimeTableRecord $record, int $classId, string $day): ?Subject
    {
        $recordIds = TimeTableRecord::whereNull('exam_id')
            ->where('year', $record->year)->pluck('id')->all();
        $dayLoads = SectionTeacher::effectiveDayLoads($recordIds);

        foreach (Subject::where('my_class_id', $classId)->orderBy('id')->get() as $subject) {
            $teacherId = SectionTeacher::teacherIdFor($record->section_id, $subject);
            if (!$teacherId) {
                return $subject;
            }
            $teacher = User::find($teacherId);
            if (!$teacher) {
                return $subject;
            }
            $caps = Staffing::capsFor($teacher);
            $dayLoad = (int) ($dayLoads[$teacherId][$day] ?? 0);
            $weekLoad = (int) array_sum($dayLoads[$teacherId] ?? []);
            if ($dayLoad + 1 <= $caps['day'] && $weekLoad + 1 <= $caps['week']) {
                return $subject;
            }
        }

        return null;
    }

    public function test_cross_class_subject_is_refused_and_saves_nothing()
    {
        [$record, $slot, $day, $foreign] = $this->fixture(7); // a Class 6 subject

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
        [$record, $slot, $day, $own] = $this->fixture(13);

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
