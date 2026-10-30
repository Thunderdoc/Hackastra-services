<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Models\UserNotification;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Owner order 2026-10-30, three parts:
 *  (a) removing a teacher's standing (suspend / terminate /
 *      delete) must not strand the substitution cover they hold —
 *      the system moves every future period to the next free
 *      teacher and alerts the office about any it cannot move;
 *  (b) the substitute's bell lands on their own extra classes
 *      page (substitutions.mine), the one place listing all of it;
 *  (c) that page lists confirmed cover (live), planned cover and
 *      recent past cover — for the holding teacher only.
 *
 * All dates are computed from the run date (a future Monday), so
 * the file is calendar-safe — the 2026-10-30 CI lesson.
 */
class OwnerSubstitutionCoverHealTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function futureMonday(): Carbon
    {
        return Carbon::now()->next(Carbon::MONDAY)->startOfDay();
    }

    /** A Monday cell plus its effective (absent) teacher. */
    protected function mondayCell(): array
    {
        $cell = TimeTable::with(['subject', 'tt_record'])
            ->where('day', 'Monday')->whereNotNull('subject_id')
            ->orderBy('id')->firstOrFail();
        $absentId = (int) SectionTeacher::teacherIdFor(
            optional($cell->tt_record)->section_id, $cell->subject);
        $absent = User::findOrFail($absentId);

        return [$cell, $absent];
    }

    /** A live teacher other than the given ones, to hold cover. */
    protected function holder(int ...$exclude): User
    {
        return User::where('user_type', 'teacher')
            ->whereNotIn('id', $exclude)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', 'active');
            })
            ->orderBy('id')->firstOrFail();
    }

    protected function plantCover(TimeTable $cell, User $absent, User $substitute, Carbon $date, string $status): Substitution
    {
        return Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $date->toDateString(),
            'absent_teacher_id' => $absent->id,
            'substitute_teacher_id' => $substitute->id,
            'created_by' => $this->admin()->id,
            'status' => $status,
        ]);
    }

    public function test_suspend_moves_future_confirmed_cover_and_bells_the_new_substitute()
    {
        [$cell, $absent] = $this->mondayCell();
        $departing = $this->holder((int) $absent->id);
        $row = $this->plantCover($cell, $absent, $departing, $this->futureMonday(), 'confirmed');

        $response = $this->actingAs($this->admin())
            ->post(route('users.suspend', Qs::hash($departing->id)));
        $response->assertSessionHas('flash_success');
        $this->assertStringContainsString(
            'substitution cover(s) moved',
            (string) session('flash_success'));

        $row->refresh();
        $this->assertNotSame((int) $departing->id, (int) $row->substitute_teacher_id,
            'Cover must move off the suspended teacher');
        $newSub = User::findOrFail($row->substitute_teacher_id);
        $this->assertSame('teacher', $newSub->user_type);
        $this->assertTrue($newSub->isActiveAccount());

        // The new substitute is belled, landing on their own page.
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $newSub->id,
            'type' => 'substitution_assigned',
            'link' => route('substitutions.mine'),
        ]);
    }

    public function test_suspend_moves_pending_plan_rows_silently()
    {
        [$cell, $absent] = $this->mondayCell();
        $departing = $this->holder((int) $absent->id);
        $row = $this->plantCover($cell, $absent, $departing, $this->futureMonday(), 'pending');

        $this->actingAs($this->admin())
            ->post(route('users.suspend', Qs::hash($departing->id)));

        $row->refresh();
        $this->assertNotSame((int) $departing->id, (int) $row->substitute_teacher_id);
        // A planned row rings nobody — it is not live yet.
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $row->substitute_teacher_id,
            'type' => 'substitution_assigned',
        ]);
    }

    public function test_suspend_leaves_past_cover_untouched()
    {
        [$cell, $absent] = $this->mondayCell();
        $departing = $this->holder((int) $absent->id);
        $past = Carbon::now()->previous(Carbon::MONDAY)->startOfDay();
        $row = $this->plantCover($cell, $absent, $departing, $past, 'confirmed');

        $this->actingAs($this->admin())
            ->post(route('users.suspend', Qs::hash($departing->id)));

        $row->refresh();
        $this->assertSame((int) $departing->id, (int) $row->substitute_teacher_id,
            'Past cover is history — it stays as it happened');
    }

    public function test_mine_page_lists_my_cover_and_is_teachers_only()
    {
        [$cell, $absent] = $this->mondayCell();
        $holder = $this->holder((int) $absent->id);
        $date = $this->futureMonday();
        $this->plantCover($cell, $absent, $holder, $date, 'confirmed');
        $this->plantCover($cell, $absent, $holder, $date->copy()->addWeek(), 'pending');

        $response = $this->actingAs($holder)->get(route('substitutions.mine'));
        $response->assertOk();
        $response->assertSee('My Extra Classes');
        $response->assertSee($absent->name);
        $response->assertSee($cell->subject->name);
        $response->assertSee($date->format('l, d M Y'));
        $response->assertSee('Planned for you');

        // Another teacher does not see this cover as theirs.
        $other = $this->holder((int) $absent->id, (int) $holder->id);
        $otherView = $this->actingAs($other)->get(route('substitutions.mine'));
        $otherView->assertOk();
        $otherView->assertDontSee($date->format('l, d M Y'));

        // The page is for teachers — admin and the absent-side
        // office roles use the board instead.
        $this->actingAs($this->admin())->get(route('substitutions.mine'))->assertForbidden();
    }

    public function test_teacher_menu_links_my_extra_classes_not_the_board()
    {
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $response = $this->actingAs($teacher)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('My Extra Classes');
        $response->assertSee(route('substitutions.mine'), false);
    }
}
