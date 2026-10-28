<?php

namespace Tests\Feature;

use App\Models\MyClass;
use App\Models\Section;
use App\Models\Subject;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Agent F round 14 — round-13 fix end-to-end on rendered pages.
 *
 * Round 13 proved the Allocation/Staffing LAYER excludes subjects
 * no section of their class offers. But the planning pages could
 * in principle recompute demand in the blade or pass a different
 * universe — so this round proves the PAGES an admin actually
 * reads: planning/allocation and planning/staffing.
 *
 * Fixture (same shape as round 13): a probe class with ONLY pcm
 * sections, holding an unstaffed common subject, an unstaffed pcm
 * subject and an unstaffed commerce subject. Distinct names keep
 * every assertion exact — no seeded subject shares the prefix.
 */
class OwnerAllotmentRound14Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function setupPageFixture(): void
    {
        $class = MyClass::create(['name' => 'ProbePageClass']);
        Section::create(['name' => 'A', 'my_class_id' => $class->id, 'stream' => 'pcm']);
        Section::create(['name' => 'B', 'my_class_id' => $class->id, 'stream' => 'pcm']);
        foreach ([['ProbePage English', null], ['ProbePage Maths', 'pcm'], ['ProbePage Accountancy', 'commerce']] as [$name, $stream]) {
            Subject::create([
                'name' => $name,
                'my_class_id' => $class->id,
                'teacher_id' => null,
                'slug' => Str::slug($name) . '-' . $class->id,
                'periods_per_week' => 6,
                'stream' => $stream,
            ]);
        }
    }

    public function test_allocation_page_excludes_unoffered_subject_but_lists_offered()
    {
        $this->setupPageFixture();

        $this->actingAs($this->admin())
            ->get(route('planning.allocation'))
            ->assertOk()
            ->assertSee('ProbePage English')
            ->assertSee('ProbePage Maths')
            ->assertDontSee('ProbePage Accountancy');
    }

    public function test_staffing_page_excludes_unoffered_subject_but_lists_offered()
    {
        $this->setupPageFixture();

        $this->actingAs($this->admin())
            ->get(route('planning.staffing'))
            ->assertOk()
            ->assertSee('ProbePage English')
            ->assertSee('ProbePage Maths')
            ->assertDontSee('ProbePage Accountancy');
    }
}
