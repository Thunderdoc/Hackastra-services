<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MarkSubmission;
use App\Models\Section;
use App\Models\Subject;
use App\Support\SectionTeacher;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Owner order 2026-11-01: on the marks selector, when the chosen
 * unit is NOT enabled (the exam date has not passed, or the marks
 * are already submitted and locked), the Manage Marks button must
 * become "Not Enabled" instead of letting the user click through
 * into a locked grid. GET marks/entry-state mirrors the manage()
 * gates (exam scope, teacher scope, MarkLifecycle) and the
 * selector page carries the wiring that consumes it.
 *
 * Fixture unit: Term 1 (exam 1, global) / Class 12 (13) /
 * section 76 / subject 79 (English) — Ananya (teacher@) is its
 * effective teacher on the seed (same anchor as R24).
 */
class OwnerMarksEntryStateButtonTest extends TestCase
{
    use DatabaseTransactions;

    private function ananya(): User
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    private function unitUrl(): string
    {
        return route('marks.entry_state') . '?exam_id=1&class_id=13&section_id=76&subject_id=79';
    }

    private function assertUnitIsAnanyas(): void
    {
        $subject = Subject::findOrFail(79);
        $this->assertSame($this->ananya()->id, (int) SectionTeacher::teacherIdFor(76, $subject),
            'seed anchor drifted: section 76 / subject 79 is no longer Ananya\'s unit');
    }

    public function test_entry_state_enabled_for_a_clean_unit()
    {
        $this->assertUnitIsAnanyas();

        $res = $this->actingAs($this->ananya())->getJson($this->unitUrl());

        $res->assertOk();
        $this->assertTrue($res->json('enabled'));
        $this->assertNull($res->json('reason'));
    }

    public function test_entry_state_not_enabled_before_the_exam_day()
    {
        $this->assertUnitIsAnanyas();
        ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 13, 'subject_id' => 79,
            'exam_date' => Carbon::today()->addDays(3)->format('Y-m-d'), 'start_time' => '09:00',
        ]);

        $res = $this->actingAs($this->ananya())->getJson($this->unitUrl());

        $res->assertOk();
        $this->assertFalse($res->json('enabled'));
        $this->assertStringContainsString('Marks entry opens on', (string) $res->json('reason'));
    }

    public function test_entry_state_not_enabled_after_submission_for_teacher_and_admin()
    {
        $this->assertUnitIsAnanyas();
        MarkSubmission::create([
            'exam_id' => 1, 'my_class_id' => 13, 'section_id' => 76, 'subject_id' => 79,
            'submitted_by' => $this->ananya()->id,
        ]);

        $asTeacher = $this->actingAs($this->ananya())->getJson($this->unitUrl());
        $this->assertFalse($asTeacher->json('enabled'));
        $this->assertStringContainsString('already submitted', (string) $asTeacher->json('reason'));

        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $asAdmin = $this->actingAs($admin)->getJson($this->unitUrl());
        $this->assertFalse($asAdmin->json('enabled'));
        $this->assertStringContainsString('already submitted', (string) $asAdmin->json('reason'));
    }

    public function test_entry_state_not_enabled_for_a_teacher_who_does_not_teach_the_unit()
    {
        $this->assertUnitIsAnanyas();
        $other = User::where('user_type', 'teacher')->where('id', '!=', $this->ananya()->id)->firstOrFail();

        $res = $this->actingAs($other)->getJson($this->unitUrl());

        $res->assertOk();
        $this->assertFalse($res->json('enabled'));
        $this->assertStringContainsString('not assigned to you', (string) $res->json('reason'));
    }

    public function test_selector_page_carries_the_not_enabled_wiring()
    {
        $res = $this->actingAs($this->ananya())->get(route('marks.index'));

        $res->assertOk();
        $res->assertSee('l84-manage-btn-label', false);
        $res->assertSee('l84-entry-note', false);
        $res->assertSee('marks/entry-state', false);
        $res->assertSee('Not Enabled', false);
    }
}
