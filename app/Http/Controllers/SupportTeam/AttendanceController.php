<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MyClass;
use App\Models\StudentRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily attendance (audit §5/§9) — the feature the dashboard's
 * "Today's Attendance" panel reads from. One record per student per
 * day, enforced by the unique key on the attendances table.
 */
class AttendanceController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSAT');
    }

    public function index(Request $request)
    {
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['selected_class'] = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : $d['classes']->first();
        $d['date'] = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::today();
        $d['students'] = collect();
        $d['existing'] = [];

        if ($d['selected_class']) {
            $d['students'] = StudentRecord::with('user', 'section')
                ->where('my_class_id', $d['selected_class']->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();

            $d['existing'] = Attendance::whereDate('date', $d['date']->toDateString())
                ->whereIn('student_record_id', $d['students']->pluck('id'))
                ->pluck('status', 'student_record_id')
                ->all();
        }

        $d['summary'] = [
            'present' => count(array_filter($d['existing'], function ($s) { return $s === 'present'; })),
            'absent' => count(array_filter($d['existing'], function ($s) { return $s === 'absent'; })),
            'late' => count(array_filter($d['existing'], function ($s) { return $s === 'late'; })),
        ];

        return view('pages.support_team.attendance.index', $d);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'class_id' => 'required|exists:my_classes,id',
            'statuses' => 'required|array',
            'statuses.*' => 'required|in:' . implode(',', Attendance::statuses()),
        ]);

        $studentIds = StudentRecord::where('my_class_id', $data['class_id'])
            ->where('grad', 0)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $studentIds, $request) {
            foreach ($data['statuses'] as $studentRecordId => $status) {
                if (!in_array((int) $studentRecordId, $studentIds, true)) {
                    continue; // never record attendance for another class via a tampered form
                }

                Attendance::updateOrCreate(
                    ['student_record_id' => (int) $studentRecordId, 'date' => $data['date']],
                    ['status' => $status, 'recorded_by' => $request->user()->id]
                );
            }
        });

        return redirect()
            ->route('attendance.index', ['class_id' => $data['class_id'], 'date' => $data['date']])
            ->with('flash_success', 'Attendance saved for ' . Carbon::parse($data['date'])->format('d M Y') . '.');
    }
}
