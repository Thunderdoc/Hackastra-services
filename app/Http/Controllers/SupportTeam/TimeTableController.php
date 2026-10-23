<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Requests\TimeTable\TSRequest;
use App\Http\Requests\TimeTable\TTRecordRequest;
use App\Http\Requests\TimeTable\TTRequest;
use App\Http\Requests\TimeTable\UseTimeSlotRequest;
use App\Models\Setting;
use App\Models\TimeTable;
use App\Models\TimeTableRecord;
use App\Repositories\ExamRepo;
use App\Repositories\MyClassRepo;
use App\Repositories\TimeTableRepo;
use App\Support\TeacherScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

        return view('pages.support_team.timetables.index', $d);
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
        return redirect()->route('ttr.manage', $req->ttr_id)->with('flash_success', __('msg.update_ok'));
    }

    public function delete_time_slot($ts_id)
    {
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
        $this->tt->deleteRecord($ttr_id);
        return back()->with('flash_success', __('msg.delete_ok'));
    }
}
