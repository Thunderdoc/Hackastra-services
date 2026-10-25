<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\StaffAttendance;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAttendanceController extends Controller
{
    public function __construct()
    {
        // Admin only — the staff register is a school-office record.
        $this->middleware('teamSAWrite');
    }

    /** Register for one day: every staff member, one status each. */
    public function index(Request $request)
    {
        $date = $request->get('date', date('Y-m-d'));
        $staff = User::whereIn('user_type', ['teacher', 'accountant', 'librarian', 'admin'])
            ->orderBy('user_type')->orderBy('name')->get();
        $marks = StaffAttendance::where('date', $date)->get()->keyBy('user_id');
        return view('pages.support_team.staff_attendance.index', [
            'date' => $date,
            'staff' => $staff,
            'marks' => $marks,
            'locked' => $marks->isNotEmpty(),
        ]);
    }

    /** Mark the whole day at once. Locked once marked — like the
     *  student register, a marked day is never re-marked. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'status' => 'required|array',
            'status.*' => 'in:present,absent,late',
        ]);

        if (StaffAttendance::where('date', $data['date'])->exists()) {
            return back()->with('flash_danger', 'Attendance for this date is already marked and cannot be changed.');
        }

        $rows = [];
        foreach ($data['status'] as $userId => $status) {
            $rows[] = [
                'user_id' => (int) $userId,
                'date' => $data['date'],
                'status' => $status,
                'marked_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        StaffAttendance::insert($rows);

        return back()->with('flash_success', count($rows) . ' staff attendance record(s) saved for ' . $data['date'] . '.');
    }
}
