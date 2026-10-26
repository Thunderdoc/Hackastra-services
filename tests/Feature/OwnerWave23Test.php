<?php

namespace Tests\Feature;

use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Subject;
use App\Support\SchoolLevel;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Regression tests for the 2026-10-25 date-sheet session-level
 * separation: Primary (Prep–Class 5) papers never share a (day,
 * session) with Secondary/Senior (Class 6–12) papers, as a
 * default of the generator — no toggle.
 */
class OwnerWave23Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->first();
    }

    public function test_generate_form_notes_session_separation()
    {
        $this->actingAs($this->admin());
        $this->get(route('exam_dates.generate.form'))->assertOk()
            ->assertSee('kept in separate sessions', false);
    }

    public function test_generate_never_mixes_levels_in_one_session()
    {
        ExamDate::where('exam_id', 1)->delete();

        $this->actingAs($this->admin());
        $resp = $this->post(route('exam_dates.generate'), [
            'exam_id' => 1,
            'scope' => 'all',
            'start_date' => '2026-11-02',
            'exam_days' => 12,
            'papers_per_day' => 2,
            'session1_time' => '09:00',
            'session2_time' => '13:00',
            'mode' => 'skip',
        ]);
        $resp->assertRedirect(route('exam_dates.manage', ['exam_id' => 1]));
        $this->assertStringNotContainsString('Could not fit', (string) $resp->getSession()->get('flash_success'));

        $rows = ExamDate::where('exam_id', 1)->get();
        $this->assertNotEmpty($rows);
        // Every class got a paper for every one of its subjects.
        foreach (MyClass::orderBy('id')->get() as $class) {
            $expected = Subject::where('my_class_id', $class->id)->count();
            $this->assertSame($expected, $rows->where('my_class_id', $class->id)->count());
        }
        // No (date, session) mixes the two level groups.
        $seenGroups = [];
        foreach ($rows->groupBy(function ($r) { return $r->exam_date . '|' . substr((string) $r->start_time, 0, 5); }) as $slotRows) {
            $levels = $slotRows->map(function ($r) { return SchoolLevel::levelForClassId((int) $r->my_class_id); })->unique()->values()->all();
            $this->assertCount(1, $levels, 'Slot mixes levels: ' . $slotRows->first()->exam_date . ' ' . $slotRows->first()->start_time);
            $seenGroups[$levels[0]] = true;
        }
        $this->assertTrue($seenGroups['primary'] ?? false);
        $this->assertTrue($seenGroups['secondary'] ?? false);
    }
}
