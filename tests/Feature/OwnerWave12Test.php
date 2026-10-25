<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 12 (admissions
 * pipeline, CASE_STUDY gap 13): a public enquiry lands on the
 * Admin board, moves through pipeline states, and Convert
 * pre-fills the Admit Student form without creating anything.
 */
class OwnerWave12Test extends TestCase
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

    public function test_public_enquiry_form_renders_and_stores()
    {
        $this->get(route('admission_enquiry.form'))->assertOk()
            ->assertSee('Admission Enquiry');

        $this->post(route('admission_enquiry.store'), [
            'name' => 'Prospective Parent',
            'phone' => '08031113333',
            'my_class_id' => 7,
            'message' => 'Seat for my daughter, Class 6.',
        ])->assertRedirect(route('admission_enquiry.form'));

        $row = Admission::where('phone', '08031113333')->first();
        $this->assertNotNull($row);
        $this->assertSame('new', $row->status);
        $this->assertSame(7, (int) $row->my_class_id);
        $this->assertSame('2026-2027', $row->session);
    }

    public function test_public_enquiry_validates_required_fields()
    {
        $this->post(route('admission_enquiry.store'), [
            'name' => 'Al',
            'phone' => '',
        ])->assertSessionHasErrors(['name', 'phone']);
        $this->assertSame(0, Admission::count());
    }

    public function test_admin_board_lists_and_moves_enquiry()
    {
        $a = Admission::create([
            'name' => 'Board Test Parent', 'phone' => '08032224444',
            'my_class_id' => 1, 'status' => 'new', 'session' => '2026-2027',
        ]);

        $this->actingAs($this->admin());
        $this->get(route('admissions.index'))->assertOk()
            ->assertSee('Board Test Parent');
        $this->get(route('admissions.index', ['status' => 'contacted']))->assertOk()
            ->assertDontSee('Board Test Parent');

        $this->post(route('admissions.status', $a->id), [
            'status' => 'contacted', 'notes' => 'Called, test on Monday.',
        ])->assertRedirect();
        $this->assertSame('contacted', $a->fresh()->status);
        $this->assertSame('Called, test on Monday.', $a->fresh()->notes);
    }

    public function test_admin_board_rejects_bad_status()
    {
        $a = Admission::create([
            'name' => 'Bad Status Parent', 'phone' => '08033335555',
            'status' => 'new', 'session' => '2026-2027',
        ]);
        $this->actingAs($this->admin());
        $this->post(route('admissions.status', $a->id), ['status' => 'enrolled'])
            ->assertSessionHasErrors(['status']);
        $this->assertSame('new', $a->fresh()->status);
    }

    public function test_convert_prefills_admit_form_without_creating()
    {
        $a = Admission::create([
            'name' => 'Convert Test Parent', 'phone' => '08034446666',
            'my_class_id' => 7, 'status' => 'approved', 'session' => '2026-2027',
        ]);
        $this->actingAs($this->admin());
        $response = $this->get(route('admissions.convert', $a->id));
        $response->assertRedirect(route('students.create'));
        $response->assertSessionHasInput('name', 'Convert Test Parent');
        $response->assertSessionHasInput('phone', '08034446666');
        $response->assertSessionHasInput('my_class_id', 7);
        // The create form reads old() — flashed input pre-fills it.
        $this->withSession(['_old_input' => [
            'name' => 'Convert Test Parent', 'phone' => '08034446666', 'my_class_id' => 7,
        ]])->get(route('students.create'))->assertOk()
            ->assertSee('Convert Test Parent', false);
        // Nothing was created and the enquiry is NOT marked converted
        // by the prefill alone — that happens on the board after
        // the student record actually exists.
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertNull(User::where('phone', '08034446666')->where('user_type', 'student')->first());
    }

    public function test_teacher_cannot_open_admissions_board()
    {
        $this->actingAs($this->teacher());
        $this->get(route('admissions.index'))->assertRedirect();
    }

    public function test_dashboard_counts_new_enquiries()
    {
        Admission::create([
            'name' => 'Tile Test Parent', 'phone' => '08035557777',
            'status' => 'new', 'session' => '2026-2027',
        ]);
        $this->actingAs($this->admin());
        $this->get(route('home'))->assertOk()->assertSee('New Enquiries');
    }
}
