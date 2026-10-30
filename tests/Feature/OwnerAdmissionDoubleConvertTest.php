<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner loophole review 2026-10-30: one approved enquiry could
 * be admitted any number of times — Convert only pre-filled the
 * Admit form, nothing linked the student back, and "Converted"
 * was a manual board status. Now the convert carries the
 * enquiry id into the admit; a successful admit marks the
 * enquiry converted AND links the student record it produced;
 * a second admit from the same enquiry is refused before
 * anything is created; and the board shows Convert only where
 * it can actually happen.
 */
class OwnerAdmissionDoubleConvertTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function enquiry(): Admission
    {
        return Admission::create([
            'name' => 'Double Convert Child',
            'phone' => '08037776655',
            'my_class_id' => 1,
            'message' => 'Seat please',
            'status' => 'approved',
            'session' => '2026-2027',
        ]);
    }

    protected function admitForm(array $over = []): array
    {
        return array_merge([
            'name' => 'Double Convert Child',
            'gender' => 'Male',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => 1,
            'lga_id' => 1,
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08037776655',
        ], $over);
    }

    public function test_convert_carries_the_enquiry_into_the_admit_form()
    {
        $enquiry = $this->enquiry();
        $response = $this->actingAs($this->admin())->get(route('admissions.convert', $enquiry->id));
        $response->assertRedirect(route('students.create'));
        $response->assertSessionHasInput('admission_id', $enquiry->id);
    }

    public function test_admitting_converts_and_links_the_enquiry_exactly_once()
    {
        $enquiry = $this->enquiry();
        $before = StudentRecord::count();

        $this->actingAs($this->admin())
            ->post(route('students.store'), $this->admitForm(['admission_id' => $enquiry->id]))
            ->assertRedirect(route('students.list', 1));

        $this->assertSame($before + 1, StudentRecord::count());
        $enquiry->refresh();
        $this->assertSame('converted', $enquiry->status);
        $this->assertNotNull($enquiry->student_record_id);
        $student = StudentRecord::find($enquiry->student_record_id);
        $this->assertNotNull($student);
        $this->assertSame('Double Convert Child', $student->user->name);

        // The same enquiry can never produce a second student.
        $this->actingAs($this->admin())
            ->post(route('students.store'), $this->admitForm(['admission_id' => $enquiry->id, 'name' => 'Second Attempt Child']))
            ->assertSessionHas('flash_danger');
        $this->assertSame($before + 1, StudentRecord::count());

        // And Convert itself now bounces (no longer approved).
        $this->actingAs($this->admin())
            ->get(route('admissions.convert', $enquiry->id))
            ->assertSessionHas('flash_danger');
    }

    public function test_board_shows_convert_only_for_approved_and_student_link_for_converted()
    {
        $approved = $this->enquiry();
        $converted = $this->enquiry();
        $student = StudentRecord::firstOrFail();
        $converted->update(['status' => 'converted', 'student_record_id' => $student->id]);

        $html = $this->actingAs($this->admin())->get(route('admissions.index'))->assertOk()->getContent();
        $this->assertStringContainsString(route('admissions.convert', $approved->id), $html);
        $this->assertStringNotContainsString(route('admissions.convert', $converted->id), $html);
        $this->assertStringContainsString('View Student', $html);
    }
}
