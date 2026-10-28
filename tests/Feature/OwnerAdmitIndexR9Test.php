<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Batch 2026-10-28 (exam-timetable round 9) — admit index null-exam 500.
 *
 * AdmitCardController@index set selected=true whenever BOTH query
 * params were present, but only looked the exam up afterwards — so a
 * stale bookmark / tampered URL with a nonexistent exam_id
 * (?exam_id=99999&class_id=1) reached admit_cards.blade.php with
 * $exam = null and died with a 500 at `{{ $exam->name }}`. The
 * sibling Manage page already guarded this (selected only when both
 * records resolve); the admit index did not. Duties index was checked
 * too and is already safe (isset($exam) guard in its blade).
 *
 * Fix: index() sets selected=true only when the exam resolves; a
 * nonexistent exam renders the plain unfiltered picker (same clean
 * empty state as Manage), never a 500.
 */
class OwnerAdmitIndexR9Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_index_with_nonexistent_exam_renders_picker_not_500()
    {
        $this->actingAs($this->admin())
            ->get(route('admit_cards.index', ['exam_id' => 99999, 'class_id' => 1]))
            ->assertOk()
            ->assertViewHas('selected', false);
    }

    public function test_index_with_nonexistent_exam_and_class_renders_picker_not_500()
    {
        $this->actingAs($this->admin())
            ->get(route('admit_cards.index', ['exam_id' => 99999, 'class_id' => 99999]))
            ->assertOk()
            ->assertViewHas('selected', false);
    }

    public function test_index_with_nonexistent_class_but_real_exam_stays_clean()
    {
        $this->actingAs($this->admin())
            ->get(route('admit_cards.index', ['exam_id' => 1, 'class_id' => 99999]))
            ->assertOk();
    }

    public function test_index_with_real_exam_and_class_still_selects()
    {
        $this->actingAs($this->admin())
            ->get(route('admit_cards.index', ['exam_id' => 1, 'class_id' => 1]))
            ->assertOk()
            ->assertViewHas('selected', true);
    }
}
