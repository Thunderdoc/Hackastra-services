<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTableRecord;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner live catch, 2026-11-08 (SHOW-ONLY-ASSIGNED): a STUDENT
 * opening Timetables got the "Manage Timetables" index — class +
 * section chooser and class cards — and could open/print ANOTHER
 * section's grid of his own class by direct URL (the record guard
 * checked the class only). A parent had the same reach.
 *
 * Locked here:
 *  - Student: GET tt.index redirects DIRECTLY to his own
 *    class+section record's show page; with nothing in force he
 *    lands on My Timetable's honest empty state, never the picker.
 *  - Student/parent: show + print of a section record that is not
 *    their own (section) is refused server-side (403); whole-class
 *    records of their class stay open.
 *  - Parent: tt.index lists ONLY his own children, one direct
 *    link per child — no chooser, no class cards, no other
 *    records; a child without a published timetable is named
 *    honestly instead of linked.
 *  - Teacher: the index stays scoped to the sections she touches
 *    (verified behaviour of the G1 era — pinned so it cannot
 *    silently widen again).
 *  - Admin: the Manage index is unchanged.
 *
 * Fixtures resolve from the seed by EMAIL, never hardcoded ids
 * (the seed's nurse user shifts generated ids).
 */
class OwnerTimetableStudentParentScopeTest extends TestCase
{
    use DatabaseTransactions;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function freshUser(string $type, string $tag): User
    {
        return User::create([
            'name' => 'TTScope ' . $tag . ' ' . uniqid(),
            'email' => 'ttscope-' . strtolower($type) . '-' . uniqid() . '@test.local',
            'password' => bcrypt($type),
            'user_type' => $type,
            'code' => 'TTS' . strtoupper(substr($type, 0, 1)) . uniqid(),
        ]);
    }

    private function student(): User
    {
        return $this->user('student@hackstra.app');
    }

    private function studentRecord(): StudentRecord
    {
        $sr = Qs::findStudentRecord($this->student()->id);
        $this->assertNotNull($sr, 'demo student must carry a student record');

        return $sr;
    }

    /** The student's own in-force record, resolved the spec way. */
    private function ownRecord(): TimeTableRecord
    {
        $sr = $this->studentRecord();
        $ttr = SectionTeacher::recordForClassSection(
            $sr->my_class_id, $sr->section_id, Qs::getCurrentSession());
        $this->assertNotNull($ttr, 'demo student class must have a timetable record');
        $this->assertTrue($ttr->isInForce(), 'demo student record must be in force');

        return $ttr;
    }

    /** An in-force record of the SAME class but a DIFFERENT section. */
    private function foreignSectionRecord(): TimeTableRecord
    {
        $sr = $this->studentRecord();
        $ttr = TimeTableRecord::where('my_class_id', $sr->my_class_id)
            ->whereNotNull('section_id')
            ->where('section_id', '!=', $sr->section_id)
            ->whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())
            ->where('approval_status', 'approved')
            ->first();
        $this->assertNotNull($ttr, 'premise: the demo class must have another section record');

        return $ttr;
    }

    public function test_student_index_redirects_directly_to_own_record()
    {
        $own = $this->ownRecord();

        $this->actingAs($this->student())
            ->get(route('tt.index'))
            ->assertRedirect(route('ttr.show', $own->id));
    }

    public function test_student_other_section_show_and_print_refused_own_stays_open()
    {
        $student = $this->student();
        $foreign = $this->foreignSectionRecord();
        $own = $this->ownRecord();

        $this->actingAs($student)->get(route('ttr.show', $foreign->id))->assertForbidden();
        $this->actingAs($student)->get(route('ttr.print', $foreign->id))->assertForbidden();

        $this->actingAs($student)->get(route('ttr.show', $own->id))->assertOk();
        $this->actingAs($student)->get(route('ttr.print', $own->id))->assertOk();
    }

    public function test_student_whole_class_record_of_own_class_stays_open()
    {
        $sr = $this->studentRecord();
        $admin = $this->user('admin@hackstra.app');

        $name = 'TTScope WholeClass ' . uniqid();
        $this->actingAs($admin)->post(route('ttr.store'), [
            'name' => $name, 'my_class_id' => $sr->my_class_id,
        ])->assertOk()->assertJson(['ok' => true]);
        $record = TimeTableRecord::where('name', $name)->firstOrFail();
        $this->assertNull($record->section_id, 'premise: record must be whole-class');
        TimeTableRecord::where('id', $record->id)->update(['approval_status' => 'approved']);

        TimeSlot::create([
            'ttr_id' => $record->id,
            'hour_from' => 9, 'min_from' => '00', 'meridian_from' => 'AM',
            'hour_to' => 9, 'min_to' => '40', 'meridian_to' => 'AM',
            'time_from' => '9:00 AM', 'time_to' => '9:40 AM',
            'timestamp_from' => strtotime('9:00 AM'), 'timestamp_to' => strtotime('9:40 AM'),
            'full' => '9:00 AM - 9:40 AM',
        ]);
        $subject = Subject::where('my_class_id', $sr->my_class_id)->firstOrFail();
        $this->actingAs($admin)->post(route('tt.store'), [
            'ttr_id' => $record->id,
            'ts_id' => TimeSlot::where('ttr_id', $record->id)->firstOrFail()->id,
            'day' => 'Monday', 'subject_id' => $subject->id,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->actingAs($this->student())->get(route('ttr.show', $record->id))->assertOk();
        $this->actingAs($this->student())->get(route('ttr.print', $record->id))->assertOk();
    }

    public function test_student_without_published_timetable_gets_empty_state_not_picker()
    {
        $class = MyClass::create(['name' => 'TTScope Class ' . uniqid()]);
        $section = Section::create([
            'name' => 'A', 'my_class_id' => $class->id, 'active' => 1,
        ]);
        $studentUser = $this->freshUser('student', 'NoGrid');
        StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $studentUser->id,
            'my_class_id' => $class->id, 'section_id' => $section->id,
            'adm_no' => 'TTS/' . uniqid(), 'year_admitted' => '2026',
        ]);

        $this->actingAs($studentUser)
            ->get(route('tt.index'))
            ->assertRedirect(route('tt.mine'));

        $this->actingAs($studentUser)
            ->get(route('tt.mine'))
            ->assertOk()
            ->assertSee('No timetable has been published for this class yet.');
    }

    public function test_parent_index_lists_only_own_children_with_direct_links()
    {
        $parent = $this->user('parent@hackstra.app');
        $children = Qs::findMyChildren($parent->id);
        $this->assertTrue($children->count() >= 1, 'premise: demo parent must have children');

        $response = $this->actingAs($parent)->get(route('tt.index'))->assertOk();
        $html = $response->getContent();

        foreach ($children as $child) {
            $this->assertStringContainsString($child->user->name, $html,
                'parent index must name each of his children');
            $ttr = SectionTeacher::recordForClassSection(
                $child->my_class_id, $child->section_id, Qs::getCurrentSession());
            $this->assertNotNull($ttr, 'premise: demo child class must have a record');
            $this->assertStringContainsString(route('ttr.show', $ttr->id), $html,
                'parent index must link directly to each child\'s own record');
        }

        // No chooser, no Manage index, no other section's record.
        $this->assertStringNotContainsString('Manage Timetables', $html);
        $this->assertStringNotContainsString('name="class_id"', $html);
        $this->assertStringNotContainsString('name="section_id"', $html);
        $foreign = $this->foreignSectionRecord();
        $this->assertStringNotContainsString(route('ttr.show', $foreign->id), $html,
            'parent index must not link another section\'s record');
    }

    public function test_parent_other_section_show_and_print_refused_own_child_stays_open()
    {
        $parent = $this->user('parent@hackstra.app');
        $foreign = $this->foreignSectionRecord();

        $child = Qs::findMyChildren($parent->id)->first();
        $this->assertNotNull($child, 'premise: demo parent must have a child');
        $own = SectionTeacher::recordForClassSection(
            $child->my_class_id, $child->section_id, Qs::getCurrentSession());
        $this->assertNotNull($own, 'premise: demo child class must have a record');

        $this->actingAs($parent)->get(route('ttr.show', $foreign->id))->assertForbidden();
        $this->actingAs($parent)->get(route('ttr.print', $foreign->id))->assertForbidden();

        $this->actingAs($parent)->get(route('ttr.show', $own->id))->assertOk();
        $this->actingAs($parent)->get(route('ttr.print', $own->id))->assertOk();
    }

    public function test_parent_child_without_published_timetable_is_named_not_linked()
    {
        $class = MyClass::create(['name' => 'TTScope PClass ' . uniqid()]);
        $section = Section::create([
            'name' => 'A', 'my_class_id' => $class->id, 'active' => 1,
        ]);
        $parentUser = $this->freshUser('parent', 'NoGrid');
        $childUser = $this->freshUser('student', 'NoGridChild');
        StudentRecord::create([
            'session' => Qs::getCurrentSession(), 'user_id' => $childUser->id,
            'my_parent_id' => $parentUser->id,
            'my_class_id' => $class->id, 'section_id' => $section->id,
            'adm_no' => 'TTS/' . uniqid(), 'year_admitted' => '2026',
        ]);

        $response = $this->actingAs($parentUser)->get(route('tt.index'))->assertOk();
        $response->assertSee($childUser->name);
        $response->assertSee('No timetable has been published for this class yet.');
        $response->assertDontSee('Open Timetable');
    }

    /** Sections the demo teacher touches, by the spec rule (independent of the controller). */
    private function teacherTouchedSectionIds(User $teacher): array
    {
        $ids = [];
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
                $ids[] = (int) $sec->id;
            }
        }

        return $ids;
    }

    public function test_teacher_index_stays_scoped_to_sections_she_touches()
    {
        $teacher = $this->user('teacher@hackstra.app');
        $touched = $this->teacherTouchedSectionIds($teacher);
        $this->assertNotEmpty($touched, 'premise: demo teacher must touch sections');

        $records = TimeTableRecord::whereNull('exam_id')
            ->where('year', Qs::getCurrentSession())
            ->where('approval_status', 'approved')
            ->get();
        // The discriminating class: one she teaches in (a record in
        // a section she touches) that ALSO holds a record in a
        // section she does not touch.
        $foreign = null;
        $hers = null;
        foreach ($records->groupBy('my_class_id') as $classRecords) {
            $herHere = $classRecords->first(function ($r) use ($touched) {
                return $r->section_id && in_array((int) $r->section_id, $touched, true);
            });
            $foreignHere = $classRecords->first(function ($r) use ($touched) {
                return $r->section_id && !in_array((int) $r->section_id, $touched, true);
            });
            if ($herHere && $foreignHere) {
                $hers = $herHere;
                $foreign = $foreignHere;
                break;
            }
        }
        $this->assertNotNull($foreign, 'premise: seed must hold a class with records in both a touched and an untouched section');

        $html = $this->actingAs($teacher)->get(route('tt.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('ttr.show', $foreign->id), $html,
            'teacher index must not link a record from a section she does not touch');

        $listHtml = $this->actingAs($teacher)
            ->get(route('tt.index', ['class_id' => $foreign->my_class_id]))
            ->assertOk()->getContent();
        $this->assertStringNotContainsString(route('ttr.show', $foreign->id), $listHtml,
            'teacher class list must not link a record from a section she does not touch');
        $this->assertStringContainsString(route('ttr.show', $hers->id), $listHtml,
            'teacher class list must link the record of her own section');
    }

    public function test_admin_index_unchanged()
    {
        $admin = $this->user('admin@hackstra.app');
        $foreign = $this->foreignSectionRecord();

        $html = $this->actingAs($admin)->get(route('tt.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Manage Timetables', $html);
        $this->assertStringContainsString('name="class_id"', $html);

        $listHtml = $this->actingAs($admin)
            ->get(route('tt.index', ['class_id' => $foreign->my_class_id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString(route('ttr.show', $foreign->id), $listHtml,
            'admin class list must keep every record of the class');
    }
}
