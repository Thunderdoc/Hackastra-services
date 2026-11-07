<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\TeacherSlotPreference;
use App\Support\SchoolLevel;
use App\Support\SectionTeacher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * My Slot Preferences (owner ask 2026-11-06, audit gap G2:
 * "teacher ko sirf uske section ka slot hi choose karne ka
 * option aaye").
 *
 * Until now a teacher had no slot surface at all — every
 * timetable write was Admin-only, so there was nothing to scope;
 * this page is the first slot-choice surface, scoped from birth:
 *
 * - TEACHER-ONLY, both actions: the routes carry no team
 *   middleware, so each action guards itself here (the
 *   WorkloadController::mine pattern). The Admin does NOT edit a
 *   teacher's preferences — there is no admin view of this page
 *   at all.
 * - The slots a teacher can pick live inside the frame of HER
 *   effective sections (SectionTeacher::unitSectionIdsByClass —
 *   the same scoping the picker forms use): the page shows those
 *   sections as context, and a teacher with no sections gets an
 *   honest empty state instead of a grid she cannot use.
 * - Preferences are (weekday, period) requests, replaced as a
 *   set on save, at most TeacherSlotPreference::MAX_PER_TEACHER.
 *   The generator consumes them as a soft tie-break ONLY (see
 *   TimeTableController::generate) — the page says so plainly:
 *   a preference is a request, never a guarantee, and it can
 *   never override a clash, a cap or a weekly target.
 */
class SlotPreferenceController extends Controller
{
    private const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    public function index()
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $user = Auth::user();

        [$days, $slots] = $this->grid();

        $d['days'] = $days;
        $d['slots'] = $slots;
        $d['max'] = TeacherSlotPreference::MAX_PER_TEACHER;
        $d['selected'] = TeacherSlotPreference::keysFor((int) $user->id);
        $d['sections'] = $this->sectionChips($user);

        return view('pages.support_team.timetables.slot_preferences', $d);
    }

    public function update(Request $request)
    {
        if (!Qs::userIsTeacher()) {
            abort(403, __('msg.denied'));
        }
        $user = Auth::user();

        // Only cells the grid actually offers can be saved — a
        // crafted post naming another day or a period the school
        // day does not have is filtered out before counting, so
        // the cap below judges real choices only.
        [$days, $slots] = $this->grid();
        $valid = [];
        foreach ($days as $day) {
            foreach ($slots as $slot) {
                $valid[TeacherSlotPreference::key($day, $slot['period'])] = [$day, $slot['period']];
            }
        }
        $chosen = [];
        foreach ((array) $request->input('slots', []) as $key) {
            if (is_string($key) && isset($valid[$key])) {
                $chosen[$key] = $valid[$key];
            }
        }

        if (count($chosen) > TeacherSlotPreference::MAX_PER_TEACHER) {
            return back()->with('flash_danger', 'You can choose at most '
                . TeacherSlotPreference::MAX_PER_TEACHER . ' preferred slots — you picked '
                . count($chosen) . '. Nothing was saved; trim the list and save again.');
        }

        DB::transaction(function () use ($user, $chosen) {
            TeacherSlotPreference::where('user_id', $user->id)->delete();
            foreach ($chosen as [$day, $period]) {
                TeacherSlotPreference::create([
                    'user_id' => $user->id, 'day' => $day, 'period' => $period,
                ]);
            }
        });

        return redirect()->route('tt.slot_preferences')
            ->with('flash_success', 'Slot preferences saved. The generator will try to place your periods in these slots when everything else is equal.');
    }

    /**
     * The grid the page offers, from the school-day configs the
     * generator itself uses (SchoolLevel): the longer of the two
     * level days (the standard 8 periods on the seed) with that
     * level's start/break/lunch shape, over the wider day count.
     * Returns [day names, slots] where each slot is
     * ['period' => 1-based number, 'label' => '9:00 AM – 9:40 AM'].
     */
    private function grid(): array
    {
        $primary = SchoolLevel::loadConfig('primary');
        $secondary = SchoolLevel::loadConfig('secondary');
        $cfg = (int) $secondary['periods_per_day'] >= (int) $primary['periods_per_day']
            ? $secondary : $primary;
        $dayCount = max((int) $primary['days'], (int) $secondary['days']);
        $days = array_slice(self::DAY_NAMES, 0, min(6, $dayCount));

        $cursor = Carbon::parse('2026-01-05 ' . $cfg['start_time']); // a Monday
        $slots = [];
        for ($i = 1; $i <= (int) $cfg['periods_per_day']; $i++) {
            $from = $cursor->copy();
            $to = $cursor->copy()->addMinutes((int) $cfg['period_minutes']);
            $slots[] = [
                'period' => $i,
                'label' => $from->format('g:i A') . ' – ' . $to->format('g:i A'),
            ];
            $cursor = $to->copy();
            if ($i === (int) $cfg['lunch_after']) {
                $cursor->addMinutes((int) $cfg['lunch_minutes']);
            }
            if (!empty($cfg['break_after']) && $i === (int) $cfg['break_after']) {
                $cursor->addMinutes((int) ($cfg['break_minutes'] ?? 15));
            }
        }

        return [$days, $slots];
    }

    /**
     * Her effective sections as context chips — the sections the
     * picked slots are FOR. Only sections where she is the
     * effective teacher of at least one subject can appear
     * (SectionTeacher::unitSectionIdsByClass); there is no path
     * by which another teacher's section is listed here.
     */
    private function sectionChips($user): array
    {
        $byClass = SectionTeacher::unitSectionIdsByClass($user);
        $classes = MyClass::whereIn('id', array_keys($byClass))->get()->keyBy('id');
        $chips = [];
        foreach ($byClass as $classId => $sectionIds) {
            $sections = Section::whereIn('id', $sectionIds)->orderBy('name')->get();
            foreach ($sections as $section) {
                $chips[] = [
                    'class' => $classes[$classId]->name ?? '',
                    'section' => $section->name,
                    'label' => trim(($classes[$classId]->name ?? '') . ' · ' . $section->name),
                ];
            }
        }
        return $chips;
    }
}
