<?php

namespace Tests\Feature;

use App\Http\Controllers\SupportTeam\PayrollController;
use App\Models\HealthRecord;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Nurse role (owner order 2026-11-06): Health Records moved out of
 * the school Admin to a dedicated Nurse login. The nurse owns the
 * clinic (full read/write on health records), lands on her own
 * clinic home, sees only her own menu, and counts as staff
 * everywhere staff are listed. Admin's loss of access itself is
 * pinned in OwnerHealthRecordTest.
 */
class OwnerNurseRoleTest extends TestCase
{
    use DatabaseTransactions;

    protected function nurse()
    {
        return User::where('email', 'nurse@hackstra.app')->firstOrFail();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_nurse_type_and_demo_login_are_seeded()
    {
        $type = DB::table('user_types')->where('title', 'nurse')->first();
        $this->assertNotNull($type, 'nurse user type must be seeded');
        $this->assertEquals(7, (int) $type->level);

        $nurse = $this->nurse();
        $this->assertEquals('nurse', $nurse->user_type);
        $this->assertTrue($nurse->isActiveAccount());
    }

    public function test_nurse_home_is_the_clinic_board()
    {
        $response = $this->actingAs($this->nurse())->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Recent Visits');
        $response->assertSee('Record a Visit');
        $response->assertSee(route('health_records.create'));
    }

    public function test_nurse_menu_is_clinic_only()
    {
        $html = $this->actingAs($this->nurse())->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString(route('health_records.index'), $html);
        $this->assertStringContainsString('>Clinic</div>', $html);
        // None of the school-operator surfaces leak into her sidebar.
        $this->assertStringNotContainsString(route('users.index'), $html);
        $this->assertStringNotContainsString(route('classes.index'), $html);
        $this->assertStringNotContainsString(route('payments.manage'), $html);
    }

    public function test_nurse_sees_every_students_records()
    {
        $students = StudentRecord::where('grad', 0)->orderBy('id')->take(2)->get();
        $this->assertCount(2, $students);
        $ids = [];
        foreach ($students as $sr) {
            $record = HealthRecord::create([
                'student_id' => $sr->user_id,
                'visit_date' => '2026-10-28',
                'complaint' => 'PROBE-NURSE scope complaint ' . $sr->id,
                'recorded_by' => $this->nurse()->id,
            ]);
            $ids[] = $record->id;
        }

        $html = $this->actingAs($this->nurse())->get(route('health_records.index'))->assertOk()->getContent();
        foreach ($ids as $id) {
            $this->assertStringContainsString('/health-records/' . $id, $html);
        }
        foreach ($ids as $id) {
            $this->actingAs($this->nurse())->get(route('health_records.show', $id))->assertOk();
        }
    }

    public function test_nurse_counts_as_staff()
    {
        $this->assertContains('nurse', PayrollController::STAFF_TYPES);

        $html = $this->actingAs($this->admin())->get(route('staff_attendance.index'))->assertOk()->getContent();
        $this->assertStringContainsString($this->nurse()->name, $html);
    }
}
