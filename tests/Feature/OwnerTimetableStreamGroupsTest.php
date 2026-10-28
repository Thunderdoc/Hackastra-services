<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Repositories\MyClassRepo;
use App\Support\Streams;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Timetable generate page redesign (owner order 2026-10-28):
 * Classes 11–12 subjects group by CBSE stream (Common, PCM, PCB,
 * shared PCM & PCB, Commerce, Arts) instead of one flat mixed list,
 * and classes list in class order (Prep, Class 1 … Class 12) site
 * wide — not string order ("Class 10" before "Class 2", "Prep"
 * last). The POST contract is unchanged: subjects[class_id][] +
 * subjects_present[class_id], so generation logic is untouched.
 * Runs on a snapshot DB; nothing writes to the shared DB.
 */
class OwnerTimetableStreamGroupsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    public function test_stream_groups_for_class_11()
    {
        $subjects = Subject::where('my_class_id', 12)->orderBy('id')->get();
        $groups = Streams::groupForDisplay($subjects);

        $this->assertSame(['common', 'pcm', 'shared', 'pcb', 'commerce', 'arts'], array_column($groups, 'key'));

        $byKey = [];
        foreach ($groups as $group) {
            $byKey[$group['key']] = $group['subjects']->pluck('name')->all();
        }
        $this->assertSame(['English Language', 'Hindi'], $byKey['common']);
        $this->assertSame(['Mathematics'], $byKey['pcm']);
        // Physics + Chemistry are tagged pcm,pcb: exactly once, shared.
        $this->assertSame(['Physics', 'Chemistry'], $byKey['shared']);
        $this->assertSame(['Biology'], $byKey['pcb']);
        $this->assertSame(['Accountancy', 'Business Studies', 'Economics'], $byKey['commerce']);
        $this->assertSame(['History', 'Political Science', 'Geography'], $byKey['arts']);

        $allIds = collect($groups)->flatMap(function ($group) {
            return $group['subjects']->pluck('id');
        });
        $this->assertSame(12, $allIds->count());
        $this->assertSame(12, $allIds->unique()->count(), 'No subject may appear in two stream groups.');
    }

    public function test_unstreamed_class_has_single_common_group()
    {
        $subjects = Subject::where('my_class_id', 2)->orderBy('id')->get();
        $groups = Streams::groupForDisplay($subjects);

        $this->assertSame(['common'], array_column($groups, 'key'));
        $this->assertSame(6, $groups[0]['subjects']->count());
    }

    public function test_class_list_is_in_class_order_site_wide()
    {
        $names = app(MyClassRepo::class)->all()->pluck('name')->all();

        $this->assertSame([
            'Prep',
            'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5',
            'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10',
            'Class 11', 'Class 12',
        ], $names);
    }

    public function test_generate_form_renders_stream_groups_in_class_order()
    {
        $page = $this->actingAs($this->admin())
            ->get(route('tt.generate.form'))
            ->assertOk()
            ->getContent();

        foreach (['PCM (Science – Maths)', 'PCB (Science – Biology)', 'Commerce', 'Arts', 'PCM &amp; PCB (shared)', 'Primary — Prep to Class 5', 'Secondary — Class 6 to Class 12'] as $needle) {
            $this->assertStringContainsString($needle, $page);
        }

        // Class order in the rendered picker: Prep, then 1, 2 … 10, 11, 12.
        $positions = [];
        foreach (['Prep —', 'Class 1 —', 'Class 2 —', 'Class 10 —', 'Class 11 —', 'Class 12 —'] as $label) {
            $pos = strpos($page, $label);
            $this->assertNotFalse($pos, "Missing class group: {$label}");
            $positions[] = $pos;
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Class groups are not in numeric order.');

        // POST contract unchanged: per-class subject arrays + present markers.
        $this->assertStringContainsString('name="subjects[12][]"', $page);
        $this->assertStringContainsString('name="subjects_present[12]"', $page);
        $this->assertStringContainsString('id="tt-subject-filter"', $page);
    }
}
