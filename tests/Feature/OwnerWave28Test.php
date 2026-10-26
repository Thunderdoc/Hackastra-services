<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wave 28: teacher week grid showed the same clock time once per
 * record (Ananya: 76 columns for 8 times, the 2:40 PM group alone
 * filling PERIOD 69-76) and the teacher filter over-attributed
 * section-grid cells to the class-level subject teacher (100
 * "periods", 31 same-time collisions). Fixed by effective-teacher
 * filtering (override wins) + clock-time slot dedupe.
 */
class OwnerWave28Test extends TestCase
{
    use DatabaseTransactions;

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->first();
    }

    public function test_week_grid_headers_are_unique_clock_times_with_contiguous_periods()
    {
        $response = $this->actingAs($this->teacher())->get(route('tt.mine'));
        $response->assertOk();
        $html = $response->getContent();

        preg_match_all('/l81-slot-time">([^<]+)</', $html, $timeMatches);
        $times = $timeMatches[1];
        $this->assertNotEmpty($times, 'Week grid should render slot headers');
        $this->assertSame(count($times), count(array_unique($times)), 'Week grid must not repeat a clock time');

        preg_match_all('/l81-slot-period">Period (\d+)</', $html, $periodMatches);
        $periods = array_map('intval', $periodMatches[1]);
        $this->assertSame($times === [] ? [] : range(1, count($times)), $periods, 'Period labels must be 1..N over the deduped slots');
    }

    public function test_teaching_periods_stat_counts_effective_teacher_only()
    {
        $teacherId = $this->teacher()->id;

        // Independent of applyTeacherFilter: effective teacher =
        // override when one exists for the cell's section+subject,
        // else the subject's class-level teacher.
        $expected = DB::table('time_tables as t')
            ->join('time_table_records as r', 'r.id', '=', 't.ttr_id')
            ->join('subjects as s', 's.id', '=', 't.subject_id')
            ->leftJoin('section_subject_teachers as o', function ($j) {
                $j->on('o.section_id', '=', 'r.section_id')->on('o.subject_id', '=', 't.subject_id');
            })
            ->whereNotNull('t.day')
            ->where(function ($q) use ($teacherId) {
                $q->where('o.teacher_id', $teacherId)
                    ->orWhere(function ($q2) use ($teacherId) {
                        $q2->where('s.teacher_id', $teacherId)->whereNull('o.id');
                    });
            })
            ->count();

        $this->assertLessThan(100, $expected, 'Sanity: the old over-attributed count was 100');

        $response = $this->actingAs($this->teacher())->get(route('tt.mine'));
        $response->assertOk();
        preg_match('/Teaching Periods<\/span>\s*<strong>(\d+)<\/strong>/', $response->getContent(), $m);
        $this->assertNotEmpty($m, 'Teaching Periods stat should render');
        $this->assertSame($expected, (int) $m[1]);
    }

    public function test_no_teacher_is_double_booked_at_one_day_and_time()
    {
        $cells = DB::table('time_tables as t')
            ->join('time_slots as ts', 'ts.id', '=', 't.ts_id')
            ->join('time_table_records as r', 'r.id', '=', 't.ttr_id')
            ->join('subjects as s', 's.id', '=', 't.subject_id')
            ->leftJoin('section_subject_teachers as o', function ($j) {
                $j->on('o.section_id', '=', 'r.section_id')->on('o.subject_id', '=', 't.subject_id');
            })
            ->whereNotNull('t.day')
            ->get(['t.day', 'ts.full', 's.teacher_id', 'o.teacher_id as override_teacher']);

        $seen = [];
        foreach ($cells as $c) {
            $tid = $c->override_teacher ?: $c->teacher_id;
            if (!$tid) {
                continue;
            }
            $key = $tid . '|' . $c->day . '|' . $c->full;
            $this->assertArrayNotHasKey($key, $seen, "Teacher {$tid} double-booked on {$c->day} {$c->full}");
            $seen[$key] = true;
        }
        $this->assertNotEmpty($seen);
    }
}
