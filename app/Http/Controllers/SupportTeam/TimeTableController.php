<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\TimeTable\TSRequest;
use App\Http\Requests\TimeTable\TTRecordRequest;
use App\Http\Requests\TimeTable\TTRequest;
use App\Http\Requests\TimeTable\UseTimeSlotRequest;
use App\Models\AttendanceSession;
use App\Support\TimetableValidator;
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
use App\Support\TeacherScope;
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

        if (Qs::userIsTeamSA()) {
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
        foreach (TimeTable::select('ttr_id', 'subject_id')->get()->groupBy('ttr_id') as $ttrId => $cells) {
            $d['record_stats'][$ttrId] = [
                'periods' => $cells->count(),
                'subjects' => $cells->pluck('subject_id')->unique()->count(),
            ];
        }

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
        $d['defaults'] = [
            'start_time' => '08:00', 'period_minutes' => 40,
            'periods_per_day' => 8, 'lunch_after' => 4,
            'lunch_minutes' => 45, 'break_after' => 2,
            'break_minutes' => 15, 'days' => 5,
        ];

        return view('pages.support_team.timetables.generate', $d);
    }

    public function generate(Request $req)
    {
        $data = $req->validate([
            'start_time' => 'required|date_format:H:i',
            'period_minutes' => 'required|integer|min:20|max:90',
            'periods_per_day' => 'required|integer|min:4|max:12',
            'lunch_after' => 'required|integer|min:1|max:12',
            'lunch_minutes' => 'required|integer|min:15|max:90',
            'break_after' => 'nullable|integer|min:0|max:12',
            'break_minutes' => 'nullable|integer|min:5|max:45',
            'days' => 'required|integer|in:5,6',
            'scope' => 'required|in:all,one',
            'class_id' => 'nullable|integer',
            'mode' => 'required|in:skip,replace',
        ]);
        foreach (['period_minutes', 'periods_per_day', 'lunch_after', 'lunch_minutes', 'break_after', 'break_minutes', 'days'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = (int) $data[$k];
            }
        }
        if ($data['scope'] === 'one' && empty($data['class_id'])) {
            return back()->with('flash_danger', 'Choose the class to generate for.')->withInput();
        }

        // The school-day clock: teaching slots only; lunch and the
        // short break simply move the clock forward.
        $cursor = Carbon::parse('2026-01-05 ' . $data['start_time']); // a Monday
        $slots = [];
        for ($i = 1; $i <= $data['periods_per_day']; $i++) {
            $from = $cursor->copy();
            $to = $cursor->copy()->addMinutes($data['period_minutes']);
            $slots[] = ['from' => $from, 'to' => $to];
            $cursor = $to->copy();
            if ($i === (int) $data['lunch_after']) {
                $cursor->addMinutes($data['lunch_minutes']);
            }
            if (!empty($data['break_after']) && $i === (int) $data['break_after']) {
                $cursor->addMinutes($data['break_minutes'] ?? 15);
            }
        }
        // Sanity clamp (break-it pass, 2026-10-23): a school day that
        // runs past 9:30 PM is a form mistake, not a timetable —
        // refuse it instead of generating a nonsense grid.
        $lastSlot = end($slots);
        if ($lastSlot && ($lastSlot['to']->format('Y-m-d') !== '2026-01-05'
            || $lastSlot['to']->gt(Carbon::parse('2026-01-05 21:30')))) {
            return back()->with('flash_danger', 'That school day would run past 9:30 PM — reduce the periods, shorten them, or start earlier.')->withInput();
        }
        $dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        $classes = $data['scope'] === 'one'
            ? MyClass::where('id', $data['class_id'])->get()
            : MyClass::orderBy('id')->get();

        $teacherBusy = []; // [day][slot][teacher_id] = true, across ALL classes
        /* Slice D follow-up (2026-10-23, found by the validator):
           the busy map must also respect timetables that already
           exist and are NOT being regenerated in this run — a
           single-class regeneration used to be blind to every other
           class's record and could double-book their teachers.
           Kept records (out of scope, skip mode, or attendance-
           locked) preload a by-time busy map; placement checks both
           maps. Records with a different period grid are matched by
           slot start time — the validator remains the final check. */
        $busyByTime = []; // [day][startMinute][teacher_id] = true
        $scopeIds = $classes->pluck('id')->all();
        $existingRecords = TimeTableRecord::whereNull('exam_id')->where('year', $this->year)->get();
        foreach ($existingRecords as $existing) {
            $inScope = in_array($existing->my_class_id, $scopeIds, true);
            if ($inScope && $data['mode'] === 'replace') {
                $locked = TimeTable::where('ttr_id', $existing->id)
                    ->whereIn('id', AttendanceSession::pluck('time_table_id'))->exists();
                if (!$locked) {
                    continue; // this record is being rebuilt below
                }
            }
            foreach (TimeTable::where('ttr_id', $existing->id)->with('subject')->get() as $cell) {
                if ($cell->subject && $cell->subject->teacher_id) {
                    $minute = (int) date('H', (int) $cell->timestamp_from) * 60 + (int) date('i', (int) $cell->timestamp_from);
                    $busyByTime[$cell->day_num][$minute][$cell->subject->teacher_id] = true;
                }
            }
        }
        $summary = ['generated' => [], 'skipped' => [], 'no_subjects' => [], 'locked' => [], 'placed' => 0, 'empty' => 0, 'free' => 0, 'shortfalls' => []];

        foreach ($classes as $class) {
            $subjects = Subject::where('my_class_id', $class->id)->orderBy('id')->get();
            if ($subjects->isEmpty()) {
                $summary['no_subjects'][] = $class->name;
                continue;
            }

            $record = TimeTableRecord::where('my_class_id', $class->id)
                ->whereNull('exam_id')->where('year', $this->year)->first();
            if ($record && $data['mode'] === 'skip') {
                $summary['skipped'][] = $class->name;
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
                    $summary['locked'][] = $class->name;
                    continue;
                }
            }

            DB::transaction(function () use ($record, $class, $subjects, $slots, $dayNames, $data, &$teacherBusy, &$busyByTime, &$summary) {
                if ($record) {
                    TimeTable::where('ttr_id', $record->id)->delete();
                    TimeSlot::where('ttr_id', $record->id)->delete();
                } else {
                    $record = TimeTableRecord::create([
                        'name' => $class->name . ' Timetable',
                        'my_class_id' => $class->id,
                        'exam_id' => null,
                        'year' => $this->year,
                    ]);
                }

                $slotModels = [];
                foreach ($slots as $slot) {
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
                for ($day = 1; $day <= $data['days']; $day++) {
                    $dayCount = $subjects->mapWithKeys(function ($s) { return [$s->id => 0]; })->all();
                    $prevSubjectId = null;
                    foreach ($slotModels as $slotIdx => $slotModel) {
                        $candidates = $subjects->filter(function ($s) use ($targets, $weekCount) {
                            return $targets[$s->id] === null || $weekCount[$s->id] < (int) $targets[$s->id];
                        })->sortBy(function ($s) use ($weekCount, $dayCount, $prevSubjectId, $doubled, $targets) {
                            $doubleBoost = ($prevSubjectId === $s->id && $s->needs_double && !$doubled[$s->id]
                                && ($targets[$s->id] === null || $weekCount[$s->id] < (int) $targets[$s->id])) ? -1000 : 0;
                            return $doubleBoost + ($weekCount[$s->id] * 100) + ($dayCount[$s->id] * 10);
                        })->values();
                        $chosen = null;
                        $slotMinute = (int) $slots[$slotIdx]['from']->format('H') * 60 + (int) $slots[$slotIdx]['from']->format('i');
                        foreach ($candidates as $cand) {
                            if (!$cand->teacher_id
                                || (empty($teacherBusy[$day][$slotIdx][$cand->teacher_id])
                                    && empty($busyByTime[$day][$slotMinute][$cand->teacher_id]))) {
                                $chosen = $cand;
                                break;
                            }
                        }
                        if (!$chosen) {
                            $allMet = collect($targets)->every(function ($t, $sid) use ($weekCount) {
                                return $t === null || $weekCount[$sid] >= (int) $t;
                            });
                            // $allMet with every target satisfied and
                            // only untargeted subjects left unplaced
                            // cannot happen (untargeted are always
                            // candidates); a null here with all
                            // targets set+met means a genuinely free
                            // period, otherwise teachers were busy.
                            if (collect($targets)->every(function ($t) { return $t !== null; })
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
                        if ($chosen->teacher_id) {
                            $teacherBusy[$day][$slotIdx][$chosen->teacher_id] = true;
                            $busyByTime[$day][$slotMinute][$chosen->teacher_id] = true;
                        }
                        $weekCount[$chosen->id]++;
                        $dayCount[$chosen->id]++;
                        $cell = TimeTable::create([
                            'ttr_id' => $record->id,
                            'ts_id' => $slotModel->id,
                            'subject_id' => $chosen->id,
                            'day' => $dayNames[$day - 1],
                            'timestamp_from' => $slotModel->timestamp_from + (($day - 1) * 86400),
                            'timestamp_to' => $slotModel->timestamp_to + (($day - 1) * 86400),
                        ]);
                        $cell->day_num = $day; // not in TimeTable::$fillable
                        $cell->save();
                        $summary['placed']++;
                    }
                }
                foreach ($subjects as $s) {
                    if ($s->periods_per_week && $weekCount[$s->id] < (int) $s->periods_per_week) {
                        $summary['shortfalls'][] = $class->name . ' — ' . $s->name . ': '
                            . $weekCount[$s->id] . ' of ' . (int) $s->periods_per_week . ' placed';
                    }
                }
                $summary['generated'][] = $class->name;
            });
        }

        $msg = 'Timetables generated for ' . count($summary['generated']) . ' class(es): '
            . $summary['placed'] . ' periods placed'
            . ($summary['empty'] ? ', ' . $summary['empty'] . ' period(s) left empty (every teacher busy) — fill them on the Manage page' : '')
            . (!empty($summary['free']) ? ', ' . $summary['free'] . ' free period(s) (all subject requirements met)' : '')
            . (!empty($summary['shortfalls']) ? '. REQUIREMENTS NOT FULLY MET — ' . implode('; ', array_slice($summary['shortfalls'], 0, 4)) . (count($summary['shortfalls']) > 4 ? '; and ' . (count($summary['shortfalls']) - 4) . ' more' : '') . ' — add periods to the week or lower the requirement on Subjects' : '')
            . ($summary['skipped'] ? '. Skipped (already have one): ' . implode(', ', $summary['skipped']) : '')
            . (!empty($summary['locked']) ? '. Kept (attendance already submitted, timetable locked this session): ' . implode(', ', $summary['locked']) : '')
            . ($summary['no_subjects'] ? '. No subjects yet: ' . implode(', ', $summary['no_subjects']) : '')
            . '.';

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
                $ttr = TimeTableRecord::where('my_class_id', $child->my_class_id)
                        ->whereNull('exam_id')->where('year', $this->year)->first()
                    ?: TimeTableRecord::where('my_class_id', $child->my_class_id)
                        ->whereNull('exam_id')->orderByDesc('year')->first();

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
        $periods = TimeTable::with(['subject.my_class', 'time_slot', 'tt_record.my_class'])
            ->whereNotNull('day')
            ->whereHas('subject', function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            })
            ->get();

        $d = ['mode' => 'teacher', 'map' => []];
        $d['slots'] = $periods->pluck('time_slot')->filter()->unique('id')
            ->sortBy(function ($slot) { return (int) $slot->timestamp_from; })->values();
        $d['period_count'] = $periods->count();

        foreach ($periods as $p) {
            if (!$p->time_slot) {
                continue;
            }
            $class = optional($p->tt_record)->my_class ?: optional($p->subject)->my_class;
            $d['map'][$p->day][$p->time_slot->full] = [
                'title' => optional($p->subject)->name ?: '—',
                'sub'   => $class ? $class->name : '',
            ];
        }

        $dayOrder = array_flip(Qs::getDaysOfTheWeek());
        $entries = $periods->filter(function ($p) { return $p->time_slot; })
            ->map(function ($p) {
                $class = optional($p->tt_record)->my_class ?: optional($p->subject)->my_class;
                return [
                    'day'     => $p->day,
                    'slot'    => $p->time_slot->full,
                    'from'    => (int) $p->time_slot->timestamp_from,
                    'subject' => optional($p->subject)->name ?: '—',
                    'class'   => $class ? $class->name : '',
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

        return TimeTableRecord::where('my_class_id', $child->my_class_id)
                ->whereNull('exam_id')->where('year', $this->year)->first()
            ?: TimeTableRecord::where('my_class_id', $child->my_class_id)
                ->whereNull('exam_id')->orderByDesc('year')->first();
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
            $teacher = optional(optional($e->subject)->teacher)->name;
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
        $d['subjects'] = $this->my_class->getSubject(['my_class_id' => $ttr->my_class_id])->get();
        $d['my_class'] = $this->my_class->find($ttr->my_class_id);

        if($ttr->exam_id){
            $d['exam_id'] = $ttr->exam_id;
            $d['exam'] = $this->exam->find($ttr->exam_id);
        }

        $d['tts'] = $this->tt->getTimeTable(['ttr_id' => $ttr_id]);

        return view('pages.support_team.timetables.manage', $d);
    }

    public function store(TTRequest $req)
    {
        $data = $req->all();
        $tms = $this->tt->findTimeSlot($req->ts_id);
        $d_date = $req->exam_date ?? $req->day;
        $data['timestamp_from'] = strtotime($d_date.' '.$tms->time_from);
        $data['timestamp_to'] = strtotime($d_date.' '.$tms->time_to);

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

        if($tf == $tt){
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

        if($tf == $tt){
            return back()->with('flash_danger', __('msg.invalid_time_slot'));
        }

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
            $cell->timestamp_from = strtotime(date('Y-m-d', $fromTs) . ' ' . $tf);
            $cell->timestamp_to = strtotime(date('Y-m-d', $toTs) . ' ' . $tt);
            $cell->save();
        });

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

    public function edit_record($ttr_id)
    {
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $d['exams'] = $this->exam->getExam(['year' => $ttr->year]);
        $d['my_classes'] = $this->my_class->all();

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

    public function show_record($ttr_id)
    {
        $d_time = [];
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $this->guardRecordScope($ttr);
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
                $d_time[] = ['day' => $day, 'time' => $tm->full, 'subject' => $tts->where('ts_id', $tm->id)->where($d_date, $day)->first()->subject->name ?? NULL ];
            }
        }

        $d['d_time'] = collect($d_time);

        return view('pages.support_team.timetables.show', $d);
    }
    public function print_record($ttr_id)
    {
        $d['ttr'] = $ttr = $this->tt->findRecord($ttr_id);
        $this->guardRecordScope($ttr);

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
                $d_time[] = ['day' => $day, 'time' => $tm->full, 'subject' => $tts->where('ts_id', $tm->id)->where($d_date, $day)->first()->subject->name ?? NULL ];
            }
        }

        $d['d_time'] = collect($d_time);
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        return $d;
    }

    public function store_record(TTRecordRequest $req)
    {
        $data = $req->all();
        $data['year'] = $this->year;
        $this->tt->createRecord($data);

        return Qs::jsonStoreOk();
    }

    public function update_record(TTRecordRequest $req, $id)
    {
        $data = $req->all();
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
        $this->tt->deleteRecord($ttr_id);
        return back()->with('flash_success', __('msg.delete_ok'));
    }
}
