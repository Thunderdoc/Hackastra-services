<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\LeaveRequest;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\LeaveCover;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner substitution package (2026-10-29, from his live queue
 * screenshot + voice orders):
 *  - the leave queue names WHO covers (not a bare "Covered");
 *  - for the substitution window the substitute gets the class's
 *    access — the period joins their attendance list, they take
 *    and submit its register, and they can view the absent
 *    teacher's LMS content for that class + subject that day;
 *  - a teacher with no row (or the same teacher on another date)
 *    gets nothing;
 *  - if the substitute also goes on leave, the cover CHAINS to
 *    the next free teacher, who is belled;
 *  - partial leave (half day / a few periods) runs the same
 *    workflow and covers only the window's periods.
 *
 * Anchors: Ananya Sharma (teacher@) has exactly 8 Monday periods.
 * Mondays 2026-11-02 / 11-09 / 11-16 / 11-23 are the test days.
 */
class OwnerSubstitutionAccessChainTest extends TestCase
{
    use DatabaseTransactions;

    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    protected function ananya(): User
    {
        return $this->userByEmail('teacher@hackstra.app');
    }

    protected function admin(): User
    {
        return $this->userByEmail('admin@hackstra.app');
    }

    protected function submitLeave(User $as, string $from, string $to, string $type = 'emergency', array $extra = []): LeaveRequest
    {
        $this->actingAs($as)->post(route('leave.store'), array_merge([
            'start_date' => $from,
            'end_date' => $to,
            'reason' => 'Package probe',
            'leave_type' => $type,
        ], $extra))->assertRedirect();

        return LeaveRequest::where('user_id', $as->id)->latest('id')->firstOrFail();
    }

    public function test_queue_names_the_covering_teachers()
    {
        $this->submitLeave($this->ananya(), '2026-11-02', '2026-11-02');

        $subs = Substitution::where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')->with('substitute')->get();
        $this->assertNotEmpty($subs, 'Emergency leave must arrange cover');

        $page = $this->actingAs($this->admin())->get(route('leave.index'));
        $page->assertOk();
        $page->assertSee('Covered');
        $page->assertSee($subs->first()->substitute->name);
    }

    /** Submit the pending plan for (absent teacher, date) as the
     *  Admin — under the full-auto flow (owner order 2026-10-29)
     *  access and bells exist only after this step. */
    protected function confirmPlan(int $absentId, string $date): void
    {
        $this->actingAs($this->admin())->post(route('substitutions.confirm'), [
            'absent_teacher_id' => $absentId, 'date' => $date,
        ])->assertRedirect();
    }

    public function test_substitute_sees_and_takes_the_covered_period_only()
    {
        $this->submitLeave($this->ananya(), '2026-11-02', '2026-11-02');
        $sub = Substitution::where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();
        $substitute = User::findOrFail($sub->substitute_teacher_id);

        // While the plan is unsubmitted the substitute has NO
        // access — the period is not theirs yet.
        $this->actingAs($substitute)
            ->get(route('attendance.periods.take', ['tt_id' => $sub->time_table_id, 'date' => '2026-11-02']))
            ->assertForbidden();

        $this->confirmPlan($this->ananya()->id, '2026-11-02');
        $sub = $sub->fresh();
        $substitute = User::findOrFail($sub->substitute_teacher_id);

        // The covered period joins the substitute's own list.
        $index = $this->actingAs($substitute)->get(route('attendance.periods.index', ['date' => '2026-11-02']));
        $index->assertOk();
        $index->assertSee('Covering for');

        // …and the take page opens for that date — labelled
        // unmistakably as a substitute register (owner order
        // 2026-10-29: the covering teacher must see whose class
        // this is the moment they open it).
        $take = $this->actingAs($substitute)
            ->get(route('attendance.periods.take', ['tt_id' => $sub->time_table_id, 'date' => '2026-11-02']));
        $take->assertOk();
        $take->assertSee('Substitute Attendance');
        $take->assertSee($this->ananya()->name);

        // Same period, a Monday with NO row for them: refused.
        $this->actingAs($substitute)
            ->get(route('attendance.periods.take', ['tt_id' => $sub->time_table_id, 'date' => '2026-11-09']))
            ->assertForbidden();

        // A teacher with no row at all: refused.
        $other = User::where('user_type', 'teacher')
            ->whereNotIn('id', [$this->ananya()->id, $substitute->id])
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->firstOrFail();
        $this->actingAs($other)
            ->get(route('attendance.periods.take', ['tt_id' => $sub->time_table_id, 'date' => '2026-11-02']))
            ->assertForbidden();
    }

