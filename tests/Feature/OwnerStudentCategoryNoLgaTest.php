<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\StudentRecord;
use App\Models\UserType;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Owner order 2026-10-29 (via the main chat, ~21:43 IST), from his
 * screenshot of the Admit Student form: "ye kyu puch rha hai — here
 * it should ask like gender, blood group, category."
 *
 * LGA (Local Government Area) is a Nigerian leftover from the
 * forked codebase. For the Indian product the admission form asks
 * Gender, Blood Group and Category (General / OBC / SC / ST / EWS)
 * instead:
 *  - Gender + Blood Group already existed on the student form.
 *  - Category is new: student_records.category (migration
 *    2026_10_29_000013), a select on the admit + edit forms, saved
 *    through Qs::getStudentData().
 *  - The LGA field is REMOVED from the student admit/edit forms
 *    and the staff create/edit forms; lga_id validation is
 *    nullable everywhere (UserRequest, StudentRecordCreate,
 *    StudentRecordUpdate). The DB column stays (harmless, other
 *    legacy data may carry it) — nothing asks for it anymore.
 *  - Profile pages no longer print an LGA row; the student page
 *    prints Category; the "Incomplete profile" marker no longer
 *    counts a blank LGA (every student would have worn it forever).
 */
class OwnerStudentCategoryNoLgaTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function admitForm(array $over = []): array
    {
        return array_merge([
            'name' => 'Category Test Child',
            'gender' => 'Female',
            'year_admitted' => '2026',
            'address' => '12 School Road',
            'state_id' => \App\Models\State::where('name', 'Tamil Nadu')->value('id'),
            'nal_id' => 1,
            'my_class_id' => 1,
            'section_id' => 1,
            'phone' => '08032220000',
        ], $over);
    }

    public function test_admit_form_asks_category_and_never_lga()
    {
        $res = $this->actingAs($this->admin())->get(route('students.create'));
        $res->assertOk();
        $res->assertSee('Category');
        $res->assertSee('General');
        $res->assertSee('EWS');
        $res->assertSee('Blood Group');
        $res->assertSee('Gender');
        $res->assertDontSee('id="lga_id"', false);
    }

    public function test_admit_without_lga_saves_and_persists_category()
    {
        $this->actingAs($this->admin())
            ->post(route('students.store'), $this->admitForm(['category' => 'OBC']))
            ->assertRedirect(route('students.list', 1));

        $user = User::where('phone', '08032220000')->where('user_type', 'student')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->lga_id);

        $record = StudentRecord::where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('OBC', $record->category);
    }

    public function test_unknown_category_is_refused_and_creates_nothing()
    {
        $this->actingAs($this->admin())
            ->post(route('students.store'), $this->admitForm(['category' => 'XYZ']))
            ->assertSessionHasErrors('category');

        $this->assertNull(User::where('phone', '08032220000')->first());
    }

    public function test_edit_form_offers_category_and_update_persists_it()
    {
        $record = StudentRecord::where('my_class_id', 1)->where('grad', 0)->firstOrFail();

        $res = $this->actingAs($this->admin())->get(route('students.edit', Qs::hash($record->id)));
        $res->assertOk();
        $res->assertSee('Category');
        $res->assertDontSee('id="lga_id"', false);

        $user = $record->user;
        $this->actingAs($this->admin())->put(route('students.update', Qs::hash($record->id)), [
            'name' => $user->name,
            'gender' => $user->gender ?: 'Male',
            'year_admitted' => $record->year_admitted,
            'address' => $user->address ?: '12 School Road',
            'state_id' => $user->state_id ?: \App\Models\State::where('name', 'Tamil Nadu')->value('id'),
            'nal_id' => $user->nal_id ?: 1,
            'my_class_id' => $record->my_class_id,
            'section_id' => $record->section_id,
            'category' => 'EWS',
        ])->assertRedirect();

        $this->assertSame('EWS', $record->fresh()->category);
    }

    public function test_staff_create_form_has_no_lga_and_teacher_saves_without_it()
    {
        $res = $this->actingAs($this->admin())->get(route('users.create'));
        $res->assertOk();
        $res->assertDontSee('id="lga_id"', false);

        $typeId = UserType::where('title', 'teacher')->firstOrFail()->id;
        $this->actingAs($this->admin())->post(route('users.store'), [
            'name' => 'No LGA Teacher',
            'email' => 'nolga.teacher@hackstra.app',
            'user_type' => Qs::hash($typeId),
            'gender' => 'female',
            'address' => '12 School Road',
            'emp_date' => '2026-01-05',
            'state_id' => \App\Models\State::where('name', 'Tamil Nadu')->value('id'),
            'nal_id' => 1,
        ])->assertRedirect();

        $teacher = User::where('email', 'nolga.teacher@hackstra.app')->first();
        $this->assertNotNull($teacher);
        $this->assertNull($teacher->lga_id);
    }
}
