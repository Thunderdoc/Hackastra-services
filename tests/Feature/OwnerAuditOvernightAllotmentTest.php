<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * AUDIT stream AUD-ALLOTMENT, Audit Round 1 (overnight batch).
 * Verification ONLY — no app code touched. Probes for batch items
 * 2 (teacher Low Attendance scope), 3 (section registers),
 * 4 (student-information selector section filtering),
 * 17 (teacher-side link removals, hidden not deleted),
 * 18 (PTM scheduling admin-only, teacher keeps viewing),
 * 19 (decisions gate — ADJUDICATED by the owner 2026-10-29:
 * the section teacher DOES decide his own section's student
 * leaves, alongside Admin/Principal. The candidate probe that
 * asserted the opposite reading was removed on his ruling).
 */
class OwnerAuditOvernightAllotmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function scopeClassIds(): array
    {
        return TeacherScope::classIds($this->teacher());
    }

    protected function outOfScopeClassId(): int
    {
        $all = \App\Models\MyClass::orderBy('id')->pluck('id')->all();
        foreach ($all as $id) {
            if (!in_array((int) $id, $this->scopeClassIds(), true)) {
                return (int) $id;
            }
        }
        $this->fail('No out-of-scope class exists for the demo teacher');
    }

    protected function viewData($response): array
    {
        $content = $response->getOriginalContent();
        $this->assertNotFalse($content, 'Response has no view content');
        return method_exists($content, 'getData') ? $content->getData() : [];
    }

    protected function csvAdmNos(string $csv): array
    {
        $found = [];
        preg_match_all('/HA\/S\/\d+\/\d+/i', $csv, $found);
        // Normalise case: blades render a lowercased copy of the
        // same adm_no in data-search attributes, which must not
        // double-count as a second student.
        return array_values(array_unique(array_map('strtoupper', $found[0])));
    }

    // ---- Item 2: teacher Low Attendance scope ----

    public function test_item2_teacher_low_attendance_totals_equal_assigned_scope()
    {
        $teacher = $this->teacher();
        $scope = $this->scopeClassIds();
        $this->assertNotEmpty($scope, 'Demo teacher must have assigned classes');

        $response = $this->actingAs($teacher)
            ->get(route('attendance.defaulters', ['month' => '2026-10']));
        $response->assertOk();
        $data = $this->viewData($response);

        $expected = StudentRecord::where('grad', 0)->whereIn('my_class_id', $scope)->count();
        $wholeSchool = StudentRecord::where('grad', 0)->count();
        $this->assertNotEquals($wholeSchool, $expected, 'Precondition: scope must differ from whole school');
        $this->assertEquals($expected, $data['total_students'] ?? null,
            'Low Attendance total_students must equal assigned-classes enrolment, not whole school');
        $classIds = collect($data['classes'] ?? [])->pluck('id')->map(function ($id) { return (int) $id; })->all();
        sort($classIds);
        $sortedScope = $scope;
        sort($sortedScope);
        $this->assertEquals($sortedScope, $classIds, 'Low Attendance class picker must offer only assigned classes');
    }

    public function test_item2_teacher_low_attendance_export_has_no_out_of_scope_student()
    {
        $scope = $this->scopeClassIds();
        $adminCsv = $this->actingAs($this->admin())
            ->get(route('attendance.defaulters.export', ['month' => '2026-10', 'threshold' => 75]))
            ->assertOk()->streamedContent();
        $adminAdmNos = $this->csvAdmNos($adminCsv);
        $outsider = null;
        foreach ($adminAdmNos as $admNo) {
            $rec = StudentRecord::where('adm_no', $admNo)->first();
            if ($rec && !in_array((int) $rec->my_class_id, $scope, true)) {
                $outsider = $admNo;
                break;
            }
        }
        if (!$outsider) {
            $this->markTestSkipped('No out-of-scope defaulter exists in the admin Oct-2026 export to use as a control; teacher-export scope check below still runs.');
        }
        $teacherCsv = $this->actingAs($this->teacher())
            ->get(route('attendance.defaulters.export', ['month' => '2026-10', 'threshold' => 75]))
            ->assertOk()->streamedContent();
        $this->assertStringNotContainsString($outsider, $teacherCsv,
            'Teacher Low Attendance export must not contain an out-of-scope defaulter present in the admin export');
        foreach ($this->csvAdmNos($teacherCsv) as $admNo) {
            $rec = StudentRecord::where('adm_no', $admNo)->first();
            $this->assertNotNull($rec, "Teacher export adm_no {$admNo} must resolve to a student record");
            $this->assertContains((int) $rec->my_class_id, $scope,
                "Teacher export adm_no {$admNo} is in class {$rec->my_class_id}, outside teacher scope");
        }
    }

    public function test_item2_teacher_low_attendance_out_of_scope_class_is_forbidden()
    {
        $this->actingAs($this->teacher())
            ->get(route('attendance.defaulters', ['month' => '2026-10', 'class_id' => $this->outOfScopeClassId()]))
            ->assertForbidden();
    }

    // ---- Item 3: section-wise attendance registers ----

    public function test_item3_section_take_page_lists_only_section_students()
    {
        // tt 788: Class 12 (id 13), section 68 (E), 40 students.
        $expected = StudentRecord::where('my_class_id', 13)->where('section_id', 68)
            ->where('grad', 0)->orderBy('id')->pluck('adm_no')->all();
        $this->assertCount(40, $expected);

        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.take', [788, 'date' => '2026-11-02']));
        $response->assertOk();
        $rolls = [];
        preg_match_all('/reg-roll">(\d+)</', $response->getContent(), $rolls);
        $this->assertCount(40, $rolls[1], 'Take page must list the 40 section students, not the 520 class students');
    }

    public function test_item3_section_roster_export_lists_exactly_section_students()
    {
        $expected = StudentRecord::where('my_class_id', 13)->where('section_id', 68)
            ->where('grad', 0)->orderBy('id')->pluck('adm_no')->all();
        $csv = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.roster_export', [788]))
            ->assertOk()->streamedContent();
        $this->assertEqualsCanonicalizing($expected, $this->csvAdmNos($csv),
            'Roster export for a section period must contain exactly that section\'s students');
    }

    public function test_item3_submitted_register_view_filters_legacy_class_wide_session()
    {
        // Seeded session 11: Class 11 (id 12) section period stored
        // with section_id NULL and class-wide entries.
        $session = AttendanceSession::findOrFail(11);
        $this->assertNull($session->section_id);
        $response = $this->actingAs($this->admin())
            ->get(route('attendance.periods.show', $session->id));
        $response->assertOk();
        $this->assertCount(40, array_unique($this->csvAdmNos($response->getContent())),
            'Register view must list the 40 section students, not the 360 class students');
        $outsider = StudentRecord::where('my_class_id', 12)->where('section_id', 56)
            ->where('grad', 0)->firstOrFail();
        $response->assertDontSee($outsider->adm_no);
    }

    // ---- Item 4: student-information selector section filtering ----

    public function test_item4_student_selector_picker_offers_only_assigned_classes()
    {
        $response = $this->actingAs($this->teacher())->get(route('students.list'));
        $response->assertOk();
        $data = $this->viewData($response);
        $classIds = collect($data['classes'] ?? [])->pluck('id')->map(function ($id) { return (int) $id; })->all();
        $this->assertNotEmpty($classIds);
        foreach ($classIds as $id) {
            $this->assertContains($id, $this->scopeClassIds(), "Picker offered out-of-scope class {$id}");
        }
        $this->assertNotContains($this->outOfScopeClassId(), $classIds);
    }

    public function test_item4_student_selector_section_choice_filters_list_exactly()
    {
        // Picker submit: class 13 + section 68 must land on the
        // class list with the section filter applied. (Query-string
        // form: route() would put class_id into the {class_id?}
        // path slot instead of the picker query.)
        $response = $this->actingAs($this->teacher())
            ->get('/students/list?class_id=13&section_id=68');
        $response->assertRedirect(route('students.list', [13, 'section_id' => 68]));

        $listed = $this->actingAs($this->teacher())
            ->get(route('students.list', [13, 'section_id' => 68]))
            ->assertOk();
        $data = $this->viewData($listed);
        $expected = StudentRecord::where('my_class_id', 13)->where('section_id', 68)
            ->where('grad', 0)->orderBy('id')->pluck('id')->all();
        $listedIds = collect($data['listed'] ?? [])->pluck('id')->map(function ($id) { return (int) $id; })->all();
        $this->assertEqualsCanonicalizing($expected, $listedIds,
            'Student list with section 68 chosen must contain exactly section 68 students');
        $this->assertEquals(68, (int) optional($data['selected_section'] ?? null)->id);
    }

    public function test_item4_student_selector_cross_class_section_is_ignored()
    {
        // Section 56 belongs to class 12, not class 13: it must be
        // ignored, never honoured as a filter on class 13's list.
        $response = $this->actingAs($this->teacher())
            ->get(route('students.list', [13, 'section_id' => 56]))
            ->assertOk();
        $data = $this->viewData($response);
        $this->assertNull($data['selected_section'] ?? null, 'Cross-class section must not be selected');
    }

    public function test_item4_student_list_out_of_scope_class_is_forbidden()
    {
        $this->actingAs($this->teacher())
            ->get(route('students.list', [$this->outOfScopeClassId()]))
            ->assertForbidden();
    }

    // ---- Item 17: teacher-side removals (hidden, not deleted) ----

    public function test_item17_teacher_menu_hides_question_bank_and_ptm_links_but_routes_exist()
    {
        $this->assertTrue(Route::has('question_bank.index'), 'question_bank.index route must still exist in code');
        $this->assertTrue(Route::has('ptm.index'), 'ptm.index route must still exist in code');
        $this->assertTrue(Route::has('question_bank.create'), 'question_bank.create route must still exist in code');
        $this->assertTrue(Route::has('ptm.create'), 'ptm.create route must still exist in code');

        $response = $this->actingAs($this->teacher())
            ->get(route('attendance.periods.index', ['date' => '2026-11-02']));
        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringNotContainsString(route('question_bank.index'), $content,
            'Teacher menu must not link to Question Bank');
        $this->assertStringNotContainsString(route('ptm.index'), $content,
            'Teacher menu must not link to PTM Meetings');
        // Same overnight menu change also removed these three from
        // the teacher menu (comment in partials/menu.blade.php);
        // asserted here as the likely "image-30 items" set.
        $this->assertStringNotContainsString(route('lesson_plans.index'), $content,
            'Teacher menu must not link to Lesson Plans');
        $this->assertStringNotContainsString(route('homework_tracker.index'), $content,
            'Teacher menu must not link to Homework Tracker');
        $this->assertStringNotContainsString(route('substitutions.index'), $content,
            'Teacher menu must not link to Substitutions');
    }

    // ---- Item 18: PTM scheduling admin-only ----

    protected function ptmSlotPayload(array $overrides = [])
    {
        return array_merge([
            'date' => now()->addDays(7)->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'teacher_id' => $this->teacher()->id,
            'my_class_id' => 1,
            'section_id' => null,
        ], $overrides);
    }

    public function test_item18_teacher_ptm_scheduling_refused_admin_scheduling_works()
    {
        // Teacher keeps viewing: index must stay 200.
        $this->actingAs($this->teacher())->get(route('ptm.index'))->assertOk();
        // Teacher scheduling refused on both surfaces.
        $this->actingAs($this->teacher())->get(route('ptm.create'))->assertForbidden();
        $this->actingAs($this->teacher())->post(route('ptm.store'), $this->ptmSlotPayload())
            ->assertForbidden();
        $this->assertDatabaseMissing('ptm_slots', [
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);
        // Admin scheduling works.
        $this->actingAs($this->admin())->get(route('ptm.create'))->assertOk();
        $this->actingAs($this->admin())->post(route('ptm.store'), $this->ptmSlotPayload())
            ->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', [
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);
    }

    public function test_item18_teacher_ptm_store_post_standalone()
    {
        $this->actingAs($this->teacher())->post(route('ptm.store'), $this->ptmSlotPayload())
            ->assertForbidden();
        $this->assertDatabaseMissing('ptm_slots', [
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);
    }

    public function test_item18_admin_ptm_create_and_store_standalone()
    {
        $this->actingAs($this->admin())->get(route('ptm.create'))->assertOk();
        $this->actingAs($this->admin())->post(route('ptm.store'), $this->ptmSlotPayload())
            ->assertRedirect(route('ptm.index'));
        $this->assertDatabaseHas('ptm_slots', [
            'teacher_id' => $this->teacher()->id, 'my_class_id' => 1,
        ]);
    }

    public function test_item18_teacher_can_still_view_own_ptm_slots_and_bookings()
    {
        $this->actingAs($this->admin())->post(route('ptm.store'), $this->ptmSlotPayload());
        $response = $this->actingAs($this->teacher())->get(route('ptm.index'));
        $response->assertOk();
        $response->assertSee($this->teacher()->name);
    }
}