    public function test_cover_chains_when_the_substitute_goes_on_leave()
    {
        $this->submitLeave($this->ananya(), '2026-11-02', '2026-11-02');
        $row = Substitution::where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-02')->firstOrFail();
        $firstSubstitute = User::findOrFail($row->substitute_teacher_id);

        // The substitute goes on emergency leave the same day.
        $this->submitLeave($firstSubstitute, '2026-11-02', '2026-11-02');

        $after = Substitution::find($row->id);
        if ($after) {
            $this->assertNotSame((int) $firstSubstitute->id, (int) $after->substitute_teacher_id,
                'The cover must move off the absent substitute');
            $this->assertNotSame((int) $this->ananya()->id, (int) $after->substitute_teacher_id);
            // The row is still a pending plan (Ananya's leave was
            // never submitted), so the move was silent — the new
            // substitute is belled only when the Admin submits
            // Ananya's plan (owner order 2026-10-29).
            $this->assertSame(0, UserNotification::where('user_id', $after->substitute_teacher_id)
                ->where('type', 'substitution_assigned')->count());
            $this->confirmPlan($this->ananya()->id, '2026-11-02');
            $this->assertGreaterThan(0, UserNotification::where('user_id', $after->substitute_teacher_id)
                ->where('type', 'substitution_assigned')->count());
        } else {
            // No free teacher existed: the row is gone and the
            // office was alerted instead of a silent hole.
            $this->assertGreaterThan(0, UserNotification::where('user_id', $this->admin()->id)
                ->where('type', 'leave_coverage_unresolved')->count());
        }
    }

    public function test_partial_period_leave_covers_only_the_window()
    {
        $ananya = $this->ananya();
        // Her Monday cells and their period numbers, per record grid.
        $cells = TimeTable::with(['subject', 'tt_record'])
            ->where('day', 'Monday')->whereNotNull('subject_id')->get()
            ->filter(function ($cell) use ($ananya) {
                return (int) SectionTeacher::teacherIdFor(optional($cell->tt_record)->section_id ?? null, $cell->subject) === (int) $ananya->id;
            })->values();
        $this->assertNotEmpty($cells);
        $ttrIds = $cells->map(function ($c) {
            return (int) $c->ttr_id;
        })->unique()->all();
        $indexMap = LeaveCover::periodIndexMap($ttrIds, 'Monday');
        $targetIndex = $indexMap[(int) $cells->first()->id];
        $inWindow = $cells->filter(function ($c) use ($indexMap, $targetIndex) {
            return ($indexMap[(int) $c->id] ?? null) === $targetIndex;
        });
        $outWindow = $cells->filter(function ($c) use ($indexMap, $targetIndex) {
            return ($indexMap[(int) $c->id] ?? null) !== $targetIndex;
        });
        $this->assertNotEmpty($outWindow, 'Fixture needs cells outside the window too');

        $leave = $this->submitLeave($ananya, '2026-11-16', '2026-11-16', 'normal', [
            'day_part' => 'periods',
            'period_from' => $targetIndex,
            'period_to' => $targetIndex,
        ]);
        $this->assertSame('2026-11-16', \Illuminate\Support\Carbon::parse($leave->end_date)->toDateString(),
            'A partial leave is a single day');

        $this->actingAs($this->admin())->post(route('leave.decide', $leave->id), ['status' => 'approved'])
            ->assertRedirect();

        $rows = Substitution::where('absent_teacher_id', $ananya->id)
            ->whereDate('date', '2026-11-16')->get();
        $coveredIds = $rows->map(function ($r) {
            return (int) $r->time_table_id;
        })->all();
        foreach ($inWindow as $cell) {
            $this->assertContains((int) $cell->id, $coveredIds, 'In-window period must be covered');
        }
        foreach ($outWindow as $cell) {
            $this->assertNotContains((int) $cell->id, $coveredIds, 'Out-of-window period must NOT be covered');
        }
    }

