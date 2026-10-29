<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\StudentRecord;
use App\Support\SectionCapacity;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Section capacity + auto-allotment (owner order 2026-10-29):
 * each section may carry a fixed number of seats; an admission
 * into a full section is automatically placed in the next
 * section of the class with room — and told so; a class whose
 * sections are all full refuses the admission with the reason.
 * Capacity NULL/0 = unlimited (historic behaviour).
 */
class OwnerSectionCapacityTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function admitForm(array $over = []): array
    {
        return array_merge([
            'name' => 'Capacity Test Child',
            'gender' => 'Male',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => 1,
            'lga_id' => 1,
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08032220000',
        ], $over);
    }

    /** Pin every Prep section's capacity to its live strength. */
    protected function fillPrepSections(): void
    {
        foreach (Section::where('my_class_id', 1)->orderBy('id')->get() as $s) {
            $s->update(['capacity' => SectionCapacity::strength($s->id)]);
        }
    }

    public function test_admission_into_full_section_moves_to_next_with_room()
    {
        // Prep A full; B and C keep room.
        $a = Section::findOrFail(1);
        $a->update(['capacity' => SectionCapacity::strength(1)]);

        $resp = $this->actingAs($this->admin())->post(route('students.store'), $this->admitForm());
        $resp->assertRedirect(route('students.list', 1));
        $resp->assertSessionHas('flash_success', function ($msg) {
            return strpos($msg, 'Section A is full') !== false
                && strpos($msg, 'placed in Section B') !== false;
        });
        $record = StudentRecord::where('my_class_id', 1)->orderByDesc('id')->firstOrFail();
        $this->assertSame(2, (int) $record->section_id);
    }

    public function test_admission_refused_when_every_section_is_full()
    {
        $this->fillPrepSections();
        $before = StudentRecord::where('my_class_id', 1)->count();

        $resp = $this->actingAs($this->admin())->from(route('students.create'))
            ->post(route('students.store'), $this->admitForm());
        $resp->assertSessionHas('flash_danger', function ($msg) {
            return strpos($msg, 'Every section of Prep is full') !== false;
        });
        $this->assertSame($before, StudentRecord::where('my_class_id', 1)->count());
    }

    public function test_unlimited_sections_admit_as_before()
    {
        $resp = $this->actingAs($this->admin())->post(route('students.store'), $this->admitForm());
        $resp->assertRedirect(route('students.list', 1));
        $record = StudentRecord::where('my_class_id', 1)->orderByDesc('id')->firstOrFail();
        $this->assertSame(1, (int) $record->section_id);
        // No capacity note when nothing was moved.
        $resp->assertSessionHas('flash_success', function ($msg) {
            return strpos($msg, 'is full') === false;
        });
    }

    public function test_capacity_is_settable_on_section_update()
    {
        $section = Section::findOrFail(3);
        $this->actingAs($this->admin())->put(route('sections.update', \App\Helpers\Qs::hash(3)), [
            'name' => $section->name,
            'teacher_id' => $section->teacher_id,
            'capacity' => 60,
        ])->assertRedirect(route('sections.index'));
        $this->assertSame(60, (int) Section::findOrFail(3)->capacity);
    }
}
