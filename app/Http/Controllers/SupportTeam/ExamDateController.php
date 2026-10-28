<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Models\Section;
use App\Repositories\ExamRepo;
use App\Repositories\MyClassRepo;
use App\Support\Audit;
use App\Support\ExamDateSheet;
use App\Support\Invigilation;
use App\Support\SchoolLevel;
use App\Support\TeacherScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exam Date Sheet module (owner-ordered 2026-10-22: "exam dates will
 * be set by admin").
 *
 * Exams hold only name/term/session; the per-subject dates live in
 * exam_dates and are managed here by the school Admin (teamSA read /
 * teamSAWrite write, exactly like exam creation itself). The read
 * side is one page, scoped on the server per role:
 *
 *   student — their own class only; the class is never a parameter.
 *   parent  — the classes of their own children only (child picked
 *             from their linked records; a foreign child id is a 403).
 *   teacher — their TeacherScope classes only (a foreign class id
 *             in the URL is a 403, never a silent fallback).
 *   admin   — any class (the office preview of what families see).
 *
 * The Super Admin never reaches these actions: PlatformOnly keeps
 * every non-allowlisted route name out of the platform workspace.
 * Screen, print and PDF all render from App\Support\ExamDateSheet,
 * so the dates can never disagree between surfaces.
 */
class ExamDateController extends Controller
{
    protected $exam, $my_class;

    public function __construct(ExamRepo $exam, MyClassRepo $my_class)
    {
        $this->middleware('teamSA', ['only' => ['manage']]);
        $this->middleware('teamSAWrite', ['only' => ['save', 'generateForm', 'generate']]);

        $this->exam = $exam;
        $this->my_class = $my_class;
    }

    /*********************** Admin management ***********************/

    /** Picker + per-subject date editor for one exam + class. */
    public function manage(Request $req)
    {
        $d['exams'] = $this->exam->getExam(['year' => Qs::getCurrentSession()]);
        $d['my_classes'] = $this->my_class->all();
        $d['exam_id'] = $req->query('exam_id');
        $d['class_id'] = $req->query('class_id');
        $d['selected'] = false;
        $d['subjects'] = collect();

        if ($d['exam_id'] && $d['class_id']) {
            $d['exam'] = $this->exam->find($d['exam_id']);
            $d['my_class_sel'] = $this->my_class->find($d['class_id']);
            if ($d['exam'] && $d['my_class_sel']) {
                $d['selected'] = true;
                $entries = ExamDate::where('exam_id', $d['exam_id'])
                    ->where('my_class_id', $d['class_id'])
                    ->get()
                    ->keyBy('subject_id');
                $d['subjects'] = $this->my_class->findSubjectByClass($d['class_id'])
                    ->map(function ($s) use ($entries) {
                        $s->date_entry = $entries->get($s->id);
                        return $s;
                    });
            }
        }

        return view('pages.support_team.exams.date_sheet_manage', $d);
    }

    /**
     * AUTO-GENERATE the date sheet (owner's ADMIN-LOAD-ZERO law,
     * 2026-10-23): the system asks the Admin only for the basics —
     * the exam, when it starts, in how many days it must finish,
     * how many papers per day, the session times and the venue —
     * then allocates every subject of every class across those
     * days itself. The Admin reviews and re-times any paper on the
     * Manage page, then it is final. Nobody types dates subject
     * by subject.
     */
    public function generateForm()
    {
        $d['exams'] = $this->exam->getExam(['year' => Qs::getCurrentSession()]);
        $d['my_classes'] = $this->my_class->all();

        return view('pages.support_team.exams.date_sheet_generate', $d);
    }

    /**
     * Round 31 scope message: "This exam is scoped to {X} — it
     * cannot carry dates for {Y}." Shared by generate() and
     * save() so the refusal text is identical on both paths.
     */
    protected function scopeMismatchMessage($exam, $classId)
    {
        $scopedClass = MyClass::find($exam->my_class_id);
        $targetClass = MyClass::find($classId);
        return 'This exam is scoped to ' . ($scopedClass ? $scopedClass->name : 'one class')
            . ' — it cannot carry dates for ' . ($targetClass ? $targetClass->name : 'this class') . '.';
    }

    public function generate(Request $req)
    {
        $data = $req->validate([
            'exam_id' => 'required|integer',
            'scope' => 'required|in:all,one',
            'class_id' => 'nullable|integer',
            // A fresh schedule cannot start in the past — a past
            // start would stamp papers on days that already
            // happened (batch 2026-10-28, exam-timetable audit).
            'start_date' => 'required|date|after_or_equal:today',
            'exam_days' => 'required|integer|min:1|max:30',
            'papers_per_day' => 'required|integer|in:1,2',
            'session1_time' => 'required|date_format:H:i',
            'session2_time' => 'nullable|date_format:H:i',
            'venue' => 'nullable|string|max:100',
            'skip_sunday' => 'nullable',
            'gap_every' => 'nullable|integer|min:0|max:15',
            'gap_days' => 'nullable|integer|min:0|max:10',
            'skip_dates' => 'nullable|string|max:500',
            'mode' => 'required|in:skip,replace',
        ]);

        $exam = $this->exam->find($data['exam_id']);
        if (!$exam) {
            return back()->with('flash_danger', __('msg.rnf'));
        }
        if ($data['scope'] === 'one' && empty($data['class_id'])) {
            return back()->with('flash_danger', 'Choose the class to generate for.')->withInput();
        }
        if ($data['scope'] === 'one' && !MyClass::where('id', $data['class_id'])->exists()) {
            return back()->with('flash_danger', __('msg.rnf'))->withInput();
        }

        // Batch 2026-10-28 (exam-timetable round 31): an exam's
        // scope is FIXED at creation (ExamController's
        // examScopeConflict + UNIQUE(term, year, my_class_id)):
        // my_class_id 0 = global, anything else = exactly one
        // class. generate() and save() never checked it — the
        // generate form lists every exam against every class,
        // so an admin could generate a full sheet for Class Y
        // under a Class-X exam, and the read side (rows keyed
        // by exam_id + my_class_id, no scope check) would then
        // show that sheet to Y's students as if the exam were
        // theirs. Refuse mismatched writes before any row is
        // touched; scope=all under a scoped exam is refused
        // wholesale (generating "for all classes" contradicts
        // a one-class exam).
        if (!empty($exam->my_class_id)) {
            if ($data['scope'] === 'one' && (int) $exam->my_class_id !== (int) $data['class_id']) {
                return back()->with('flash_danger', $this->scopeMismatchMessage($exam, (int) $data['class_id']))->withInput();
            }
            if ($data['scope'] === 'all') {
                $scopedClass = MyClass::find($exam->my_class_id);
                return back()->with('flash_danger', 'This exam is scoped to ' . ($scopedClass ? $scopedClass->name : 'one class') . ' — generate for that class only, not for all classes.')->withInput();
            }
        }

        // The exam days: from the start date forward until the
        // Admin's day budget is met — honouring the real rhythm of
        // an exam calendar (owner, 2026-10-23): Sundays off when
        // asked, named holiday/festival dates skipped, and a break
        // of N days after every M exam days (exam, exam, break,
        // exam...), never a blind back-to-back run.
        $skipDates = [];
        foreach (preg_split('/[\s,]+/', (string) ($data['skip_dates'] ?? '')) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && strtotime($piece)) {
                $skipDates[] = \Illuminate\Support\Carbon::parse($piece)->format('Y-m-d');
            }
        }
        $gapEvery = (int) ($data['gap_every'] ?? 0);
        $gapDays = (int) ($data['gap_days'] ?? 0);
        $days = [];
        $cursor = \Illuminate\Support\Carbon::parse($data['start_date']);
        $sinceGap = 0;
        $guard = 0;
        while (count($days) < (int) $data['exam_days'] && $guard++ < 400) {
            $skip = (!empty($data['skip_sunday']) && $cursor->isSunday())
                || in_array($cursor->format('Y-m-d'), $skipDates, true);
            if ($skip) {
                $cursor->addDay();
                continue;
            }
            $days[] = $cursor->copy();
            $sinceGap++;
            $cursor->addDay();
            if ($gapEvery > 0 && $gapDays > 0 && $sinceGap >= $gapEvery && count($days) < (int) $data['exam_days']) {
                $cursor->addDays($gapDays);
                $sinceGap = 0;
            }
        }
        $times = [$data['session1_time'], $data['session2_time'] ?? '13:00'];
        $ppd = (int) $data['papers_per_day'];
        // Two papers a day in the SAME session would put two of
        // one class's papers on one day at one time — refuse
        // (covers both an explicitly equal second time and the
        // 13:00 default colliding with a 13:00 first session).
        if ($ppd === 2 && $times[0] === $times[1]) {
            return back()->with('flash_danger', 'The two session times must differ — both papers would otherwise sit in one session.')->withInput();
        }
        $totalSlots = count($days) * $ppd;

        $classes = $data['scope'] === 'one'
            ? MyClass::where('id', $data['class_id'])->get()
            : MyClass::orderBy('id')->get();

        // Batch 2026-10-25 (session-level separation): Primary
        // (Prep–Class 5) papers never share a (day, session) with
        // Secondary/Senior (Class 6–12) papers. Every (date, time)
        // slot remembers which level group already sits there —
        // preloaded from this exam's existing rows so a later
        // generate respects earlier sheets — and a class's papers
        // skip slots held by the other group. Same-group classes
        // still share a slot, as before.
        $allLevels = SchoolLevel::levelsForClasses(MyClass::orderBy('id')->get());
        $occupied = [];
        foreach (ExamDate::where('exam_id', $exam->id)->get(['my_class_id', 'exam_date', 'start_time']) as $row) {
            $key = $row->exam_date . '|' . substr((string) $row->start_time, 0, 5);
            $occupied[$key][$allLevels[(int) $row->my_class_id] ?? 'secondary'] = true;
        }

        $done = [];
        $skipped = [];
        $overflow = [];
        $noSubjects = [];
        // Batch 2026-10-28 (exam-timetable round 3): the whole
        // write loop runs inside ONE transaction — a mid-loop
        // failure (lock, exception) used to leave a half-written
        // sheet behind (proven in round 2: a dead generate left
        // exactly one stray row). Now it rolls back to zero rows
        // and the Admin gets a clean refusal instead of a partial
        // sheet. The loop body itself is unchanged; only the
        // transaction boundaries wrap it.
        DB::beginTransaction();
        try {
            foreach ($classes as $class) {
            $subjects = $this->my_class->findSubjectByClass($class->id);
            // Replace means a fresh sheet: drop rows for subjects
            // no longer in this class (exam_dates has no FK, so a
            // deleted/moved subject's row otherwise survives as an
            // invisible phantom that still occupies a slot).
            // Round 45 (P18): the dropped row's duty + bell go
            // with it, or the duty orphans (dash row on My
            // Duties, stale bell naming a dead paper).
            if ($data['mode'] === 'replace') {
                $subjectIds = $subjects->pluck('id')->all();
                $orphans = ExamDate::where('exam_id', $exam->id)->where('my_class_id', $class->id);
                if (!empty($subjectIds)) {
                    $orphans->whereNotIn('subject_id', $subjectIds);
                }
                foreach ((clone $orphans)->get() as $orphan) {
                    Invigilation::clearSitting($orphan);
                }
                $orphans->delete();
            }
            if ($subjects->isEmpty()) {
                $noSubjects[] = $class->name;
                continue;
            }
            $existing = ExamDate::where('exam_id', $exam->id)
                ->where('my_class_id', $class->id)->count();
            if ($existing > 0 && $data['mode'] === 'skip') {
                $skipped[] = $class->name;
                continue;
            }

            $group = $allLevels[(int) $class->id] ?? 'secondary';
            $slotIdx = 0;
            foreach ($subjects as $subject) {
                $placed = false;
                while ($slotIdx < $totalSlots) {
                    $day = $days[intdiv($slotIdx, $ppd)];
                    $sessionIdx = $slotIdx % $ppd;
                    $key = $day->format('Y-m-d') . '|' . $times[$sessionIdx];
                    $groups = $occupied[$key] ?? [];
                    if (empty($groups) || (count($groups) === 1 && isset($groups[$group]))) {
                        ExamDate::updateOrCreate(
                            ['exam_id' => $exam->id, 'my_class_id' => $class->id, 'subject_id' => $subject->id],
                            [
                                'exam_date' => $day->format('Y-m-d'),
                                'start_time' => $times[$sessionIdx] . ':00',
                                'note' => !empty($data['venue']) ? $data['venue'] : null,
                            ]
                        );
                        $occupied[$key][$group] = true;
                        $slotIdx++;
                        $placed = true;
                        break;
                    }
                    $slotIdx++;
                }
                if (!$placed) {
                    $overflow[] = $class->name . ' — ' . $subject->name;
                }
            }
            $done[] = $class->name;
        }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->with('flash_danger', 'Date sheet generation failed — nothing was saved. Please try again.')->withInput();
        }

        Audit::log('exams.date_sheet_generated', null, 'Exam date sheet auto-generated: exam ' . $exam->id . ' (' . $exam->name . ') from ' . $data['start_date'] . ' over ' . $data['exam_days'] . ' day(s) — classes: ' . implode(', ', $done));

        $msg = 'Date sheet generated for ' . $exam->name . ': ' . count($done) . ' class(es) ('
            . $days[0]->format('d M Y') . ' – ' . end($days)->format('d M Y') . '). Review and adjust any paper on the Manage page.'
            . ($skipped ? ' Skipped (sheet already exists): ' . implode(', ', $skipped) . '.' : '')
            . ($noSubjects ? ' No subjects yet: ' . implode(', ', $noSubjects) . '.' : '')
            . ($overflow ? ' Could not fit within the days given: ' . implode('; ', $overflow) . ' — extend the days or set those on the Manage page.' : '');

        return redirect()->route('exam_dates.manage', ['exam_id' => $exam->id])
            ->with('flash_success', $msg);
    }

    /** Save the whole sheet for one exam + class (upsert per subject). */
    public function save(Request $req)
    {
        $req->validate([
            'exam_id' => 'required|integer',
            'class_id' => 'required|integer',
            'dates' => 'array',
            'dates.*.exam_date' => 'nullable|date',
            'dates.*.start_time' => 'nullable|date_format:H:i',
            'dates.*.note' => 'nullable|string|max:100',
        ]);

        $exam = $this->exam->find($req->input('exam_id'));
        $mc = $this->my_class->find($req->input('class_id'));
        if (!$exam || !$mc) {
            return back()->with('flash_danger', __('msg.rnf'));
        }
        // Round 31: same scope rule as generate() — a Manage save
        // for a foreign class under a class-scoped exam is refused
        // before any row is touched.
        if (!empty($exam->my_class_id) && (int) $exam->my_class_id !== (int) $mc->id) {
            return back()->with('flash_danger', $this->scopeMismatchMessage($exam, $mc->id));
        }

        $subjects = $this->my_class->findSubjectByClass($mc->id);
        $validIds = $subjects->pluck('id')->map(function ($id) { return (int) $id; })->all();
        $submitted = collect($req->input('dates', []));

        // A subject id outside this class's own subject set means the
        // form was tampered with — refuse the whole save.
        foreach ($submitted->keys() as $subjectId) {
            if (!in_array((int) $subjectId, $validIds, true)) {
                return back()->with('flash_danger', __('msg.rnf'));
            }
        }

        // Batch 2026-10-28 (exam-timetable audit): two validations
        // the save never had. (1) A NEW past date is refused — a
        // paper stamped on a day that already happened is
        // misinformation on a published sheet. (2) Two papers of
        // this class in one (date, session) are refused — the
        // generate form promises "No class gets two papers in the
        // same session", but the manual save could create exactly
        // that. Both checks grandfather what is already stored:
        // an unchanged past date, or a clash that already exists
        // in the stored sheet, never blocks an unrelated edit.
        $today = \Illuminate\Support\Carbon::today()->toDateString();
        $storedBySubject = [];
        foreach (ExamDate::where('exam_id', $exam->id)->where('my_class_id', $mc->id)->get(['subject_id', 'exam_date', 'start_time']) as $r) {
            $storedBySubject[(int) $r->subject_id] = [
                'date' => (string) $r->exam_date,
                'time' => $r->start_time ? substr((string) $r->start_time, 0, 5) : null,
            ];
        }
        $subjectNames = $subjects->pluck('name', 'id')->mapWithKeys(function ($name, $id) {
            return [(int) $id => $name];
        })->all();
        foreach ($submitted as $subjectId => $row) {
            $date = trim((string) ($row['exam_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $old = $storedBySubject[(int) $subjectId] ?? null;
            if ($date < $today && (!$old || $old['date'] !== $date)) {
                return back()->withInput()->with('flash_danger', 'Past dates cannot be set: ' . ($subjectNames[(int) $subjectId] ?? 'Subject') . ' — ' . $date . ' has already passed.');
            }
        }
        // Post-save map: stored rows overlaid with the submission
        // (an emptied date removes its row); any (date, time)
        // group holding two papers that was not already a clash
        // in the stored sheet is refused.
        $postMap = $storedBySubject;
        foreach ($submitted as $subjectId => $row) {
            $date = trim((string) ($row['exam_date'] ?? ''));
            if ($date === '') {
                unset($postMap[(int) $subjectId]);
                continue;
            }
            $postMap[(int) $subjectId] = [
                'date' => $date,
                'time' => !empty($row['start_time']) ? substr((string) $row['start_time'], 0, 5) : null,
            ];
        }
        $clashGroups = function ($map) {
            $groups = [];
            foreach ($map as $sid => $v) {
                if (!$v['date'] || !$v['time']) {
                    continue;
                }
                $groups[$v['date'] . '|' . $v['time']][] = $sid;
            }
            $clashes = [];
            foreach ($groups as $key => $sids) {
                if (count($sids) > 1) {
                    sort($sids);
                    $clashes[$key] = $sids;
                }
            }
            return $clashes;
        };
        $storedClashes = $clashGroups($storedBySubject);
        foreach ($clashGroups($postMap) as $key => $sids) {
            if (($storedClashes[$key] ?? null) === $sids) {
                continue; // Already clashing in the stored sheet — grandfathered.
            }
            [$cdate, $ctime] = explode('|', $key);
            $names = array_map(function ($sid) use ($subjectNames) { return $subjectNames[$sid] ?? 'Subject'; }, $sids);
            return back()->withInput()->with('flash_danger', 'Two papers cannot share one session: ' . implode(' and ', $names) . ' are both on ' . $cdate . ' at ' . $ctime . '. Move one to another session.');
        }

        // Batch 2026-10-28 (exam-timetable round 4): the whole
        // save loop runs inside ONE transaction — same reasoning
        // as the generate loop (round 3). A mid-save failure used
        // to leave a partially saved single-class sheet behind
        // (upserts make re-saving idempotent, so the risk is lower
        // than generate's, but a half-saved sheet is still a
        // half-saved sheet). Now it rolls back to the pre-save
        // state and the Admin gets a clean refusal instead.
        // All validation refusals above already return before
        // any write, so only the loop + audit sit inside.
        $saved = 0;
        $cleared = 0;

        DB::beginTransaction();
        try {
        foreach ($subjects as $subject) {
            $row = $submitted->get($subject->id);
            if ($row === null) {
                continue; // Subject not in the form — leave it alone.
            }

            $date = trim((string) ($row['exam_date'] ?? ''));

            if ($date === '') {
                // An emptied date is the remove action — and the
                // sitting's duty + bell go with it (round 45,
                // P18): a duty pointing at a deleted sitting
                // renders as a dash row on My Duties and its bell
                // still names a paper that no longer exists.
                $sitting = ExamDate::where([
                    'exam_id' => $exam->id,
                    'my_class_id' => $mc->id,
                    'subject_id' => $subject->id,
                ])->first();
                if ($sitting) {
                    Invigilation::clearSitting($sitting);
                    $sitting->delete();
                    $cleared += 1;
                }
                continue;
            }

            ExamDate::updateOrCreate(
                ['exam_id' => $exam->id, 'my_class_id' => $mc->id, 'subject_id' => $subject->id],
                [
                    'exam_date' => $date,
                    'start_time' => ($row['start_time'] ?? null) ?: null,
                    'note' => trim((string) ($row['note'] ?? '')) ?: null,
                ]
            );
            $saved++;
        }

        Audit::log('exams.date_sheet_saved', null, 'Exam date sheet saved: exam ' . $exam->id . ' (' . $exam->name . '), class ' . $mc->id . ' (' . $mc->name . ') — ' . $saved . ' date(s) set, ' . $cleared . ' cleared');
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return back()->with('flash_danger', 'Date sheet save failed — nothing was saved. Please try again.')->withInput();
        }

        $msg = 'Date sheet saved for ' . $exam->name . ' — ' . $mc->name . ': ' . $saved . ' date(s) set';
        $msg .= $cleared ? ', ' . $cleared . ' cleared.' : '.';

        return redirect()->route('exam_dates.manage', ['exam_id' => $exam->id, 'class_id' => $mc->id])
            ->with('flash_success', $msg);
    }

    /*********************** Scoped read views **********************/

    public function show(Request $req)
    {
        $scope = $this->resolveScope($req);
        if ($scope instanceof \Illuminate\Http\Response || $scope instanceof \Illuminate\Http\RedirectResponse) {
            return $scope;
        }

        return view('pages.support_team.exams.date_sheet', $scope);
    }

    public function printSheet(Request $req)
    {
        $scope = $this->resolveScope($req);
        if ($scope instanceof \Illuminate\Http\Response || $scope instanceof \Illuminate\Http\RedirectResponse) {
            return $scope;
        }
        /* Round 8 (2026-10-28): the index view handles its empty
           states gracefully, but this route is a plain GET — a
           bookmarked or directly navigated print URL in an empty
           state (no exams this session, teacher with no classes,
           parent with no linked children) resolved a null exam or
           class and died with a 500 at the blade <title>. Redirect
           to the index instead, which already explains the state. */
        if (!$scope['exam'] || !$scope['my_class']) {
            return redirect()->route('exam_dates.index')
                ->with('flash_info', 'There is no date sheet available.');
        }

        $scope['school'] = ExamDateSheet::schoolInfo();
        $scope['for_pdf'] = false;

        return view('pages.support_team.exams.date_sheet_print', $scope);
    }

    public function pdf(Request $req)
    {
        $scope = $this->resolveScope($req);
        if ($scope instanceof \Illuminate\Http\Response || $scope instanceof \Illuminate\Http\RedirectResponse) {
            return $scope;
        }
        /* Round 8 (2026-10-28): same null-scope guard as printSheet
           — the filename line below dereferences exam + class, so
           an empty state would 500 before any PDF bytes exist. */
        if (!$scope['exam'] || !$scope['my_class']) {
            return redirect()->route('exam_dates.index')
                ->with('flash_info', 'There is no date sheet available.');
        }

        $school = ExamDateSheet::schoolInfo();
        /* dompdf cannot fetch asset() URLs — inline the school's
           images from local storage, the same treatment the annual
           report PDF gets. */
        foreach (['logo', 'stamp', 'principal_signature'] as $key) {
            if (!empty($school[$key])) {
                $school[$key] = ExamDateSheet::pdfImageDataUri($school[$key]);
            }
        }
        $scope['school'] = $school;
        $scope['for_pdf'] = true;

        $name = 'exam-date-sheet-' . Str::slug($scope['exam']->name) . '-' . Str::slug($scope['my_class']->name) . '.pdf';

        return Pdf::loadView('pages.support_team.exams.date_sheet_print', $scope)
            ->setPaper('a4')
            ->download($name);
    }

    /**
     * Resolve the viewer's scope and build the sheet data.
     * Returns the view-data array, or an abort/redirect response.
     * $req may carry exam_id and — for the roles that may choose —
     * class_id (teacher/admin) or student_id (parent). Students get
     * no choice at all: their class comes from their own record.
     */
    protected function resolveScope(Request $req)
    {
        $user = Auth::user();
        $exams = $this->exam->getExam(['year' => Qs::getCurrentSession()]);

        $exam = $exams->firstWhere('id', (int) $req->query('exam_id')) ?: $exams->first();

        $d = [
            'exams' => $exams,
            'exam' => $exam,
            'my_class' => null,
            'class_teacher_name' => null,
            'rows' => collect(),
            'announced' => 0,
            'classes' => collect(),
            'children' => collect(),
            'child' => null,
            'viewer' => $user->user_type,
            'scope_params' => [],
            'section' => null,
            'sections' => collect(),
        ];

        if (!$exam) {
            return $d; // No exams this session — the view says so.
        }

        if ($user->user_type === 'student') {
            $sr = Qs::getSRByUserID($user->id);
            if (!$sr) {
                abort(403);
            }
            $d['my_class'] = $this->my_class->find($sr->my_class_id);
            $d['child'] = $sr; // Own record — the header names the student.
        } elseif ($user->user_type === 'parent') {
            $children = Qs::findMyChildren($user->id);
            $d['children'] = $children;
            if ($children->isEmpty()) {
                return $d;
            }
            $childId = (int) $req->query('student_id');
            if ($childId) {
                $child = $children->firstWhere('user_id', $childId);
                if (!$child) {
                    abort(403); // Not this parent's child.
                }
            } else {
                $child = $children->first();
            }
            $d['child'] = $child;
            $d['my_class'] = $this->my_class->find($child->my_class_id);
            $d['scope_params']['student_id'] = $child->user_id;
        } elseif ($user->user_type === 'teacher') {
            $classIds = TeacherScope::classIds($user);
            $d['classes'] = MyClass::whereIn('id', $classIds)->orderBy('name')->get();
            if ($d['classes']->isEmpty()) {
                return $d;
            }
            $classId = (int) $req->query('class_id');
            if ($classId) {
                if (!in_array($classId, $classIds, true)) {
                    abort(403); // Outside this teacher's assigned classes.
                }
                $d['my_class'] = $d['classes']->firstWhere('id', $classId);
            } else {
                $d['my_class'] = $d['classes']->first();
            }
            $d['scope_params']['class_id'] = $d['my_class']->id;
        } elseif (Qs::userIsPrincipal()) {
            // Limited principal role (owner order 2026-10-28):
            // date sheets are school-wide monitoring reads for the
            // principal, like the admin's view. Manage/save/generate
            // stay teamSA/teamSAWrite (constructor) — reads only.
            $d['classes'] = $this->my_class->all();
            $classId = (int) $req->query('class_id');
            $d['my_class'] = $classId
                ? $d['classes']->firstWhere('id', $classId)
                : $d['classes']->first();
            if (!$d['my_class']) {
                abort(404);
            }
            $d['scope_params']['class_id'] = $d['my_class']->id;
        } elseif (Qs::userIsAdmin()) {
            $d['classes'] = $this->my_class->all();
            $classId = (int) $req->query('class_id');
            $d['my_class'] = $classId
                ? $d['classes']->firstWhere('id', $classId)
                : $d['classes']->first();
            if (!$d['my_class']) {
                abort(404);
            }
            $d['scope_params']['class_id'] = $d['my_class']->id;
        } else {
            // Accountant, librarian and anyone else have no date-sheet
            // surface. (Super Admin is stopped earlier by PlatformOnly.)
            abort(403);
        }

        /* Batch 2026-10-28 (exam-timetable round 32): read-side
           scope check, the belt-and-braces half of F-R31-1.
           Writes refuse mismatched pairs, but a LEGACY row
           (direct-DB-only after R31) still rendered to the
           foreign class through rows() keyed by (exam_id,
           my_class_id) alone. Redirect ONLY when the request
           EXPLICITLY selected the mismatched pair (an exam_id /
           class_id / student_id param equal to the resolved exam
           / class / child): the redirect target carries no scope
           params, so it can never re-trigger the redirect and
           the loop is impossible. When the mismatch arrives via
           DEFAULT resolution (no explicit param — reachable only
           when the session's first exam is itself scoped for
           another class), render with EMPTY rows instead: the
           blade's honest "Dates not announced yet" state, never
           the legacy dates. */
        $scopeMismatch = $d['my_class'] && !empty($exam->my_class_id)
            && (int) $exam->my_class_id !== (int) $d['my_class']->id;
        if ($scopeMismatch) {
            $explicitlySelected = (int) $req->query('exam_id') === (int) $exam->id
                || (int) $req->query('class_id') === (int) $d['my_class']->id
                || ($d['child'] && (int) $req->query('student_id') === (int) $d['child']->user_id);
            if ($explicitlySelected) {
                return redirect()->route('exam_dates.index')
                    ->with('flash_danger', $this->scopeMismatchMessage($exam, $d['my_class']->id));
            }
        }

        $d['scope_params']['exam_id'] = $exam->id;
        if ($scopeMismatch) {
            $d['rows'] = collect();
            $d['announced'] = 0;
        } else {
            $d['rows'] = ExamDateSheet::rows($exam->id, $d['my_class']->id);
            $d['announced'] = $d['rows']->filter(function ($r) { return $r['entry'] !== null; })->count();
        }

        /* Batch 2026-10-26 (streams): a streamed section reads
           only its own subjects — a Class 12 PCM sheet used to
           list all 12. The section decides: the student's own, the
           parent's selected child, or an explicit ?section_id= for
           teacher/admin. The unsectioned admin default stays
           complete — it is the management view, and every subject
           still needs its date. Print/PDF inherit the section
           through scope_params. */
        $section = null;
        if ($d['child']) {
            $section = $d['child']->section;
        } elseif ($user->user_type === 'teacher' || Qs::userIsAdmin()) {
            $sectionId = (int) $req->query('section_id');
            if ($sectionId) {
                $section = Section::where('my_class_id', $d['my_class']->id)->find($sectionId);
            }
        }
        $d['section'] = $section;
        if ($user->user_type === 'teacher' || Qs::userIsAdmin()) {
            $d['sections'] = Section::where('my_class_id', $d['my_class']->id)->orderBy('id')->get();
        }
        if ($section && $section->stream) {
            $d['rows'] = $d['rows']->filter(function ($r) use ($section) {
                return \App\Support\Streams::appliesTo($r['subject'], $section->stream);
            })->values();
            $d['announced'] = $d['rows']->filter(function ($r) { return $r['entry'] !== null; })->count();
            $d['scope_params']['section_id'] = $section->id;
        }

        /* Batch fix 2026-10-25: the date-sheet footer printed a
           blank Class Teacher name by construction (hardcoded empty
           div). The sheet is always one class's, so name its teacher
           — but only when the class's sections agree on one teacher;
           a class with different section teachers keeps the blank
           signing line rather than a guessed name. */
        $names = Section::where('my_class_id', $d['my_class']->id)
            ->whereNotNull('teacher_id')
            ->with('teacher')
            ->get()
            ->map(function ($s) { return optional($s->teacher)->name; })
            ->filter()
            ->unique()
            ->values();
        $d['class_teacher_name'] = $names->count() === 1 ? $names->first() : null;

        return $d;
    }
}
