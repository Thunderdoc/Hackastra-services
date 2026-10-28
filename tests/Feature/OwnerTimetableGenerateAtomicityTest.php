<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 6 — generate-run atomicity.
 *
 * The per-target DB::transaction (line ~588) makes each target atomic,
 * but a multi-target run had NO outer transaction: a mid-run failure
 * (DB exception, lock, timeout) left earlier targets rebuilt, the
 * failing target rolled back, and later targets never attempted —
 * plus a bare 500 instead of any summary. These tests pin whole-run
 * atomicity: a forced mid-run failure must leave ZERO targets
 * changed and return the admin to the form with a clear flash.
 */
class OwnerTimetableGenerateAtomicityTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'Atomicity Teacher ' . $code,
            'email' => 'atomicity-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    /** Two fresh classes, each with its own fresh teacher + subject. */
    private function twoFreshClasses(): array
    {
        $a = MyClass::create(['name' => 'R6 Atomicity A']);
        $b = MyClass::create(['name' => 'R6 Atomicity B']);
        $ta = $this->teacher('R6A');
        $tb = $this->teacher('R6B');
        Subject::create([
            'name' => 'R6 Subject A', 'slug' => 'r6-sub-a',
            'my_class_id' => $a->id, 'teacher_id' => $ta->id,
            'periods_per_week' => 6,
        ]);
        Subject::create([
            'name' => 'R6 Subject B', 'slug' => 'r6-sub-b',
            'my_class_id' => $b->id, 'teacher_id' => $tb->id,
            'periods_per_week' => 6,
        ]);

        return [$a, $b];
    }

    private function generateAllSkip()
    {
        return $this->actingAs($this->admin())
            ->from(route('tt.generate.form'))
            ->post(route('tt.generate'), [
                'start_time' => '09:00',
                'period_minutes' => 40,
                'periods_per_day' => 6,
                'lunch_after' => 4,
                'lunch_minutes' => 45,
                'break_after' => 2,
                'break_minutes' => 15,
                'days' => 6,
                'scope' => 'all',
                'mode' => 'skip',
            ]);
    }

    private function cellsForClass(int $classId): int
    {
        $recordIds = TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->pluck('id');

        return TimeTable::whereIn('ttr_id', $recordIds)->count();
    }

    private function recordsForClass(int $classId): int
    {
        return TimeTableRecord::where('my_class_id', $classId)
            ->whereNull('exam_id')->where('year', Qs::getCurrentSession())->count();
    }

    /**
     * Mid-run failure (thrown on the second fresh class's first cell)
     * must leave ZERO targets changed: no records, no slots, no cells
     * for either fresh class — and the admin gets a failure flash,
     * not a bare 500.
     */
    public function test_generate_rolls_back_whole_run_on_mid_run_failure()
    {
        [$a, $b] = $this->twoFreshClasses();

        try {
            TimeTable::creating(function ($cell) use ($b) {
                $classId = TimeTableRecord::where('id', $cell->ttr_id)->value('my_class_id');
                if ((int) $classId === (int) $b->id) {
                    throw new \Exception('R6 mid-run failure probe');
                }
            });

            $response = $this->generateAllSkip();
        } finally {
            // No TimeTable listeners exist in the app; flushing is
            // safe and keeps the probe out of later tests.
            TimeTable::flushEventListeners();
        }

        $response->assertRedirect(route('tt.generate.form'));
        $response->assertSessionHas(
            'flash_danger',
            'Timetable generation failed — nothing was changed. Please try again.'
        );
        $this->assertSame(0, $this->cellsForClass($a->id), 'first target must roll back too');
        $this->assertSame(0, $this->cellsForClass($b->id));
        $this->assertSame(0, $this->recordsForClass($a->id), 'first target record must roll back');
        $this->assertSame(0, $this->recordsForClass($b->id));
        $this->assertSame(0, TimeSlot::whereIn('ttr_id', TimeTableRecord::where('my_class_id', $a->id)->pluck('id'))->count());
    }

    /** Happy path unchanged: both fresh classes generate fully. */
    public function test_generate_success_still_builds_all_targets()
    {
        [$a, $b] = $this->twoFreshClasses();

        $response = $this->generateAllSkip();

        $response->assertRedirect(route('tt.index'));
        $response->assertSessionHas('flash_success');
        $this->assertSame(1, $this->recordsForClass($a->id));
        $this->assertSame(1, $this->recordsForClass($b->id));
        $this->assertGreaterThan(0, $this->cellsForClass($a->id));
        $this->assertGreaterThan(0, $this->cellsForClass($b->id));
    }
}
