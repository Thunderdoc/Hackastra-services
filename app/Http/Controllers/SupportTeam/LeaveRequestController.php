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
        ]);
        $isEmergency = ($data['leave_type'] ?? 'normal') === 'emergency';
        // TEMPORARY LIVE DIAGNOSTIC (2026-10-29, remove once fixed):
        // leave submit 500s ONLY on the live MariaDB/PHP 8.4 stack —
        // local SQLite runs this code green and the container's log
        // file is unreachable from outside. Until the cause is known,
        // surface the real exception as the response body so it can
        // be read off the page; then this catch comes out.
        try {
            $leave = LeaveRequest::create([
            'user_id' => Auth::id(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'leave_type' => $data['leave_type'] ?? 'normal',
            // Owner order 2026-10-28: emergency is auto-approved
            // within seconds — synchronously at submit, so no
            // worker or cron is involved (free Render runs none).
            // decided_by stays null: no human decided it.
            'status' => $isEmergency ? 'approved' : 'pending',
            'decided_at' => $isEmergency ? now() : null,
            'decision_note' => $isEmergency ? 'Auto-approved (emergency).' : null,
            ]);
        } catch (\Throwable $e) {
            return response('LEAVE-DIAG: ' . get_class($e) . ': ' . $e->getMessage(), 500);
        }
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
                LeaveAutoCover::arrange($leave, null);
            } catch (\Throwable $e) {
                // Cover is best-effort here; the queue still shows
                // the uncovered count for manual arranging.
            }
            return back()->with('flash_success', 'Emergency leave auto-approved.');
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
                $allocCache[$user->id] ??= LeaveCover::allocationLines($user);
                $r->allocation_lines = $allocCache[$user->id];
                $r->uncovered_periods = ($user->user_type === 'teacher' && in_array($r->status, ['pending', 'approved'], true))
                    ? LeaveCover::uncoveredCount($user->id, $r->start_date, $r->end_date) : null;
            } catch (\Throwable $e) {
                $r->allocation_lines = [];
                $r->uncovered_periods = null;
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
                LeaveAutoCover::arrange($leave, (int) Auth::id());
            } catch (\Throwable $e) {
                // The decision stands; uncovered periods remain
                // visible in the queue for manual arranging.
            }
        }
        return back()->with('flash_success', 'Leave request ' . $data['status'] . '.');
    }
}
