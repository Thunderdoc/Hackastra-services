<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Back-button doctrine (owner, 2026-10-29): pressing back from
 * a form must return to the section/subheading page the user
 * came from — never straight to the dashboard. The app serves
 * this through the form_page pattern's Cancel (= back) link and
 * explicit Back buttons; the audit found the wizard and
 * sub-form pages below passing NO back target at all. These
 * pins keep every one of them pointed at its parent section,
 * and bar the regression class outright (a form_page 'back'
 * aimed at home/dashboard).
 */
class OwnerBackButtonDoctrineTest extends TestCase
{
    use DatabaseTransactions;

    protected function admin(): User
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_timetable_generate_cancels_back_to_timetables()
    {
        $this->actingAs($this->admin())->get(route('tt.generate.form'))
            ->assertOk()
            ->assertSee('href="' . route('tt.index') . '"', false);
    }

    public function test_date_sheet_generate_cancels_back_to_manage()
    {
        $this->actingAs($this->admin())->get(route('exam_dates.generate.form'))
            ->assertOk()
            ->assertSee('href="' . route('exam_dates.manage') . '"', false);
    }

    public function test_transport_forms_cancel_back_to_transport()
    {
        $this->actingAs($this->admin())->get(route('transport.routes.create'))
            ->assertOk()
            ->assertSee('href="' . route('transport.index') . '"', false);

        $this->actingAs($this->admin())->get(route('transport.vehicles.create'))
            ->assertOk()
            ->assertSee('href="' . route('transport.index') . '"', false);
    }

    public function test_platform_mail_create_cancels_back_to_sent()
    {
        $sa = User::where('email', 'superadmin@hackstra.app')->firstOrFail();
        $this->actingAs($sa)->get(route('platform_mail.create'))
            ->assertOk()
            ->assertSee('href="' . route('platform_mail.index') . '"', false);
    }

    public function test_student_edit_cancels_back_to_the_profile()
    {
        $this->actingAs($this->admin())->get(route('students.edit', Qs::hash(1)))
            ->assertOk()
            ->assertSee('href="' . route('students.show', Qs::hash(1)) . '"', false);
    }

    public function test_no_form_page_back_points_at_the_dashboard()
    {
        $views = dirname(__DIR__, 2) . '/resources/views';
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));
        foreach ($it as $file) {
            if ($file->isDir() || substr($file->getFilename(), -10) !== '.blade.php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if (preg_match("/'back'\\s*=>\\s*route\\('(home|dashboard)'\\)/", $src)) {
                $bad[] = $file->getPathname();
            }
        }
        $this->assertSame([], $bad, 'A form page Cancel/back must land on its parent section, never the dashboard.');
    }

    public function test_marks_workspace_carries_a_visible_back_to_marks()
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/resources/views/pages/support_team/marks/edit.blade.php');
        $this->assertStringContainsString("route('marks.manage'", $src);
        $this->assertStringContainsString('Back to Marks', $src);
    }
}
