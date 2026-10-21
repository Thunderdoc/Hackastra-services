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

    public function report(Request $request)
    {
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['selected_class'] = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : $d['classes']->first();
        $monthParam = $request->query('month');
        $d['month'] = $monthParam
            ? Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth()
            : Carbon::today()->startOfMonth();
        $d['rows'] = collect();
        $d['totals'] = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];

        if ($d['selected_class']) {
            $students = StudentRecord::with('user', 'section')
                ->where('my_class_id', $d['selected_class']->id)
                ->where('grad', 0)
                ->orderBy('id')
                ->get();

            $grouped = Attendance::whereIn('student_record_id', $students->pluck('id'))
                ->whereYear('date', $d['month']->year)
                ->whereMonth('date', $d['month']->month)
                ->get()
                ->groupBy('student_record_id');

            $d['rows'] = $students->map(function ($student) use ($grouped, &$d) {
                $records = $grouped->get($student->id, collect());
                $present = $records->where('status', Attendance::STATUS_PRESENT)->count();
                $absent = $records->where('status', Attendance::STATUS_ABSENT)->count();
                $late = $records->where('status', Attendance::STATUS_LATE)->count();
                $marked = $present + $absent + $late;
                $d['totals']['present'] += $present;
                $d['totals']['absent'] += $absent;
                $d['totals']['late'] += $late;
                $d['totals']['marked'] += $marked;

                return [
                    'student' => $student,
                    'present' => $present,
                    'absent' => $absent,
                    'late' => $late,
                    'marked' => $marked,
                    // Late still counts as attended; unmarked days are excluded, never faked as absent.
                    'percent' => $marked > 0 ? round((($present + $late) / $marked) * 100, 1) : null,
                ];
            });
        }

        return view('pages.support_team.attendance.report', $d);
    }
}
