<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Helpers\Qs;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 8) — null-scope print/PDF.
 *
 * The index view handles its empty states gracefully ("No exams
 * have been created…" / "You are not assigned to any class yet…")
 * and only renders Print/PDF links when a scope exists. But the
 * print and PDF routes are plain GETs with optional query params,
 * so a bookmarked, history, or directly navigated print URL in an
 * empty state — zero exams in the session, a teacher with no
 * classes, a parent with no linked children — reached the document
 * blade with a null exam/my_class and died with a 500 at the
 * <title> (print) or at the filename line (PDF).
 *
 * Fix: printSheet/pdf redirect to the index with an informational
 * flash when the resolved scope has no exam or no class, so the
 * user lands on the page that already explains the empty state.
 */
class OwnerExamDateSheetR8Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function makeUser($code, $email, $type)
    {
        return User::create([
            'name' => 'Probe R8 ' . $code,
            'username' => $code,
            'email' => $email,
            'password' => 'secret',
            'code' => $code,
            'user_type' => $type,
        ]);
    }

    public function test_print_redirects_when_no_exams_in_session()
    {
        Exam::where('year', Qs::getCurrentSession())->delete();

        $this->actingAs($this->admin())->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'))
            ->assertSessionHas('flash_info');
    }

    public function test_pdf_redirects_when_no_exams_in_session()
    {
        Exam::where('year', Qs::getCurrentSession())->delete();

        $this->actingAs($this->admin())->get(route('exam_dates.pdf'))
            ->assertRedirect(route('exam_dates.index'))
            ->assertSessionHas('flash_info');
    }

    public function test_print_redirects_for_teacher_with_no_classes()
    {
        $teacher = $this->makeUser('PROBE-R8-T', 'probe-r8-t@example.com', 'teacher');

        $this->actingAs($teacher)->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'))
            ->assertSessionHas('flash_info');
    }

    public function test_print_redirects_for_parent_with_no_children()
    {
        $parent = $this->makeUser('PROBE-R8-P', 'probe-r8-p@example.com', 'parent');

        $this->actingAs($parent)->get(route('exam_dates.print'))
            ->assertRedirect(route('exam_dates.index'))
            ->assertSessionHas('flash_info');
    }

    public function test_print_still_renders_when_scope_present()
    {
        $this->actingAs($this->admin())->get(route('exam_dates.print'))
            ->assertOk();
    }
}
