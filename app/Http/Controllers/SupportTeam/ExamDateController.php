<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\ExamDate;
use App\Models\MyClass;
use App\Repositories\ExamRepo;
use App\Repositories\MyClassRepo;
use App\Support\Audit;
use App\Support\ExamDateSheet;
use App\Support\TeacherScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function generate(Request $req)
    {
        $data = $req->validate([
            'exam_id' => 'required|integer',
            'scope' => 'required|in:all,one',
            'class_id' => 'nullable|integer',
            'start_date' => 'required|date',
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
        $slotsPerClass = count($days) * (int) $data['papers_per_day'];
        $times = [$data['session1_time'], $data['session2_time'] ?? '13:00'];

        $classes = $data['scope'] === 'one'
            ? MyClass::where('id', $data['class_id'])->get()
            : MyClass::orderBy('id')->get();

        $done = [];
        $skipped = [];
        $overflow = [];
        $noSubjects = [];
        foreach ($classes as $class) {
            $subjects = $this->my_class->findSubjectByClass($class->id);
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

            $position = 0;
            foreach ($subjects as $subject) {
                if ($position >= $slotsPerClass) {
                    $overflow[] = $class->name . ' — ' . $subject->name;
                    continue;
                }
                $day = $days[intdiv($position, (int) $data['papers_per_day'])];
                $sessionIdx = $position % (int) $data['papers_per_day'];
                ExamDate::updateOrCreate(
                    ['exam_id' => $exam->id, 'my_class_id' => $class->id, 'subject_id' => $subject->id],
                    [
                        'exam_date' => $day->format('Y-m-d'),
                        'start_time' => $times[$sessionIdx] . ':00',
                        'note' => !empty($data['venue']) ? $data['venue'] : null,
                    ]
                );
                $position++;
            }
            $done[] = $class->name;
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

        $saved = 0;
        $cleared = 0;

        foreach ($subjects as $subject) {
            $row = $submitted->get($subject->id);
            if ($row === null) {
                continue; // Subject not in the form — leave it alone.
            }

            $date = trim((string) ($row['exam_date'] ?? ''));

            if ($date === '') {
                // An emptied date is the remove action.
                $deleted = ExamDate::where([
                    'exam_id' => $exam->id,
                    'my_class_id' => $mc->id,
                    'subject_id' => $subject->id,
                ])->delete();
                $cleared += $deleted ? 1 : 0;
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
            'rows' => collect(),
            'announced' => 0,
            'classes' => collect(),
            'children' => collect(),
            'child' => null,
            'viewer' => $user->user_type,
            'scope_params' => [],
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

        $d['scope_params']['exam_id'] = $exam->id;
        $d['rows'] = ExamDateSheet::rows($exam->id, $d['my_class']->id);
        $d['announced'] = $d['rows']->filter(function ($r) { return $r['entry'] !== null; })->count();

        return $d;
    }
}