    public function test_half_day_leave_window_and_queue_label()
    {
        $leave = $this->submitLeave($this->ananya(), '2026-11-23', '2026-11-23', 'emergency', [
            'day_part' => 'first_half',
        ]);
        $this->assertSame([1, 4], $leave->periodWindow());

        $cells = TimeTable::with(['subject', 'tt_record'])
            ->where('day', 'Monday')->whereNotNull('subject_id')->get()
            ->filter(function ($cell) {
                return (int) SectionTeacher::teacherIdFor(optional($cell->tt_record)->section_id ?? null, $cell->subject) === (int) $this->ananya()->id;
            });
        $ttrIds = $cells->map(function ($c) {
            return (int) $c->ttr_id;
        })->unique()->all();
        $indexMap = LeaveCover::periodIndexMap($ttrIds, 'Monday');

        $rows = Substitution::where('absent_teacher_id', $this->ananya()->id)
            ->whereDate('date', '2026-11-23')->get();
        $this->assertNotEmpty($rows, 'Half-day emergency leave must arrange some cover');
        foreach ($rows as $row) {
            $n = $indexMap[(int) $row->time_table_id] ?? null;
            $this->assertNotNull($n);
            $this->assertLessThanOrEqual(4, $n, 'Second-half periods stay with the teacher herself');
        }

        $page = $this->actingAs($this->admin())->get(route('leave.index'));
        $page->assertSee('Half day (periods 1-4)');
    }

    public function test_substitute_can_view_the_absent_teachers_assignment_that_day()
    {
        $today = now()->toDateString();
        $dayName = now()->format('l');
        $cell = TimeTable::with(['subject', 'tt_record'])
            ->where('day', $dayName)->whereNotNull('subject_id')->get()
            ->first(function ($c) {
                return (int) SectionTeacher::teacherIdFor(optional($c->tt_record)->section_id ?? null, $c->subject) === (int) $this->ananya()->id;
            });
        $this->assertNotNull($cell, 'Ananya must teach some period today for this fixture');

        $classId = (int) optional($cell->tt_record)->my_class_id;
        $assignment = Assignment::create([
            'teacher_id' => $this->ananya()->id,
            'my_class_id' => $classId,
            'section_id' => optional($cell->tt_record)->section_id,
            'subject_id' => (int) $cell->subject_id,
            'title' => 'Cover-day reading',
            'description' => 'probe',
            'max_marks' => 100,
            'due_at' => '2030-01-01 10:00:00',
            'allow_late' => false,
        ]);

        $substitute = User::where('user_type', 'teacher')
            ->where('id', '!=', $this->ananya()->id)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->firstOrFail();
        $other = User::where('user_type', 'teacher')
            ->whereNotIn('id', [$this->ananya()->id, $substitute->id])
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })->orderBy('id')->firstOrFail();

        Substitution::create([
            'time_table_id' => (int) $cell->id,
            'date' => $today,
            'absent_teacher_id' => $this->ananya()->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Manual cover',
        ]);

        // The covering teacher can view it today…
        $this->actingAs($substitute)->get(route('assignments.show', $assignment->id))->assertOk();
        // …a teacher with no cover row cannot.
        $this->actingAs($other)->get(route('assignments.show', $assignment->id))
            ->assertRedirect(route('dashboard'));
    }
}
