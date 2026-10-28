<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamDate;
use App\Models\ExamDuty;
use App\Repositories\ExamRepo;
use App\Support\Audit;
use App\Support\Invigilation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Exam invigilation (Scheduling Engine Slice F): one invigilator
 * per date-sheet sitting. The clash rule (never two duties at the
 * same date + time) is enforced in App\Support\Invigilation for
 * both manual assignment and auto-assignment; duty loads stay
 * visible so the Admin can balance them deliberately.
 * Access mirrors the date sheet: teamSA read, teamSAWrite write.
 */
class ExamDutyController extends Controller
{
    protected $exam;

    public function __construct(ExamRepo $exam)
    {
        $this->middleware('teamSA', ['except' => ['myDuties']]);
        $this->middleware('teamSAWrite', ['only' => ['assign', 'auto']]);
        $this->exam = $exam;
    }

    /**
     * Batch fix 2026-10-25: the teacher's own duties page. Duties
     * were real rows but invisible to teachers (no page, no menu,
     * no bell) — this is the page the bell and the home panel link
     * to. Controller-guarded like leave.my: teachers only, every
     * other role back to the dashboard.
     */
    public function myDuties()
    {
        if (!Qs::userIsTeacher()) {
            return redirect()->route('dashboard');
        }
        $duties = ExamDuty::with(['exam_date.exam', 'exam_date.my_class', 'exam_date.subject'])
            ->where('teacher_id', Auth::id())
            ->get()
            ->sortBy(function ($duty) {
                return ($duty->exam_date->exam_date ?? '') . ' ' . ($duty->exam_date->start_time ?? '');
            })->values();
        return view('pages.support_team.exams.my_duties', ['duties' => $duties]);
    }

    public function index(Request $request)
    {
        $d['exams'] = $this->exam->getExam(['year' => Qs::getCurrentSession()]);
        $d['exam_id'] = $request->query('exam_id');
        $d['teachers'] = Invigilation::pool();
        $d['sittings'] = collect();
        $d['duties'] = collect();
        $d['loads'] = collect();

        if ($d['exam_id']) {
            $d['exam'] = Exam::find($d['exam_id']);
            $d['sittings'] = ExamDate::with(['my_class', 'subject'])
                ->where('exam_id', $d['exam_id'])
                ->orderBy('exam_date')->orderBy('start_time')->orderBy('my_class_id')
                ->get();
            $d['duties'] = ExamDuty::whereIn('exam_date_id', $d['sittings']->pluck('id'))
                ->get()->keyBy('exam_date_id');
            $d['loads'] = ExamDuty::whereIn('exam_date_id', $d['sittings']->pluck('id'))
                ->select('teacher_id', \Illuminate\Support\Facades\DB::raw('COUNT(*) as c'))
                ->groupBy('teacher_id')->pluck('c', 'teacher_id');
        }

        return view('pages.support_team.exams.duties', $d);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'exam_date_id' => 'required|integer',
            'teacher_id' => 'required',
        ]);

        $sitting = ExamDate::findOrFail($data['exam_date_id']);
        $teacherId = (int) Qs::resolveId($data['teacher_id']);
        $teacher = Invigilation::pool()->firstWhere('id', $teacherId);
        if (!$teacher) {
            return back()->with('flash_danger', 'That teacher is not in the invigilator pool (must be an active teacher).');
        }

        [$ok, $message] = Invigilation::assign($sitting, $teacherId, Auth::id());
        if (!$ok) {
            return back()->with('flash_danger', $message);
        }

        Audit::log('exams.duty_assigned', $sitting,
            'Invigilator ' . $teacher->name . ' set for ' . $sitting->exam_date . ' '
            . substr((string) $sitting->start_time, 0, 5) . ' (' . optional($sitting->my_class)->name . ')');

        $note = Invigilation::isOwnSubject($teacherId, $sitting)
            ? ' Note: this is the teacher\'s own subject for this class.'
            : '';
        // Batch 2026-10-28 (exam-timetable round 6): manual
        // assignment of an on-leave teacher stays allowed for
        // deliberate record-keeping, but it must not pass silently
        // — warn exactly like the own-subject note above.
        $note .= Invigilation::isOnApprovedLeave($teacherId, $sitting->exam_date)
            ? ' Note: this teacher is on approved leave covering this date.'
            : '';

        return back()->with('flash_success', 'Invigilator saved.' . $note);
    }

    public function auto(Request $request)
    {
        $data = $request->validate(['exam_id' => 'required|integer']);
        $result = Invigilation::autoAssign((int) $data['exam_id'], Auth::id());

        Audit::log('exams.duties_auto_assigned', null,
            'Auto-assigned ' . $result['assigned'] . ' invigilation duties for exam ' . $data['exam_id']
            . (count($result['unfilled']) ? ' — unfilled: ' . implode('; ', $result['unfilled']) : ''));

        $message = 'Auto-assignment complete: ' . $result['assigned'] . ' sitting(s) got an invigilator.';
        if (!empty($result['skipped_past'])) {
            $message .= ' ' . $result['skipped_past'] . ' past sitting(s) skipped — their papers already happened.';
        }
        // Batch 2026-10-28 (exam-timetable round 6): name leave
        // whenever it shaped a filled sitting — appended before the
        // unfilled branch so it shows on both flash kinds, and
        // silent when the count is 0.
        if (!empty($result['filled_despite_leave'])) {
            $message .= ' ' . $result['filled_despite_leave'] . ' sitting(s) filled while other teachers were on approved leave.';
        }
        if (!empty($result['unfilled'])) {
            $message .= ' Could not fill: ' . implode('; ', $result['unfilled']);
            return back()->with('flash_danger', $message);
        }

        return back()->with('flash_success', $message);
    }
}
