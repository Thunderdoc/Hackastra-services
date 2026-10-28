<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\Substitution;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\UserNotification;
use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent F round 33 — substitution cover-teacher liveness.
 *
 * Round 31 (F-R31-1) closed the matrix save for suspended/terminated
 * override teachers; round 32 closed the direct-subject and
 * section-leader saves. This round probes the last assignment path in
 * F scope: SubstitutionController::store naming a dead teacher as the
 * cover. Code read first: store ALREADY guards the substitute at
 * SubstitutionController.php:152 —
 *   $substitute->user_type !== 'teacher' || !$substitute->isActiveAccount()
 *   → 'The substitute must be an active teacher.'
 * where User::isActiveAccount() treats a missing/default status as
 * live (accountStatus() defaults to 'active', with a ?: fallback for
 * empty values), the same live standard as Staffing/LeaveCover and
 * the F-R31-1/F-R32-1 guards. Note on the null leg: users.status is
 * NOT NULL with default 'active' (migration 2026_10_22_000002), so a
 * true NULL status is not creatable through the app — the live shape
 * below is the default status, and the ?: fallback is defensive only.
 * These tests lock that guard through the REAL store path, plus the residual:
 * a row planted before suspension still renders (same boundary as
 * the pre-existing dead override/direct/leader rows — suspension is
 * temporary, so rows are not auto-cleared; the admin destroy hatch
 * clears them).
 *
 * Fixtures are fully fresh (DatabaseTransactions): class K, section S
 * led by D, subject X direct-taught by D, a section-grid record with
 * one Monday 9:00–9:40 cell. Store dates are Mondays (2026-11-02,
 * 2026-11-09) so the weekday rule passes and the liveness leg is the
 * only leg under test. Fresh substitute teachers hold no cells, so
 * the clash leg can never fire spuriously.
 */
class OwnerAllotmentRound33Test extends TestCase
{
    use DatabaseTransactions;

    protected string $monday = '2026-11-02';

    protected string $monday2 = '2026-11-09';

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeTeacher(string $code, string $email, ?string $status): User
    {
        // users.status is NOT NULL with default 'active': a null
        // argument means "omit the key, take the default", never an
        // explicit NULL insert (which 500s on the constraint).
        $attrs = [
            'name' => 'Probe Teacher R33 ' . $code,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => 'teacher',
        ];
        if ($status !== null) {
            $attrs['status'] = $status;
        }

        return User::create($attrs);
    }

    /** Returns [D, K, S, subject, cell]. D is the absent teacher. */
    protected function fixture(string $suffix): array
    {
        $d = $this->makeTeacher('PROBE-R33-D' . $suffix, 'probe-r33-d' . strtolower($suffix) . '@example.com', null);
        $k = MyClass::create(['name' => 'ProbeR33Class ' . $suffix, 'class_type_id' => 4]);
        $s = Section::create(['name' => 'R33 S ' . $suffix, 'my_class_id' => $k->id, 'teacher_id' => $d->id]);
        $subject = Subject::create([
            'name' => 'Probe Sub Subject R33' . $suffix,
            'my_class_id' => $k->id,
            'teacher_id' => $d->id,
            'slug' => 'probe-r33-' . strtolower($suffix),
            'periods_per_week' => 6,
        ]);
        $record = TimeTableRecord::create([
            'name' => 'ProbeR33 Record ' . $suffix,
            'my_class_id' => $k->id,
            'section_id' => $s->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9,
            'min_from' => '00',
            'meridian_from' => 'AM',
            'hour_to' => 9,
            'min_to' => '40',
            'meridian_to' => 'AM',
            'time_from' => '9:00 AM',
            'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'),
            'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        $cell = TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'subject_id' => $subject->id,
            'day' => 'Monday',
            'day_num' => 1,
            'timestamp_from' => '9:00 AM',
            'timestamp_to' => '9:40 AM',
        ]);

