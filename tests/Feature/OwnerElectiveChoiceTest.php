<?php

namespace Tests\Feature;

use App\Models\ElectiveChoice;
use App\Models\MyClass;
use App\Models\StudentRecord;
use App\Models\Subject;
use App\Support\Streams;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Student Elective Choice (Class 11/12) — 2026-10-28.
 *
 * Users and subjects are resolved from the DB by email / class /
 * stream, never hardcoded ids. Nothing here touches marks,
 * tabulation, or exam scope: all assertions are on elective_choices
 * rows and HTTP responses only.
 *
 * Re-pick rule under test: a rejected row does NOT block a fresh
 * pick — re-picking creates a NEW pending row (history preserved).
 */
class OwnerElectiveChoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    /** The demo student is Prep; the Class 12 student comes from a real record. */
    protected function class12Record()
    {
        $class12 = MyClass::where('name', 'Class 12')->first();

        return StudentRecord::where('my_class_id', $class12->id)
            ->with(['user', 'my_class', 'section'])
            ->first();
    }

    protected function prepStudent()
    {
        return User::where('email', 'student@hackstra.app')->first();
    }

    /** Subjects offered to this record: own class, filtered by section stream. */
    protected function offeredFor($record)
    {
        $subjects = Subject::where('my_class_id', $record->my_class_id)->orderBy('name')->get();

        return Streams::subjectsForSection($subjects, $record->section);
    }

    /** A Class 12 subject that does NOT apply to this record's stream. */
    protected function foreignStreamSubject($record)
    {
        $subjects = Subject::where('my_class_id', $record->my_class_id)->orderBy('name')->get();
        $stream = $record->section ? $record->section->stream : null;

        return $subjects->first(function ($s) use ($stream) {
            return !Streams::appliesTo($s, $stream);
        });
    }

    public function test_class12_student_index_shows_offered_subjects_only()
    {
        $record = $this->class12Record();
        $offered = $this->offeredFor($record);
        $this->assertNotEmpty($offered);

        $response = $this->actingAs($record->user)->get(route('electives.index'));
        $response->assertStatus(200);
        foreach ($offered as $subject) {
            $response->assertSee($subject->name);
        }
        $foreign = $this->foreignStreamSubject($record);
        if ($foreign) {
            $response->assertDontSee($foreign->name);
        }
    }

    public function test_student_picks_offered_subject_and_duplicate_is_refused()
    {
        $record = $this->class12Record();
        $subject = $this->offeredFor($record)->first();

        $this->actingAs($record->user)
            ->post(route('electives.store'), ['subject_id' => $subject->id])
            ->assertRedirect();
        $this->assertDatabaseHas('elective_choices', [
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
        ]);

        $this->actingAs($record->user)
            ->post(route('electives.store'), ['subject_id' => $subject->id])
            ->assertRedirect()
            ->assertSessionHas('flash_danger');
        $this->assertEquals(1, ElectiveChoice::where('student_record_id', $record->id)
            ->where('subject_id', $subject->id)->count());
    }

    public function test_student_cannot_pick_foreign_stream_subject()
    {
        $record = $this->class12Record();
        $foreign = $this->foreignStreamSubject($record);
        $this->assertNotNull($foreign, 'Seed must contain a non-applicable Class 12 subject for this stream');

        $this->actingAs($record->user)
            ->post(route('electives.store'), ['subject_id' => $foreign->id])
            ->assertStatus(403);
        $this->assertEquals(0, ElectiveChoice::where('student_record_id', $record->id)
            ->where('subject_id', $foreign->id)->count());
    }

    public function test_admin_approves_and_student_sees_approved_choice()
    {
        $record = $this->class12Record();
        $subject = $this->offeredFor($record)->first();
        $choice = ElectiveChoice::create([
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin())
            ->post(route('electives.approve', $choice->id))
            ->assertRedirect();
        $this->assertEquals('approved', $choice->fresh()->status);
        $this->assertEquals($this->admin()->id, $choice->fresh()->decided_by);

        $this->actingAs($record->user)->get(route('electives.index'))
            ->assertStatus(200)->assertSee($subject->name)->assertSee('Approved');
        $this->actingAs($this->admin())->get(route('electives.index'))
            ->assertStatus(200)->assertSee($subject->name)->assertSee('Approved');
    }

    public function test_teacher_approve_is_forbidden()
    {
        $record = $this->class12Record();
        $subject = $this->offeredFor($record)->first();
        $choice = ElectiveChoice::create([
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->teacher())
            ->post(route('electives.approve', $choice->id))
            ->assertStatus(403);
        $this->assertEquals('pending', $choice->fresh()->status);

        $this->actingAs($this->teacher())
            ->post(route('electives.reject', $choice->id))
            ->assertStatus(403);
        $this->assertEquals('pending', $choice->fresh()->status);
    }

    public function test_rejected_choice_can_be_repicked_as_new_pending_row()
    {
        $record = $this->class12Record();
        $subject = $this->offeredFor($record)->first();
        $choice = ElectiveChoice::create([
            'student_record_id' => $record->id,
            'subject_id' => $subject->id,
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin())
            ->post(route('electives.reject', $choice->id))
            ->assertRedirect();
        $this->assertEquals('rejected', $choice->fresh()->status);

        $this->actingAs($record->user)
            ->post(route('electives.store'), ['subject_id' => $subject->id])
            ->assertRedirect();
        $rows = ElectiveChoice::where('student_record_id', $record->id)
            ->where('subject_id', $subject->id)->orderBy('id')->get();
        $this->assertEquals(2, $rows->count());
        $this->assertEquals('rejected', $rows[0]->status);
        $this->assertEquals('pending', $rows[1]->status);
    }

    public function test_other_class_student_sees_explanation_not_error()
    {
        $student = $this->prepStudent();
        $prepRecord = StudentRecord::where('user_id', $student->id)->with('my_class')->first();
        $this->assertNotContains($prepRecord->my_class->name, ['Class 11', 'Class 12']);

        $this->actingAs($student)->get(route('electives.index'))
            ->assertStatus(200)
            ->assertSee('only for Class 11 and Class 12 students');
        $this->actingAs($student)
            ->post(route('electives.store'), ['subject_id' => Subject::orderBy('id')->first()->id])
            ->assertStatus(403);
    }
}
