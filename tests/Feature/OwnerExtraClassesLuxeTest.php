<?php

namespace Tests\Feature;

use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Item 13 (owner choice, 2026-11-01): My Extra Classes rebuilt
 * Light Luxe — slim header, three tiles, period cards with a time
 * rail grouped by day, and a designed empty state. Access rules
 * are unchanged (confirmed rows only; Take Attendance for today's
 * confirmed periods, same route) — pinned here on the new markup.
 * (TestCase clears substitutions before every test, so the empty
 * state is the natural starting point.)
 */
class OwnerExtraClassesLuxeTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function cellAndAbsent(): array
    {
        $cell = TimeTable::with(['subject', 'tt_record'])
            ->whereNotNull('subject_id')->orderBy('id')->firstOrFail();
        $absent = User::findOrFail((int) SectionTeacher::teacherIdFor(
            optional($cell->tt_record)->section_id, $cell->subject));

        return [$cell, $absent];
    }

    private function plant(TimeTable $cell, User $absent, User $sub, Carbon $date, string $status): void
    {
        Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $date->toDateString(),
            'absent_teacher_id' => $absent->id,
            'substitute_teacher_id' => $sub->id,
            'created_by' => $this->admin()->id,
            'status' => $status,
        ]);
    }

    public function test_empty_state_renders_the_designed_card()
    {
        $html = $this->actingAs($this->teacher())->get(route('substitutions.mine'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('l13-empty', $html);
        $this->assertStringContainsString(
            'No extra classes right now. When a colleague\'s leave makes you the substitute, the period will appear here and your bell will ring.',
            $html);
        $this->assertStringContainsString('Periods Today', $html);
        $this->assertStringNotContainsString('l13-card', $html);
    }

    public function test_confirmed_cover_for_today_renders_a_period_card_with_attendance_action()
    {
        [$cell, $absent] = $this->cellAndAbsent();
        $me = $this->teacher();
        if ((int) $absent->id === (int) $me->id) {
            $me = User::where('user_type', 'teacher')->where('id', '!=', $absent->id)->firstOrFail();
        }
        $this->plant($cell, $absent, $me, Carbon::today(), 'confirmed');

        $html = $this->actingAs($me)->get(route('substitutions.mine'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('l13-card', $html);
        $this->assertStringContainsString('Confirmed', $html);
        $this->assertStringContainsString('Covering for', $html);
        $this->assertStringContainsString($absent->name, $html);
        $this->assertStringContainsString($cell->subject->name, $html);
        $this->assertStringContainsString('Take Attendance', $html);
        $this->assertStringContainsString(
            route('attendance.periods.index', ['date' => Carbon::today()->toDateString()]), $html);
        $this->assertStringContainsString('Periods Today', $html);
    }

    public function test_planned_cover_has_no_attendance_action()
    {
        [$cell, $absent] = $this->cellAndAbsent();
        $me = User::where('user_type', 'teacher')->where('id', '!=', $absent->id)->firstOrFail();
        $this->plant($cell, $absent, $me, Carbon::today()->addDays(2), 'pending');

        $html = $this->actingAs($me)->get(route('substitutions.mine'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Planned for you', $html);
        $this->assertStringContainsString('Planned', $html);
        $this->assertStringNotContainsString('Take Attendance', $html);
        $this->assertStringNotContainsString('>Confirmed<', $html);
    }
}
