<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    /** Staff roles that may apply for leave (everyone except
     *  students, parents and the platform Super Admin). */
    public const APPLICANTS = ['teacher', 'accountant', 'librarian', 'admin'];

    protected function mustBeApplicant()
    {
        if (!in_array(Auth::user()->user_type, self::APPLICANTS, true)) {
            return redirect()->route('dashboard');
        }
        return null;
    }

    /** The staff member's own requests + the apply form. */
    public function myIndex()
    {
        if ($r = $this->mustBeApplicant()) {
            return $r;
        }
        return view('pages.support_team.leave.my', [
            'requests' => LeaveRequest::where('user_id', Auth::id())->orderByDesc('id')->paginate(25),
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
        ]);
        LeaveRequest::create([
            'user_id' => Auth::id(),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);
        return back()->with('flash_success', 'Leave request submitted — the Admin will approve or reject it.');
    }

    /** Admin review queue: pending first. */
    public function index()
    {
        if (!\App\Helpers\Qs::userIsTeamSAWrite()) {
            return redirect()->route('dashboard');
        }
        return view('pages.support_team.leave.index', [
            'requests' => LeaveRequest::with('user')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')->paginate(25),
        ]);
    }

    /** Admin decision. Only a pending request can be decided. */
    public function decide(Request $request, LeaveRequest $leave)
    {
        if (!\App\Helpers\Qs::userIsTeamSAWrite()) {
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
        return back()->with('flash_success', 'Leave request ' . $data['status'] . '.');
    }
}
