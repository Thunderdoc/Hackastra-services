<?php

namespace Tests\Feature;

use App\Models\HealthRecord;
use App\Models\StudentRecord;
use App\Support\TeacherScope;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Health Records: per-student clinic visit log. Every field is
 * typed by the writer in the test (probe strings only) — no
 * medical data is invented from seed data.
 * Users are found by email / by scope query, never by hardcoded id.
 */
class OwnerHealthRecordTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    /** A student in a class the demo teacher does NOT teach. */
    protected function outOfScopeStudentRecord()
    {
        $teacherClasses = TeacherScope::classIds($this->teacher());
        return StudentRecord::whereNotIn('my_class_id', $teacherClasses)
            ->orderBy('id')
            ->firstOrFail();
    }

    /** A student who is NOT the demo parent's child (seeded data
     *  gives the demo parent the only parent-linked children, so
     *  "not their child" includes the parent-less records). */
    protected function otherParentsChildRecord()
    {
        $parentId = $this->parent()->id;
        return StudentRecord::where(function ($q) use ($parentId) {
            $q->whereNull('my_parent_id')->orWhere('my_parent_id', '!=', $parentId);
        })
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_admin_creates_record_and_is_recorded_by()
    {
        $student = $this->student();
        $response = $this->actingAs($this->admin())->post(route('health_records.store'), [
            'student_id' => $student->id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH admin complaint',
            'treatment' => 'PROBE-HEALTH admin treatment',
            'is_referred' => 0,
            'referred_to' => null,
        ]);
        $response->assertRedirect();
        $record = HealthRecord::where('complaint', 'PROBE-HEALTH admin complaint')->firstOrFail();
        $this->assertEquals($student->id, $record->student_id);
        $this->assertEquals($this->admin()->id, $record->recorded_by);
        $this->assertFalse($record->is_referred);
    }

    public function test_teacher_creates_record_for_student_in_own_class()
    {
        $student = $this->student();
        $this->assertContains((int) StudentRecord::where('user_id', $student->id)->value('my_class_id'), TeacherScope::classIds($this->teacher()));
        $response = $this->actingAs($this->teacher())->post(route('health_records.store'), [
            'student_id' => $student->id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH teacher complaint',
            'treatment' => null,
            'is_referred' => 1,
            'referred_to' => 'PROBE-HEALTH referral destination',
        ]);
        $response->assertRedirect();
        $record = HealthRecord::where('complaint', 'PROBE-HEALTH teacher complaint')->firstOrFail();
        $this->assertTrue($record->is_referred);
        $this->assertEquals('PROBE-HEALTH referral destination', $record->referred_to);
        $this->assertEquals($this->teacher()->id, $record->recorded_by);
    }

    public function test_teacher_cannot_write_for_student_outside_own_classes()
    {
        $out = $this->outOfScopeStudentRecord();
        $response = $this->actingAs($this->teacher())->post(route('health_records.store'), [
            'student_id' => $out->user_id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH out-of-scope complaint',
        ]);
        $response->assertForbidden();
        $this->assertNull(HealthRecord::where('complaint', 'PROBE-HEALTH out-of-scope complaint')->first());
    }

    public function test_teacher_index_hides_out_of_scope_record_and_show_forbids_it()
    {
        $out = $this->outOfScopeStudentRecord();
        $record = HealthRecord::create([
            'student_id' => $out->user_id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH hidden complaint',
            'recorded_by' => $this->admin()->id,
        ]);
        $this->actingAs($this->teacher())->get(route('health_records.index'))
            ->assertOk()
            ->assertDontSee('/health-records/' . $record->id);
        $this->actingAs($this->teacher())->get(route('health_records.show', $record->id))
            ->assertForbidden();
    }

    public function test_parent_sees_own_child_and_not_another_child()
    {
        $child = $this->student();
        $record = HealthRecord::create([
            'student_id' => $child->id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH child complaint',
            'recorded_by' => $this->admin()->id,
        ]);
        $this->actingAs($this->parent())->get(route('health_records.show', $record->id))
            ->assertOk()
            ->assertSee('PROBE-HEALTH child complaint');

        $other = $this->otherParentsChildRecord();
        $otherRecord = HealthRecord::create([
            'student_id' => $other->user_id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH other child complaint',
            'recorded_by' => $this->admin()->id,
        ]);
        $this->actingAs($this->parent())->get(route('health_records.show', $otherRecord->id))
            ->assertForbidden();
    }

    public function test_student_sees_self_and_not_another_student()
    {
        $self = $this->student();
        $record = HealthRecord::create([
            'student_id' => $self->id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH self complaint',
            'recorded_by' => $this->admin()->id,
        ]);
        $this->actingAs($self)->get(route('health_records.show', $record->id))->assertOk();

        $other = StudentRecord::where('user_id', '!=', $self->id)->orderBy('id')->firstOrFail();
        $otherRecord = HealthRecord::create([
            'student_id' => $other->user_id,
            'visit_date' => '2026-10-28',
            'complaint' => 'PROBE-HEALTH other student complaint',
            'recorded_by' => $this->admin()->id,
        ]);
        $this->actingAs($self)->get(route('health_records.show', $otherRecord->id))->assertForbidden();
    }

    public function test_create_page_is_write_roles_only()
    {
        $this->actingAs($this->teacher())->get(route('health_records.create'))->assertOk();
        $this->actingAs($this->parent())->get(route('health_records.create'))->assertForbidden();
        $this->actingAs($this->student())->get(route('health_records.create'))->assertForbidden();
    }

    public function test_other_roles_get_403_on_index()
    {
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        $this->actingAs($accountant)->get(route('health_records.index'))->assertForbidden();
        $librarian = User::where('email', 'librarian@hackstra.app')->firstOrFail();
        $this->actingAs($librarian)->get(route('health_records.index'))->assertForbidden();
    }
}
