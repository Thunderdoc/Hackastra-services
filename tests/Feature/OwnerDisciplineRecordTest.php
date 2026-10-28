<?php

namespace Tests\Feature;

use App\Models\DisciplineRecord;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Discipline Records (2026-10-28): behaviour / incident log per
 * student. Guards: write admin + teacher (teacher only for
 * students in classes they teach, via TeacherScope class-level
 * assignment plus section-override effective teacher); read admin
 * all, teacher own classes, parent only own children
 * (StudentRecord.my_parent_id), student only self; others 403.
 * All users are looked up by email, never by hardcoded id.
 */
class OwnerDisciplineRecordTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        // teacher@hackstra.app teaches class 1 (subjects + sections).
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function foreignTeacher()
    {
        // teacher2@teacher.com teaches class 3, not class 1.
        return User::where('email', 'teacher2@teacher.com')->firstOrFail();
    }

    protected function student()
    {
        // student@hackstra.app: class 1, parent parent@hackstra.app.
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function otherStudent()
    {
        return User::where('user_type', 'student')
            ->where('email', '!=', 'student@hackstra.app')
            ->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function foreignParent()
    {
        // parent1@parent.com is not the parent of student@hackstra.app.
        return User::where('email', 'parent1@parent.com')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function classThreeStudent()
    {
        // Any student enrolled in a class the main teacher does NOT
        // teach (the seed data puts such students in class 3).
        $classId = StudentRecord::where('user_id', $this->student()->id)->value('my_class_id');
        $other = StudentRecord::where('my_class_id', '!=', $classId)->firstOrFail();

        return User::findOrFail($other->user_id);
    }

    public function test_teacher_creates_record_for_own_class_student()
    {
        $response = $this->actingAs($this->teacher())->post(route('discipline_records.store'), [
            'student_id' => $this->student()->id,
            'incident_date' => '2026-10-28',
            'type' => 'late',
            'description' => 'Discipline probe own-class late arrival',
            'action_taken' => 'Verbal warning given',
        ]);

        $response->assertRedirect();
        $record = DisciplineRecord::where('description', 'Discipline probe own-class late arrival')->firstOrFail();
        $this->assertEquals($this->teacher()->id, $record->recorded_by);
        $this->assertEquals('late', $record->type);
        $this->assertEquals($this->student()->id, $record->student_id);
    }

    public function test_foreign_teacher_cannot_store_for_other_class_student()
    {
        $response = $this->actingAs($this->foreignTeacher())->post(route('discipline_records.store'), [
            'student_id' => $this->student()->id,
            'incident_date' => '2026-10-28',
            'type' => 'misconduct',
            'description' => 'Discipline probe foreign teacher attempt',
            'action_taken' => null,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('discipline_records', ['description' => 'Discipline probe foreign teacher attempt']);
    }

    public function test_admin_can_write_and_read_all()
    {
        $other = $this->classThreeStudent();
        $this->actingAs($this->admin())->post(route('discipline_records.store'), [
            'student_id' => $other->id,
            'incident_date' => '2026-10-28',
            'type' => 'homework',
            'description' => 'Discipline probe admin write',
            'action_taken' => 'Homework resubmission set',
        ])->assertRedirect();

        $record = DisciplineRecord::where('description', 'Discipline probe admin write')->firstOrFail();
        $this->actingAs($this->admin())->get(route('discipline_records.show', $record->id))
            ->assertOk()
            ->assertSee('Discipline probe admin write');
        $this->actingAs($this->admin())->get(route('discipline_records.index'))
            ->assertOk()
            ->assertSee('Discipline probe admin write');
    }

    public function test_teacher_index_excludes_other_class_records()
    {
        $other = $this->classThreeStudent();
        $record = DisciplineRecord::create([
            'student_id' => $other->id,
            'recorded_by' => $this->admin()->id,
            'incident_date' => '2026-10-28',
            'type' => 'other',
            'description' => 'Discipline probe other-class record',
            'action_taken' => null,
        ]);

        $this->actingAs($this->teacher())->get(route('discipline_records.index'))
            ->assertOk()
            ->assertDontSee('Discipline probe other-class record');
        $this->actingAs($this->teacher())->get(route('discipline_records.show', $record->id))
            ->assertForbidden();
    }

    public function test_parent_sees_own_child_only()
    {
        $record = DisciplineRecord::create([
            'student_id' => $this->student()->id,
            'recorded_by' => $this->teacher()->id,
            'incident_date' => '2026-10-28',
            'type' => 'misconduct',
            'description' => 'Discipline probe parent own child',
            'action_taken' => 'Parent informed',
        ]);

        $this->actingAs($this->parent())->get(route('discipline_records.show', $record->id))
            ->assertOk()
            ->assertSee('Discipline probe parent own child');
        $this->actingAs($this->foreignParent())->get(route('discipline_records.show', $record->id))
            ->assertForbidden();
        $this->actingAs($this->parent())->get(route('discipline_records.index'))
            ->assertOk()
            ->assertSee('Discipline probe parent own child');
    }

    public function test_student_sees_self_only()
    {
        $record = DisciplineRecord::create([
            'student_id' => $this->student()->id,
            'recorded_by' => $this->teacher()->id,
            'incident_date' => '2026-10-28',
            'type' => 'uniform',
            'description' => 'Discipline probe student self',
            'action_taken' => null,
        ]);

        $this->actingAs($this->student())->get(route('discipline_records.show', $record->id))
            ->assertOk()
            ->assertSee('Discipline probe student self');
        $this->actingAs($this->otherStudent())->get(route('discipline_records.show', $record->id))
            ->assertForbidden();
    }

    public function test_write_denied_for_parent_student_and_librarian()
    {
        $payload = [
            'student_id' => $this->student()->id,
            'incident_date' => '2026-10-28',
            'type' => 'late',
            'description' => 'Discipline probe denied write',
            'action_taken' => null,
        ];

        $this->actingAs($this->parent())->post(route('discipline_records.store'), $payload)->assertForbidden();
        $this->actingAs($this->student())->post(route('discipline_records.store'), $payload)->assertForbidden();
        $this->actingAs($this->librarian())->post(route('discipline_records.store'), $payload)->assertForbidden();
        $this->actingAs($this->librarian())->get(route('discipline_records.index'))->assertForbidden();
        $this->assertDatabaseMissing('discipline_records', ['description' => 'Discipline probe denied write']);
    }

    public function test_invalid_type_is_rejected()
    {
        $this->actingAs($this->teacher())->post(route('discipline_records.store'), [
            'student_id' => $this->student()->id,
            'incident_date' => '2026-10-28',
            'type' => 'not_a_type',
            'description' => 'Discipline probe invalid type',
            'action_taken' => null,
        ])->assertSessionHasErrors('type');
    }

    public function test_create_page_lists_only_writable_students_for_teacher()
    {
        $this->actingAs($this->teacher())->get(route('discipline_records.create'))
            ->assertOk()
            ->assertSee($this->student()->name);
        $this->actingAs($this->parent())->get(route('discipline_records.create'))
            ->assertForbidden();
    }
}
