<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Models\Substitution;
use App\Support\LeaveCover;
use App\Support\Staffing;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Teacher Workload Analysis (owner ask 2026-10-30: "workload
 * analysis nhi dikh rha" on the teacher's Academics menu).
 *
 * Until now the only workload surface was the Admin's Staffing
 * Dashboard (school-wide demand vs capacity). A teacher had no
 * way to see their own load. This page answers it from REAL
 * data only: the teacher's effective week grid — the exact
 * enumeration the leave queue uses (LeaveCover::weekGrid, so
 * section overrides count, whole-class rows count once) —
 * measured against the Settings caps, plus the cover duties
 * they have actually been confirmed for and the sections they
 * lead as class teacher. Own data only: role scoping is a
 * standing rule, so no other teacher's load is ever shown here.
 */
class WorkloadController extends Controller
{
    public function mine()
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $user = Auth::user();

        $grid = LeaveCover::weekGrid($user);
        $capDay = Staffing::maxPeriodsPerDay();
        $capWeek = Staffing::maxPeriodsPerWeek();

        $dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $byDay = [];
        foreach ($dayOrder as $day) {
            $byDay[$day] = 0;
        }
        $bySubject = [];
        foreach ($grid as $row) {
            if (isset($byDay[$row['day']])) {
                $byDay[$row['day']]++;
            }
            $key = $row['class'] . '|' . $row['section'] . '|' . $row['subject'];
            if (!isset($bySubject[$key])) {
                $bySubject[$key] = [
                    'class' => $row['class'],
                    'section' => $row['section'],
                    'subject' => $row['subject'],
                    'periods' => 0,
                ];
            }
            $bySubject[$key]['periods']++;
        }
        $bySubject = array_values($bySubject);
        usort($bySubject, function ($a, $b) {
            return $b['periods'] <=> $a['periods'];
        });
        // Days the school actually runs come first; a day with no
        // periods still shows so the week reads complete.
        $byDay = array_filter($byDay, function ($count, $day) use ($grid) {
            return $count > 0 || in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true);
        }, ARRAY_FILTER_USE_BOTH);

        $busiestDay = null;
        $busiestCount = 0;
        foreach ($byDay as $day => $count) {
            if ($count > $busiestCount) {
                $busiestCount = $count;
                $busiestDay = $day;
            }
        }
        $overDays = [];
        foreach ($byDay as $day => $count) {
            if ($count > $capDay) {
                $overDays[] = $day;
            }
        }

        $coverBase = Substitution::where('substitute_teacher_id', $user->id)
            ->where('status', 'confirmed');
        $coverTotal = (clone $coverBase)->count();
        $coverUpcoming = (clone $coverBase)->whereDate('date', '>=', Carbon::today())->count();

        $ledSections = Section::with('my_class')
            ->where('teacher_id', $user->id)
            ->get()
            ->map(function ($section) {
                return trim((optional($section->my_class)->name ?: '') . ' ' . $section->name);
            })->all();

        return view('pages.support_team.timetables.workload', [
            'grid' => $grid,
            'by_day' => $byDay,
            'by_subject' => $bySubject,
            'total' => count($grid),
            'cap_day' => $capDay,
            'cap_week' => $capWeek,
            'busiest_day' => $busiestDay,
            'busiest_count' => $busiestCount,
            'over_days' => $overDays,
            'cover_total' => $coverTotal,
            'cover_upcoming' => $coverUpcoming,
            'led_sections' => $ledSections,
        ]);
    }
}
