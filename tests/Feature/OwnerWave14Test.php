<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 owner wave 14 (certificates,
 * CASE_STUDY gap 14): bonafide and transfer certificates are
 * issued with system-minted serials, snapshotted student details,
 * and printable views — and a transfer without a reason is
 * refused.
 */
class OwnerWave14Test extends TestCase
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

    protected function activeRecord()
    {
        return StudentRecord::where('grad', 0)->with(['user', 'my_class', 'section'])->first();
    }

    public function test_issue_bonafide_mints_serial_and_print_renders()
    {
        $sr = $this->activeRecord();
        $this->actingAs($this->admin());
        $response = $this->post(route('certificates.store'), [
            'student_record_id' => $sr->id,
            'type' => 'bonafide',
        ]);
        $cert = Certificate::where('student_record_id', $sr->id)->where('type', 'bonafide')->first();
        $this->assertNotNull($cert);
        $this->assertMatchesRegularExpression('#^BON/\d{4}/0001$#', $cert->serial);
        $this->assertSame($sr->user->name, $cert->student_name);
        $this->assertSame($sr->adm_no, $cert->adm_no);
        $response->assertRedirect(route('certificates.print', $cert->id));

        $this->get(route('certificates.print', $cert->id))->assertOk()
            ->assertSee('BONAFIDE CERTIFICATE', false)
            ->assertSee($cert->serial, false)
            ->assertSee($sr->user->name, false);
    }

    public function test_second_bonafide_increments_serial()
    {
        $sr = $this->activeRecord();
        $this->actingAs($this->admin());
        $this->post(route('certificates.store'), ['student_record_id' => $sr->id, 'type' => 'bonafide']);
        $this->post(route('certificates.store'), ['student_record_id' => $sr->id, 'type' => 'bonafide']);
        $serials = Certificate::where('type', 'bonafide')->orderBy('id')->pluck('serial')->all();
        $this->assertSame(['BON/' . date('Y') . '/0001', 'BON/' . date('Y') . '/0002'], $serials);
    }

    public function test_transfer_requires_reason_and_prints_it()
    {
        $sr = $this->activeRecord();
        $this->actingAs($this->admin());
        $this->post(route('certificates.store'), [
            'student_record_id' => $sr->id, 'type' => 'transfer',
        ])->assertSessionHasErrors(['reason']);
        $this->assertSame(0, Certificate::where('type', 'transfer')->count());

        $this->post(route('certificates.store'), [
            'student_record_id' => $sr->id, 'type' => 'transfer',
            'reason' => 'Parent transferred to another city',
        ])->assertRedirect();
        $cert = Certificate::where('type', 'transfer')->first();
        $this->assertMatchesRegularExpression('#^TC/\d{4}/0001$#', $cert->serial);
        $this->get(route('certificates.print', $cert->id))->assertOk()
            ->assertSee('TRANSFER CERTIFICATE', false)
            ->assertSee('Parent transferred to another city', false);
    }

    public function test_teacher_cannot_issue_or_print_certificates()
    {
        $sr = $this->activeRecord();
        $this->actingAs($this->teacher());
        $this->get(route('certificates.create'))->assertRedirect();
        $this->post(route('certificates.store'), [
            'student_record_id' => $sr->id, 'type' => 'bonafide',
        ])->assertRedirect();
        $this->assertSame(0, Certificate::count());
    }

    public function test_register_lists_and_student_page_links_issue()
    {
        $sr = $this->activeRecord();
        Certificate::create([
            'user_id' => $sr->user_id, 'student_record_id' => $sr->id,
            'type' => 'bonafide', 'serial' => 'BON/' . date('Y') . '/0001',
            'student_name' => $sr->user->name, 'adm_no' => $sr->adm_no,
            'issued_by' => $this->admin()->id, 'issued_at' => now(),
        ]);
        $this->actingAs($this->admin());
        $this->get(route('certificates.index'))->assertOk()
            ->assertSee('BON/' . date('Y') . '/0001', false);
        $this->get(route('students.show', \App\Helpers\Qs::hash($sr->id)))->assertOk()
            ->assertSee('Bonafide', false)
            ->assertSee('Transfer Certificate', false);
    }
}
