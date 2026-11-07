<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * G1 (owner rule "a teacher sees ONLY her own sections", audit
 * 2026-11-06): the timetables index narrows a teacher to the
 * sections she touches, but ttr.show / ttr.print used to guard by
 * CLASS only — so a teacher could open or print another section's
 * full grid of her own class by direct URL. guardRecordScope now
 * mirrors the index: section records are openable only when the
 * section is one she touches (leads it, holds an override in it,
 * or is the effective teacher of one of its subjects); whole-class
 * records stay visible, exactly as on her index. Non-teacher
 * behaviour is unchanged.
 *
 * Fixtures are resolved from the seed dynamically (users by EMAIL,
 * never hardcoded ids — the seed's nurse user shifts generated
 * ids): one class inside the demo teacher's scope that carries
 * in-force records in both a section she touches and a section
 * she does not.
 */
class OwnerTimetableRecordSectionGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /** The spec rule, expressed independently of the controller. */
    private function teacherSectionsByClass(User $teacher): array
    {
        $out = [];
        foreach (Section::all() as $sec) {
            $touches = (int) $sec->teacher_id === (int) $teacher->id;
            if (!$touches) {
                foreach (Subject::where('my_class_id', $sec->my_class_id)->get() as $sub) {
                    if (SectionTeacher::teacherIdFor($sec->id, $sub) === (int) $teacher->id) {
                        $touches = true;
                        break;
                    }
                }
            }
            if ($touches) {
                $out[(int) $sec->my_class_id][] = (int) $sec->id;
            }
        }
        return $out;
    }

    /**
     * [teacher, classId, ownRecord, otherRecord] — a class in the
     * teacher's scope with an in-force record in a section she
     * touches and one in a section she does not.
     */
    private function fixture(): array
    {
        $teacher = $this->user('teacher@hackstra.app');
        $scope = TeacherScope::classIds($teacher);
        $hers = $this->teacherSectionsByClass($teacher);

        $records = TimeTableRecord::whereNotNull('section_id')
            ->get()
            ->filter(function ($ttr) { return $ttr->isInForce(); })
            ->groupBy('my_class_id');

        foreach ($records as $classId => $classRecords) {
            if (!in_array((int) $classId, $scope, true)) {
                continue;
            }
            $own = $classRecords->first(function ($ttr) use ($hers, $classId) {
                return in_array((int) $ttr->section_id, $hers[(int) $classId] ?? [], true);
            });
            $other = $classRecords->first(function ($ttr) use ($hers, $classId) {
                return !in_array((int) $ttr->section_id, $hers[(int) $classId] ?? [], true);
            });
            if ($own && $other) {
                return [$teacher, (int) $classId, $own, $other];
            }
        }

        $this->fail('Seed must give the demo teacher a scoped class with records in both a touched and an untouched section.');
    }

    public function test_teacher_cannot_open_another_sections_record()
    {
        [$teacher, , , $other] = $this->fixture();

        $this->actingAs($teacher)
            ->get(route('ttr.show', $other->id))
            ->assertForbidden();
    }

    public function test_teacher_cannot_print_another_sections_record()
    {
        [$teacher, , , $other] = $this->fixture();

        $this->actingAs($teacher)
            ->get(route('ttr.print', $other->id))
            ->assertForbidden();
    }

    public function test_teacher_can_open_and_print_her_own_sections_record()
    {
        [$teacher, , $own] = $this->fixture();

        $this->actingAs($teacher)
            ->get(route('ttr.show', $own->id))
            ->assertOk();
        $this->actingAs($teacher)
            ->get(route('ttr.print', $own->id))
            ->assertOk();
    }

    public function test_whole_class_record_of_her_class_stays_visible()
    {
        [$teacher, $classId] = $this->fixture();

        // The seed ships per-section records only, so the whole-class
        // half of the index rule is pinned on a record created here
        // (rolled back with the test transaction).
        $wholeClass = TimeTableRecord::create([
            'name' => 'G1 Whole Class ' . uniqid(),
            'my_class_id' => $classId,
            'section_id' => null,
            'exam_id' => null,
            'year' => Qs::getCurrentSession(),
            'approval_status' => 'approved',
        ]);

        $this->actingAs($teacher)
            ->get(route('ttr.show', $wholeClass->id))
            ->assertOk();
    }

    public function test_admin_access_is_unchanged()
    {
        [, , $own, $other] = $this->fixture();
        $admin = $this->user('admin@hackstra.app');

        $this->actingAs($admin)
            ->get(route('ttr.show', $other->id))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('ttr.print', $other->id))
            ->assertOk();
        $this->actingAs($admin)
            ->get(route('ttr.show', $own->id))
            ->assertOk();
    }
}
