<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Section;
use App\Models\State;
use App\Models\StudentRecord;
use App\User;
use Tests\TestCase;

/**
 * Cascade audit 2026-10-30 — the audit's one genuine server gap
 * outside the timetable cell: the student create/update requests
 * validated class and section independently (both `exists`), so
 * a crafted post could silently store a student in class A with
 * a section from class B (the UI cascades correctly — this is
 * the server half of the parent-message lesson). Both requests
 * now carry the belongs check in withValidator. Admissions that
 * legitimately move WITHIN the posted class (SectionCapacity)
 * are unaffected.
 */
class OwnerStudentSectionPairGuardTest extends TestCase
{
    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function admitForm(array $over = []): array
    {
        return array_merge([
            'name' => 'Pair Guard Child',
            'gender' => 'Male',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => State::where('name', 'Tamil Nadu')->value('id'),
            'lga_id' => null,
            'nal_id' => 1,
            'my_class_id' => 13,
            'section_id' => 64, // Class 12-A
            'phone' => '08034445555',
        ], $over);
    }

    public function test_store_refuses_a_section_from_another_class()
    {
        $foreign = Section::where('my_class_id', 1)->firstOrFail(); // Prep A
        $before = StudentRecord::count();

        $this->actingAs($this->admin())->post(route('students.store'),
            $this->admitForm(['section_id' => $foreign->id]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame($before, StudentRecord::count());
    }

    public function test_store_accepts_the_matching_pair()
    {
        $before = StudentRecord::count();

        $this->actingAs($this->admin())->post(route('students.store'), $this->admitForm())
            ->assertRedirect(route('students.list', 13));

        $this->assertSame($before + 1, StudentRecord::count());
        $this->assertSame(64, (int) StudentRecord::latest('id')->first()->section_id);
    }

    public function test_update_refuses_a_section_from_another_class()
    {
        $record = StudentRecord::where('my_class_id', 1)->where('grad', 0)->firstOrFail();
        $user = $record->user;
        $foreign = Section::where('my_class_id', 7)->firstOrFail();

        $this->actingAs($this->admin())->put(route('students.update', Qs::hash($record->id)), [
            'name' => $user->name,
            'gender' => $user->gender ?: 'Male',
            'year_admitted' => $record->year_admitted,
            'address' => $user->address ?: '12 School Road',
            'state_id' => $user->state_id ?: State::where('name', 'Tamil Nadu')->value('id'),
            'nal_id' => $user->nal_id ?: 1,
            'my_class_id' => 1,
            'section_id' => $foreign->id,
        ])->assertSessionHasErrors('section_id');

        $this->assertSame((int) $record->section_id, (int) $record->fresh()->section_id);
    }
}
