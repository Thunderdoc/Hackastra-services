<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamDuty;
use App\Models\Room;
use App\Models\SeatingPlan;
use App\Repositories\ExamRepo;
use App\Support\Audit;
use App\Support\ExamDateSheet;
use App\Support\SeatingPlanner;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * F3 — Exam Seating (design §§4/7/8). UI label is "Exam Seating" —
 * never "Seating Plan" (that label belongs to an unrelated Reports
 * preset, §1.8).
 *
 * Access mirrors the date sheet at slot grain: teamSA read,
 * teamSAWrite generate/submit (Admin only — the Super Admin is
 * view-only inside a school), the Principal alone publishes
 * (Qs::userIsPrincipal() guard, the exam_dates approvals pattern).
 * Teachers get exactly one surface: their own PUBLISHED hall
 * sheets, server-side scoped to the sittings they invigilate
 * (myDuties precedent). Students/parents have no surface.
 *
 * ExamDuty integration is READ-ONLY: nothing here writes or
 * deletes a duty row (§1.3).
 */
class ExamSeatingController extends Controller
{
    protected $exam;

    public function __construct(ExamRepo $exam)
    {
        $this->middleware('teamSA', ['only' => ['index', 'hallSheet', 'hallSheetPdf']]);
        $this->middleware('teamSAWrite', ['only' => ['generate', 'submit']]);
        $this->exam = $exam;
    }

    /** Exam picker + slot list with each slot's plan state. */
    public function index(Request $request)
    {
        $d['exams'] = $this->exam->getExam(['year' => Qs::getCurrentSession()]);
        $d['exam_id'] = $request->query('exam_id');
        $d['exam'] = null;
        $d['slots'] = [];
        $d['rooms'] = Room::orderBy('capacity')->orderBy('id')->get();
        $d['no_rooms'] = $d['rooms']->isEmpty();

        if ($d['exam_id']) {
            $d['exam'] = Exam::find($d['exam_id']);
            if ($d['exam']) {
                $plans = SeatingPlan::where('exam_id', $d['exam']->id)->get()
                    ->keyBy(function ($plan) {
                        return $plan->slotKey();
                    });
                foreach (SeatingPlanner::slots($d['exam']->id) as $slot) {
                    $plan = $plans->get($slot['exam_date'] . '|' . $slot['time_key']);
                    $students = 0;
                    if (!$plan) {
                        $rosters = SeatingPlanner::rostersForSlot($slot);
                        foreach ($rosters as $roster) {
                            $students += count($roster);
                        }
                    }
                    $slot['plan'] = $plan;
                    $slot['students'] = $plan ? $plan->total_students : $students;
                    $slot['stale'] = $plan ? SeatingPlanner::isStale($plan) : false;
                    /* ONE-TIME GENERATION lock (owner standing rule
                       2026-11-08): a submitted/published plan shows
                       its lock instead of a Regenerate form. */
                    $slot['lock'] = $plan ? SeatingPlanner::planLockMessage($d['exam']->id, $slot['exam_date'], $slot['time_key']) : null;
                    $d['slots'][] = $slot;
                }
            }
        }

        return view('pages.support_team.exams.seating_index', $d);
    }

