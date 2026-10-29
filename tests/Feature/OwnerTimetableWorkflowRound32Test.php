<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\AttendanceSession;
use App\Models\AttendanceSessionEntry;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 32 — end-to-end timetable WORKFLOW, the first chain
 * lock: generate → validate → manage-move → teacher week view →
 * attendance → slot-sync → regenerate-keep, through the REAL routes
 * on fresh fixtures. Every link was proven alone in earlier rounds;
 * this round proves the links agree with each other in one run.
 *
 * Patterns applied (audit-patterns.md): P7 flash honesty (a pair
 * that never forms must be NAMED in the flash, never silently
 * dropped); P10 count-vs-rows (the tt.index Validated pill must
 * agree with the validate page, both ways, from the same
 * validator); P11 display-vs-stored (the register view must show
 * exactly the entries stored, and the week view must show the
 * moved cell at its new time, not a stale slot).
 *
 * All fixtures fresh and rolled back (DatabaseTransactions).
 */
class OwnerTimetableWorkflowRound32Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R32 Teacher ' . $tag,
            'email' => 'r32-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R32' . $tag . uniqid(),
        ]);
    }

    /**
     * Fresh class with $names subjects x 6/week, one distinct teacher
     * each; $doubleName (if given) is marked needs_double.
     * Returns [class, subjectName => Subject, subjectName => teacher].
     */
    protected function makeClass(string $prefix, array $names, ?string $doubleName = null): array
    {
        $class = MyClass::create(['name' => 'R32 ' . $prefix . ' ' . uniqid()]);
        $subjects = [];
        $teachers = [];
        foreach ($names as $i => $name) {
            $t = $this->teacher($prefix . $i);
            $sub = Subject::create([
                'name' => $name,
                'slug' => 'r32-' . strtolower($prefix) . '-' . $i . '-' . uniqid(),
                'my_class_id' => $class->id,
                'teacher_id' => $t->id,
                'periods_per_week' => 6,
                'needs_double' => $doubleName !== null && $name === $doubleName,
            ]);
            $subjects[$name] = $sub;
            $teachers[$name] = $t;
        }

        return [$class, $subjects, $teachers];
    }

    protected function generateFor(MyClass $class, $cap, string $mode = 'replace')
    {
        $response = $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 6,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 6,
                'scope' => 'one',
                'class_id' => $class->id,
                'mode' => $mode,
                'max_teacher_periods_per_day' => $cap,
            ]);
        $response->assertRedirect(route('tt.index'));

        // Premise update (timetable approval, owner order
        // 2026-10-29): generated records now start as drafts and a
        // regeneration returns them to draft; this chain exercises
        // in-force timetables, so approve the class's records here.
        TimeTableRecord::where('my_class_id', $class->id)
            ->update(['approval_status' => 'approved']);

        return $response;
    }

    protected function recordFor(int $classId): TimeTableRecord
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->whereNull('section_id')->firstOrFail();
    }

    protected function cellTuples(int $recordId): array
    {
        return TimeTable::where('ttr_id', $recordId)->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
    }

    /** Back-to-back pairs for one subject: second cell starts exactly when the first ends. */
    protected function pairCount(int $recordId, int $subjectId): int
    {
        $pairs = 0;
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $day) {
            $cells = TimeTable::where('ttr_id', $recordId)->where('subject_id', $subjectId)
                ->where('day', $day)->orderBy('timestamp_from')->get();
            for ($i = 1; $i < count($cells); $i++) {
                if ((int) $cells[$i]->timestamp_from === (int) $cells[$i - 1]->timestamp_to) {
                    $pairs++;
                }
            }
        }

        return $pairs;
    }

    /** Max periods any one teacher teaches on any one day of a record (direct subjects, no overrides). */
    protected function maxTeacherDayLoad(int $recordId): int
    {
        $max = 0;
        $byTeacherDay = [];
        foreach (TimeTable::where('ttr_id', $recordId)->get() as $cell) {
            $teacherId = Subject::find($cell->subject_id)->teacher_id;
            $byTeacherDay[$teacherId . '|' . $cell->day] = ($byTeacherDay[$teacherId . '|' . $cell->day] ?? 0) + 1;
            $max = max($max, $byTeacherDay[$teacherId . '|' . $cell->day]);
        }

        return $max;
    }

    /** Three roster students for a fresh class (real section required: section_id NOT NULL). */
    protected function makeRoster(int $classId, int $sectionTeacherId): array
    {
        Section::create([
            'name' => 'R32 A ' . uniqid(),
            'my_class_id' => $classId,
            'teacher_id' => $sectionTeacherId,
        ]);
        $roster = [];
        foreach (['Asha', 'Bina', 'Chetan'] as $i => $first) {
            $u = User::create([
                'name' => 'R32 Student ' . $first . ' ' . uniqid(),
                'email' => 'r32-stu-' . $i . '-' . uniqid() . '@test.local',
                'password' => bcrypt('student'),
                'user_type' => 'student',
                'code' => 'R32S' . $i . uniqid(),
            ]);
            $sr = StudentRecord::create([
                'session' => Qs::getCurrentSession(),
                'user_id' => $u->id,
                'my_class_id' => $classId,
                'section_id' => Section::where('my_class_id', $classId)->firstOrFail()->id,
                'adm_no' => 'R32/' . uniqid(),
                'year_admitted' => '2026',
            ]);
            $roster[$sr->id] = $u->name;
        }

        return $roster;
    }

    /** Most recent date on or before today with the given weekday name (never future). */
    protected function pastDateForWeekday(string $dayName): string
    {
        $date = Carbon::today();
        while ($date->format('l') !== $dayName) {
            $date->subDay();
        }

        return $date->toDateString();
    }

    protected function parseSlotTime(string $text): array
    {
        preg_match('/(\d+):(\d+)\s*(AM|PM)/i', $text, $m);

        return [(int) $m[1], $m[2], strtoupper($m[3])];
    }

    public function test_generate_cap_double_validate_and_pill_agree()
    {
        [$class, $subjects, $teachers] = $this->makeClass('VAL', [
            'English', 'Mathematics', 'Science', 'Social Science', 'Hindi', 'Computer Science',
        ], 'Science');
        $gen = $this->generateFor($class, 4);
        $record = $this->recordFor($class->id);

        // (a) Grid placed in full; cap respected on every teacher-day.
        $this->assertSame(36, TimeTable::where('ttr_id', $record->id)->count(),
            '6 subjects x 6/week must place the full 36-cell grid');
        $this->assertLessThanOrEqual(4, $this->maxTeacherDayLoad($record->id),
            'no teacher may exceed the daily cap of 4 on any day');

        // (a) Double pair formed, or honestly warned (P7) — never silently dropped.
        $pairs = $this->pairCount($record->id, $subjects['Science']->id);
        $flash = (string) (session('flash_success') ?? '') . ' ' . (string) (session('flash_danger') ?? '');
        $this->assertTrue($pairs >= 1 || str_contains($flash, 'Double period not possible'),
            "needs_double Science must pair or be named in the flash; pairs=$pairs flash=[$flash]");

        // (b) Validate page clean AND index pill agrees (P10 — same validator, both surfaces).
        $validate = $this->actingAs($this->admin())->get(route('ttr.validate', $record->id));
        $validate->assertOk();
        $this->assertSame([], $validate->getOriginalContent()->getData()['v_errors'],
            'validate page must report zero errors for the fresh grid');
        $index = $this->actingAs($this->admin())->get(route('tt.index'));
        $index->assertOk();
        $this->assertContains($record->id,
            $index->getOriginalContent()->getData()['validated_ids'] ?? [],
            'tt.index Validated pill must list exactly the record the validate page calls clean');
    }

    public function test_full_chain_move_week_attendance_sync_regenerate_keep()
    {
        // (a) Chain class K: 5 subjects x 6/week = 30 cells over 36 slots,
        // so every day has a free slot for the Manage move. Sibling S:
        // 3 subjects x 6/week = 18 cells, all-distinct teachers so no
        // cross-class cap/busyness interference (round 27).
        [$classK, $subjectsK, $teachersK] = $this->makeClass('CHAIN', [
            'English', 'Mathematics', 'Science', 'Social Science', 'Hindi',
        ], 'Science');
        [$classS, $subjectsS, $teachersS] = $this->makeClass('SIB', [
            'Art', 'Music', 'Drama',
        ]);
        $this->generateFor($classK, 4);
        $this->generateFor($classS, 4);
        $recordK = $this->recordFor($classK->id);
        $recordS = $this->recordFor($classS->id);
        $this->assertSame(30, TimeTable::where('ttr_id', $recordK->id)->count(), 'K grid must be 30 cells');
        $this->assertSame(18, TimeTable::where('ttr_id', $recordS->id)->count(), 'S grid must be 18 cells');
        $this->assertLessThanOrEqual(4, $this->maxTeacherDayLoad($recordK->id), 'K cap respected at chain start');

        // (c) MANAGE MOVE, via the real tt.update path. Probe-found
        // layout fact (kept in the test, not assumed): the generator
        // packs whole days greedily — 5 subjects x 6/week fills
        // Monday–Friday at 6 cells each and leaves Saturday EMPTY.
        // So no weekday has a free slot; the honest move is a Monday
        // Science cell onto Saturday's P1 slot. Week view renders
        // Saturday whenever the teacher has Saturday periods
        // (teacherWeekData adds any present day to Mon–Fri), so the
        // move stays visible on the teacher's week view.
        $moveCell = TimeTable::where('ttr_id', $recordK->id)->where('subject_id', $subjectsK['Science']->id)
            ->where('day', 'Monday')->orderBy('timestamp_from')->firstOrFail();
        $moveDay = 'Saturday';
        $freeSlot = TimeSlot::where('ttr_id', $recordK->id)->orderBy('timestamp_from')->firstOrFail();
        $this->assertSame(0, TimeTable::where('ttr_id', $recordK->id)->where('day', 'Saturday')->count(),
            'Saturday must start empty for the move to be a pure relocation');
        $oldSlotFull = $moveCell->time_slot->full;
        $this->actingAs($this->admin())->from(route('ttr.manage', $recordK->id))
            ->put(route('tt.update', $moveCell->id), [
                'ttr_id' => $recordK->id,
                'ts_id' => $freeSlot->id,
                'subject_id' => $subjectsK['Science']->id,
                'day' => $moveDay,
            ])->assertRedirect(route('ttr.manage', $recordK->id));
        $moveCell->refresh();
        $this->assertSame($freeSlot->id, (int) $moveCell->ts_id, 'moved cell must sit on the new slot');
        $this->assertSame('Saturday', $moveCell->day, 'moved cell must land on Saturday');
        $this->assertSame([], TimetableValidator::report($recordK->fresh())['errors'],
            'validator must stay clean after moving to an empty day');

        // Week view reflects the move (P11): Saturday gains the period
        // at its new slot; Monday loses exactly the moved slot (the
        // pair-partner Science cell stays on Monday at its own slot).
        $week = $this->actingAs($teachersK['Science'])->get(route('tt.mine'));
        $week->assertOk();
        $entries = collect($week->getOriginalContent()->getData()['entries'] ?? []);
        $satEntries = $entries->where('day', 'Saturday')->where('subject', 'Science');
        $this->assertTrue($satEntries->contains(fn ($e) => $e['slot'] === $freeSlot->full),
            'week view must show the moved Science period on Saturday at its new slot time');
        $monEntries = $entries->where('day', 'Monday')->where('subject', 'Science');
        $this->assertFalse($monEntries->contains(fn ($e) => $e['slot'] === $oldSlotFull),
            'week view must not still show the moved period at its old Monday slot time');

        // (d) ATTENDANCE on the moved period, through the real take/store
        // path, by the period's own teacher only.
        $roster = $this->makeRoster($classK->id, $teachersK['English']->id);
        $attDate = $this->pastDateForWeekday($moveDay);
        $take = $this->actingAs($teachersK['Science'])
            ->get(route('attendance.periods.take', $moveCell->id) . '?date=' . $attDate);
        $take->assertOk();
        foreach ($roster as $name) {
            $take->assertSee($name);
        }
        $statuses = [];
        foreach (array_keys($roster) as $i => $srId) {
            $statuses[$srId] = ['present', 'absent', 'late'][$i];
        }
        $this->actingAs($teachersK['Science'])
            ->post(route('attendance.periods.store', $moveCell->id), [
                'topic' => 'R32 chain topic',
                'date' => $attDate,
                'statuses' => $statuses,
            ])->assertRedirect(route('attendance.periods.index', ['date' => $attDate]));
        // whereDate, not where('date', ...): the session's date attribute
        // is datetime-cast, so the stored value carries 00:00:00 and a
        // plain string equality misses it (this is the controller's own
        // existingSession shape).
        $session = AttendanceSession::where('time_table_id', $moveCell->id)->whereDate('date', $attDate)->firstOrFail();
        $entriesBefore = AttendanceSessionEntry::where('session_id', $session->id)
            ->orderBy('student_record_id')->get()
            ->mapWithKeys(fn ($e) => [$e->student_record_id => $e->status])->all();
        $this->assertSame($statuses, $entriesBefore, 'stored entries must match exactly what was submitted');

        // (d) SLOT-SYNC the moved slot's times: session stays attached,
        // entries byte-identical, other-slot cells untouched (round 28
        // shape, now inside the chain).
        $slot = TimeSlot::findOrFail($moveCell->ts_id);
        [$fromH, $fromM, $fromMer] = $this->parseSlotTime($slot->time_from);
        $endCarbon = Carbon::createFromFormat('h:i A', $slot->time_to)->subMinutes(10);
        $otherBefore = TimeTable::where('ttr_id', $recordK->id)->where('ts_id', '!=', $slot->id)
            ->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
        $movedDatePart = date('Y-m-d', (int) $moveCell->timestamp_from);
        $this->actingAs($this->admin())->put(route('ts.update', $slot->id), [
            'ttr_id' => $recordK->id,
            'hour_from' => $fromH, 'min_from' => $fromM, 'meridian_from' => $fromMer,
            'hour_to' => (int) $endCarbon->format('g'), 'min_to' => $endCarbon->format('i'),
            'meridian_to' => $endCarbon->format('A'),
        ])->assertRedirect(route('ttr.manage', $recordK->id));
        $this->assertSame($moveCell->id, (int) $session->fresh()->time_table_id,
            'session must still point at the same moved cell after sync');
        $entriesAfter = AttendanceSessionEntry::where('session_id', $session->id)
            ->orderBy('student_record_id')->get()
            ->mapWithKeys(fn ($e) => [$e->student_record_id => $e->status])->all();
        $this->assertSame($entriesBefore, $entriesAfter, 'sync must not touch a single entry');
        $moveCell->refresh();
        $this->assertSame($movedDatePart, date('Y-m-d', (int) $moveCell->timestamp_from),
            'synced cell must keep its date part');
        $this->assertSame($endCarbon->format('H:i'), date('H:i', (int) $moveCell->timestamp_to),
            'synced cell must follow the slot\'s new end time');
        $otherAfter = TimeTable::where('ttr_id', $recordK->id)->where('ts_id', '!=', $slot->id)
            ->orderBy('id')->get()
            ->map(fn ($c) => [$c->id, $c->ts_id, $c->day, $c->timestamp_from, $c->timestamp_to, $c->subject_id])->all();
        $this->assertSame($otherBefore, $otherAfter, 'other-slot cells must be untouched by the sync');
        $this->assertSame([], TimetableValidator::report($recordK->fresh())['errors'],
            'validator must stay clean after a shortening sync');
        // Register still renders for admin with exactly the stored roster (P11).
        $show = $this->actingAs($this->admin())->get(route('attendance.periods.show', $session->id));
        $show->assertOk();
        foreach ($roster as $name) {
            $show->assertSee($name);
        }

        // (e) REGENERATE mode=replace: K (attendance-bearing) KEPT
        // byte-identical incl. the move + sync; S (attendance-free)
        // regenerates fresh (same record id, all-new cell ids).
        $tuplesKBefore = $this->cellTuples($recordK->id);
        $cellsSBefore = TimeTable::where('ttr_id', $recordS->id)->orderBy('id')->pluck('id')->all();
        $daySubjectSBefore = TimeTable::where('ttr_id', $recordS->id)->get()
            ->map(fn ($c) => [$c->day, $c->subject_id])->sort()->values()->all();
        $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'one', 'class_id' => $classK->id, 'mode' => 'replace',
                'max_teacher_periods_per_day' => 4,
            ])->assertRedirect(route('tt.index'));
        $this->assertSame($recordK->id, $this->recordFor($classK->id)->id,
            'attendance-locked record id must survive replace');
        $this->assertSame($tuplesKBefore, $this->cellTuples($recordK->id),
            'attendance-locked grid must stay byte-identical under replace, move + sync included');
        $this->actingAs($this->admin())
            ->post(route('tt.generate'), [
                'start_time' => '09:00', 'period_minutes' => 40, 'periods_per_day' => 6,
                'lunch_after' => 4, 'lunch_minutes' => 45, 'break_after' => 2, 'break_minutes' => 15,
                'days' => 6, 'scope' => 'one', 'class_id' => $classS->id, 'mode' => 'replace',
                'max_teacher_periods_per_day' => 4,
            ])->assertRedirect(route('tt.index'));
        $this->assertSame($recordS->id, $this->recordFor($classS->id)->id,
            'attendance-free record id is reused on replace');
        // The regeneration returned the sibling to draft (approval
        // flow); the remaining legs treat it as in force.
        TimeTableRecord::where('id', $recordS->id)->update(['approval_status' => 'approved']);
        $cellsSAfter = TimeTable::where('ttr_id', $recordS->id)->orderBy('id')->pluck('id')->all();
        $this->assertSame(18, count($cellsSAfter), 'sibling grid keeps its 18 cells');
        $this->assertSame([], array_intersect($cellsSBefore, $cellsSAfter),
            'sibling regenerate must mint all-new cell ids');
        $daySubjectSAfter = TimeTable::where('ttr_id', $recordS->id)->get()
            ->map(fn ($c) => [$c->day, $c->subject_id])->sort()->values()->all();
        $this->assertSame($daySubjectSBefore, $daySubjectSAfter,
            'sibling regenerate keeps the same day/subject layout');

        // Final agreement: validator clean, pill green, week view still
        // shows the moved period at its synced time.
        $this->assertSame([], TimetableValidator::report($recordK->fresh())['errors'],
            'final validator state must be clean');
        $validate = $this->actingAs($this->admin())->get(route('ttr.validate', $recordK->id));
        $validate->assertOk();
        $this->assertSame([], $validate->getOriginalContent()->getData()['v_errors'],
            'final validate page must be clean');
        $index = $this->actingAs($this->admin())->get(route('tt.index'));
        $this->assertContains($recordK->id,
            $index->getOriginalContent()->getData()['validated_ids'] ?? [],
            'final index pill must still list the kept record');
        $syncedSlotFull = TimeSlot::findOrFail($slot->id)->full;
        $weekFinal = $this->actingAs($teachersK['Science'])->get(route('tt.mine'));
        $finalEntries = collect($weekFinal->getOriginalContent()->getData()['entries'] ?? []);
        $this->assertTrue(
            $finalEntries->where('day', $moveDay)->where('subject', 'Science')
                ->contains(fn ($e) => $e['slot'] === $syncedSlotFull),
            'final week view must show the moved period at its synced slot time');
    }
}
