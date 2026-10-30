<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\StudentRecord;
use App\Support\LeaveAutoCover;
use App\Support\LeaveCover;
use App\Support\Notify;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    /** Roles that may apply for leave. Students apply too
     *  (owner order 2026-10-28) — their class teacher decides,
     *  not the Admin. Parents and the platform Super Admin never
     *  apply. */
    public const APPLICANTS = ['teacher', 'accountant', 'librarian', 'admin', 'student', 'principal'];

    protected function mustBeApplicant()
    {
        if (!in_array(Auth::user()->user_type, self::APPLICANTS, true)) {
            return redirect()->route('dashboard');
        }
        return null;
    }

    /** The applicant's own requests + the apply form. */
    public function myIndex()
    {
        if ($r = $this->mustBeApplicant()) {
            return $r;
        }
        $approverName = null;
        if (Auth::user()->user_type === 'student') {
            $record = \App\Models\StudentRecord::where('user_id', Auth::id())
                ->where('grad', 0)->orderByDesc('id')->first();
            $approverName = $record && $record->section
                ? optional($record->section->teacher)->name
                : null;
        }
        return view('pages.support_team.leave.my', [
            'requests' => LeaveRequest::where('user_id', Auth::id())->orderByDesc('id')->paginate(25),
            'approver_name' => $approverName,
            // Paid-leave balance for staff (owner order 2026-10-30):
            // quota, taken, remaining, extra — always visible where
            // they apply. Students have no salary quota: null.
            'leave_balance' => in_array(Auth::user()->user_type, ['teacher', 'accountant', 'librarian', 'admin', 'principal'], true)
                ? \App\Support\LeaveBalance::summary(Auth::id(), (int) now()->format('Y'))
                : null,
        ]);
    }

    public function store(Request $request)
    {
        if ($r = $this->mustBeApplicant()) {
            return $r;
        }
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string|max:1000',
            // The form always sends this; older callers default to
            // normal so existing flows never break on the new field.
            'leave_type' => 'nullable|in:normal,emergency',
            // Partial leave (owner order 2026-10-29): half a day or
            // a few periods of ONE day, same workflow.
            'day_part' => 'nullable|in:first_half,second_half,periods',
            'period_from' => 'nullable|integer|min:1|max:12',
            'period_to' => 'nullable|integer|min:1|max:12',
        ]);
        if (($data['day_part'] ?? null) === 'periods') {
            if (empty($data['period_from']) || empty($data['period_to'])) {
                return back()->withInput()->with('flash_danger', 'Choose the period range for a period-only leave.');
            }
            if ((int) $data['period_to'] < (int) $data['period_from']) {
                return back()->withInput()->with('flash_danger', 'The last period cannot be before the first period.');
            }
        }
        // A partial leave lives inside a single day.
        if (!empty($data['day_part'])) {
            $data['end_date'] = $data['start_date'];
        }
        // Overlap guard (owner order 2026-10-30): nobody holds two
        // leaves over the same period. A new application that
        // touches a date AND period window already covered by the
        // applicant's own pending or approved leave is refused,
        // naming that leave — a teacher away for ten days cannot
        // apply again inside those ten days. Two partial leaves on
        // one day still combine when their windows do not touch
        // (first half + second half is a legitimate pair).
        $newWindow = (new LeaveRequest([
            'day_part' => $data['day_part'] ?? null,
            'period_from' => $data['period_from'] ?? null,
            'period_to' => $data['period_to'] ?? null,
        ]))->periodWindow() ?? [1, 8];
        $overlap = LeaveRequest::where('user_id', Auth::id())
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->orderBy('start_date')
            ->get()
            ->first(function ($existing) use ($newWindow) {
                $w = $existing->periodWindow() ?? [1, 8];
                return $newWindow[0] <= $w[1] && $w[0] <= $newWindow[1];
            });
        if ($overlap) {
            return back()->withInput()->with('flash_danger',
                'You already have a ' . $overlap->status . ' leave covering these dates ('
                . \Carbon\Carbon::parse($overlap->start_date)->format('d/m/Y')
                . ' to ' . \Carbon\Carbon::parse($overlap->end_date)->format('d/m/Y')
                . ($overlap->partialLabel() ? ' — ' . $overlap->partialLabel() : '')
                . '). Please choose dates outside that leave.');
        }
        $isEmergency = ($data['leave_type'] ?? 'normal') === 'emergency';
        $leave = LeaveRequest::create([
            'user_id' => Auth::id(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'leave_type' => $data['leave_type'] ?? 'normal',
            'day_part' => $data['day_part'] ?? null,
            'period_from' => ($data['day_part'] ?? null) === 'periods' ? ($data['period_from'] ?? null) : null,
            'period_to' => ($data['day_part'] ?? null) === 'periods' ? ($data['period_to'] ?? null) : null,
            // Owner order 2026-10-28: emergency is auto-approved
            // within seconds — synchronously at submit, so no
            // worker or cron is involved (free Render runs none).
            // decided_by stays null: no human decided it.
            'status' => $isEmergency ? 'approved' : 'pending',
            'decided_at' => $isEmergency ? now() : null,
            'decision_note' => $isEmergency ? 'Auto-approved (emergency).' : null,
        ]);
        if ($isEmergency) {
            // Inform, don't ask: the approver who would have
            // decided gets a bell item so the absence is visible.
            // Overnight fix (adopted from a5e6b05): a bell failure
            // must never 500 an already-created, auto-approved
            // leave. Notify already degrades to silence when its
            // table is missing, but any other bell failure (route,
            // column, recipient lookup) used to take the whole
            // submit down with it — the leave was created, yet the
            // user saw a 500 and could retry into a duplicate.
            // Swallow bell failures here; the approval itself
            // stands regardless.
            try {
                $me = Auth::user();
                $title = 'Emergency leave: ' . $me->name;
                $body = $me->name . ' is on emergency leave from ' . $data['start_date'] . ' to ' . $data['end_date'] . '. Reason: ' . $data['reason'];
                if ($me->user_type === 'student') {
                    $record = StudentRecord::where('user_id', $me->id)
                        ->where('grad', 0)->orderByDesc('id')->first();
                    if ($record && $record->section && $record->section->teacher_id) {
                        Notify::toUser($record->section->teacher_id, 'leave_emergency', $title, $body, route('leave.student_requests'));
                    }
                } else {
                    // Staff emergency: notify everyone who could have
                    // decided it — admins AND the principal (designated
                    // approver). Fixed by parent 2026-10-28 (C round-5
                    // finding: the loop previously covered admins only).
                    foreach (User::whereIn('user_type', ['admin', 'principal'])->pluck('id') as $adminId) {
                        Notify::toUser($adminId, 'leave_emergency', $title, $body, route('leave.index'));
                    }
                }
            } catch (\Throwable $e) {
                // Bell only — approval stands, no 500, no retry trap.
            }
            // Owner order 2026-10-29: emergency leave activates
            // immediately, and the SYSTEM arranges cover for it at
            // once — nobody waits for an approver. Unresolved
            // periods alert admins + principal from inside the
            // arranger. A cover failure must never 500 the submit.
            try {
                LeaveAutoCover::arrange($leave, null, true, false);
            } catch (\Throwable $e) {
                // Cover is best-effort here; the queue still shows
                // the uncovered count for manual arranging.
            }
            // The chain (owner order 2026-10-29): covers this
            // teacher was holding for others move on as well.
            try {
                LeaveAutoCover::reassignForAbsentSubstitute($leave, null);
            } catch (\Throwable $e) {
                // The auto-approval stands regardless.
            }
            // Owner order 2026-10-29 (night): the plan goes LIVE
            // right here — an emergency has no approval step, so
            // there is nothing left to submit anywhere else.
            try {
                LeaveAutoCover::confirmPlanFor($leave, null);
            } catch (\Throwable $e) {
                // The auto-approval stands regardless.
            }
            return back()->with('flash_success', 'Emergency leave auto-approved.');
        }
        // Owner order 2026-10-29 (live report): a NORMAL leave
        // submission belled nobody — the teacher applied and the
        // Admin saw nothing. Bell everyone who can decide this
        // leave: for a student, the section teacher plus Admin and
        // Principal; for staff, Admin and Principal (never the
        // applicant themself). Same swallow rule as the emergency
        // path: a bell failure must never 500 the submission.
        try {
            $me = Auth::user();
            $title = 'New leave request: ' . $me->name;
            $body = $me->name . ' applied for leave from '
                . \Carbon\Carbon::parse($data['start_date'])->format('d/m/Y')
                . ' to ' . \Carbon\Carbon::parse($data['end_date'])->format('d/m/Y')
                . ($leave->partialLabel() ? ' (' . $leave->partialLabel() . ')' : '')
                . '. Reason: ' . $data['reason'];
            if ($me->user_type === 'student') {
                $record = StudentRecord::where('user_id', $me->id)
                    ->where('grad', 0)->orderByDesc('id')->first();
                if ($record && $record->section && $record->section->teacher_id) {
                    Notify::toUser($record->section->teacher_id, 'leave_submitted', $title, $body, route('leave.student_requests'));
                }
                foreach (User::whereIn('user_type', ['admin', 'principal'])->pluck('id') as $adminId) {
                    Notify::toUser($adminId, 'leave_submitted', $title, $body, route('leave.index'));
                }
            } else {
                foreach (User::whereIn('user_type', ['admin', 'principal'])->where('id', '!=', $me->id)->pluck('id') as $adminId) {
                    Notify::toUser($adminId, 'leave_submitted', $title, $body, route('leave.index'));
                }
            }
        } catch (\Throwable $e) {
            // Bell only — the submission stands regardless.
        }
        // Owner order 2026-10-29 (night, live queue screenshot):
        // "yahi pe toh system allocate karega" — the cover plan
        // must already exist when the Admin opens this queue, not
        // appear only after the approval. Prepare it now, quietly:
        // the plan's rows are 'pending', they grant no access and
        // ring no bells until the leave is approved and the Admin
        // submits the plan on the board; a rejection deletes the
        // plan again (see decide()). The arranger no-ops for
        // non-teachers. Best-effort — a planning failure must
        // never 500 the submission.
        try {
            LeaveAutoCover::arrange($leave, null, false);
        } catch (\Throwable $e) {
            // The queue still shows the uncovered count.
        }
        $approver = Auth::user()->user_type === 'student'
            ? 'your class teacher will approve or reject it.'
            : 'the Admin will approve or reject it.';
        return back()->with('flash_success', 'Leave request submitted — ' . $approver);
        $approver = Auth::user()->user_type === 'student'
            ? 'your class teacher will approve or reject it.'
            : 'the Admin will approve or reject it.';
        return back()->with('flash_success', 'Leave request submitted — ' . $approver);
    }

    /** Admin review queue: emergency pending first, then other
     *  pending, then decided. */
    public function index()
    {
        // Limited principal role (owner order 2026-10-28): the
        // approval queue is the principal's Approval Center.
        if (!\App\Helpers\Qs::userIsTeamSAWrite() && !\App\Helpers\Qs::userIsPrincipal()) {
            return redirect()->route('dashboard');
        }
        return view('pages.support_team.leave.index', [
            'requests' => $this->attachCoverInfo(LeaveRequest::with('user')->orderByRaw("CASE WHEN status = 'pending' AND leave_type = 'emergency' THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")->orderByDesc('id')->paginate(25)),
        ]);
    }

    /**
     * Leave-queue display enrichment (roles agent C, wired by parent
     * 2026-10-28 after the principal-role change landed): per row,
     * the applicant's allocated classes + the count of their periods
     * inside the leave dates with no substitute. The blade renders
     * these as "Allocated Classes" / "Uncovered Periods" columns and
     * degrades to dashes when the attributes are absent. Memoised
     * per user per page; non-teachers and rejected leaves get null
     * (no cover owed).
     */
    protected function attachCoverInfo($requests)
    {
        $allocCache = [];
        foreach ($requests as $r) {
            $user = $r->user;
            if (!$user) { $r->allocation_lines = []; $r->uncovered_periods = null; continue; }
            // Overnight fix (adopted from a5e6b05): cover info is
            // display-only — the blade already renders dashes when
            // these attributes are absent. One bad row (bad dates,
            // a cover query failure) must never 500 the whole leave
            // queue for every other row. Degrade that row to dashes
            // and keep the queue.
            try {
                $allocCache[$user->id] ??= [
                    'lines' => LeaveCover::allocationLines($user),
                    'summary' => LeaveCover::allocationSummary($user),
                ];
                $r->allocation_lines = $allocCache[$user->id]['lines'];
                $r->allocation_summary = $allocCache[$user->id]['summary'];
                $r->uncovered_periods = ($user->user_type === 'teacher' && in_array($r->status, ['pending', 'approved'], true))
                    ? LeaveCover::uncoveredCount($user->id, $r->start_date, $r->end_date, $r->periodWindow()) : null;
                // Owner order 2026-10-29: a bare "Covered" badge is
                // not enough — the queue must say WHO covers. Names
                // of the distinct substitutes standing in for this
                // teacher inside the leave window.
                $r->cover_names = [];
                $r->planned_periods = null;
                $r->plan_rows = collect();
                $r->plan_free_options = [];
                if ($user->user_type === 'teacher' && in_array($r->status, ['pending', 'approved'], true)) {
                    $coverRows = \App\Models\Substitution::with([
                            'substitute',
                            'time_table.subject', 'time_table.time_slot',
                            'time_table.tt_record.my_class', 'time_table.tt_record.section',
                        ])
                        ->where('absent_teacher_id', $user->id)
                        ->whereDate('date', '>=', \Carbon\Carbon::parse($r->start_date)->toDateString())
                        ->whereDate('date', '<=', \Carbon\Carbon::parse($r->end_date)->toDateString())
                        ->get();
                    $r->cover_names = $coverRows
                        ->map(function ($sub) {
                            return optional($sub->substitute)->name;
                        })
                        ->filter()->unique()->values()->all();
                    // How many periods already carry a system plan /
                    // cover row — the queue renders a pending leave
                    // with a plan as "Plan ready", not as a failure.
                    $r->planned_periods = $coverRows->count();
                    // Owner order 2026-10-29 (night): the plan
                    // itself renders on THIS page — every prepared
                    // row with its switch options (teachers free at
                    // that slot, computed by the board's own helper
                    // so the two pages can never disagree). The
                    // leave page is where the plan is managed; the
                    // Substitutions board is only the record.
                    if ($r->status === 'pending') {
                        $r->plan_rows = $coverRows->where('status', 'pending')->values();
                        if ($r->plan_rows->isNotEmpty()) {
                            $board = new \App\Http\Controllers\SupportTeam\SubstitutionController();
                            // Built locally, then assigned whole —
                            // offset-writing a magic model property
                            // is an indirect modification and throws.
                            $freeOptions = [];
                            foreach ($r->plan_rows as $planRow) {
                                $freeOptions[$planRow->id] = $board->freeTeachersFor($planRow);
                            }
                            $r->plan_free_options = $freeOptions;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $r->allocation_lines = [];
                $r->uncovered_periods = null;
                $r->cover_names = [];
                $r->planned_periods = null;
                $r->plan_rows = collect();
                $r->plan_free_options = [];
            }
        }
        return $requests;
    }

    /**
     * Teacher queue: pending student leaves for sections the
     * teacher teaches (sections.teacher_id). Reuses the admin
     * queue view — same table, same decide forms. Admins keep
     * their own full queue above; this page is teacher-only.
     */
    public function studentRequests()
    {
        if (!\App\Helpers\Qs::userIsTeacher()) {
            return redirect()->route('dashboard');
        }
        $sectionIds = \App\Models\Section::where('teacher_id', Auth::id())->pluck('id')->all();
        $studentIds = \App\Models\StudentRecord::whereIn('section_id', $sectionIds)->pluck('user_id')->all();
        return view('pages.support_team.leave.index', [
            'requests' => $this->attachCoverInfo(LeaveRequest::with('user')->whereIn('user_id', $studentIds)
                ->orderByRaw("CASE WHEN status = 'pending' AND leave_type = 'emergency' THEN 0 WHEN status = 'pending' THEN 1 ELSE 2 END")->orderByDesc('id')->paginate(25)),
        ]);
    }

    /**
     * Decision. Admins decide anything (except their own).
     * Teachers decide ONLY student leaves for sections they
     * teach. Anyone else is bounced to the dashboard.
     */
    public function decide(Request $request, LeaveRequest $leave)
    {
        $isAdmin = \App\Helpers\Qs::userIsTeamSAWrite();
        // Limited principal role (owner order 2026-10-28): leave
        // decisions are a designated principal approval — any
        // leave, staff or student. The self-decision ban below
        // still applies to principals.
        $isPrincipal = \App\Helpers\Qs::userIsPrincipal();
        $canDecideAsTeacher = false;
        if (!$isAdmin && \App\Helpers\Qs::userIsTeacher()) {
            $applicant = \App\User::find($leave->user_id);
            if ($applicant && $applicant->user_type === 'student') {
                $record = \App\Models\StudentRecord::where('user_id', $applicant->id)
                    ->orderByDesc('id')->first();
                $canDecideAsTeacher = $record && $record->section_id
                    && (int) optional($record->section)->teacher_id === (int) Auth::id();
            }
        }
        if (!$isAdmin && !$canDecideAsTeacher && !$isPrincipal) {
            return redirect()->route('dashboard');
        }
        $data = $request->validate([
            'status' => 'required|in:approved,rejected',
            'decision_note' => 'nullable|string|max:1000',
        ]);
        if ($leave->status !== 'pending') {
            return back()->with('flash_danger', 'This request has already been decided.');
        }
        // No self-approval: an admin cannot decide their own leave.
        if ((int) $leave->user_id === (int) Auth::id()) {
            return back()->with('flash_danger', 'You cannot decide your own leave request — another admin must decide it.');
        }
        $leave->update([
            'status' => $data['status'],
            'decided_by' => Auth::id(),
            'decided_at' => now(),
            'decision_note' => $data['decision_note'] ?? null,
        ]);
        // Owner order 2026-10-29: on approval the SYSTEM arranges
        // the substitute cover itself (the admin approves the leave;
        // the system makes the arrangement). Emergency leave runs
        // the same arranger at submit. Non-teacher applicants have
        // no periods, so the arranger simply no-ops for them.
        if ($data['status'] === 'approved') {
            try {
                LeaveAutoCover::arrange($leave, (int) Auth::id(), true, false);
            } catch (\Throwable $e) {
                // The decision stands; uncovered periods remain
                // visible in the queue for manual arranging.
            }
            // The chain (owner order 2026-10-29): if the approved
            // teacher was themselves covering periods for others,
            // those covers move to the next free teacher now.
            try {
                LeaveAutoCover::reassignForAbsentSubstitute($leave, (int) Auth::id());
            } catch (\Throwable $e) {
                // The decision stands regardless.
            }
            // Owner order 2026-10-29 (night): approving the leave
            // on THIS page also makes its cover plan live — the
            // Substitutions board is a record, not a second
            // workplace with its own submit step.
            try {
                LeaveAutoCover::confirmPlanFor($leave, (int) Auth::id());
            } catch (\Throwable $e) {
                // The decision stands regardless.
            }
        }
        if ($data['status'] === 'rejected') {
            // The provisional plan prepared at submit dies with
            // the rejection — the teacher is not absent, so no
            // period may stay arranged for those dates. Only
            // still-pending rows are removed: a cover the Admin
            // already submitted on the board is their deliberate
            // act and stays theirs to remove.
            try {
                \App\Models\Substitution::where('absent_teacher_id', $leave->user_id)
                    ->where('status', 'pending')
                    ->whereDate('date', '>=', \Carbon\Carbon::parse($leave->start_date)->toDateString())
                    ->whereDate('date', '<=', \Carbon\Carbon::parse($leave->end_date)->toDateString())
                    ->delete();
            } catch (\Throwable $e) {
                // The decision stands regardless.
            }
        }
        return back()->with('flash_success', 'Leave request ' . $data['status'] . '.');
    }
}