    /** Generate / regenerate one slot's plan (Admin). */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'exam_id' => 'required|integer',
            'exam_date' => 'required|date',
            'time_key' => 'nullable|string|max:5',
            'mode' => 'nullable|in:separate,mixed',
            'room_ids' => 'nullable|array',
            'room_ids.*' => 'integer',
        ]);
        $exam = Exam::find($data['exam_id']);
        if (!$exam) {
            return back()->with('flash_danger', __('msg.rnf'));
        }
        $roomIds = $data['room_ids'] ?? [];
        if (empty($roomIds)) {
            // No explicit hall set: default to every classroom with
            // seats (labs are opt-in halls — design synthesis).
            $roomIds = Room::where('kind', 'classroom')->where('capacity', '>', 0)->pluck('id')->all();
        }

        [$ok, $message, $plan] = SeatingPlanner::generate(
            $exam->id, $data['exam_date'], $data['time_key'] ?? '', $roomIds,
            $data['mode'] ?? SeatingPlan::MODE_SEPARATE, Auth::id()
        );
        if (!$ok) {
            return back()->with('flash_danger', $message);
        }
        Audit::log('exams.seating_generated', $plan,
            'Exam seating generated: exam ' . $exam->id . ' (' . $exam->name . '), slot '
            . $plan->exam_date . ' ' . SeatingPlanner::timeKey($plan->start_time)
            . ', mode ' . $plan->mode . ', ' . $plan->total_students . ' students');

        return redirect()->route('exam_seating.show', $plan->id)->with('flash_success', $message);
    }

    /** One plan: hall summary + per-hall seat tables + lifecycle.
     *  Readable by teamSA and by the Principal (who must review a
     *  submitted plan before publishing it); everyone else is
     *  bounced — teachers use their own scoped surface (§8). */
    public function show($id)
    {
        if (!Qs::userIsTeamSA() && !Qs::userIsPrincipal()) {
            return redirect()->route('dashboard');
        }
        $plan = SeatingPlan::with('exam')->findOrFail($id);
        $d['plan'] = $plan;
        $d['halls'] = SeatingPlanner::hallsForPlan($plan);
        $d['stale'] = SeatingPlanner::isStale($plan);
        $d['rooms'] = Room::orderBy('capacity')->orderBy('id')->get();

        return view('pages.support_team.exams.seating_show', $d);
    }

    /** Admin submits a draft plan for Principal approval. */
    public function submit($id)
    {
        $plan = SeatingPlan::findOrFail($id);
        if ($plan->status === SeatingPlan::PUBLISHED) {
            return back()->with('flash_danger', 'This plan is already published.');
        }
        if ($plan->status === SeatingPlan::SUBMITTED) {
            return back()->with('flash_info', 'This plan is already awaiting Principal approval.');
        }
        if (SeatingPlanner::isStale($plan)) {
            return back()->with('flash_danger', 'The date sheet or roster changed since this plan was generated. Regenerate it before submitting.');
        }
        $plan->status = SeatingPlan::SUBMITTED;
        $plan->submitted_by = Auth::id();
        $plan->submitted_at = now();
        $plan->approved_by = null;
        $plan->approved_at = null;
        $plan->save();
        Audit::log('exams.seating_submitted', $plan,
            'Exam seating submitted for approval: plan ' . $plan->id . ', exam ' . $plan->exam_id
            . ', slot ' . $plan->exam_date . ' ' . SeatingPlanner::timeKey($plan->start_time));

        return back()->with('flash_success', 'Seating plan submitted for Principal approval.');
    }

    /** Principal's queue: plans awaiting publication, plus the
     *  published plans (the Principal's return-for-correction is
     *  the only way a locked plan opens again — ONE-TIME
     *  GENERATION, owner standing rule 2026-11-08). */
    public function approvals()
    {
        if (!Qs::userIsPrincipal()) {
            return redirect()->route('dashboard');
        }
        $d['plans'] = SeatingPlan::with('exam')
            ->where('status', SeatingPlan::SUBMITTED)
            ->orderBy('exam_date')->orderBy('start_time')
            ->get();
        $d['published'] = SeatingPlan::with('exam')
            ->where('status', SeatingPlan::PUBLISHED)
            ->orderBy('exam_date')->orderBy('start_time')
            ->get();

        return view('pages.support_team.exams.seating_approvals', $d);
    }

    /** Principal alone publishes (date-sheet approvals pattern). */
    public function approve(Request $request)
    {
        if (!Qs::userIsPrincipal()) {
            return redirect()->route('dashboard');
        }
        $data = $request->validate(['plan_id' => 'required|integer']);
        $plan = SeatingPlan::find($data['plan_id']);
        if (!$plan || $plan->status !== SeatingPlan::SUBMITTED) {
            return back()->with('flash_danger', 'This plan is not awaiting approval.');
        }
        if (SeatingPlanner::isStale($plan)) {
            return back()->with('flash_danger', 'The date sheet or roster changed since this plan was generated. Return it for correction so the Admin can regenerate and re-submit it.');
        }
        $plan->status = SeatingPlan::PUBLISHED;
        $plan->approved_by = Auth::id();
        $plan->approved_at = now();
        $plan->save();
        Audit::log('exams.seating_published', $plan,
            'Exam seating published by Principal: plan ' . $plan->id . ', exam ' . $plan->exam_id
            . ', slot ' . $plan->exam_date . ' ' . SeatingPlanner::timeKey($plan->start_time));

        return back()->with('flash_success', 'Seating plan published.');
    }

    /**
     * Principal return path (ONE-TIME GENERATION, owner standing
     * rule 2026-11-08): a SUBMITTED or PUBLISHED plan goes back
     * to draft so the Admin can correct and regenerate it. This
     * is the ONLY way a locked plan opens again —
     * SeatingPlanner::generate refuses while it is submitted or
     * published. Audited, like the publication it undoes.
     * Mirrors ExamDateController::returnSheet.
     */
    public function returnPlan(Request $request)
    {
        if (!Qs::userIsPrincipal()) {
            return redirect()->route('dashboard');
        }
        $data = $request->validate(['plan_id' => 'required|integer']);
        $plan = SeatingPlan::find($data['plan_id']);
        if (!$plan || !in_array($plan->status, [SeatingPlan::SUBMITTED, SeatingPlan::PUBLISHED], true)) {
            return back()->with('flash_danger', 'This plan is not awaiting approval or published — there is nothing to return.');
        }
        $was = $plan->status;
        $plan->status = SeatingPlan::DRAFT;
        $plan->submitted_by = null;
        $plan->submitted_at = null;
        $plan->approved_by = null;
        $plan->approved_at = null;
        $plan->save();
        Audit::log('exams.seating_returned', $plan,
            'Exam seating returned for correction by Principal: plan ' . $plan->id . ', exam ' . $plan->exam_id
            . ', slot ' . $plan->exam_date . ' ' . SeatingPlanner::timeKey($plan->start_time) . ' — was ' . $was);

        return back()->with('flash_success', 'Seating plan returned to the Admin for correction.');
    }

    /** Screen/print hall sheet for one hall of one plan. */
    public function hallSheet(Request $request, $id)
    {
        $plan = SeatingPlan::with('exam')->findOrFail($id);
        $hall = $this->hallOrFail($plan, $request->query('room_id'));
        if (!$hall) {
            return redirect()->route('exam_seating.show', $plan->id)
                ->with('flash_danger', 'That hall is not part of this plan.');
        }

        return view('pages.support_team.exams.seating_hall_sheet', $this->hallSheetData($plan, $hall, false));
    }

    /** PDF hall sheet — same partial, for_pdf flag (§1.6). */
    public function hallSheetPdf(Request $request, $id)
    {
        $plan = SeatingPlan::with('exam')->findOrFail($id);
        $hall = $this->hallOrFail($plan, $request->query('room_id'));
        if (!$hall) {
            return redirect()->route('exam_seating.show', $plan->id)
                ->with('flash_danger', 'That hall is not part of this plan.');
        }
        $data = $this->hallSheetData($plan, $hall, true);
        $name = 'hall-sheet-' . Str::slug($plan->exam->name) . '-' . $plan->exam_date
            . '-' . Str::slug($hall['room_name']) . '.pdf';

        return Pdf::loadView('pages.support_team.exams.partials.hall_sheet_document', $data)
            ->setPaper('a4')->download($name);
    }

    /**
     * Teacher surface (§8): my published hall sheets — the halls of
     * PUBLISHED plans where a sitting I invigilate has seats.
     * Read-only; server-side scoped to my own duties (myDuties
     * precedent). Every other role goes back to the dashboard.
     */
    public function myHalls()
    {
        if (!Qs::userIsTeacher()) {
            return redirect()->route('dashboard');
        }
        $mySittingIds = ExamDuty::where('teacher_id', Auth::id())->pluck('exam_date_id')->all();
        $sheets = [];
        if (!empty($mySittingIds)) {
            $plans = SeatingPlan::with('exam')
                ->where('status', SeatingPlan::PUBLISHED)
                ->whereHas('seats', function ($q) use ($mySittingIds) {
                    $q->whereIn('exam_date_id', $mySittingIds);
                })
                ->orderBy('exam_date')->orderBy('start_time')
                ->get();
            foreach ($plans as $plan) {
                foreach (SeatingPlanner::hallsForPlan($plan) as $hall) {
                    $mine = $hall['seats']->whereIn('exam_date_id', $mySittingIds)->isNotEmpty();
                    if ($mine) {
                        $sheets[] = ['plan' => $plan, 'hall' => $hall];
                    }
                }
            }
        }

        return view('pages.support_team.exams.seating_my_halls', ['sheets' => $sheets]);
    }

    /** Teacher's read-only view of one of their published hall sheets. */
    public function myHallSheet($id, $roomId)
    {
        if (!Qs::userIsTeacher()) {
            return redirect()->route('dashboard');
        }
        $plan = SeatingPlan::with('exam')->findOrFail($id);
        if ($plan->status !== SeatingPlan::PUBLISHED) {
            return redirect()->route('exam_seating.my_halls')
                ->with('flash_info', 'That hall sheet is not published yet.');
        }
        $mySittingIds = ExamDuty::where('teacher_id', Auth::id())->pluck('exam_date_id')->all();
        $hall = $this->hallOrFail($plan, $roomId);
        if (!$hall || $hall['seats']->whereIn('exam_date_id', $mySittingIds)->isEmpty()) {
            return redirect()->route('exam_seating.my_halls')
                ->with('flash_danger', 'That hall sheet is not one of your duties.');
        }

        return view('pages.support_team.exams.seating_hall_sheet', $this->hallSheetData($plan, $hall, false));
    }

    /* ---------------- helpers ---------------- */

    protected function hallOrFail(SeatingPlan $plan, $roomId): ?array
    {
        if (!$roomId) {
            return null;
        }
        foreach (SeatingPlanner::hallsForPlan($plan) as $hall) {
            if ($hall['room_id'] === (int) $roomId) {
                return $hall;
            }
        }

        return null;
    }

    protected function hallSheetData(SeatingPlan $plan, array $hall, bool $forPdf): array
    {
        $school = ExamDateSheet::schoolInfo();
        if ($forPdf) {
            /* dompdf cannot fetch URLs — inline local images via
               the shared ExamDateSheet helper (§1.6, reuse). */
            foreach (['logo', 'stamp', 'principal_signature'] as $key) {
                if (!empty($school[$key])) {
                    $school[$key] = ExamDateSheet::pdfImageDataUri($school[$key]);
                }
            }
        }

        return [
            'plan' => $plan,
            'exam' => $plan->exam,
            'hall' => $hall,
            'school' => $school,
            'for_pdf' => $forPdf,
        ];
    }
}
