<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\TimeTable\TSRequest;
use App\Http\Requests\TimeTable\TTRecordRequest;
use App\Http\Requests\TimeTable\TTRequest;
use App\Http\Requests\TimeTable\UseTimeSlotRequest;
use App\Models\AttendanceSession;
use App\Support\TimetableValidator;
use App\Models\Exam;
use App\Models\MyClass;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\TimeSlot;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Repositories\ExamRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\TimeTableRepo;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\SchoolLevel;
use App\Support\SectionTeacher;
use App\Support\Streams;
use App\Support\TeacherScope;
use App\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class TimeTableController extends Controller
{
    protected $tt, $my_class, $exam, $year;

    public function __construct(TimeTableRepo $tt, MyClassRepo $mc, ExamRepo $exam)
    {
        $this->tt = $tt;
        $this->my_class = $mc;
        $this->exam = $exam;
        $this->year = Qs::getCurrentSession();
    }

    /**
     * Timetable domain scope (owner doctrine, 2026-10-22: every role
     * sees only its own domain, server-side, direct URLs included —
     * a student was seeing every class's timetable on tt.index).
     *
     * Returns the class ids the signed-in user may see timetables
     * for, or:
     *   null  — unrestricted (teamSA: Admin runs the school; the
     *           Super Admin never reaches these actions — PlatformOnly
     *           redirects it to the platform overview first);
     *   false — no timetable domain at all (accountant, librarian,
     *           any other role): index redirects away, record URLs 403.
     *
     * Student = their own class (student record); Parent = their
     * children's classes; Teacher = TeacherScope (subjects.teacher_id
     * ∪ sections.teacher_id — the canonical helper).
     *
     * @return int[]|null|false
     */
    protected function timetableScopeClassIds()
    {
        $user = Auth::user();

        if (Qs::userIsTeamSA() || Qs::userIsPrincipal()) {
            return null;
        }

        if (Qs::userIsStudent()) {
            $sr = Qs::findStudentRecord($user->id);

            return $sr ? [(int) $sr->my_class_id] : [];
        }

        if (Qs::userIsParent()) {
            return Qs::findMyChildren($user->id)
                ->pluck('my_class_id')
                ->map(function ($id) { return (int) $id; })
                ->unique()->values()->all();
        }

        if (Qs::userIsTeacher()) {
            return TeacherScope::classIds($user);
        }

        return false;
    }

    /** Record-level guard for show/print: foreign class => 403. */
    protected function guardRecordScope($ttr)
    {
        $scope = $this->timetableScopeClassIds();

        if ($scope === null) {
            return;
        }

        if ($scope === false || !in_array((int) $ttr->my_class_id, $scope, true)) {
            abort(403, __('msg.denied'));
        }
    }

    public function index(Request $req)
    {
        $scope = $this->timetableScopeClassIds();

        if ($scope === false) {
            return redirect()->route('dashboard')->with('pop_error', __('msg.denied'));
        }

        $d['exams'] = $this->exam->getExam(['year' => $this->year]);
        $d['my_classes'] = $this->my_class->all();
        $all = $this->tt->getAllRecords();

        /* Scoped roles: the records, the summary cards, the year list
           and the class filter options all derive from the scoped set
           only, so no other class's name or counts can leak. */
        if ($scope !== null) {
            $all = $all->whereIn('my_class_id', $scope)->values();
            $d['my_classes'] = $d['my_classes']->whereIn('id', $scope)->values();
        }

        /* Timetable approval (owner order 2026-10-29): only the
           Admin and the Principal see draft / submitted timetables.
           Everyone else sees timetables that are in force. */
        if (!Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            $all = $all->filter(function ($ttr) { return $ttr->isInForce(); })->values();
        }

        $d['tt_records_all'] = $all;

        /* Loop 81: summary cards — module totals over every record
           (unfiltered; the filters below narrow the list only). There
           is no status column on time_table_records, so "Active" is
           derived honestly: the record's year IS the current session. */
        $d['stats'] = [
            'total'   => $all->count(),
            'active'  => $all->where('year', $this->year)->count(),
            'classes' => $all->pluck('my_class_id')->unique()->count(),
            'session' => $this->year,
        ];
        $d['years'] = $all->pluck('year')->unique()->sortDesc()->values();

        /* Loop 81: server-side filters (search / class / year) that
           combine; the view groups whatever survives by class. */
        $d['f_q'] = trim((string) $req->query('q', ''));
        $d['f_class'] = $req->query('class_id');
        $d['f_year'] = $req->query('year');

        $d['tt_records'] = $all->filter(function ($ttr) use ($d) {
            if ($d['f_q'] !== '') {
                $q = mb_strtolower($d['f_q']);
                $inName = mb_strpos(mb_strtolower((string) $ttr->name), $q) !== false;
                $inClass = $ttr->my_class && mb_strpos(mb_strtolower((string) $ttr->my_class->name), $q) !== false;
                if (!$inName && !$inClass) {
                    return false;
                }
            }
            if ($d['f_class'] !== null && $d['f_class'] !== '' && (int) $ttr->my_class_id !== (int) $d['f_class']) {
                return false;
            }
            if ($d['f_year'] !== null && $d['f_year'] !== '' && (string) $ttr->year !== (string) $d['f_year']) {
                return false;
            }
            return true;
        })->values();

        /* Owner redesign (2026-10-23): the list renders as CLASS
           CARDS — every class with its sections and timetable
           state — so these aggregates ride along: sections grouped
           by class, and per-record period/subject counts. */
        $d['sections_by_class'] = Section::orderBy('name')
            ->get()->groupBy('my_class_id');
        $d['record_stats'] = [];
        $slotCounts = TimeSlot::select('ttr_id')->get()->groupBy('ttr_id')->map->count();
        foreach (TimeTable::select('ttr_id', 'subject_id', 'day')->get()->groupBy('ttr_id') as $ttrId => $cells) {
            // Free = grid capacity minus placed cells: the record's
            // own slots × its days, so the admin sees allocation
            // quality (empty periods) right on the card.
            $capacity = ($slotCounts[$ttrId] ?? 0) * $cells->pluck('day')->unique()->count();
            $d['record_stats'][$ttrId] = [
                'periods' => $cells->count(),
                'subjects' => $cells->pluck('subject_id')->unique()->count(),
                'free' => max(0, $capacity - $cells->count()),
            ];
        }

        /* Wave 7 — green validation: for the Admin, the current
           session's records whose validator verdict is clean (zero
           errors), computed live by the same validator the Validate
           page uses — never stored, so an edited timetable can
           never show a stale green. Other roles get no validation
           UI at all (owner fix 2026-10-25). */
        $d['validated_ids'] = Qs::userIsTeamSAWrite()
            ? TimetableValidator::cleanRecordIds($this->year)
            : [];

        return view('pages.support_team.timetables.index', $d);
    }

    /**
     * AUTO-GENERATE timetables (owner's ADMIN-LOAD-ZERO law,
     * 2026-10-23): the system asks the Admin only for the school
     * day basics — start time, period length, periods per day,
     * lunch and break placement — then builds a complete,
     * clash-free timetable for every class from its subjects and
     * their teachers. The Admin reviews, edits any period on the
     * Manage page, and is done; nobody hand-builds a grid.
     */
    public function generateForm(Request $req)
    {
        $d['preset_class'] = $req->query('class_id');
        $d['my_classes'] = $this->my_class->all();
        $presetClassId = old('class_id', $d['preset_class']);
        $d['preset_sections'] = $presetClassId
            ? Section::where('my_class_id', (int) $presetClassId)->orderBy('name')->get()
            : collect();
        // Batch upgrade 2026-10-25: level presets (last-used values
        // remembered) + the per-class subject picker. The single
        // school-day defaults follow the preset class's level.
        $d['level_defaults'] = [
            'primary' => SchoolLevel::loadConfig('primary'),
            'secondary' => SchoolLevel::loadConfig('secondary'),
        ];
        $d['class_levels'] = SchoolLevel::levelsForClasses($d['my_classes']);
        $level = $presetClassId ? SchoolLevel::levelForClassId((int) $presetClassId) : 'primary';
        $d['defaults'] = $d['level_defaults'][$level];
        $d['subject_groups'] = collect($d['my_classes'])->map(function ($c) {
            return [
                'class' => $c,
                'subjects' => Subject::where('my_class_id', $c->id)->orderBy('id')->get(),
            ];
        });

        return view('pages.support_team.timetables.generate', $d);
    }

    /**
     * Build the teaching slots for one school-day config: teaching
     * slots only; lunch and the short break simply move the clock
     * forward. Returns [slots, runsPast930] — a day running past
     * 9:30 PM is a form mistake, not a timetable.
     */
    protected function buildSlots(array $cfg): array
    {
        $cursor = Carbon::parse('2026-01-05 ' . $cfg['start_time']); // a Monday
        $slots = [];
        for ($i = 1; $i <= $cfg['periods_per_day']; $i++) {
            $from = $cursor->copy();
            $to = $cursor->copy()->addMinutes($cfg['period_minutes']);
            $slots[] = ['from' => $from, 'to' => $to];
            $cursor = $to->copy();
            if ($i === (int) $cfg['lunch_after']) {
                $cursor->addMinutes($cfg['lunch_minutes']);
            }
            if (!empty($cfg['break_after']) && $i === (int) $cfg['break_after']) {
                $cursor->addMinutes($cfg['break_minutes'] ?? 15);
            }
        }
        $lastSlot = end($slots);
        $runsPast = $lastSlot && ($lastSlot['to']->format('Y-m-d') !== '2026-01-05'
            || $lastSlot['to']->gt(Carbon::parse('2026-01-05 21:30')));
        return [$slots, (bool) $runsPast];
    }

    public function generate(Request $req)
    {
        $rules = [
            'start_time' => 'required|date_format:H:i',
            'period_minutes' => 'required|integer|min:20|max:90',
            'periods_per_day' => 'required|integer|min:4|max:12',
            'lunch_after' => 'required|integer|min:1|max:12',
            'lunch_minutes' => 'required|integer|min:15|max:90',
            'break_after' => 'nullable|integer|min:0|max:12',
            'break_minutes' => 'nullable|integer|min:5|max:45',
            'days' => 'required|integer|in:5,6',
            // Owner order 2026-10-28: a teacher must get free slots
            // — the admin may cap teaching periods per teacher per
            // day (empty = no cap, as before). A capped period stays
            // empty and is reported, never silently filled.
            'max_teacher_periods_per_day' => 'nullable|integer|min:1|max:12',
            'scope' => 'required|in:all,one',
            /* Agent D round 2: a nonexistent class used to sail
               through and report "0 classes, 0 periods". */
            'class_id' => 'nullable|integer|exists:my_classes,id',
            'section_id' => 'nullable|integer|exists:sections,id',
            'mode' => 'required|in:skip,replace',
            // Batch upgrade 2026-10-25: per-level day configs + the
            // per-class subject picker (absent = old form post).
            'subjects' => 'nullable|array',
            'subjects.*' => 'array',
            'subjects.*.*' => 'integer|exists:subjects,id',
            'subjects_present' => 'nullable|array',
        ];
        foreach (['primary', 'secondary'] as $lv) {
            $rules[$lv . '_start_time'] = 'nullable|date_format:H:i';
            $rules[$lv . '_period_minutes'] = 'nullable|integer|min:20|max:90';
            $rules[$lv . '_periods_per_day'] = 'nullable|integer|min:4|max:12';
            $rules[$lv . '_lunch_after'] = 'nullable|integer|min:1|max:12';
            $rules[$lv . '_lunch_minutes'] = 'nullable|integer|min:15|max:90';
            $rules[$lv . '_break_after'] = 'nullable|integer|min:0|max:12';
            $rules[$lv . '_break_minutes'] = 'nullable|integer|min:5|max:45';
            $rules[$lv . '_days'] = 'nullable|integer|in:5,6';
        }
        $data = $req->validate($rules);
        foreach (['period_minutes', 'periods_per_day', 'lunch_after', 'lunch_minutes', 'break_after', 'break_minutes', 'days',
                  'primary_period_minutes', 'primary_periods_per_day', 'primary_lunch_after', 'primary_lunch_minutes', 'primary_break_after', 'primary_break_minutes', 'primary_days',
                  'secondary_period_minutes', 'secondary_periods_per_day', 'secondary_lunch_after', 'secondary_lunch_minutes', 'secondary_break_after', 'secondary_break_minutes', 'secondary_days',
                  'max_teacher_periods_per_day'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = (int) $data[$k];
            }
        }
        if ($data['scope'] === 'one' && empty($data['class_id'])) {
            return back()->with('flash_danger', 'Choose the class to generate for.')->withInput();
        }

        // Section scope (owner build 2026-10-25): a section grid
        // replaces the class grid for that section only. A section
        // target must belong to the chosen class. With any other
        // scope the section choice is ignored, not an error.
        $sectionId = !empty($data['section_id']) ? (int) $data['section_id'] : null;
        $section = null;
        if ($data['scope'] !== 'one') {
            $sectionId = null;
        }
        if ($sectionId) {
            if (empty($data['class_id'])) {
                return back()->with('flash_danger', 'Choose the class the section belongs to.')->withInput();
            }
            $section = Section::find($sectionId);
            if (!$section || (int) $section->my_class_id !== (int) $data['class_id']) {
                return back()->with('flash_danger', 'That section does not belong to the chosen class.')->withInput();
            }
        }

        /* Agent D round 2: posted subjects must belong to their
           class — a crafted post could otherwise plant another
           class's subject (and teacher) into this grid. */
        foreach (($data['subjects'] ?? []) as $postedClassId => $postedSubjectIds) {
            if (!empty($postedSubjectIds)
                && Subject::whereIn('id', array_map('intval', (array) $postedSubjectIds))
                    ->where('my_class_id', '!=', (int) $postedClassId)->exists()) {
                return back()->with('flash_danger', 'The chosen subjects must belong to their class — a subject from another class cannot be placed here.')->withInput();
            }
        }

        /* Agent D round 2: a FULLY-streamed class has no whole-class
           grid to build — generating one without a section would
           mix every stream's subjects (the exact bug fixed for
           scope=all in 2026-10-26). Refuse and point at the section
           picker. A mixed class keeps its class grid for untagged
           sections, and a section-less class is unaffected. */
        if ($data['scope'] === 'one' && !$sectionId && !empty($data['class_id'])) {
            $clsSections = Section::where('my_class_id', (int) $data['class_id'])->get();
            if ($clsSections->isNotEmpty() && $clsSections->every(function ($sec) { return !empty($sec->stream); })) {
                return back()->with('flash_danger', 'This class is fully streamed — choose a section to generate its grid. A whole-class grid would mix every stream\'s subjects.')->withInput();
            }
        }

        // Batch upgrade 2026-10-25: one school-day config per level
        // for scope=all (each level's own form block; a missing
        // block falls back to the single fields, so old posts keep
        // working). Scope=one keeps the single config exactly.
        $singleCfg = [
            'start_time' => $data['start_time'], 'period_minutes' => $data['period_minutes'],
            'periods_per_day' => $data['periods_per_day'], 'lunch_after' => $data['lunch_after'],
            'lunch_minutes' => $data['lunch_minutes'], 'break_after' => $data['break_after'] ?? 0,
            'break_minutes' => $data['break_minutes'] ?? 15, 'days' => $data['days'],
        ];
        $levelCfgs = [];
        foreach (['primary', 'secondary'] as $lv) {
            $cfg = [];
            foreach ($singleCfg as $k => $v) {
                $cfg[$k] = $data[$lv . '_' . $k] ?? $v;
            }
            $levelCfgs[$lv] = $cfg;
        }
        // Sanity clamp (break-it pass, 2026-10-23): a school day that
        // runs past 9:30 PM is a form mistake, not a timetable —
        // refuse it instead of generating a nonsense grid.
        foreach (array_merge([$singleCfg], array_values($levelCfgs)) as $cfg) {
            [, $runsPast] = $this->buildSlots($cfg);
            if ($runsPast) {
                return back()->with('flash_danger', 'That school day would run past 9:30 PM — reduce the periods, shorten them, or start earlier.')->withInput();
            }
            /* Agent D round 2: lunch/break AFTER the last period
               builds a gap with no following slot, so the grid
               shows no Lunch/Break column at all — silently. A
               break must fall inside the day to be visible. */
            if ((int) $cfg['lunch_after'] >= (int) $cfg['periods_per_day']) {
                return back()->with('flash_danger', 'Lunch must fall inside the school day — lunch_after must be less than periods_per_day.')->withInput();
            }
            if (!empty($cfg['break_after']) && (int) $cfg['break_after'] >= (int) $cfg['periods_per_day']) {
                return back()->with('flash_danger', 'The short break must fall inside the school day — break_after must be less than periods_per_day.')->withInput();
            }
        }
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $classes = $data['scope'] === 'one'
            ? MyClass::where('id', $data['class_id'])->get()
            : MyClass::orderBy('id')->get();

        // Generation targets: (class, section?) pairs, each with
        // its own day config + slots. A section target builds only
        // that section's grid; every other target builds the
        // whole-class grid as before.
        $targets = [];
        $classLevels = SchoolLevel::levelsForClasses($classes);
        foreach ($classes as $c) {
            $cfg = $data['scope'] === 'one' ? $singleCfg : $levelCfgs[$classLevels[$c->id]];
            [$targetSlots, ] = $this->buildSlots($cfg);
            // Batch 2026-10-26: scope=all must EXPAND a streamed
            // class into section targets — a class grid for Class
            // 11/12 would mix every stream's subjects (and, in skip
            // mode, the old code skipped nothing because streamed
            // classes have no class grid at all, so it built bogus
            // mixed grids). A fully-streamed class yields section
            // targets only; a mixed class yields its section
            // targets first (they generate first, so their cells
            // then block the fallback class grid for shared
            // teachers) plus the class target for untagged sections.
            if ($data['scope'] === 'all') {
                $streamed = Section::where('my_class_id', $c->id)
                    ->whereNotNull('stream')->orderBy('id')->get();
                if ($streamed->isNotEmpty()) {
                    foreach ($streamed as $sec) {
                        $targets[] = [
                            'class' => $c, 'section_id' => $sec->id, 'section' => $sec,
                            'cfg' => $cfg, 'slots' => $targetSlots,
                        ];
                    }
                    $allSections = Section::where('my_class_id', $c->id)->count();
                    if ($streamed->count() < $allSections) {
                        $targets[] = [
                            'class' => $c, 'section_id' => null, 'section' => null,
                            'cfg' => $cfg, 'slots' => $targetSlots,
                        ];
                    }
                    continue;
                }
            }
            $targets[] = [
                'class' => $c,
                'section_id' => ($data['scope'] === 'one' ? $sectionId : null),
                'section' => ($data['scope'] === 'one' ? $section : null),
                'cfg' => $cfg,
                'slots' => $targetSlots,
            ];
        }

        /* Slice D follow-up (2026-10-23, found by the validator):
           the busy map must also respect timetables that already
           exist and are NOT being regenerated in this run — a
           single-class regeneration used to be blind to every other
           class's record and could double-book their teachers.
           Kept records (out of scope, skip mode, or attendance-
           locked) preload an interval busy map; placement checks
           it. Agent D round 2: intervals, not start minutes —
           levels with different period lengths overlap without
           sharing a start (9:00-9:40 vs 9:30-10:00), and the old
           start-minute map let those through. The validator
           remains the final check. Section build (2026-10-25):
           only the records actually being rebuilt are excluded
           from the preload — section grids stay blocking when a
           class grid is regenerated, because a teacher cannot
           teach both at once. Kept cells resolve their teacher
           through the record's section, so section overrides count
           as busy. */
        $busyIntervals = []; // [teacher_id][day] = [[fromMinute, toMinute], ...]
        $targetKeys = [];
        foreach ($targets as $t) {
            $targetKeys[$t['class']->id . ':' . ($t['section_id'] ?? '')] = true;
        }
        // Section supersede (2026-10-25, extended agent D round 2
        // to EVERY section target): a section grid REPLACES the
        // class grid for that section, so the same class's class
        // grid must not block the section's own generation — the
        // section's teachers would otherwise never be placeable
        // (the class grid already books them at every slot). The
        // old code did this only for scope=one, so a scope=all
        // skip run over a mixed class starved every section grid.
        // The class grid stays live for the OTHER sections, and
        // the validator flags any resulting double-booking, so the
        // admin sees it and generates those sections too (or
        // deletes the class grid once every section has its own).
        $supersededIds = [];
        foreach ($targets as $t) {
            if ($t['section_id']) {
                $classGrid = TimeTableRecord::where('my_class_id', $t['class']->id)
                    ->whereNull('section_id')->whereNull('exam_id')->where('year', $this->year)->first();
                if ($classGrid) {
                    $supersededIds[$classGrid->id] = true;
                }
            }
        }
        $existingRecords = TimeTableRecord::whereNull('exam_id')->where('year', $this->year)->get();
        // Teacher day load, preloaded from the same kept cells:
        // the placer spreads a teacher's periods across days (and
        // the daily cap below counts kept periods too, so a cap is
        // real load, not just this run's load).
        $teacherDayCount = [];
        foreach ($existingRecords as $existing) {
            if (isset($supersededIds[$existing->id])) {
                continue; // superseded by the section grid being built
            }
            if ($data['mode'] === 'replace'
                && isset($targetKeys[$existing->my_class_id . ':' . ($existing->section_id ?? '')])) {
                $locked = TimeTable::where('ttr_id', $existing->id)
                    ->whereIn('id', AttendanceSession::pluck('time_table_id'))->exists();
                if (!$locked) {
                    continue; // this record is being rebuilt below
                }
            }
            foreach (TimeTable::where('ttr_id', $existing->id)->with('subject')->get() as $cell) {
                $teacherId = SectionTeacher::teacherIdFor($existing->section_id ?? null, $cell->subject);
                if ($cell->subject && $teacherId) {
                    /* Agent D round 2: the weekday comes from the
                       day NAME when day_num is NULL (legacy manual
                       cells) — the old code keyed the busy map on
                       raw day_num, so a NULL cell landed on a
                       phantom '' day and never blocked anything,
                       while the day-count below DID count it. */
                    $dayIdx = array_search($cell->day, $dayNames);
                    $cellDay = $cell->day_num ?: ($dayIdx !== false ? $dayIdx + 1 : null);
                    if ($cellDay) {
                        $fromMin = (int) date('H', (int) $cell->timestamp_from) * 60 + (int) date('i', (int) $cell->timestamp_from);
                        $toMin = (int) date('H', (int) $cell->timestamp_to) * 60 + (int) date('i', (int) $cell->timestamp_to);
                        $busyIntervals[$teacherId][$cellDay][] = [$fromMin, $toMin];
                        $teacherDayCount[$teacherId][$cellDay] = ($teacherDayCount[$teacherId][$cellDay] ?? 0) + 1;
                    }
                }
            }
        }
        $summary = ['generated' => [], 'generated_section_count' => 0, 'skipped' => [], 'no_subjects' => [], 'locked' => [], 'placed' => 0, 'empty' => 0, 'free' => 0, 'capped' => 0, 'no_teacher' => 0, 'shortfalls' => [], 'unassigned' => [], 'double_warnings' => []];

        /* Agent D round 6 — whole-run atomicity: the per-target
           DB::transaction below makes each target atomic, but a
           mid-run failure (DB exception, lock, timeout) used to
           leave earlier targets rebuilt, later targets stale, and
           the admin staring at a bare 500 with no summary (proven
           pre-fix: a throw on the 2nd target left target 1 with 6
           cells committed). The outer transaction makes the whole
           run atomic: inner transactions become savepoints, so an
           inner failure rolls back to its savepoint and rethrows,
           and the catch below rolls back everything. The run
           writes at most ~35 targets × ~50 rows, so one
           transaction for the run is cheap on MariaDB row locks;
           per-target atomicity alone cannot fix a partial run.
           Validation refusals above all return before this point,
           and SchoolLevel::saveConfig below runs only on the
           success path. NOTE: the loop body keeps its original
           indentation to keep this diff reviewable; it runs
           inside the try. */
        try {
            DB::beginTransaction();
        foreach ($targets as $target) {
            $class = $target['class'];
            $targetSectionId = $target['section_id'];
            $targetSection = $target['section'];
            $targetSlots = $target['slots'];
            $targetCfg = $target['cfg'];
            $displayName = $targetSection ? $class->name . ' ' . $targetSection->name : $class->name;
            $subjects = Subject::where('my_class_id', $class->id)->orderBy('id')->get();
            // Batch upgrade 2026-10-25: per-class subject choice.
            // A rendered class posts subjects_present, so its posted
            // selection (possibly empty) wins. No marker = an old
            // form post: all subjects, as before.
            if (isset($data['subjects_present'][$class->id])) {
                $sel = array_map('intval', (array) ($data['subjects'][$class->id] ?? []));
                $subjects = $subjects->whereIn('id', $sel)->values();
            }
            // Batch 2026-10-25 (streams): a section grid places only
            // the section's stream subjects (common + own stream).
            if ($targetSection && !empty($targetSection->stream)) {
                $subjects = $subjects->filter(function ($s) use ($targetSection) {
                    return Streams::appliesTo($s, $targetSection->stream);
                })->values();
            }
            if ($subjects->isEmpty()) {
                $summary['no_subjects'][] = $displayName;
                continue;
            }

            $record = TimeTableRecord::where('my_class_id', $class->id)
                ->whereNull('exam_id')->where('year', $this->year);
            if ($targetSectionId) {
                $record->where('section_id', $targetSectionId);
            } else {
                $record->whereNull('section_id');
            }
            $record = $record->first();
            if ($record && $data['mode'] === 'skip') {
                $summary['skipped'][] = $displayName;
                continue;
            }
            if ($record && $data['mode'] === 'replace') {
                /* Break-it guard (2026-10-23): attendance sessions
                   point at timetable cells (time_table_id, set-null
                   on delete). Replacing a timetable whose periods
                   already carry SUBMITTED attendance would orphan
                   those registers and let the same lesson be marked
                   twice under the new grid — refuse and report; the
                   class keeps its timetable for this session. */
                $hasSessions = AttendanceSession::whereIn('time_table_id',
                    TimeTable::where('ttr_id', $record->id)->pluck('id'))->exists();
                if ($hasSessions) {
                    $summary['locked'][] = $displayName;
                    continue;
                }
            }

            DB::transaction(function () use ($record, $class, $targetSectionId, $displayName, $subjects, $targetSlots, $targetCfg, $dayNames, $data, &$busyIntervals, &$teacherDayCount, &$summary) {
                if ($record) {
                    TimeTable::where('ttr_id', $record->id)->delete();
                    TimeSlot::where('ttr_id', $record->id)->delete();
                    // Timetable approval (owner order 2026-10-29):
                    // a regenerated grid is a NEW timetable — it
                    // returns to draft and must be reviewed and
                    // approved again before it is in force.
                    $record->update([
                        'approval_status' => 'draft',
                        'approval_submitted_by' => null,
                        'approval_submitted_at' => null,
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);
                } else {
                    $record = TimeTableRecord::create([
                        'name' => $displayName . ' Timetable',
                        'my_class_id' => $class->id,
                        'section_id' => $targetSectionId,
                        'exam_id' => null,
                        'year' => $this->year,
                        'approval_status' => 'draft',
                    ]);
                }

                $slotModels = [];
                foreach ($targetSlots as $slot) {
                    $slotModels[] = TimeSlot::create([
                        'ttr_id' => $record->id,
                        'hour_from' => $slot['from']->format('g'),
                        'min_from' => $slot['from']->format('i'),
                        'meridian_from' => $slot['from']->format('A'),
                        'hour_to' => $slot['to']->format('g'),
                        'min_to' => $slot['to']->format('i'),
                        'meridian_to' => $slot['to']->format('A'),
                        'time_from' => $slot['from']->format('g:i A'),
                        'time_to' => $slot['to']->format('g:i A'),
                        'timestamp_from' => $slot['from']->timestamp,
                        'timestamp_to' => $slot['to']->timestamp,
                        'full' => $slot['from']->format('g:i A') . ' - ' . $slot['to']->format('g:i A'),
                    ]);
                }

                $weekCount = $subjects->mapWithKeys(function ($s) { return [$s->id => 0]; })->all();
                /* Slice D (2026-10-23): the generator now plans
                   against the curriculum requirements from Slice A.
                   A subject with periods_per_week set has a hard
                   weekly TARGET — it is never placed beyond it, and
                   any shortfall is reported per class in the summary
                   (never silently dropped). A subject marked
                   needs_double gets one back-to-back pair during the
                   week: the placer prefers continuing the previous
                   slot's subject until that pair exists. Subjects
                   without a requirement keep the legacy balanced
                   fill. When every target is met, remaining slots
                   stay empty as free periods (counted separately
                   from teacher-busy empties). */
                $targets = $subjects->mapWithKeys(function ($s) { return [$s->id => $s->periods_per_week]; })->all();
                $doubled = $subjects->mapWithKeys(function ($s) { return [$s->id => false]; })->all();
                // Section build: placement uses the teacher who
                // actually teaches the subject IN THIS SECTION
                // (override or the subject's class teacher), so a
                // section grid never double-books its own teachers.
                $teacherOf = [];
                foreach ($subjects as $s) {
                    $teacherOf[$s->id] = SectionTeacher::teacherIdFor($targetSectionId, $s);
                }
                // Batch upgrade 2026-10-25 (pre-flight): subjects
                // with no teacher are a warning in the summary, never
                // a blocker — the placer skips them (agent D round
                // 2: they used to be PLACED, so the grid looked
                // complete but the validator flagged every one of
                // their periods as teacher-less), and their periods
                // stay empty until a teacher is assigned on Subjects.
                foreach ($subjects as $s) {
                    if (!$teacherOf[$s->id]) {
                        $summary['unassigned'][] = $displayName . ' — ' . $s->name;
                    }
                    /* Agent D round 2: needs_double with fewer than
                       2 weekly periods can never form a pair, yet
                       the old code reported nothing (placed ==
                       target, so no shortfall fired). Warn plainly. */
                    if ($s->needs_double && $s->periods_per_week !== null && (int) $s->periods_per_week < 2) {
                        $summary['double_warnings'][] = $displayName . ' — ' . $s->name
                            . ' (needs a double period but only ' . (int) $s->periods_per_week . ' period/week is set)';
                    }
                }
                /* Rotation (agent D round 2): identical subject
                   structures used to produce identical grids for
                   every class — subjects arrive in id order and the
                   placement sort is stable, so ties always broke the
                   same way (Prep = Class 1 = Class 2 on the demo
                   seed, teachers differing but days copy-pasted).
                   Each target now starts its rotation one subject
                   later than the previous target id, and each day
                   rotates one further. This is ONLY the final
                   tiebreaker: it never overrides the weekly target,
                   the day spread, the daily cap or the busy map, so
                   balance, cap, load spreading, overrides and
                   clash-freedom are unchanged, and re-generating
                   the same class gives the same grid. Only N
                   rotations exist for N subjects, so targets N apart
                   share one by pigeonhole — neighbours differ. */
                $subjectOrder = $subjects->values()->mapWithKeys(function ($s, $i) { return [$s->id => $i]; })->all();
                $subjectCount = count($subjectOrder);
                $rotSeed = $targetSectionId ?? $class->id;
                $rotBase = $subjectCount > 0 ? (($rotSeed - 1) % $subjectCount) : 0;
                for ($day = 1; $day <= $targetCfg['days']; $day++) {
                    $dayCount = $subjects->mapWithKeys(function ($s) { return [$s->id => 0]; })->all();
                    $prevSubjectId = null;
                    foreach ($slotModels as $slotIdx => $slotModel) {
                        $candidates = $subjects->filter(function ($s) use ($targets, $weekCount) {
                            return $targets[$s->id] === null || $weekCount[$s->id] < (int) $targets[$s->id];
                        })->sortBy(function ($s) use ($weekCount, $dayCount, $prevSubjectId, $doubled, $targets, $teacherOf, $teacherDayCount, $day, $subjectOrder, $subjectCount, $rotBase) {
                            $doubleBoost = ($prevSubjectId === $s->id && $s->needs_double && !$doubled[$s->id]
                                && ($targets[$s->id] === null || $weekCount[$s->id] < (int) $targets[$s->id])) ? -1000 : 0;
                            // Load tiebreaker (capped at 9, below the
                            // dayCount step of 10): among equal
                            // subjects, the teacher with fewer
                            // periods already that day wins, so one
                            // teacher's days stop being solid walls.
                            $teacherLoad = $teacherOf[$s->id]
                                ? min($teacherDayCount[$teacherOf[$s->id]][$day] ?? 0, 9)
                                : 0;
                            // Rotation tiebreaker (last): only breaks
                            // exact score ties, so it can never
                            // overturn a target, spread, cap or load
                            // decision. Array sort: score first, then
                            // rotated position.
                            $rot = $subjectCount > 0
                                ? (($subjectOrder[$s->id] + $rotBase + ($day - 1)) % $subjectCount)
                                : 0;
                            return [$doubleBoost + ($weekCount[$s->id] * 100) + ($dayCount[$s->id] * 10) + $teacherLoad, $rot];
                        })->values();
                        $chosen = null;
                        $slotMinute = (int) $targetSlots[$slotIdx]['from']->format('H') * 60 + (int) $targetSlots[$slotIdx]['from']->format('i');
                        $slotToMinute = (int) $targetSlots[$slotIdx]['to']->format('H') * 60 + (int) $targetSlots[$slotIdx]['to']->format('i');
                        $cap = $data['max_teacher_periods_per_day'] ?? null;
                        $cappedSkip = false;
                        $noTeacherSkip = false;
                        foreach ($candidates as $cand) {
                            $candTeacher = $teacherOf[$cand->id] ?? null;
                            /* Agent D round 2: a teacher-less subject
                               is never placed — its periods stay
                               empty and are reported as no-teacher
                               empties, not hidden inside a grid. */
                            if (!$candTeacher) {
                                $noTeacherSkip = true;
                                continue;
                            }
                            if ($cap !== null && ($teacherDayCount[$candTeacher][$day] ?? 0) >= (int) $cap) {
                                $cappedSkip = true;
                                continue;
                            }
                            // Busy is tracked by INTERVAL overlap
                            // (not slot index or start minute) so
                            // levels with different day lengths can
                            // neither false-clash nor truly clash.
                            $busy = false;
                            foreach (($busyIntervals[$candTeacher][$day] ?? []) as [$f, $t]) {
                                if ($slotMinute < $t && $f < $slotToMinute) {
                                    $busy = true;
                                    break;
                                }
                            }
                            if ($busy) {
                                continue;
                            }
                            $chosen = $cand;
                            break;
                        }
                        if (!$chosen) {
                            $allMet = collect($targets)->every(function ($t, $sid) use ($weekCount) {
                                return $t === null || $weekCount[$sid] >= (int) $t;
                            });
                            // A null here with all targets set+met
                            // means a genuinely free period (any
                            // untargeted subject would have been a
                            // candidate); otherwise teachers were
                            // busy.
                            // Cause order matters for honesty: cap
                            // first (its message always offers a
                            // working lever — raise the cap, or add
                            // teachers when the capped teacher is
                            // also busy), then no-teacher, then
                            // free, then busy. Agent D round 4: a
                            // cell mixing a teacher-less subject
                            // with busy teachers used to bucket as
                            // "every teacher busy", which is false
                            // for the subject with no teacher at
                            // all — and placing the busy teacher on
                            // Manage would clash. Assigning a
                            // teacher is the only clean lever, so
                            // the mix buckets as no-teacher; the
                            // busy bucket is now literally true
                            // (no teacher-less, no capped
                            // candidate in it).
                            if ($cappedSkip) {
                                $summary['capped'] = ($summary['capped'] ?? 0) + 1;
                            } elseif ($noTeacherSkip) {
                                $summary['no_teacher'] = ($summary['no_teacher'] ?? 0) + 1;
                            } elseif (collect($targets)->every(function ($t) { return $t !== null; })
                                && $allMet) {
                                $summary['free'] = ($summary['free'] ?? 0) + 1;
                            } else {
                                $summary['empty']++;
                            }
                            $prevSubjectId = null;
                            continue;
                        }
                        if ($prevSubjectId === $chosen->id && $chosen->needs_double) {
                            $doubled[$chosen->id] = true;
                        }
                        $prevSubjectId = $chosen->id;
                        if ($teacherOf[$chosen->id]) {
                            $busyIntervals[$teacherOf[$chosen->id]][$day][] = [$slotMinute, $slotToMinute];
                            $teacherDayCount[$teacherOf[$chosen->id]][$day] = ($teacherDayCount[$teacherOf[$chosen->id]][$day] ?? 0) + 1;
                        }
                        $weekCount[$chosen->id]++;
                        $dayCount[$chosen->id]++;
                        TimeTable::create([
                            'ttr_id' => $record->id,
                            'ts_id' => $slotModel->id,
                            'subject_id' => $chosen->id,
                            'day' => $dayNames[$day - 1],
                            'day_num' => $day,
                            'timestamp_from' => $slotModel->timestamp_from + (($day - 1) * 86400),
                            'timestamp_to' => $slotModel->timestamp_to + (($day - 1) * 86400),
                        ]);
                        $summary['placed']++;
                    }
                }
                /* Agent D round 18: cap × double honesty — a
                   needs_double subject whose pair never formed
                   (cap 1 forbids 2 cells in a day for one teacher;
                   busy days and day-final placements can do the
                   same) used to degrade to singles with no word in
                   the flash, while its target still showed met.
                   Name it in double_warnings so the admin sees the
                   double did not happen. The pre-flight warning
                   above already covers targets < 2; this covers the
                   placed-but-unpaired case only. */
                foreach ($subjects as $s) {
                    if ($s->needs_double && $s->periods_per_week !== null
                        && (int) $s->periods_per_week >= 2 && !$doubled[$s->id]) {
                        $summary['double_warnings'][] = $displayName . ' — ' . $s->name
                            . ' (double period could not be formed)';
                    }
                }
                foreach ($subjects as $s) {
                    if ($s->periods_per_week && $weekCount[$s->id] < (int) $s->periods_per_week) {
                        $summary['shortfalls'][] = $displayName . ' — ' . $s->name . ': '
                            . $weekCount[$s->id] . ' of ' . (int) $s->periods_per_week . ' placed';
                    }
                }
                $summary['generated'][] = $displayName;
                /* Agent D round 7: scope=all mixes class targets and
                   section targets (streamed classes expand into
                   sections), but the flash used to report every
                   generated target as a "class" — 2 section grids
                   read as "2 class(es)". Count section targets
                   separately so the headline states what was built. */
                if ($targetSectionId) {
                    $summary['generated_section_count']++;
                }
            });
        }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->with('flash_danger', 'Timetable generation failed — nothing was changed. Please try again.')->withInput();
        }

        /* Skip-all reads as failure ("0 classes, 0 periods") when every
           class already has a timetable for this session — say so plainly
           and point at Replace mode instead of reporting a zero. */
        if (empty($summary['generated']) && !empty($summary['skipped'])) {
            $msg = 'No new timetables: every class already has one for ' . $this->year
                . ' — nothing was generated. Use “Replace theirs with a fresh generated one” to rebuild, or generate for a different session year.'
                . ' Skipped: ' . implode(', ', $summary['skipped']) . '.';
            /* Agent D round 7: the skip-all message used to drop the
               no-subjects targets entirely — a class with no
               subjects at all vanished from the report (locked
               targets cannot coexist here: locks only fire in
               replace mode, which never skips). Name them too. */
            if (!empty($summary['no_subjects'])) {
                $msg .= ' No subjects yet: ' . implode(', ', $summary['no_subjects']) . '.';
            }
        } else {
        /* Agent D round 7: scope=all headline splits class vs
           section targets (scope=one keeps its old wording, so
           existing assertions on it are untouched). A run that
           generated nothing here has no skipped list either (else
           the branch above fired), so the fallback is 0 classes. */
        if ($data['scope'] === 'all') {
            $genSections = $summary['generated_section_count'] ?? 0;
            $genClasses = count($summary['generated']) - $genSections;
            $genParts = [];
            if ($genClasses > 0) {
                $genParts[] = $genClasses . ' class(es)';
            }
            if ($genSections > 0) {
                $genParts[] = $genSections . ' section(s)';
            }
            if (empty($genParts)) {
                $genParts[] = '0 class(es)';
            }
            $generatedPhrase = implode(' and ', $genParts);
        } else {
            $generatedPhrase = count($summary['generated']) . ' ' . ($sectionId ? 'section(s)' : 'class(es)');
        }
        $msg = 'Timetables generated for ' . $generatedPhrase . ': '
            . $summary['placed'] . ' periods placed'
            . ($summary['empty'] ? ', ' . $summary['empty'] . ' period(s) left empty (every teacher busy) — fill them on the Manage page' : '')
            . (!empty($summary['capped']) ? ', ' . $summary['capped'] . ' period(s) left empty (teacher daily cap reached — raise the cap or add teachers on Subjects)' : '')
            . (!empty($summary['no_teacher']) ? ', ' . $summary['no_teacher'] . ' period(s) left empty (no teacher assigned — assign one on Subjects)' : '')
            . (!empty($summary['free']) ? ', ' . $summary['free'] . ' free period(s) (all subject requirements met)' : '')
            . (!empty($summary['shortfalls']) ? '. REQUIREMENTS NOT FULLY MET — ' . implode('; ', array_slice($summary['shortfalls'], 0, 4)) . (count($summary['shortfalls']) > 4 ? '; and ' . (count($summary['shortfalls']) - 4) . ' more' : '') . ' — add periods to the week or lower the requirement on Subjects' : '')
            . ($summary['skipped'] ? '. Skipped (already have one): ' . implode(', ', $summary['skipped']) : '')
            . (!empty($summary['locked']) ? '. Kept (attendance already submitted, timetable locked this session): ' . implode(', ', $summary['locked']) : '')
            . ($summary['no_subjects'] ? '. No subjects yet: ' . implode(', ', $summary['no_subjects']) : '')
            . (!empty($summary['unassigned']) ? '. No teacher assigned (those periods may stay empty): ' . implode('; ', array_slice($summary['unassigned'], 0, 4)) . (count($summary['unassigned']) > 4 ? '; and ' . (count($summary['unassigned']) - 4) . ' more' : '') : '')
            . (!empty($summary['double_warnings']) ? '. Double period not possible: ' . implode('; ', array_slice($summary['double_warnings'], 0, 4)) . (count($summary['double_warnings']) > 4 ? '; and ' . (count($summary['double_warnings']) - 4) . ' more' : '') : '')
            . '.';
        }

        // Batch upgrade 2026-10-25: remember the used day configs
        // so the next generate opens pre-filled.
        if ($data['scope'] === 'all') {
            SchoolLevel::saveConfig('primary', $levelCfgs['primary']);
            SchoolLevel::saveConfig('secondary', $levelCfgs['secondary']);
        } elseif (!empty($data['class_id'])) {
            SchoolLevel::saveConfig(SchoolLevel::levelForClassId((int) $data['class_id']), $singleCfg);
        }

        return redirect()->route('tt.index')->with('flash_success', $msg);
    }

    /**
     * Role-personal timetable ("My Timetable").
     *
     * Teacher  -> week grid of only the periods they teach. Derivation is
     *             the teacher dashboard's (HomeController::teacherHomeData):
     *             TimeTable rows whose subject.teacher_id is the teacher,
     *             extended from "today" to the whole week.
     * Student  -> week grid of their own class's timetable record.
     * Parent   -> week grid of a child's class record; the child is chosen
     *             via ?child=<student_record id> and validated against
     *             Qs::findMyChildren() — an id that is not their own child
     *             silently falls back to their first child.
     * Everyone else (SA/admin/accountant/librarian) keeps the records UI.
     */
    public function mine(Request $req)
    {
        $user = Auth::user();
        $d = ['mode' => null, 'days' => collect(), 'slots' => collect(), 'map' => [], 'ttr' => null];

        if (Qs::userIsTeacher()) {
            $d = array_merge($d, $this->teacherWeekData($user));

            return view('pages.support_team.timetables.mine', $d);
        }

        if (Qs::userIsStudent() || Qs::userIsParent()) {
            $d['mode'] = Qs::userIsStudent() ? 'student' : 'parent';
            $d['children'] = collect();
            $child = null;

            if ($d['mode'] === 'student') {
                $child = Qs::findStudentRecord($user->id);
            } else {
                $d['children'] = $children = Qs::findMyChildren($user->id);
                // Tamper guard: only an id from the parent's OWN children is
                // honoured; anything else falls back to the first child.
                $child = $children->firstWhere('id', (int) $req->query('child')) ?: $children->first();
            }

            $d['child'] = $child;

            if ($child) {
                // Section build: the section's own grid wins over
                // the whole-class grid (current session first, then
                // latest year) — see SectionTeacher.
                $ttr = SectionTeacher::recordForClassSection($child->my_class_id, $child->section_id ?? null, $this->year);

                // Timetable approval: students/parents see only
                // timetables that are in force (Principal-approved).
                if ($ttr && !$ttr->isInForce()) {
                    $ttr = null;
                }

                if ($ttr) {
                    $grid = $this->recordWeekGrid($ttr);
                    $d['ttr'] = $ttr;
                    $d['days'] = $grid['days'];
                    $d['slots'] = $grid['slots'];
                    $d['map'] = $grid['map'];
                }
            }

            return view('pages.support_team.timetables.mine', $d);
        }

        return redirect()->route('tt.index');
    }

    /**
     * Loop 81: the signed-in teacher's week dataset — single source for
     * the My Timetable screen, its print view and its PDF, so all three
     * always agree. Derivation is the teacher dashboard's: TimeTable
     * rows whose subject.teacher_id is the teacher. Sections and rooms
     * are not stored for a teacher's periods, so neither is produced.
     */
    protected function teacherWeekData($user)
    {
        $periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class', 'tt_record.section'])
            ->whereNotNull('day')
            // Timetable approval: a teacher's week shows only
            // timetables the Principal has approved (in force).
            ->whereHas('tt_record', function ($q) { $q->where('approval_status', 'approved'); });
        SectionTeacher::applyTeacherFilter($periods, (int) $user->id);
        $periods = $periods->get();

        $d = ['mode' => 'teacher', 'map' => []];
        // Slots are per-record rows: the same clock time exists once
        // per record a teacher teaches in, so dedupe by the clock
        // interval, not by row id — otherwise the week grid repeats
        // every time once per record (Ananya: 76 columns for 8
        // times, the 2:40 PM group alone filling PERIOD 69-76).
        $d['slots'] = $periods->pluck('time_slot')->filter()->unique('full')
            ->sortBy(function ($slot) { return (int) $slot->timestamp_from; })->values();
        $d['period_count'] = $periods->count();

        // Owner fix 2026-10-28: the cell names the SECTION too —
        // "Class 12" alone hides which of 13 sections the period is
        // for. Section comes from the period's own record.
        $sectionSub = function ($p) {
            $class = optional($p->tt_record)->my_class ?: optional($p->subject)->my_class;
            $section = optional(optional($p->tt_record)->section)->name;
            return $class ? $class->name . ($section ? ' — ' . $section : '') : '';
        };

        foreach ($periods as $p) {
            if (!$p->time_slot) {
                continue;
            }
            $d['map'][$p->day][$p->time_slot->full] = [
                'title' => optional($p->subject)->name ?: '—',
                'sub'   => $sectionSub($p),
            ];
        }

        $dayOrder = array_flip(Qs::getDaysOfTheWeek());
        $entries = $periods->filter(function ($p) { return $p->time_slot; })
            ->map(function ($p) use ($sectionSub) {
                return [
                    'day'     => $p->day,
                    'slot'    => $p->time_slot->full,
                    'from'    => (int) $p->time_slot->timestamp_from,
                    'subject' => optional($p->subject)->name ?: '—',
                    'class'   => $sectionSub($p),
                ];
            })
            ->sortBy(function ($e) use ($dayOrder) {
                return (($dayOrder[$e['day']] ?? 99) * 10000000000) + $e['from'];
            })->values();
        $d['entries'] = $entries;

        $perDay = $entries->groupBy('day')->map->count();
        $workDays = collect(Qs::getDaysOfTheWeek())->filter(function ($day) use ($perDay) {
            return $perDay->has($day);
        })->values();
        $durations = $d['slots']->map(function ($slot) {
            return ((int) $slot->timestamp_to - (int) $slot->timestamp_from) / 60;
        })->filter(function ($m) { return $m > 0; })->unique()->values();
        $perDayCounts = $perDay->values()->unique();

        $d['stats'] = [
            'periods'   => $periods->count(),
            'work_days' => $workDays->count(),
            'days_range' => $workDays->count()
                ? substr($workDays->first(), 0, 3) . ' – ' . substr($workDays->last(), 0, 3)
                : '',
            'per_day'   => $perDayCounts->count() === 1 ? $perDay->first() : null,
            'duration'  => $durations->count() === 1 ? (int) $durations->first() : null,
            'classes'   => $entries->pluck('class')->filter()->unique()->values(),
        ];

        $present = $periods->pluck('day')->unique()->all();
        $d['days'] = collect(Qs::getDaysOfTheWeek())->filter(function ($day) use ($present) {
            return in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'])
                || in_array($day, $present);
        })->values();

        return $d;
    }

    /**
     * Audit Fix Wave 1 (R-14): the class timetable record mine()
     * shows a student (own record) or a parent (the ?child-selected
     * child, with mine()'s tamper guard), resolved exactly like
     * mine() — current-session record first, latest year as fallback.
     * Null when there is no child or no record for the class.
     */
    protected function ownClassRecord(Request $req)
    {
        $user = Auth::user();

        if (Qs::userIsStudent()) {
            $child = Qs::findStudentRecord($user->id);
        } else {
            $children = Qs::findMyChildren($user->id);
            $child = $children->firstWhere('id', (int) $req->query('child')) ?: $children->first();
        }

        if (!$child) {
            return null;
        }

        return SectionTeacher::recordForClassSection($child->my_class_id, $child->section_id ?? null, $this->year);
    }

    /**
     * Mobile API access to the teacher week dataset (Loop 81 single
     * source). Thin public wrapper — the dataset itself is still
     * built only by teacherWeekData(), so screen, print, PDF and API
     * can never drift apart.
     */
    public function apiTeacherWeek($user)
    {
        return $this->teacherWeekData($user);
    }

    /**
     * Mobile API access to a class/section record's week grid.
     * Thin public wrapper over recordWeekGrid() — same grid the
     * student/parent My Timetable screen renders.
     */
    public function apiRecordGrid(TimeTableRecord $ttr)
    {
        return $this->recordWeekGrid($ttr);
    }

    /**
     * Loop 81: print view of the teacher's own weekly timetable
     * (v2 document design). Audit Fix Wave 1 (R-14): students and
     * parents get the print document of their own class record —
     * the same document records/print renders — instead of falling
     * through to the (HTML) timetables list.
     */
    public function mine_print(Request $req)
    {
        if (Qs::userIsTeacher()) {
            $d = $this->teacherWeekData(Auth::user());
            $d['teacher'] = Auth::user();
            $d['year'] = $this->year;
            $d['s'] = Setting::all()->flatMap(function ($s) {
                return [$s->type => $s->description];
            });

            return view('pages.support_team.timetables.mine_print', $d);
        }

        if (Qs::userIsStudent() || Qs::userIsParent()) {
            $ttr = $this->ownClassRecord($req);

            if (!$ttr) {
                return redirect()->route('tt.mine');
            }

            return view('pages.support_team.timetables.print', $this->recordPrintData($ttr));
        }

        return redirect()->route('tt.index');
    }

    /**
     * Loop 81: the same teacher weekly timetable as a downloadable PDF.
     * barryvdh/laravel-dompdf is already in the tree (composer.lock),
     * so this is a real download of the print template, not a mock.
     * Audit Fix Wave 1 (R-14): students and parents download a real
     * PDF of their own class record's document — this route used to
     * redirect them to the HTML list, so "pdf" returned text/html.
     */
    /* Map a stored upload URL (/storage/...) to a base64 data URI read
       from the local file, for renderers that cannot fetch URLs
       (dompdf). Anything that is not a local storage file is returned
       unchanged. (Same helper as MarkController/CircularController.) */
    protected function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && \Illuminate\Support\Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . \Illuminate\Support\Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }

    public function mine_pdf(Request $req)
    {
        if (Qs::userIsTeacher()) {
            $d = $this->teacherWeekData(Auth::user());
            $d['teacher'] = Auth::user();
            $d['year'] = $this->year;
            $d['s'] = Setting::all()->flatMap(function ($s) {
                return [$s->type => $s->description];
            });
            /* Documents Premium pass: inline the Settings logo for
               dompdf (it cannot fetch the URL); browser print keeps
               the URL from the same builder. */
            if (!empty($d['s']['logo'])) {
                $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
            }
            $d['for_pdf'] = true;

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.timetables.mine_print', $d)
                ->setPaper('a4', 'landscape');

            return $pdf->download('my-timetable-' . \Illuminate\Support\Str::slug(Auth::user()->name) . '-' . $this->year . '.pdf');
        }

        if (Qs::userIsStudent() || Qs::userIsParent()) {
            $ttr = $this->ownClassRecord($req);

            if (!$ttr) {
                return redirect()->route('tt.mine');
            }

            $d = $this->recordPrintData($ttr);
            if (!empty($d['s']['logo'])) {
                $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.timetables.print', $d)
                ->setPaper('a4', 'landscape');

            return $pdf->download('my-timetable-' . \Illuminate\Support\Str::slug(optional($d['my_class'])->name ?: 'class') . '-' . $this->year . '.pdf');
        }

        return redirect()->route('tt.index');
    }

    /**
     * Build a week grid (days x slots + cell map) for one class timetable
     * record. Cell = subject name + the subject's assigned teacher, when
     * the subject carries one (subjects.teacher_id) — never invented.
     */
    protected function recordWeekGrid(TimeTableRecord $ttr)
    {
        $slots = $this->tt->getTimeSlotByTTR($ttr->id);
        $entries = TimeTable::with(['subject.teacher', 'time_slot'])
            ->where('ttr_id', $ttr->id)->whereNotNull('day')->get();

        $map = [];
        foreach ($entries as $e) {
            if (!$e->time_slot) {
                continue;
            }
            $teacher = optional(SectionTeacher::teacherFor($ttr->section_id ?? null, $e->subject))->name;
            $map[$e->day][$e->time_slot->full] = [
                'title' => optional($e->subject)->name ?: '—',
                'sub'   => $teacher ?: '',
            ];
        }

        $present = $entries->pluck('day')->unique()->all();
        $days = collect(Qs::getDaysOfTheWeek())->filter(function ($day) use ($present) {
            return in_array($day, $present);
        })->values();

        return ['days' => $days, 'slots' => $slots, 'map' => $map];
    }

    public function manage($ttr_id)
    {
        $d['ttr_id'] = $ttr_id;
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $d['time_slots'] = $this->tt->getTimeSlotByTTR($ttr_id);
        $d['ts_existing'] = $this->tt->getExistingTS($ttr_id);
        /* Agent D round 9: the add/edit forms offered every class
           subject, so a streamed section's grid could gain an
           out-of-stream subject in one careless click (the
           generator would never place it). Filter to the section's
           stream — the same rule the generator, the readiness
           check and section subject lists use. Unstreamed
           sections and whole-class grids still see every subject. */
        $d['subjects'] = Streams::subjectsForSection(
            $this->my_class->getSubject(['my_class_id' => $ttr->my_class_id])->get(),
            $ttr->section
        );
        $d['my_class'] = $this->my_class->find($ttr->my_class_id);

        if($ttr->exam_id){
            $d['exam_id'] = $ttr->exam_id;
            $d['exam'] = $this->exam->find($ttr->exam_id);
        }

        $d['tts'] = $this->tt->getTimeTable(['ttr_id' => $ttr_id]);

        /* Agent D round 3: broken-cell flags for the Manage grid —
           exactly the validator's definition (see
           TimetableValidator::isBrokenCell), so the grid and the
           Validate page never disagree about which periods are
           broken. The edit tab renders these rows flagged. */
        $d['broken_tt_ids'] = $d['tts']->filter(fn($tt) => TimetableValidator::isBrokenCell($tt))->pluck('id')->all();

        return view('pages.support_team.timetables.manage', $d);
    }

    /* Agent D round 2: a weekly cell's weekday number is derived
       from its day name here (exam cells carry no day, so they keep
       day_num unset). Without this, manual cells stored NULL and
       the validator's day grouping + the generator's busy map
       silently missed them. */
    protected function dayNumFromRequest(TTRequest $req): ?int
    {
        if (!$req->day) {
            return null;
        }
        $idx = array_search($req->day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'], true);
        return $idx !== false ? $idx + 1 : null;
    }

    /**
     * Agent D round 2: time_tables carries UNIQUE(ttr_id, ts_id,
     * day), UNIQUE(ttr_id, ts_id, exam_date) and UNIQUE(from, to,
     * ttr_id) — posting the same slot twice used to die with a
     * 500 QueryException (the AJAX 500 handler even apologised
     * for "Duplicate entry"). Refuse gracefully instead: same
     * record + same slot on the same day/exam date, or same
     * record + identical timestamps (same-time slot definitions),
     * is a duplicate. Timestamps encode their date, so the
     * timestamp check cannot false-positive across days.
     */
    protected function duplicateCellExists(TTRequest $req, $from, $to, $excludeId = null): bool
    {
        $q = TimeTable::where('ttr_id', $req->ttr_id);
        if ($excludeId) {
            $q->where('id', '!=', $excludeId);
        }
        return $q->where(function ($w) use ($req, $from, $to) {
            // Slot branch only when the post names its day or
            // exam date — a post naming neither must not match
            // every day the slot is used on.
            if ($req->exam_date || $req->day) {
                $w->where(function ($s) use ($req) {
                    $s->where('ts_id', $req->ts_id);
                    if ($req->exam_date) {
                        $s->where('exam_date', $req->exam_date);
                    } else {
                        $s->where('day', $req->day);
                    }
                });
            }
            $w->orWhere(function ($s) use ($from, $to) {
                $s->where('timestamp_from', $from)->where('timestamp_to', $to);
            });
        })->exists();
    }

    public function store(TTRequest $req)
    {
        $data = $req->all();
        $tms = $this->tt->findTimeSlot($req->ts_id);
        $d_date = $req->exam_date ?? $req->day;
        $data['timestamp_from'] = strtotime($d_date.' '.$tms->time_from);
        $data['timestamp_to'] = strtotime($d_date.' '.$tms->time_to);
        if (($dayNum = $this->dayNumFromRequest($req)) !== null) {
            $data['day_num'] = $dayNum;
        }
        if ($this->duplicateCellExists($req, $data['timestamp_from'], $data['timestamp_to'])) {
            return Qs::json('This slot already has a period on ' . ($req->exam_date ?? $req->day) . ' — pick a free slot instead.', false);
        }

        $this->tt->create($data);

        return Qs::jsonStoreOk();
    }

    public function update(TTRequest $req, $tt_id)
    {
        $data = $req->all();
        $tms = $this->tt->findTimeSlot($req->ts_id);
        $d_date = $req->exam_date ?? $req->day;
        $data['timestamp_from'] = strtotime($d_date.' '.$tms->time_from);
        $data['timestamp_to'] = strtotime($d_date.' '.$tms->time_to);
        if (($dayNum = $this->dayNumFromRequest($req)) !== null) {
            $data['day_num'] = $dayNum;
        }
        if ($this->duplicateCellExists($req, $data['timestamp_from'], $data['timestamp_to'], $tt_id)) {
            return back()->with('flash_danger', 'This slot already has a period on ' . ($req->exam_date ?? $req->day) . ' — pick a free slot instead.');
        }

        $this->tt->update($tt_id, $data);

        return back()->with('flash_success', __('msg.update_ok'));

    }

    public function delete($tt_id)
    {
        /* Break-it guard (2026-10-23, TT-01): a period cell that
           already carries SUBMITTED attendance must not be deleted —
           attendance_sessions.time_table_id is set-null on delete,
           which would orphan the register and free the same lesson
           to be marked again under a new cell. Refuse and explain;
           the register is history and history is not deletable. */
        if (AttendanceSession::where('time_table_id', $tt_id)->exists()) {
            return back()->with('flash_danger', 'This period cannot be deleted — attendance has already been submitted for it. Submitted attendance is locked history.');
        }
        $this->tt->delete($tt_id);
        return back()->with('flash_success', __('msg.delete_ok'));
    }

    /*********** TIME SLOTS *************/

    public function store_time_slot(TSRequest $req)
    {
        $data = $req->all();
        $data['time_from'] = $tf =$req->hour_from.':'.$req->min_from.' '.$req->meridian_from;
        $data['time_to'] = $tt = $req->hour_to.':'.$req->min_to.' '.$req->meridian_to;
        $data['timestamp_from'] = strtotime($tf);
        $data['timestamp_to'] = strtotime($tt);
        $data['full'] = $tf.' - '.$tt;

        /* Agent D round 3: equal times were refused, but inverted
           times (10:00 AM - 9:00 AM) slipped through — and the slot
           edit sync below would then stamp broken timestamps onto
           every cell using the slot. Refuse any end at or before
           the start, same message and shape as before. */
        if(strtotime($tt) <= strtotime($tf)){
            return response()->json(['msg' => __('msg.invalid_time_slot'), 'ok' => FALSE]);
        }

        $this->tt->createTimeSlot($data);
        return Qs::jsonStoreOk();
    }

    public function use_time_slot(UseTimeSlotRequest $req, $ttr_id)
    {
        /* TT-02 guard (2026-10-23, Phase 5 residual of TT-01):
           copying slots REPLACES the record's slot rows wholesale,
           and cells reference slots by a plain id with no foreign
           key — on a record that already has periods placed, their
           slot references would dangle and any submitted attendance
           on them would point at a grid that no longer exists.
           Slot-copy is for EMPTY timetables (its actual use: a new
           record adopting a sibling's day structure); a populated
           record is regenerated instead. */
        if (TimeTable::where('ttr_id', $ttr_id)->exists()) {
            return back()->with('flash_danger', 'This timetable already has periods placed — its time slots cannot be replaced. Copy slots onto an empty timetable, or regenerate this one.');
        }
        $d = [];  //  Empty Current Time Slot Before Adding New
        $this->tt->deleteTimeSlots(['ttr_id' => $ttr_id]);
        $time_slots = $this->tt->getTimeSlotByTTR($req->ttr_id)->toArray();

        foreach($time_slots as $ts){
            $ts['ttr_id'] = $ttr_id;
            $this->tt->createTimeSlot($ts);
        }

        return redirect()->route('ttr.manage', $ttr_id)->with('flash_success', __('msg.update_ok'));

    }

    public function edit_time_slot($ts_id)
    {
        $d['tms'] = $this->tt->findTimeSlot($ts_id);
        return view('pages.support_team.timetables.time_slots.edit', $d);
    }

    public function update_time_slot(TSRequest $req, $ts_id)
    {
        $data = $req->all();
        $data['time_from'] = $tf =$req->hour_from.':'.$req->min_from.' '.$req->meridian_from;
        $data['time_to'] = $tt = $req->hour_to.':'.$req->min_to.' '.$req->meridian_to;
        $data['timestamp_from'] = strtotime($tf);
        $data['timestamp_to'] = strtotime($tt);
        $data['full'] = $tf.' - '.$tt;

        /* Agent D round 3: same inverted-times guard as slot
           creation — an inverted edit would otherwise stamp broken
           timestamps onto every cell using this slot. */
        if(strtotime($tt) <= strtotime($tf)){
            return back()->with('flash_danger', __('msg.invalid_time_slot'));
        }

        /* Agent D round 5 (parent decision, same class as the exam
           generate/save transactions): the slot-row update + the
           cell sync loop below are ONE atomic unit. A mid-loop
           failure used to leave a partially synced record (slot row
           new times, some cells new, the rest old) plus a 500. Now
           everything rolls back and the admin gets back + a danger
           flash instead — strictly better than the 500, and the
           behavior change is accepted deliberately. */
        DB::beginTransaction();
        try {
            $this->tt->updateTimeSlot($ts_id, $data);

            /* TT-02 residual (2026-10-23): placed cells snapshot the
               slot's time-of-day into their own timestamps at placement
               (generator: slot timestamp + day offset; manual entry:
               cell date + slot time string), and the clash validator
               keys off the CELL timestamps. Without this sync, editing
               a slot's times left every placed period reporting the old
               times to the validator. Recompose each cell's timestamps
               from its own date part + the slot's new times, preserving
               the day/date each cell belongs to. Attendance sessions
               point at cells, not timestamps, so locked registers stay
               attached to their periods. */
            TimeTable::where('ts_id', $ts_id)->get()->each(function ($cell) use ($tf, $tt) {
                /* Cell timestamps arrive as unix integers from the
                   generator/manual flows, but nothing casts the columns
                   — normalize datetime strings too before reading the
                   date part, or an (int) cast would eat the year. */
                $fromTs = is_numeric($cell->timestamp_from) ? (int) $cell->timestamp_from : strtotime($cell->timestamp_from);
                $toTs = is_numeric($cell->timestamp_to) ? (int) $cell->timestamp_to : strtotime($cell->timestamp_to);
                /* Agent D round 4: unparseable timestamps (legacy empty
                   strings) must be left alone — strtotime('') is false
                   and date('Y-m-d', false) would stamp 1970-01-01 onto
                   the cell. Such cells stay visible via the Manage
                   broken-cell flag instead of being silently rewritten. */
                if ($fromTs === false || $toTs === false) {
                    return;
                }
                $cell->timestamp_from = strtotime(date('Y-m-d', $fromTs) . ' ' . $tf);
                $cell->timestamp_to = strtotime(date('Y-m-d', $toTs) . ' ' . $tt);
                $cell->save();
            });

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return back()->with('flash_danger', 'Time slot update failed — nothing was changed. Please try again.')->withInput();
        }

        return redirect()->route('ttr.manage', $req->ttr_id)->with('flash_success', __('msg.update_ok'));
    }

    public function delete_time_slot($ts_id)
    {
        /* TT-02 guard: same dangling-reference problem as the
           slot-copy — a slot with periods in it cannot be deleted
           from under them. */
        if (TimeTable::where('ts_id', $ts_id)->exists()) {
            return back()->with('flash_danger', 'This time slot has periods placed in it — delete those periods first, then the slot.');
        }
        $this->tt->deleteTimeSlot($ts_id);
        return back()->with('flash_success', __('msg.delete_ok'));
    }


    /*********** RECORDS *************/

    /**
     * Section build (2026-10-25): a record's section must belong to
     * its class, and exam timetables are always whole-class. Returns
     * the clean section id, null for a whole-class record, or false
     * when the section does not belong to the class.
     */
    protected function cleanRecordSection(array $data)
    {
        if (!empty($data['exam_id'])) {
            return null;
        }
        $sectionId = !empty($data['section_id']) ? (int) $data['section_id'] : null;
        if (!$sectionId) {
            return null;
        }
        $section = Section::find($sectionId);
        if (!$section || (int) $section->my_class_id !== (int) ($data['my_class_id'] ?? 0)) {
            return false;
        }
        return $sectionId;
    }

    public function edit_record($ttr_id)
    {
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $d['exams'] = $this->exam->getExam(['year' => $ttr->year]);
        $d['my_classes'] = $this->my_class->all();
        $d['sections'] = Section::where('my_class_id', $ttr->my_class_id)->orderBy('name')->get();

        return view('pages.support_team.timetables.edit', $d);
    }

    /**
     * Validation report (Slice E, validation half): the shared
     * TimetableValidator run over this record — clashes across the
     * whole year's records, requirement coverage, teacher loads.
     * Read-only; publishing states arrive separately.
     */
    public function validateRecord($ttr_id)
    {
        $ttr = $this->tt->findRecord($ttr_id);
        $this->guardRecordScope($ttr);
        $report = TimetableValidator::report($ttr);
        // Named v_* on purpose: a plain $errors/$warnings in view
        // data would shadow Laravel's shared ViewErrorBag and break
        // the layout's error handling.
        $d = [
            'record' => $report['record'],
            'cells' => $report['cells'],
            'v_errors' => $report['errors'],
            'v_warnings' => $report['warnings'],
            'coverage' => $report['coverage'],
            'loads' => $report['loads'],
            'my_class' => $this->my_class->find($ttr->my_class_id),
        ];

        return view('pages.support_team.timetables.validate', $d);
    }

    /**
     * Timetable approval gate for the read surfaces (owner order
     * 2026-10-29): a draft or submitted timetable is visible only
     * to the Admin (who reviews it) and the Principal (who approves
     * it). Teachers, students and parents get it once it is in
     * force.
     */
    protected function guardRecordApproval($ttr)
    {
        if ($ttr && !$ttr->isInForce() && !Qs::userIsAdmin() && !Qs::userIsPrincipal()) {
            abort(403, 'This timetable has not been approved yet.');
        }
    }

    /**
     * Timetable approval (owner order 2026-10-29): the Admin
     * reviews the generated grid HERE (manage page + Validate),
     * and OKs it by submitting — the Principal then approves.
     * Submission is refused while the validator reports problems.
     */
    public function submitApproval($ttr_id)
    {
        $ttr = $this->tt->findRecord($ttr_id);
        if (!$ttr) {
            return back()->with('pop_error', __('msg.rnf'));
        }
        if ($ttr->approval_status === 'submitted') {
            return back()->with('pop_error', 'This timetable is already with the Principal for approval.');
        }
        if ($ttr->approval_status === 'approved') {
            return back()->with('pop_error', 'This timetable is already approved and in force.');
        }
        $report = TimetableValidator::report($ttr);
        if (!empty($report['errors'])) {
            return back()->with('flash_danger', 'Fix the ' . count($report['errors']) . ' timetable problem(s) first — open Validate to see them, then submit.');
        }
        TimeTableRecord::where('id', $ttr->id)->update([
            'approval_status' => 'submitted',
            'approval_submitted_by' => Auth::user()->id,
            'approval_submitted_at' => now(),
        ]);
        foreach (User::where('user_type', 'principal')->pluck('id') as $principalId) {
            Notify::toUser($principalId, 'timetable_submitted',
                'Timetable approval requested: ' . $ttr->name,
                'The Admin has reviewed ' . $ttr->name . ' (' . $ttr->year . ') and sent it for your approval.',
                route('tt.approvals'));
        }
        Audit::log('timetable.submitted_for_approval', null, 'Timetable ' . $ttr->id . ' (' . $ttr->name . ') submitted for principal approval by ' . Auth::user()->name);
        return back()->with('flash_success', 'Timetable sent to the Principal for approval.');
    }

    /** The Principal's timetable approval queue. */
    public function approvals()
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'The timetable approval queue belongs to the Principal.');
        }
        $d['records'] = TimeTableRecord::with(['my_class', 'section'])
            ->where('approval_status', 'submitted')
            ->orderBy('approval_submitted_at')->get();
        $d['submitters'] = User::whereIn('id', $d['records']->pluck('approval_submitted_by')->filter()->unique())
            ->get()->keyBy('id');
        return view('pages.support_team.timetables.approvals', $d);
    }

    public function approveRecord($ttr_id)
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal approves timetables.');
        }
        $ttr = $this->tt->findRecord($ttr_id);
        if (!$ttr) {
            return back()->with('pop_error', __('msg.rnf'));
        }
        if ($ttr->approval_status !== 'submitted') {
            return back()->with('pop_error', 'Only a submitted timetable can be approved.');
        }
        TimeTableRecord::where('id', $ttr->id)->update([
            'approval_status' => 'approved',
            'approved_by' => Auth::user()->id,
            'approved_at' => now(),
        ]);
        if ($ttr->approval_submitted_by) {
            Notify::toUser($ttr->approval_submitted_by, 'timetable_approved',
                'Timetable approved: ' . $ttr->name,
                'The Principal approved ' . $ttr->name . ' (' . $ttr->year . '). It is now in force for teachers and students.',
                route('tt.index'));
        }
        Audit::log('timetable.approved', null, 'Timetable ' . $ttr->id . ' (' . $ttr->name . ') approved by ' . Auth::user()->name);
        return back()->with('flash_success', 'Timetable approved — it is now in force.');
    }

    public function sendBackRecord($ttr_id)
    {
        if (!Qs::userIsPrincipal()) {
            abort(403, 'Only the Principal can send a timetable back.');
        }
        $ttr = $this->tt->findRecord($ttr_id);
        if (!$ttr) {
            return back()->with('pop_error', __('msg.rnf'));
        }
        if ($ttr->approval_status !== 'submitted') {
            return back()->with('pop_error', 'Only a submitted timetable can be sent back.');
        }
        TimeTableRecord::where('id', $ttr->id)->update([
            'approval_status' => 'draft',
            'approval_submitted_by' => null,
            'approval_submitted_at' => null,
        ]);
        if ($ttr->approval_submitted_by) {
            Notify::toUser($ttr->approval_submitted_by, 'timetable_sent_back',
                'Timetable sent back: ' . $ttr->name,
                'The Principal sent ' . $ttr->name . ' (' . $ttr->year . ') back for changes. Review it and submit it again.',
                route('ttr.manage', $ttr->id));
        }
        Audit::log('timetable.sent_back', null, 'Timetable ' . $ttr->id . ' (' . $ttr->name . ') sent back by ' . Auth::user()->name);
        return back()->with('flash_success', 'Timetable sent back to the Admin for changes.');
    }

    public function show_record($ttr_id)
    {
        $d_time = [];
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $this->guardRecordScope($ttr);
        $this->guardRecordApproval($ttr);
        $d['ttr_id'] = $ttr_id;
        $d['my_class'] = $this->my_class->find($ttr->my_class_id);

        $d['time_slots'] = $tms = $this->tt->getTimeSlotByTTR($ttr_id);
        $d['tts'] = $tts = $this->tt->getTimeTable(['ttr_id' => $ttr_id]);

        if($ttr->exam_id){
            $d['exam_id'] = $ttr->exam_id;
            $d['exam'] = $this->exam->find($ttr->exam_id);
            $d['days'] = $days = $tts->unique('exam_date')->pluck('exam_date');
            $d_date = 'exam_date';
        }

        else{
            $d['days'] = $days = $tts->unique('day')->pluck('day');
            $d_date = 'day';
        }

        foreach ($days as $day) {
            foreach ($tms as $tm) {
                $cell = $tts->where('ts_id', $tm->id)->where($d_date, $day)->first();
                $teacher = ($cell && $cell->subject) ? optional(SectionTeacher::teacherFor($ttr->section_id ?? null, $cell->subject))->name : null;
                $d_time[] = ['day' => $day, 'time' => $tm->full, 'subject' => $cell->subject->name ?? null, 'teacher' => $teacher];
            }
        }

        $d['d_time'] = collect($d_time);

        return view('pages.support_team.timetables.show', $d);
    }
    public function print_record($ttr_id)
    {
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $this->guardRecordScope($ttr);
        $this->guardRecordApproval($ttr);

        return view('pages.support_team.timetables.print', $this->recordPrintData($ttr));
    }

    /**
     * Audit Fix Wave 1 (R-14): the class-record print assembly, shared
     * by print_record() and the student/parent mine_print/mine_pdf —
     * one source so every route renders the same document.
     */
    protected function recordPrintData($ttr): array
    {
        $d_time = [];
        $d['ttr'] = $ttr;
        $ttr_id = $ttr->id;
        $d['ttr_id'] = $ttr_id;
        $d['my_class'] = $this->my_class->find($ttr->my_class_id);

        $d['time_slots'] = $tms = $this->tt->getTimeSlotByTTR($ttr_id);
        $d['tts'] = $tts = $this->tt->getTimeTable(['ttr_id' => $ttr_id]);

        if($ttr->exam_id){
            $d['exam_id'] = $ttr->exam_id;
            $d['exam'] = $this->exam->find($ttr->exam_id);
            $d['days'] = $days = $tts->unique('exam_date')->pluck('exam_date');
            $d_date = 'exam_date';
        }

        else{
            $d['days'] = $days = $tts->unique('day')->pluck('day');
            $d_date = 'day';
        }

        foreach ($days as $day) {
            foreach ($tms as $tm) {
                $cell = $tts->where('ts_id', $tm->id)->where($d_date, $day)->first();
                $teacher = ($cell && $cell->subject) ? optional(SectionTeacher::teacherFor($ttr->section_id ?? null, $cell->subject))->name : null;
                $d_time[] = ['day' => $day, 'time' => $tm->full, 'subject' => $cell->subject->name ?? null, 'teacher' => $teacher];
            }
        }

        $d['d_time'] = collect($d_time);
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        return $d;
    }

    /**
     * Round 30 scope guard: mirrors agent E round 31's
     * scopeMismatchMessage in ExamDateController::generate()/save()
     * (identical text, identical scope test: !empty($exam->my_class_id)
     * = scoped). A grid naming an exam scoped to class X cannot be
     * created/updated for class Y. Global exams (my_class_id 0) and
     * exam-less class grids always pass; a dead exam_id passes the
     * guard (no scope to enforce) — existence validation is separate.
     */
    protected function scopeMismatchMessage($exam, $classId)
    {
        $scopedClass = MyClass::find($exam->my_class_id);
        $targetClass = MyClass::find($classId);
        return 'This exam is scoped to ' . ($scopedClass ? $scopedClass->name : 'one class')
            . ' — it cannot carry dates for ' . ($targetClass ? $targetClass->name : 'this class') . '.';
    }

    protected function recordScopeMismatch($data)
    {
        if (empty($data['exam_id'])) {
            return null;
        }
        $exam = Exam::find($data['exam_id']);
        if (!$exam || empty($exam->my_class_id)) {
            return null;
        }
        if ((int) $exam->my_class_id === (int) ($data['my_class_id'] ?? 0)) {
            return null;
        }
        return $this->scopeMismatchMessage($exam, (int) $data['my_class_id']);
    }

    public function store_record(TTRecordRequest $req)
    {
        $data = $req->all();
        $data['year'] = $this->year;
        $data['approval_status'] = 'draft';
        $data['section_id'] = $this->cleanRecordSection($data);
        if ($data['section_id'] === false) {
            return back()->with('flash_danger', 'That section does not belong to the chosen class.');
        }
        if ($msg = $this->recordScopeMismatch($data)) {
            return back()->with('flash_danger', $msg);
        }
        $this->tt->createRecord($data);

        return Qs::jsonStoreOk();
    }

    public function update_record(TTRecordRequest $req, $id)
    {
        $data = $req->all();
        $data['section_id'] = $this->cleanRecordSection($data);
        if ($data['section_id'] === false) {
            return back()->with('flash_danger', 'That section does not belong to the chosen class.');
        }
        if ($msg = $this->recordScopeMismatch($data)) {
            return back()->with('flash_danger', $msg);
        }
        $this->tt->updateRecord($id, $data);

        // Loop 112: post-save lands on the timetables index (owner
        // ruling) — was Qs::jsonUpdateOk() (AJAX stay-on-form).
        return Qs::updateOk('tt.index');
    }

    public function delete_record($ttr_id)
    {
        /* TT-01 companion guard: deleting a whole timetable record
           deletes its cells — the same orphaning risk as the single
           cell delete when any of those cells carries submitted
           attendance. */
        if (AttendanceSession::whereIn('time_table_id',
            TimeTable::where('ttr_id', $ttr_id)->pluck('id'))->exists()) {
            return back()->with('flash_danger', 'This timetable cannot be deleted — attendance has already been submitted on its periods. Submitted attendance is locked history.');
        }
        /* Agent D round 33 (F-R33-1): deleteRecord removed only the
           record row, orphaning its cells + slots — and the deleted
           grid's periods kept rendering in the direct teacher's
           week view (period aggregation has no record-existence
           check) even though students correctly fell back to the
           class grid. Delete the grid with its record, in one
           transaction. Safe by construction: the guard above
           refuses whenever any cell carries a submitted session,
           so no session can point at a cell deleted here. This
           mirrors generate()'s replace path, which already deletes
           a rebuilt record's cells + slots together. */
        DB::transaction(function () use ($ttr_id) {
            TimeTable::where('ttr_id', $ttr_id)->delete();
            TimeSlot::where('ttr_id', $ttr_id)->delete();
            $this->tt->deleteRecord($ttr_id);
        });
        return back()->with('flash_success', __('msg.delete_ok'));
    }
}
