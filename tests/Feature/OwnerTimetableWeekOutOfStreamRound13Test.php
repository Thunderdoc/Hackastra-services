<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Agent D round 13 — week-view downstream of the stream guard.
 *
 * Rounds 9/10/12 proved the validator flags out-of-stream cells,
 * the Manage lists filter them, and slot-sync preserves them. None
 * of that proves the surface teachers actually read — their own
 * week view (screen, print, PDF) — survives a dirty grid. The
 * week dataset (teacherWeekData) filters via
 * SectionTeacher::applyTeacherFilter, which resolves by override
 * or subject teacher with NO stream check, so a placed
 * out-of-stream cell whose subject teacher is the viewer should
 * render like any other period (visibility philosophy), not crash
 * and not vanish.
 *
 * Fixture is fully fresh: a pcb section in Class 12, one in-stream
 * subject and one commerce-only subject, both taught by the probe
 * teacher, one cell each. All data rolls back.
 */
class OwnerTimetableWeekOutOfStreamRound13Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R13 Teacher ' . $tag,
            'email' => 'r13-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R13' . $tag . uniqid(),
        ]);
    }

    protected function subject(string $name, User $teacher, ?string $stream): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)) . '-' . uniqid(),
            'my_class_id' => 13,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
            'stream' => $stream,
        ]);
    }

    protected function slot(int $recordId, string $from, string $to): TimeSlot
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

    /**
     * Returns [teacher, inSubject, outSubject, section, record].
     * The record is genuinely dirty: the validator must flag the
     * commerce cell (asserted in the first test).
     */
    protected function weekFixture(): array
    {
        $teacher = $this->teacher('WK');
        $section = Section::create([
            'name' => 'R13 Sec ' . uniqid(),
            'my_class_id' => 13,
            'teacher_id' => $this->teacher('SEC')->id,
            'stream' => 'pcb',
        ]);
        $in = $this->subject('R13 PCB ' . uniqid(), $teacher, 'pcb');
        $out = $this->subject('R13 Commerce ' . uniqid(), $teacher, 'commerce');
        $record = TimeTableRecord::create([
            'name' => 'R13 Week ' . uniqid(),
            'my_class_id' => 13,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        // NOTE: time_slots UNIQUE(timestamp_from, timestamp_to,
        // ttr_id) — the two slots must differ in clock times.
        $slot1 = $this->slot($record->id, '9:00 AM', '9:40 AM');
        $slot2 = $this->slot($record->id, '9:40 AM', '10:20 AM');
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot1->id,
            'day' => 'Monday', 'day_num' => 1, 'subject_id' => $in->id,
            'timestamp_from' => strtotime('2026-10-26 09:00:00'),
            'timestamp_to' => strtotime('2026-10-26 09:40:00'),
        ]);
        TimeTable::create([
            'ttr_id' => $record->id, 'ts_id' => $slot2->id,
            'day' => 'Tuesday', 'day_num' => 2, 'subject_id' => $out->id,
            'timestamp_from' => strtotime('2026-10-27 09:40:00'),
            'timestamp_to' => strtotime('2026-10-27 10:20:00'),
        ]);

        return [$teacher, $in, $out, $section, $record];
    }

    public function test_week_screen_shows_out_of_stream_period()
    {
        [$teacher, $in, $out, $section, $record] = $this->weekFixture();

        // The fixture is genuinely dirty, not a clean grid in disguise.
        $errors = TimetableValidator::report($record->fresh())['errors'];
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, 'is not offered to this section')),
            'Expected a stream error for the commerce cell, got: ' . implode(' | ', $errors));

        $this->actingAs($teacher)
            ->get(route('tt.mine'))
            ->assertOk()
            ->assertSee($in->name, false)
            ->assertSee($out->name, false)
            ->assertSee($section->name, false);
    }

    public function test_week_print_shows_out_of_stream_period()
    {
        [$teacher, $in, $out] = $this->weekFixture();

        $this->actingAs($teacher)
            ->get(route('tt.mine_print'))
            ->assertOk()
            ->assertSee($in->name, false)
            ->assertSee($out->name, false);
    }

    public function test_week_pdf_renders_for_dirty_grid()
    {
        [$teacher] = $this->weekFixture();

        $response = $this->actingAs($teacher)->get(route('tt.mine_pdf'));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf',
            $response->headers->get('Content-Type') ?? '');
        $this->assertNotEmpty($response->getContent(), 'PDF body must not be empty');
    }

    public function test_unrelated_teacher_week_hides_both_periods()
    {
        [$teacher, $in, $out] = $this->weekFixture();
        $other = $this->teacher('OTH');

        $this->actingAs($other)
            ->get(route('tt.mine'))
            ->assertOk()
            ->assertDontSee($in->name, false)
            ->assertDontSee($out->name, false);
    }
}
