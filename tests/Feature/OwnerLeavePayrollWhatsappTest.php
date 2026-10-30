<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Assignment;
use App\Models\LeaveRequest;
use App\Models\Payroll;
use App\Models\Section;
use App\Models\Setting;
use App\Models\StudyMaterial;
use App\Models\Substitution;
use App\Models\TimeTable;
use App\Support\LeaveBalance;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Owner orders 2026-10-30 (morning batch):
 *  - leave applications may not overlap an existing leave;
 *  - a teacher on approved leave gets NO take-attendance option
 *    for those periods (the substitute takes the register), and
 *    the periods list says "On Leave / Covered by …";
 *  - government-style paid leave quota (Settings): extra leave
 *    deducts from salary at the school's per-day rate, the
 *    payslip names the leave days + deduction, and the teacher
 *    portal shows quota / taken / remaining;
 *  - a covered class joins the substitute's LMS lists for the
 *    day, labelled "(Substitute)" — attendance included;
 *  - each class teacher keeps one WhatsApp group link per
 *    section; students see it beside their class, and the
 *    student dashboard carries a basic ID card.
 */
class OwnerLeavePayrollWhatsappTest extends TestCase
{
    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /* ---------------- leave overlap ---------------- */

    public function test_overlapping_leave_application_is_refused()
    {
        $teacher = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        LeaveRequest::where('user_id', $teacher->id)->delete();
        $before = LeaveRequest::where('user_id', $teacher->id)->count();

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2027-05-03', 'end_date' => '2027-05-05',
            'reason' => 'Family function', 'leave_type' => 'normal',
        ])->assertSessionHas('flash_success');
        $this->assertSame($before + 1, LeaveRequest::where('user_id', $teacher->id)->count());

        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2027-05-04', 'end_date' => '2027-05-06',
            'reason' => 'Overlap attempt', 'leave_type' => 'normal',
        ])->assertSessionHas('flash_danger');
        $this->assertSame($before + 1, LeaveRequest::where('user_id', $teacher->id)->count(),
            'An overlapping leave must not be created');
    }

    public function test_disjoint_partial_leaves_still_combine_but_same_half_is_refused()
    {
        $teacher = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        LeaveRequest::where('user_id', $teacher->id)->delete();
        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => '2027-05-12', 'end_date' => '2027-05-12',
            'reason' => 'Morning work', 'leave_type' => 'normal', 'status' => 'approved',
            'day_part' => 'first_half', 'decided_by' => $this->admin()->id,
        ]);
        $before = LeaveRequest::where('user_id', $teacher->id)->count();

        // The other half of the same day is a different window: allowed.
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2027-05-12', 'end_date' => '2027-05-12',
            'reason' => 'Evening work', 'leave_type' => 'normal', 'day_part' => 'second_half',
        ])->assertSessionHas('flash_success');
        $this->assertSame($before + 1, LeaveRequest::where('user_id', $teacher->id)->count());

        // The SAME half again overlaps the approved one: refused.
        $this->actingAs($teacher)->post(route('leave.store'), [
            'start_date' => '2027-05-12', 'end_date' => '2027-05-12',
            'reason' => 'Duplicate half', 'leave_type' => 'normal', 'day_part' => 'first_half',
        ])->assertSessionHas('flash_danger');
        $this->assertSame($before + 1, LeaveRequest::where('user_id', $teacher->id)->count());
    }

    /* ---------------- attendance while on leave ---------------- */

    protected function ananyaMondayCell()
    {
        $teacher = $this->teacher();
        foreach (TimeTable::with('tt_record')->where('day', 'Monday')->get() as $cell) {
            $sectionId = optional($cell->tt_record)->section_id;
            if ((int) SectionTeacher::teacherIdFor($sectionId, $cell->subject) === (int) $teacher->id) {
                return $cell;
            }
        }
        $this->fail('Seed must give the demo teacher a Monday period');
    }

    public function test_teacher_on_approved_leave_cannot_take_and_list_says_on_leave()
    {
        $teacher = $this->teacher();
        $cell = $this->ananyaMondayCell();
        $monday = '2026-11-02';

        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => $monday, 'end_date' => $monday,
            'reason' => ' unwell ', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);

        $this->actingAs($teacher)
            ->get(route('attendance.periods.take', ['tt_id' => $cell->id, 'date' => $monday]))
            ->assertForbidden();

        $response = $this->actingAs($teacher)
            ->get(route('attendance.periods.index', ['date' => $monday]));
        $response->assertOk();
        $response->assertSee('On Leave');
    }

    /* ---------------- leave quota + payroll ---------------- */

    protected function quotaTeacherWithLeave(): User
    {
        Setting::updateOrCreate(['type' => 'paid_leave_quota_days'], ['description' => '12']);
        $teacher = $this->teacher();
        // 10 approved days in January 2027 + 5 in February 2027.
        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => '2028-01-03', 'end_date' => '2028-01-12',
            'reason' => 'January break', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);
        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => '2028-02-01', 'end_date' => '2028-02-05',
            'reason' => 'February work', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);
        return $teacher;
    }

    public function test_leave_balance_counts_chronologically()
    {
        $teacher = $this->quotaTeacherWithLeave();

        $this->assertSame(15.0, LeaveBalance::usedInYear($teacher->id, 2028));
        $this->assertSame(3.0, LeaveBalance::extraInYear($teacher->id, 2028));
        $this->assertSame(0.0, LeaveBalance::extraInMonth($teacher->id, 2028, 1),
            'January fits inside the 12-day quota');
        $this->assertSame(3.0, LeaveBalance::extraInMonth($teacher->id, 2028, 2),
            'February: 2 days paid, 3 beyond the quota');
        // Gross 60,000 → day rate 2,000 → 3 extra days = 6,000.
        $this->assertEquals(6000.0, LeaveBalance::deductionFor($teacher->id, 2028, 2, 60000.0));

        $summary = LeaveBalance::summary($teacher->id, 2028);
        $this->assertSame(12.0, $summary['quota']);
        $this->assertSame(0.0, $summary['remaining']);
    }

    public function test_payroll_store_applies_leave_deduction_and_payslip_names_it()
    {
        $teacher = $this->quotaTeacherWithLeave();
        Payroll::where('user_id', $teacher->id)
            ->where('year', 2028)->where('month', 2)->delete();

        $this->actingAs($this->admin())->post(route('payroll.store'), [
            'user_id' => $teacher->id, 'year' => 2028, 'month' => 2,
            'basic_salary' => 45000, 'allowances' => 15000, 'deductions' => 1000,
        ])->assertSessionHas('flash_success');

        $payroll = Payroll::where('user_id', $teacher->id)
            ->where('year', 2028)->where('month', 2)->firstOrFail();
        $this->assertEquals(5.0, (float) $payroll->leave_days);
        $this->assertEquals(3.0, (float) $payroll->leave_extra_days);
        $this->assertEquals(6000.0, (float) $payroll->leave_deduction);
        $this->assertEquals(7000.0, (float) $payroll->deductions,
            'Total deductions = other deductions + leave deduction');
        $this->assertEquals(53000.0, (float) $payroll->net_pay);

        $payslip = $this->actingAs($this->admin())->get(route('payroll.payslip', $payroll->id));
        $payslip->assertOk();
        $payslip->assertSee('Leave Deduction (3 extra days)');
        $payslip->assertSee('Other Deductions');
        $payslip->assertSee('5 day(s) this month');
    }

    public function test_teacher_portal_shows_leave_balance()
    {
        // The portal strip reports the CURRENT year, so this test
        // seeds its own 2026 leave: 12 days in January (the whole
        // quota) + 3 in February — extra days must show regardless
        // of other tests' fixtures.
        Setting::updateOrCreate(['type' => 'paid_leave_quota_days'], ['description' => '12']);
        $teacher = $this->teacher();
        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => '2026-01-05', 'end_date' => '2026-01-16',
            'reason' => 'Portal check A', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);
        LeaveRequest::create([
            'user_id' => $teacher->id, 'start_date' => '2026-02-02', 'end_date' => '2026-02-04',
            'reason' => 'Portal check B', 'leave_type' => 'normal', 'status' => 'approved',
            'decided_by' => $this->admin()->id,
        ]);

        $response = $this->actingAs($teacher)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Paid leave:');
        $response->assertSee('Extra (unpaid):');

        $my = $this->actingAs($teacher)->get(route('leave.my'));
        $my->assertOk();
        $my->assertSee('Your leave balance');
    }

    /* ---------------- substitute LMS labels ---------------- */

    public function test_covered_class_joins_substitute_lms_lists_labelled()
    {
        $material = StudyMaterial::with('teacher')->first();
        $this->assertNotNull($material, 'Seed must carry study materials');
        $absent = $material->teacher;
        $substitute = User::where('user_type', 'teacher')
            ->where('id', '!=', $absent->id)->orderBy('id')->firstOrFail();

        $cell = TimeTable::where('subject_id', $material->subject_id)
            ->whereHas('tt_record', function ($q) use ($material) {
                $q->where('my_class_id', $material->my_class_id);
            })->first();
        $this->assertNotNull($cell, 'A timetable cell must exist for the material class + subject');

        Substitution::where('time_table_id', $cell->id)
            ->whereDate('date', Carbon::today())->delete();
        Substitution::create([
            'time_table_id' => $cell->id, 'date' => Carbon::today()->toDateString(),
            'absent_teacher_id' => $absent->id, 'substitute_teacher_id' => $substitute->id,
            'status' => 'confirmed', 'created_by' => $this->admin()->id,
        ]);

        $assignment = Assignment::create([
            'teacher_id' => $absent->id, 'my_class_id' => $material->my_class_id,
            'subject_id' => $material->subject_id, 'title' => 'Cover Day Worksheet ZQ',
            'due_at' => now()->addDay(),
        ]);

        $materials = $this->actingAs($substitute)->get(route('materials.index'));
        $materials->assertOk();
        $materials->assertSee($material->title);
        $materials->assertSee('(Substitute)');

        $assignments = $this->actingAs($substitute)->get(route('assignments.index'));
        $assignments->assertOk();
        $assignments->assertSee('Cover Day Worksheet ZQ');
        $assignments->assertSee('(Substitute)');

        // A teacher with no cover sees neither.
        $other = User::where('user_type', 'teacher')
            ->whereNotIn('id', [$absent->id, $substitute->id])->orderBy('id')->firstOrFail();
        $this->actingAs($other)->get(route('assignments.index'))
            ->assertDontSee('Cover Day Worksheet ZQ');
    }

    /* ---------------- WhatsApp link + ID card ---------------- */

    public function test_section_whatsapp_link_flow_and_student_dashboard()
    {
        $teacher = $this->teacher(); // class teacher of section 1 (Prep A)
        $section = Section::findOrFail(1);

        $this->actingAs($teacher)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/AbCdEfGhIjK',
        ])->assertSessionHas('flash_success');
        $this->assertSame('https://chat.whatsapp.com/AbCdEfGhIjK', $section->fresh()->whatsapp_link);

        // Non-WhatsApp hosts are refused.
        $this->actingAs($teacher)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://evil.example.com/group',
        ])->assertSessionHasErrors('whatsapp_link');

        // Another teacher who does not lead the section may not set it.
        $other = User::where('user_type', 'teacher')->where('id', '!=', $teacher->id)
            ->orderBy('id')->firstOrFail();
        $this->actingAs($other)->post(route('sections.whatsapp', Qs::hash($section->id)), [
            'whatsapp_link' => 'https://chat.whatsapp.com/XyZ',
        ])->assertForbidden();

        // The student of that section sees the group link and the
        // dashboard ID card.
        $student = User::where('email', 'student@hackstra.app')->firstOrFail();
        $response = $this->actingAs($student)->get(route('dashboard'));
        $response->assertOk();
        $response->assertSee('Class WhatsApp Group');
        $response->assertSee('My ID Card');
        $response->assertSee('Adm No:');
    }
}
