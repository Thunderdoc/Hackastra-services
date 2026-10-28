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
 * Agent D round 4 — generate summary cause accuracy.
 *
 * The summary names ONE cause per empty cell (cap > no-teacher >
 * free > busy). These probes mix two blockers in one cell and check
 * the named cause is the TRUE first blocker, not a misleading one.
 * The mix case (teacher-less + busy) is the sharp one: before the
 * round-4 fix it was bucketed "every teacher busy", which is
 * factually false for the subject that has no teacher at all —
 * and "fill them on the Manage page" would clash if the admin
 * placed the busy teacher. Assigning a teacher to the unassigned
 * subject is the only clean lever, so the mix must bucket as
 * no-teacher. Capped stays first: for a capped cell at least one
 * lever in its message always works (raise the cap, or add
 * teachers when the capped teacher is also busy).
 */
class OwnerTimetableCauseRound4Test extends TestCase
{
    use DatabaseTransactions;

    private function year(): string
    {
        return Qs::getCurrentSession();
    }

    private function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $code): User
    {
        return User::create([
            'name' => 'Cause Teacher ' . $code,
            'email' => 'cause-' . strtolower($code) . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => $code,
        ]);
    }

    private function subject(string $name, string $slug, int $classId, $teacherId, int $ppw = 6): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => $slug,
            'my_class_id' => $classId,
            'teacher_id' => $teacherId,
            'periods_per_week' => $ppw,
        ]);
    }

    private function record(int $classId): TimeTableRecord
    {
        return TimeTableRecord::create([
            'name' => 'Cause Record ' . $classId,
            'my_class_id' => $classId,
            'section_id' => null,
            'exam_id' => null,
            'year' => $this->year(),
        ]);
    }

    private function slot(int $recordId, string $from, string $to): TimeSlot
    {
        return TimeSlot::create([
            'ttr_id' => $recordId,
            'hour_from' => (int) date('g', strtotime($from)),
            'min_from' => date('i', strtotime($from)),
            'meridian_from' => date('A', strtotime($from)),
            'hour_to' => (int) date('g', strtotime($to)),
            'min_to' => date('i', strtotime($to)),
            'meridian_to' => date('A', strtotime($to)),
            'time_from' => $from,
            'time_to' => $to,
            'timestamp_from' => strtotime($from),
            'timestamp_to' => strtotime($to),
            'full' => $from . ' - ' . $to,
        ]);
    }

    /** Kept grid that makes $teacherId busy at every generated slot, every day. */
    private function keptBusyGrid(int $teacherId): void
    {
        $class = MyClass::create(['name' => 'Cause Kept Class ' . $teacherId]);
        $subject = $this->subject('Kept Subject', 'kept-' . $teacherId, $class->id, $teacherId);
        $rec = $this->record($class->id);
        $intervals = [
            ['9:00 AM', '9:40 AM'], ['9:40 AM', '10:20 AM'], ['10:35 AM', '11:15 AM'],
            ['11:15 AM', '11:55 AM'], ['12:40 PM', '1:20 PM'], ['1:20 PM', '2:00 PM'],
        ];
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        foreach ($intervals as [$from, $to]) {
            $slot = $this->slot($rec->id, $from, $to);
            foreach ($days as $i => $day) {
                TimeTable::create([
                    'ttr_id' => $rec->id,
                    'ts_id' => $slot->id,
                    'subject_id' => $subject->id,
                    'day' => $day,
                    'day_num' => $i + 1,
                    'timestamp_from' => strtotime($day . ' ' . $from),
                    'timestamp_to' => strtotime($day . ' ' . $to),
                ]);
            }
        }
    }

    private function generatePost(array $overrides = [])
    {
        return $this->actingAs($this->admin())->post(route('tt.generate'), array_merge([
            'start_time' => '09:00',
            'period_minutes' => 40,
            'periods_per_day' => 6,
            'lunch_after' => 4,
            'lunch_minutes' => 45,
            'break_after' => 2,
            'break_minutes' => 15,
            'days' => 6,
            'scope' => 'one',
            'mode' => 'replace',
        ], $overrides));
    }

    /** Mix: one teacher-less subject + one busy-teacher subject. Must bucket no-teacher, not busy. */
    public function test_mix_teacherless_and_busy_buckets_as_no_teacher()
    {
        $t = $this->teacher('MIX');
        $this->keptBusyGrid($t->id);

        $class = MyClass::create(['name' => 'Cause Mix Class']);
        $this->subject('Mix Unassigned', 'mix-unassigned', $class->id, null);
        $this->subject('Mix Busy', 'mix-busy', $class->id, $t->id);

        $response = $this->generatePost(['class_id' => $class->id]);
        $response->assertRedirect(route('tt.index'));
        $flash = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('0 periods placed', $flash);
        $this->assertStringContainsString('36 period(s) left empty (no teacher assigned', $flash);
        $this->assertStringNotContainsString('every teacher busy', $flash);
    }

    /** Capped + busy: cap stays first (raise-the-cap OR add-teachers always offers a working lever). */
    public function test_capped_and_busy_buckets_as_capped()
    {
        $t = $this->teacher('CAPBUSY');
        $this->keptBusyGrid($t->id);

        $class = MyClass::create(['name' => 'Cause CapBusy Class']);
        $this->subject('CapBusy Subject', 'capbusy-sub', $class->id, $t->id);

        $response = $this->generatePost(['class_id' => $class->id, 'max_teacher_periods_per_day' => 1]);
        $response->assertRedirect(route('tt.index'));
        $flash = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('36 period(s) left empty (teacher daily cap reached', $flash);
    }

    /** Pure busy (no teacher-less, no cap): every-teacher-busy is literally true. */
    public function test_pure_busy_buckets_as_busy()
    {
        $t = $this->teacher('BUSY');
        $this->keptBusyGrid($t->id);

        $class = MyClass::create(['name' => 'Cause Busy Class']);
        $this->subject('Busy Subject', 'busy-sub', $class->id, $t->id);

        $response = $this->generatePost(['class_id' => $class->id]);
        $response->assertRedirect(route('tt.index'));
        $flash = $response->getSession()->get('flash_success');
        $this->assertStringContainsString('36 period(s) left empty (every teacher busy)', $flash);
    }
}
