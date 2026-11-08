<?php

namespace Tests\Feature;

use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nurse type-to-search (owner, 2026-11-08): on the Health Record
 * form the Student field must be a TYPE-TO-SEARCH picker — the
 * nurse types a few letters and the server answers with matching
 * students of the chosen section only. The full roster must never
 * be dumped into the page or shipped for a 1-letter query, and a
 * teacher caller stays inside her writable-students scope.
 * Fixtures are resolved by email / scope queries, never by id.
 */
class OwnerHealthStudentSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected function nurse()
    {
        return User::where('email', 'nurse@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    /** First active student record (the section fixture source). */
    protected function aStudentRecord()
    {
        return StudentRecord::with('user')
            ->where('grad', 0)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_create_form_carries_the_search_picker_without_a_student_dump()
    {
        $response = $this->actingAs($this->nurse())->get(route('health_records.create'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-student-search="remote"', $html);

        // The student select itself ships placeholder-only options.
        $this->assertMatchesRegularExpression('/<select[^>]*name="student_id".*?<\/select>/s', $html);
        preg_match('/<select[^>]*name="student_id".*?<\/select>/s', $html, $m);
        $this->assertLessThanOrEqual(2, substr_count($m[0], '<option'),
            'the student select must not ship a roster of options');

        // And no seeded student leaks anywhere into the page HTML.
        $probe = $this->aStudentRecord();
        $response->assertDontSee($probe->user->name);
        $response->assertDontSee($probe->adm_no);
    }

    public function test_search_matches_by_name_and_adm_no_inside_the_section()
    {
        $record = $this->aStudentRecord();
        $sectionId = $record->section_id;

        $byName = $this->actingAs($this->nurse())->get(route('get_section_students', [
            'section_id' => $sectionId,
            'q' => mb_substr($record->user->name, 0, 3),
        ]));
        $byName->assertOk();
        $ids = collect($byName->json())->pluck('user_id')->all();
        $this->assertContains((int) $record->user_id, $ids);
        $hit = collect($byName->json())->firstWhere('user_id', (int) $record->user_id);
        $this->assertSame($record->adm_no, $hit['adm_no'], 'each result must carry the admission no');

        $byAdm = $this->actingAs($this->nurse())->get(route('get_section_students', [
            'section_id' => $sectionId,
            'q' => mb_substr((string) $record->adm_no, -4),
        ]));
        $this->assertContains((int) $record->user_id, collect($byAdm->json())->pluck('user_id')->all());
    }

    public function test_search_never_crosses_into_another_section()
    {
        $mine = $this->aStudentRecord();
        $foreign = StudentRecord::with('user')
            ->where('grad', 0)
            ->where('section_id', '!=', $mine->section_id)
            ->orderBy('id')
            ->firstOrFail();

        // Search MY section with the FOREIGN student's exact name:
        // every returned row must belong to my section.
        $response = $this->actingAs($this->nurse())->get(route('get_section_students', [
            'section_id' => $mine->section_id,
            'q' => $foreign->user->name,
        ]));
        $response->assertOk();
        $returnedIds = collect($response->json())->pluck('user_id')->map(function ($id) {
            return (int) $id;
        })->all();
        $this->assertNotContains((int) $foreign->user_id, $returnedIds);
        $sectionIds = StudentRecord::where('section_id', $mine->section_id)
            ->where('grad', 0)->pluck('user_id')->map(function ($id) {
                return (int) $id;
            })->all();
        $this->assertSame([], array_values(array_diff($returnedIds, $sectionIds)),
            'search results must be a subset of the chosen section');
    }

    public function test_search_needs_two_letters_and_the_plain_feed_is_unchanged()
    {
        $record = $this->aStudentRecord();

        $short = $this->actingAs($this->nurse())->get(route('get_section_students', [
            'section_id' => $record->section_id,
            'q' => 'A',
        ]));
        $this->assertSame([], $short->json(), 'a 1-letter query must return nothing');

        // No ?q= at all: the legacy cascade contract (the whole
        // section roster) is untouched for every other form.
        $plain = $this->actingAs($this->nurse())->get(route('get_section_students', [
            'section_id' => $record->section_id,
        ]));
        $expected = StudentRecord::where('section_id', $record->section_id)
            ->where('grad', 0)->count();
        $this->assertCount($expected, $plain->json());
    }

    public function test_search_keeps_the_teacher_inside_her_writable_students()
    {
        $teacher = $this->teacher();
        $classIds = TeacherScope::classIds($teacher);
        $overrideSections = DB::table('section_subject_teachers')
            ->where('teacher_id', $teacher->id)->pluck('section_id')
            ->map(function ($id) { return (int) $id; })->all();

        $foreign = StudentRecord::with('user')
            ->where('grad', 0)
            ->whereNotIn('my_class_id', $classIds)
            ->whereNotIn('section_id', $overrideSections ?: [0])
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->actingAs($teacher)->get(route('get_section_students', [
            'section_id' => $foreign->section_id,
            'q' => $foreign->user->name,
        ]));
        $this->assertNotContains((int) $foreign->user_id,
            collect($response->json())->pluck('user_id')->map(function ($id) {
                return (int) $id;
            })->all(), 'a teacher must never search up a student she cannot record for');
    }

    public function test_search_is_empty_for_parent_callers()
    {
        $record = $this->aStudentRecord();
        $response = $this->actingAs($this->parent())->get(route('get_section_students', [
            'section_id' => $record->section_id,
            'q' => $record->user->name,
        ]));
        $this->assertSame([], $response->json());
    }
}