        return [$d, $k, $s, $subject, $cell];
    }

    protected function storeAsAdmin(TimeTable $cell, string $date, int $substituteId)
    {
        return $this->actingAs($this->admin())->post(route('substitutions.store'), [
            'date' => $date,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $substituteId,
            'reason' => 'Sick leave',
        ]);
    }

    protected function bellsFor(int $userId): int
    {
        return UserNotification::where('user_id', $userId)
            ->where('type', 'substitution_assigned')->count();
    }

    public function test_suspended_substitute_is_refused_with_no_row_and_no_bell(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('SUSP');
        $dead = $this->makeTeacher('PROBE-R33-SUSP', 'probe-r33-susp@example.com', 'suspended');

        $response = $this->storeAsAdmin($cell, $this->monday, $dead->id);

        $response->assertSessionHasErrors(['substitute_teacher_id' => 'The substitute must be an active teacher.']);
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
        $this->assertSame(0, $this->bellsFor($dead->id));
    }

    public function test_terminated_substitute_is_refused_with_no_row_and_no_bell(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('TERM');
        $dead = $this->makeTeacher('PROBE-R33-TERM', 'probe-r33-term@example.com', 'terminated');

        $response = $this->storeAsAdmin($cell, $this->monday, $dead->id);

        $response->assertSessionHasErrors(['substitute_teacher_id' => 'The substitute must be an active teacher.']);
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
        $this->assertSame(0, $this->bellsFor($dead->id));
    }

    public function test_live_substitute_with_default_status_stores_and_bells(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('LIVE');
        $live = $this->makeTeacher('PROBE-R33-LIVE', 'probe-r33-live@example.com', null);

        $response = $this->storeAsAdmin($cell, $this->monday, $live->id);

        $response->assertRedirect(route('substitutions.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame(1, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
        $row = Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->firstOrFail();
        $this->assertSame($d->id, (int) $row->absent_teacher_id);
        $this->assertSame($live->id, (int) $row->substitute_teacher_id);
        $this->assertSame(1, $this->bellsFor($live->id));
    }

    public function test_live_substitute_with_explicit_active_status_stores(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('ACTV');
        $live = $this->makeTeacher('PROBE-R33-ACTV', 'probe-r33-actv@example.com', 'active');

        $response = $this->storeAsAdmin($cell, $this->monday, $live->id);

        $response->assertRedirect(route('substitutions.index'));
        $this->assertSame(1, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
        $this->assertSame(1, $this->bellsFor($live->id));
    }

    public function test_non_teacher_substitute_is_refused_by_the_same_guard(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('NONT');
        $admin = $this->admin();

        $response = $this->storeAsAdmin($cell, $this->monday, $admin->id);

        $response->assertSessionHasErrors(['substitute_teacher_id' => 'The substitute must be an active teacher.']);
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
    }

    public function test_absent_teacher_creator_is_held_to_the_same_liveness_guard(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('CREA');
        $dead = $this->makeTeacher('PROBE-R33-CREA', 'probe-r33-crea@example.com', 'suspended');

        // The absent teacher herself creates for her own period — the
        // create guard passes (absent == caller) but liveness refuses.
        $response = $this->actingAs($d)->post(route('substitutions.store'), [
            'date' => $this->monday,
            'time_table_id' => $cell->id,
            'substitute_teacher_id' => $dead->id,
            'reason' => 'Sick leave',
        ]);

        $response->assertSessionHasErrors(['substitute_teacher_id' => 'The substitute must be an active teacher.']);
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
    }

    public function test_preexisting_row_for_teacher_suspended_after_assignment_still_renders_and_blocks_until_destroyed(): void
    {
        [$d, $k, $s, $subject, $cell] = $this->fixture('RESI');
        $sub = $this->makeTeacher('PROBE-R33-RESI', 'probe-r33-resi@example.com', null);
        $row = Substitution::create([
            'time_table_id' => $cell->id,
            'date' => $this->monday,
            'absent_teacher_id' => $d->id,
            'substitute_teacher_id' => $sub->id,
            'created_by' => $this->admin()->id,
            'reason' => 'Sick leave',
        ]);
        // Suspended AFTER the assignment was arranged.
        $sub->update(['status' => 'suspended']);

        // The dead row still renders for the admin (and names her).
        $response = $this->actingAs($this->admin())->get(route('substitutions.index'));
        $response->assertOk();
        $response->assertSee($sub->name);

        // It still occupies the slot: a live replacement for the same
        // cell+date is refused until the dead row is destroyed.
        $live = $this->makeTeacher('PROBE-R33-RESL', 'probe-r33-resl@example.com', null);
        $blocked = $this->storeAsAdmin($cell, $this->monday, $live->id);
        $blocked->assertSessionHasErrors(['time_table_id']);
        $this->assertSame(1, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());

        // The admin destroy hatch clears it.
        $this->actingAs($this->admin())->delete(route('substitutions.destroy', $row))
            ->assertRedirect(route('substitutions.index'));
        $this->assertSame(0, Substitution::where('time_table_id', $cell->id)->whereDate('date', $this->monday)->count());
    }
}
