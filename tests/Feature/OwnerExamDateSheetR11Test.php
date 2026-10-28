<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Round 11 (2026-10-28): date-sheet SAVE-refusal RENDERED outcome.
 *
 * Companion to round 10 (generate refusals). Round 4 locked the
 * save TRANSACTION (a mid-save failure rolls back); this file locks
 * what the admin actually SEES for each save refusal: the request
 * must land back on the Manage page with a readable message — never
 * a 500, never a partial write, and the stored rows byte-identical.
 * The two grandfathering rules (an unchanged past date, or a clash
 * that already exists in the stored sheet, never blocks an
 * unrelated edit) must still let a save through.
 *
 * Anchors: exam 1 (global Term 1), Prep = class 1, subjects 1–6
 * (English Language, Mathematics, Science, Social Science, Hindi,
 * Computer Science). Today in the test env is 2026-10-28, so
 * 2026-10-01 / 2026-10-20 are past and 2026-11-* are future.
 * Toast format (partials/js/custom_js): flash({msg : '...',
 * type : 'danger'}); assertSee needs escape=false because ' is
 * escaped to &#039; by default (round-10 lesson).
 */
class OwnerExamDateSheetR11Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function manageUrl()
    {
        return route('exam_dates.manage', ['exam_id' => 1, 'class_id' => 1]);
    }

    protected function store(int $subjectId, string $date, string $time = '09:00')
    {
        return ExamDate::create([
            'exam_id' => 1, 'my_class_id' => 1, 'subject_id' => $subjectId,
            'exam_date' => $date, 'start_time' => $time, 'note' => null,
        ]);
    }

    protected function rows()
    {
        return ExamDate::where('exam_id', 1)->where('my_class_id', 1)
            ->orderBy('subject_id')->get(['subject_id', 'exam_date', 'start_time'])
            ->map(function ($r) {
                return [(int) $r->subject_id, (string) $r->exam_date, substr((string) $r->start_time, 0, 5)];
            })->all();
    }

    protected function postSave(array $dates)
    {
        return $this->from($this->manageUrl())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => 1, 'dates' => $dates,
        ]);
    }

    public function test_save_with_new_past_date_refused_and_rows_unchanged()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-11-02');
        $this->store(2, '2026-11-03');
        $before = $this->rows();
        $name = Subject::find(1)->name;

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-10-01', 'start_time' => '09:00'],
            2 => ['exam_date' => '2026-11-03', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_danger',
            "Past dates cannot be set: {$name} — 2026-10-01 has already passed.");
        $this->assertSame($before, $this->rows());
    }

    public function test_save_with_new_same_session_clash_refused_and_rows_unchanged()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-11-02');
        $this->store(2, '2026-11-03');
        $before = $this->rows();
        $n1 = Subject::find(1)->name;
        $n2 = Subject::find(2)->name;

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
            2 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_danger',
            "Two papers cannot share one session: {$n1} and {$n2} are both on 2026-11-05 at 09:00. Move one to another session.");
        $this->assertSame($before, $this->rows());
    }

    public function test_save_grandfathered_past_date_succeeds()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-10-20'); // already past in the stored sheet
        $this->store(2, '2026-11-03');

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-10-20', 'start_time' => '09:00'], // unchanged past date
            2 => ['exam_date' => '2026-11-04', 'start_time' => '09:00'], // unrelated edit
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_success');
        $this->assertSame([
            [1, '2026-10-20', '09:00'],
            [2, '2026-11-04', '09:00'],
        ], $this->rows());
    }

    public function test_save_grandfathered_clash_succeeds()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-11-05'); // stored sheet already clashes
        $this->store(2, '2026-11-05');

        $resp = $this->postSave([
            1 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
            2 => ['exam_date' => '2026-11-05', 'start_time' => '09:00'],
            3 => ['exam_date' => '2026-11-06', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_success');
        $this->assertSame([
            [1, '2026-11-05', '09:00'],
            [2, '2026-11-05', '09:00'],
            [3, '2026-11-06', '09:00'],
        ], $this->rows());
    }

    public function test_save_with_tampered_subject_refused()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-11-02');
        $before = $this->rows();

        // Subject 7 belongs to Class 1, not Prep — a tampered form.
        $resp = $this->postSave([
            1 => ['exam_date' => '2026-11-02', 'start_time' => '09:00'],
            7 => ['exam_date' => '2026-11-03', 'start_time' => '09:00'],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_danger', 'Record Not Found');
        $this->assertSame($before, $this->rows());
    }

    public function test_save_with_nonexistent_exam_refused()
    {
        $this->actingAs($this->admin());

        $resp = $this->from($this->manageUrl())->post(route('exam_dates.save'), [
            'exam_id' => 99999, 'class_id' => 1, 'dates' => [],
        ]);
        $resp->assertRedirect($this->manageUrl());
        $resp->assertSessionHas('flash_danger', 'Record Not Found');
    }

    public function test_past_date_refusal_renders_toast_on_manage_page()
    {
        $this->actingAs($this->admin());
        $this->store(1, '2026-11-02');
        $name = Subject::find(1)->name;

        $this->followingRedirects()->from($this->manageUrl())->post(route('exam_dates.save'), [
            'exam_id' => 1, 'class_id' => 1,
            'dates' => [1 => ['exam_date' => '2026-10-01', 'start_time' => '09:00']],
        ])->assertOk()
          ->assertSee("msg : 'Past dates cannot be set: {$name} — 2026-10-01 has already passed.'", false);
    }
}
