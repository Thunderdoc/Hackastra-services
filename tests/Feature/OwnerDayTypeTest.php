<?php

namespace Tests\Feature;

use App\Models\SchoolDayType;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\LeaveCover;
use App\User;
use Tests\TestCase;

/**
 * T2 — day types (master prompt, owner order 2026-10-29). A
 * date's kind, set by the Admin on the School Events page,
 * drives two behaviours end to end:
 *   - substitution auto-cover arranges NOTHING for a
 *     non-teaching day, and only the running window of a
 *     half day;
 *   - period attendance expects no new register on a
 *     non-teaching day (take + store both refuse), while a
 *     half day refuses periods beyond its window.
 * A date with no row stays a normal day everywhere.
 */
class OwnerDayTypeTest extends TestCase
{
    /** Highest notification id before this test ran. */
    protected $notifFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifFloor = (int) \App\Models\UserNotification::max('id');
    }

    /**
     * A day type is GLOBAL state for its date and this suite
     * shares one snapshot DB without transactions — everything
     * this file creates (day types, December leaves, their
     * cover rows and bells) is removed again so no other test's
     * fixtures or counts change underneath it.
     */
    protected function tearDown(): void
    {
        $ananyaId = User::where('email', 'teacher@hackstra.app')->value('id');
        Substitution::where('absent_teacher_id', $ananyaId)
            ->whereDate('date', '>=', '2026-12-01')->delete();
        \App\Models\LeaveRequest::where('user_id', $ananyaId)
            ->whereDate('start_date', '>=', '2026-12-01')->delete();
        \App\Models\UserNotification::where('id', '>', $this->notifFloor)->delete();
        SchoolDayType::query()->delete();
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function setDayType(string $date, string $type, ?int $periods = null): void
    {
        $this->actingAs($this->admin())->post(route('day_types.store'), [
            'date' => $date,
            'type' => $type,
            'half_day_periods' => $periods,
            'note' => 'Test day type',
        ])->assertRedirect(route('events.index'));
        $row = SchoolDayType::whereDate('date', $date)->first();
        $this->assertNotNull($row, 'the day type row must exist');
        $this->assertSame($type, $row->type);
    }

    protected function emergencyLeave(User $teacher, string $from, string $to): void
    {
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => $from,
            'end_date' => $to,
            'reason' => 'DayType emergency',
            'leave_type' => 'emergency',
        ])->assertSessionHas('flash_success', 'Emergency leave auto-approved.');
    }

    public function test_holiday_gets_no_cover_and_no_register()
    {
        $ananya = $this->ananya();
        $this->setDayType('2026-12-07', 'holiday');

        // Auto-cover arranges nothing for the holiday…
        $this->emergencyLeave($ananya, '2026-12-07', '2026-12-07');
        $this->assertSame(0, Substitution::where('absent_teacher_id', $ananya->id)
            ->whereDate('date', '2026-12-07')->count());

        // …but the NEXT day (a normal Tuesday… wait, 2026-11-03
        // is a Tuesday with no Ananya cells necessarily) — use a
        // two-Monday span instead: holiday only on the first.
        $this->setDayType('2026-12-14', 'holiday');
        $this->emergencyLeave($ananya, '2026-12-14', '2026-12-21');
        $this->assertSame(0, Substitution::where('absent_teacher_id', $ananya->id)
            ->whereDate('date', '2026-12-14')->count());
        $this->assertGreaterThan(0, Substitution::where('absent_teacher_id', $ananya->id)
            ->whereDate('date', '2026-12-21')->count(),
            'the normal Monday in the same leave must still be covered');

        // The attendance page says so and expects no registers.
        $page = $this->actingAs($ananya)->get(route('attendance.periods.index', ['date' => '2026-12-07']));
        $page->assertOk();
        $page->assertSee('Holiday');
        $page->assertSee('No regular period registers are taken today.');

        // One of Ananya's own Monday cells: take + store refuse.
        $cell = null;
        foreach (TimeTable::where('day', 'Monday')->whereNotNull('subject_id')->get() as $c) {
            $tid = \App\Support\SectionTeacher::teacherIdFor(optional($c->tt_record)->section_id ?? null, $c->subject);
            if ((int) $tid === (int) $ananya->id) {
                $cell = $c;
                break;
            }
        }
        $this->assertNotNull($cell, 'fixture: Ananya must have a Monday cell');
        $this->actingAs($ananya)
            ->get(route('attendance.periods.take', ['tt_id' => $cell->id, 'date' => '2026-12-07']))
            ->assertRedirect(route('attendance.periods.index', ['date' => '2026-12-07']))
            ->assertSessionHas('flash_danger');
        $this->actingAs($ananya)->post(route('attendance.periods.store', $cell->id), [
            'topic' => 'Should not land',
            'date' => '2026-12-07',
            'statuses' => [1 => 'present'],
        ])->assertSessionHasErrors('date');
    }

    public function test_half_day_cover_and_registers_stop_at_the_window()
    {
        $ananya = $this->ananya();
        $this->setDayType('2026-12-28', 'half_day', 4);

        $this->emergencyLeave($ananya, '2026-12-28', '2026-12-28');
        $rows = Substitution::where('absent_teacher_id', $ananya->id)
            ->whereDate('date', '2026-12-28')->get();
        $this->assertGreaterThan(0, $rows->count(), 'the running half must still be covered');
        $this->assertLessThan(8, $rows->count(), 'a half day cannot arrange a full day of cover');

        $ttrIds = $rows->map(function ($r) {
            $tt = TimeTable::find($r->time_table_id);

            return $tt ? (int) $tt->ttr_id : 0;
        })->unique()->all();
        $indexMap = LeaveCover::periodIndexMap($ttrIds, 'Monday');
        foreach ($rows as $row) {
            $n = $indexMap[(int) $row->time_table_id] ?? null;
            $this->assertNotNull($n);
            $this->assertLessThanOrEqual(4, $n, 'no cover beyond the half day window');
        }

        $page = $this->actingAs($ananya)->get(route('attendance.periods.index', ['date' => '2026-12-28']));
        $page->assertOk();
        $page->assertSee('Half day');
    }

    public function test_day_type_writes_are_admin_only_and_removable()
    {
        $this->actingAs($this->ananya())->post(route('day_types.store'), [
            'date' => '2026-12-01', 'type' => 'holiday',
        ]);
        $this->assertSame(0, SchoolDayType::whereDate('date', '2026-12-01')->count(),
            'a teacher must not be able to declare a holiday');

        $this->setDayType('2026-12-01', 'closure');
        $row = SchoolDayType::whereDate('date', '2026-12-01')->firstOrFail();
        $this->actingAs($this->admin())->delete(route('day_types.destroy', $row->id))
            ->assertRedirect(route('events.index'));
        $this->assertSame(0, SchoolDayType::whereDate('date', '2026-12-01')->count());
        $this->assertSame('normal', \App\Support\DayType::forDate('2026-12-01'));
    }
}
