<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Models\TimeSlot;
use App\Support\SectionTeacher;
use App\Support\TimetableValidator;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Agent F round 15 (2026-10-28) — edit-matrix × stream coherence.
 *
 * Agent D round 9 made the timetable stream guard visibility, not
 * restriction: the validator flags out-of-stream cells, Manage
 * lists filter them, placement stays possible. This round asks
 * the same question of the OTHER surface that assigns teachers
 * to streamed sections — the section edit matrix:
 *
 * The matrix (SectionController::edit) passes ALL of the
 * section's class subjects to the blade with NO stream filter,
 * and updateTeachers() stores an override for ANY class subject
 * (its only filter is the class boundary: foreign-class ids are
 * ignored, round 5). So the shape is (a) LISTED AS ASSIGNABLE —
 * and the coherence question becomes: does saving an override
 * for an out-of-stream subject LAUNDER the timetable grid?
 *
 * It must not. The validator's stream error keys off
 * Streams::appliesTo(subject, section stream) — subject tags
 * vs section stream — never off teacher resolution, so an
 * override changes WHO teaches the cell, never WHETHER the
 * subject is offered there. These tests lock that:
 *
 *  1. the edit page for a pcb section lists a commerce-only
 *     subject in the matrix (assignable, not filtered);
 *  2. a matrix PUT storing an override for that commerce
 *     subject persists (class-subject boundary, not a stream
 *     boundary);
 *  3. a grid cell for that subject on the section's record
 *     carries the stream error BEFORE the override and the
 *     SAME stream error exactly once AFTER it — the record
 *     stays out of cleanRecordIds. The override never launders;
 *  4. control: a crafted PUT naming a subject of ANOTHER
 *     class still plants nothing on this section (the matrix's
 *     real filter boundary, round 5, still holds).
 *
 * Fixtures are fully fresh (own section, teachers, subjects,
 * record, slot) so no seeded cell or clash can pollute counts.
 * All data rolls back.
 */
class OwnerAllotmentRound15Test extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    private function teacher(string $tag): User
    {
        return User::create([
            'name' => 'R15 Teacher ' . $tag,
            'email' => 'r15-' . strtolower($tag) . '-' . uniqid() . '@test.local',
            'password' => bcrypt('teacher'),
            'user_type' => 'teacher',
            'code' => 'R15' . $tag . uniqid(),
        ]);
    }

    private function subject(string $name, int $classId, User $teacher, ?string $stream): Subject
    {
        return Subject::create([
            'name' => $name,
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/', '-', $name)) . '-' . uniqid(),
            'my_class_id' => $classId,
            'teacher_id' => $teacher->id,
            'periods_per_week' => 6,
            'stream' => $stream,
        ]);
    }

    /** Fresh pcb section in Class 12 + one pcb + one commerce-only subject. */
    private function fixture(): array
    {
        $section = Section::create([
            'name' => 'R15 Sec ' . uniqid(),
            'my_class_id' => 13,
            'teacher_id' => $this->teacher('SEC')->id,
            'stream' => 'pcb',
        ]);
        $pcb = $this->subject('R15 Pcb ' . uniqid(), 13, $this->teacher('PCB'), 'pcb');
        $commerce = $this->subject('R15 Commerce ' . uniqid(), 13, $this->teacher('COM'), 'commerce');
        return [$section, $pcb, $commerce];
    }

    private function streamErrors(TimeTableRecord $record): array
    {
        $errors = TimetableValidator::report($record->fresh())['errors'];
        return array_values(array_filter($errors, function ($e) {
            return str_contains($e, "is not offered to this section's stream");
        }));
    }

    public function test_edit_matrix_lists_out_of_stream_subject_as_assignable()
    {
        [$section, $pcb, $commerce] = $this->fixture();

        $response = $this->actingAs($this->admin())
            ->get(route('sections.edit', $section->id));

        $response->assertOk();
        $response->assertSee($pcb->name);
        // The commerce-only subject is listed in the matrix for a
        // pcb section — no stream filter on the edit surface.
        $response->assertSee($commerce->name);
        $response->assertSee('teachers[' . $commerce->id . ']');
    }

    public function test_matrix_save_stores_out_of_stream_override()
    {
        [$section, $pcb, $commerce] = $this->fixture();
        $overrideTeacher = $this->teacher('OVR');

        $response = $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash($section->id)),
            ['teachers' => [$commerce->id => Qs::hash($overrideTeacher->id)]]
        );

        $response->assertRedirect(route('sections.index'));
        $this->assertDatabaseHas('section_subject_teachers', [
            'section_id' => $section->id,
            'subject_id' => $commerce->id,
            'teacher_id' => $overrideTeacher->id,
        ]);
        $this->assertSame(
            $overrideTeacher->id,
            SectionTeacher::teacherIdFor($section->id, $commerce)
        );
    }

    public function test_out_of_stream_override_does_not_launder_grid()
    {
        [$section, $pcb, $commerce] = $this->fixture();
        $overrideTeacher = $this->teacher('OVR');

        $record = TimeTableRecord::create([
            'name' => 'R15 Grid ' . uniqid(),
            'my_class_id' => 13,
            'section_id' => $section->id,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
        ]);
        $slot = TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        TimeTable::create([
            'ttr_id' => $record->id,
            'ts_id' => $slot->id,
            'day' => 'Monday',
            'day_num' => 1,
            'subject_id' => $commerce->id,
            'timestamp_from' => strtotime('2026-10-26 09:00:00'),
            'timestamp_to' => strtotime('2026-10-26 09:40:00'),
        ]);

        // Before the override: exactly one stream error, record dirty.
        $before = $this->streamErrors($record);
        $this->assertCount(1, $before);
        $this->assertStringContainsString($commerce->name, $before[0]);
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));

        // Save the override for the out-of-stream subject.
        $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash($section->id)),
            ['teachers' => [$commerce->id => Qs::hash($overrideTeacher->id)]]
        );
        $this->assertSame(
            $overrideTeacher->id,
            SectionTeacher::teacherIdFor($section->id, $commerce)
        );

        // After the override: the SAME stream error exactly once,
        // record still dirty. Resolution changed who teaches the
        // cell; offering did not change, so validation did not.
        $after = $this->streamErrors($record);
        $this->assertCount(1, $after);
        $this->assertSame($before, $after);
        $this->assertNotContains($record->id, TimetableValidator::cleanRecordIds(Qs::getCurrentSession()));
    }

    public function test_crafted_foreign_class_subject_still_plants_nothing()
    {
        [$section, $pcb, $commerce] = $this->fixture();
        $foreign = $this->subject('R15 Foreign ' . uniqid(), 2, $this->teacher('FOR'), null);

        $response = $this->actingAs($this->admin())->put(
            route('sections.teachers.update', Qs::hash($section->id)),
            ['teachers' => [$foreign->id => Qs::hash($this->teacher('X')->id)]]
        );

        $response->assertRedirect(route('sections.index'));
        $this->assertDatabaseMissing('section_subject_teachers', [
            'section_id' => $section->id,
            'subject_id' => $foreign->id,
        ]);
    }
}
