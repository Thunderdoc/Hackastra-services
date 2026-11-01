<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\StudentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Section segment (owner order 2026-10-29): the Class Overview
 * detail compares the class's sections against each other and
 * against the class total — strength, attendance, marks
 * average, topper and at-risk per section — so a teacher can
 * see which section needs attention. Same per-student figures
 * as the class snapshot, grouped by section; nothing invented.
 */
class OwnerClassOverviewSectionsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_sees_section_comparison_with_strengths_summing_to_the_class()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $prep = 1; // Prep: sections A/B/C, 121 students.

        $response = $this->actingAs($admin)
            ->get(route('class_overview.index', ['class_id' => $prep]));
        $response->assertOk();
        $response->assertSee('Section comparison');
        $response->assertSee('Class total');

        $expected = StudentRecord::where('my_class_id', $prep)->where('grad', 0)
            ->selectRaw('section_id, COUNT(*) as c')->groupBy('section_id')
            ->pluck('c', 'section_id');
        $sum = 0;
        foreach (Section::where('my_class_id', $prep)->orderBy('name')->get() as $section) {
            $response->assertSee('Section ' . $section->name);
            $sum += (int) ($expected[$section->id] ?? 0);
        }
        $this->assertSame(121, $sum, 'Seed anchor: Prep strength');
        $response->assertSee('121');
    }

    public function test_teacher_sees_the_comparison_for_their_own_class_only()
    {
        $ananya = User::where('email', 'teacher@hackstra.app')->firstOrFail();

        $own = $this->actingAs($ananya)
            ->get(route('class_overview.index', ['class_id' => 1]));
        $own->assertOk();
        $own->assertSee('Section comparison');
        $own->assertSee('Section A');

        // A class she does not teach stays closed (existing rule).
        $this->actingAs($ananya)
            ->get(route('class_overview.index', ['class_id' => 2]))
            ->assertForbidden();
    }

    public function test_section_marks_and_topper_come_from_real_marks()
    {
        $admin = User::where('email', 'admin@hackstra.app')->firstOrFail();
        $response = $this->actingAs($admin)
            ->get(route('class_overview.index', ['class_id' => 1]));
        $response->assertOk();
        // Prep has Term 1 marks for every section (726 rows in
        // class 1): the comparison must show real percentages,
        // not dashes, and a named topper per section.
        $response->assertSee('Topper');
        $this->assertMatchesRegularExpression('/Section A.*?\\d+\\.\\d%/s', $response->getContent());
    }
}
