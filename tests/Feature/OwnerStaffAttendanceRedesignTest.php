<?php

namespace Tests\Feature;

use App\Models\StaffAttendance;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Staff Attendance redesign (owner, 2026-11-07 late — "yha ka
 * ve uiux bekar hai"): the admin register was tall loose rows
 * with a giant full-width select per person and the save button
 * buried under 100+ rows. It is now an hk-std table with a
 * compact segmented status control, a live summary strip,
 * search + role filters, Mark all Present, and a sticky bottom
 * action bar. The backend contract is unchanged (same GET
 * date flow, same POST status[{id}] in present/late/absent,
 * marked days locked) — the store/lock behaviour itself stays
 * pinned in OwnerWave13Test; these pins hold the new surface:
 * the segmented radios carrying the exact POST field names,
 * the summary strip's server-rendered counts on a locked day,
 * and the toolbar/action-bar contract the page script drives.
 */
class OwnerStaffAttendanceRedesignTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_unlocked_register_renders_segmented_controls_and_tools()
    {
        $resp = $this->actingAs($this->admin())->get(route('staff_attendance.index', ['date' => '2026-11-16']));
        $resp->assertOk();
        // The hk-std sheet + segmented status radios posting the
        // unchanged field contract.
        $resp->assertSee('table hk-std sa-table', false);
        $resp->assertSee('sa-seg', false);
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $resp->assertSee('name="status[' . $teacher->id . ']"', false);
        foreach (['value="present"', 'value="late"', 'value="absent"'] as $v) {
            $resp->assertSee($v, false);
        }
        // Summary strip + tools + sticky action bar.
        $resp->assertSee('id="sa-summary"', false);
        $resp->assertSee('id="sa-present"', false);
        $resp->assertSee('id="sa-late"', false);
        $resp->assertSee('id="sa-absent"', false);
        $resp->assertSee('id="sa-search"', false);
        $resp->assertSee('id="sa-role"', false);
        $resp->assertSee('id="sa-mark-all"', false);
        $resp->assertSee('Mark all Present');
        $resp->assertSee('pat-actionbar sa-actionbar', false);
        $resp->assertSee('Save Attendance');
        // Everyone starts Present on an unmarked day.
        $total = User::whereIn('user_type', ['teacher', 'accountant', 'librarian', 'admin', 'nurse'])->count();
        $resp->assertSee('id="sa-present">' . $total . '<', false);
    }

    public function test_locked_register_summary_counts_come_from_the_marks()
    {
        $admin = $this->admin();
        $teacher = User::where('email', 'teacher@hackstra.app')->firstOrFail();
        $accountant = User::where('email', 'accountant@hackstra.app')->firstOrFail();
        $librarian = User::where('email', 'librarian@hackstra.app')->firstOrFail();
        $date = '2026-10-27';
        StaffAttendance::create(['user_id' => $teacher->id, 'date' => $date, 'status' => 'present', 'marked_by' => $admin->id]);
        StaffAttendance::create(['user_id' => $accountant->id, 'date' => $date, 'status' => 'late', 'marked_by' => $admin->id]);
        StaffAttendance::create(['user_id' => $librarian->id, 'date' => $date, 'status' => 'absent', 'marked_by' => $admin->id]);

        $resp = $this->actingAs($admin)->get(route('staff_attendance.index', ['date' => $date]));
        $resp->assertOk();
        $resp->assertSee('already marked');
        // The strip counts the saved states, one each.
        $resp->assertSee('id="sa-present">1<', false);
        $resp->assertSee('id="sa-late">1<', false);
        $resp->assertSee('id="sa-absent">1<', false);
        // Locked: badges instead of controls, no save bar, no
        // mark-all. (The input markup is what must be absent —
        // the page script legitimately names the class.)
        $resp->assertDontSee('class="sa-status-input"', false);
        $resp->assertDontSee('id="sa-mark-all"', false);
        $resp->assertDontSee('Save Attendance');
    }

    public function test_page_script_recounts_and_marks_all_present()
    {
        $blade = file_get_contents(resource_path('views/pages/support_team/staff_attendance/index.blade.php'));
        // The recount reads the register's CURRENT selections,
        // mark-all checks Present on the rows currently shown.
        $this->assertStringContainsString("querySelector('.sa-status-input:checked')", $blade);
        $this->assertStringContainsString("querySelector('.sa-status-input[value=\"present\"]')", $blade);
        $this->assertStringContainsString('row.style.display', $blade);
        $this->assertStringContainsString("getElementById('sa-mark-all')", $blade);
    }
}
