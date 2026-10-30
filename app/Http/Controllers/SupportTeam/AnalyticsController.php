<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\MyClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Wave 2 feature 5 — Analytics Dashboard.
 *
 * READ-ONLY trends computed LIVE from existing tables; nothing is
 * stored, nothing is invented, no new model, no migration, no chart
 * library (the app ships no chart JS, so trends render as
 * server-side CSS bars plus data tables).
 *
 * The three blocks and their exact sources:
 *
 * 1. Attendance % per class per month — period-attendance sessions
 *    (attendance_sessions) joined to their entries
 *    (attendance_session_entries), using the app's standard rule:
 *    (present + late) / marked * 100, where marked =
 *    present + late + absent. A class-month with zero marked entries
 *    has no percentage (null), never a division by zero.
 * 2. Fee collection per month — PaymentRecord rows
 *    (payment_records) with paid = 1, summed amt_paid and grouped by
 *    the created_at month. Outstanding = SUM(balance) over the
 *    payment_records in scope (paid rows carry balance 0 in the
 *    seed, so this equals the unpaid outstanding). Class scoping
 *    joins student_records on user_id = payment_records.student_id
 *    (verified 1:1 — every payment row has exactly one student
 *    record, no row is double-counted).
 * 3. Marks averages per class per exam — exam_records.ave, the
 *    per-student average the app itself already computes: the marks
 *    recompute pipeline (MarkComputation::recomputeRecords) writes
 *    ave = the student's average across subjects for the term. The
 *    dashboard averages that stored per-student figure per
 *    (class, exam) with AVG(CAST(ave AS REAL)). It never re-averages
 *    raw Mark rows and never invents scores. Marks carry no month,
 *    so the month range does not apply to this block; it takes an
 *    optional exam filter instead.
 *
 * Filters (all query params, never route params): class_id (single
 * class or all), from_month / to_month (YYYY-MM, applied to the
 * attendance and fee blocks only), exam_id (marks block only).
 *
 * Roles (owner order 2026-10-30): the school ADMIN's page alone.
 * Teachers had a TeacherScope-limited view until today — the
 * owner ruled the analytics dashboard is of no use to a teacher
 * and removed it from their account entirely (their class numbers
 * live on their own dashboards and class overviews); they now get
 * a plain 403 and no menu link. The Super Admin is platform-only
 * and is sent to his own dashboard like every other school GET.
 * The Principal keeps his Command Center analytics and stays out
 * of this page. Do not widen without a fresh owner decision.
 */
class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        // Platform-only doctrine (owner, 2026-10-28): the Super
        // Admin's place is the platform dashboard — school pages,
        // this one included, send him back there like every other
        // school GET.
        if ($user && $user->user_type === 'super_admin') {
            return redirect('/dashboard');
        }
        // Admin alone (owner order 2026-10-30: teachers removed
        // from the analytics dashboard entirely).
        if (!$user || $user->user_type !== 'admin') {
            abort(403);
        }

        $allowedClassIds = MyClass::orderBy('id')->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();

        $classes = MyClass::whereIn('id', $allowedClassIds)->orderBy('id')->get();

        $classId = $request->query('class_id');
        $classId = ($classId !== null && $classId !== '' && is_numeric($classId)) ? (int) $classId : null;
        if ($classId !== null) {
            if (!MyClass::where('id', $classId)->exists()) {
                abort(404);
            }
        }

        $fromMonth = $this->monthParam($request->query('from_month'));
        $toMonth = $this->monthParam($request->query('to_month'));
        if ($fromMonth && $toMonth && $fromMonth > $toMonth) {
            list($fromMonth, $toMonth) = [$toMonth, $fromMonth];
        }

        $examId = $request->query('exam_id');
        $examId = ($examId !== null && $examId !== '' && is_numeric($examId)) ? (int) $examId : null;

        $scopeClassIds = $classId !== null ? [$classId] : $allowedClassIds;
        $classNames = $classes->pluck('name', 'id')->all();

        $attendance = $this->attendanceRows($scopeClassIds, $fromMonth, $toMonth, $classNames);
        $fees = $this->feeRows($scopeClassIds, $fromMonth, $toMonth);
        $feeOutstanding = $this->feeOutstanding($scopeClassIds, $classNames);
        $marks = $this->marksRows($scopeClassIds, $examId, $classNames);

        // Weighted overall attendance for the KPI strip: total
        // (present + late) over total marked across the rows in
        // scope — the same standard rule, never an average of
        // percentages.
        $attMarked = array_sum(array_column($attendance, 'marked'));
        $attPresent = array_sum(array_column($attendance, 'present')) + array_sum(array_column($attendance, 'late'));
        $attendanceOverall = $attMarked > 0 ? (int) round($attPresent / $attMarked * 100) : null;

        $maxFee = 0;
        foreach ($fees as $row) {
            $maxFee = max($maxFee, (int) $row['total']);
        }

        $exams = DB::table('exam_records')
            ->join('exams', 'exams.id', '=', 'exam_records.exam_id')
            ->when(!empty($scopeClassIds), function ($q) use ($scopeClassIds) {
                return $q->whereIn('exam_records.my_class_id', $scopeClassIds);
            })
            ->select('exams.id', 'exams.name')
            ->distinct()
            ->orderBy('exams.id')
            ->get();

        return view('pages.support_team.analytics.index', [
            'classes' => $classes,
            'selectedClassId' => $classId,
            'fromMonth' => $fromMonth,
            'toMonth' => $toMonth,
            'selectedExamId' => $examId,
            'exams' => $exams,
            'attendance' => $attendance,
            'fees' => $fees,
            'feeTotal' => array_sum(array_column($fees, 'total')),
            'feeMax' => $maxFee,
            'feeOutstanding' => $feeOutstanding,
            'feeOutstandingTotal' => array_sum(array_column($feeOutstanding, 'outstanding')),
            'marks' => $marks,
            'attendanceOverall' => $attendanceOverall,
            'classCount' => count($scopeClassIds),
        ]);
    }

    /** Validate a YYYY-MM month param; anything else is ignored. */
    protected function monthParam($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            return null;
        }

        return $value;
    }

    /** Driver-aware YYYY-MM expression for a date/datetime column. */
    protected function monthExpr($column)
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    protected function applyMonthRange($query, $monthExpr, $fromMonth, $toMonth)
    {
        if ($fromMonth) {
            $query->whereRaw("{$monthExpr} >= ?", [$fromMonth]);
        }
        if ($toMonth) {
            $query->whereRaw("{$monthExpr} <= ?", [$toMonth]);
        }

        return $query;
    }

    protected function attendanceRows(array $scopeClassIds, $fromMonth, $toMonth, array $classNames)
    {
        if (empty($scopeClassIds)) {
            return [];
        }

        $monthExpr = $this->monthExpr('s.date');
        $query = DB::table('attendance_sessions as s')
            ->join('attendance_session_entries as e', 'e.session_id', '=', 's.id')
            ->whereIn('s.my_class_id', $scopeClassIds)
            ->selectRaw("{$monthExpr} as ym, s.my_class_id as class_id")
            ->selectRaw("SUM(CASE WHEN e.status = 'present' THEN 1 ELSE 0 END) as present")
            ->selectRaw("SUM(CASE WHEN e.status = 'late' THEN 1 ELSE 0 END) as late")
            ->selectRaw("SUM(CASE WHEN e.status = 'absent' THEN 1 ELSE 0 END) as absent")
            ->groupBy('ym', 's.my_class_id')
            ->orderBy('ym')
            ->orderBy('s.my_class_id');
        $this->applyMonthRange($query, $monthExpr, $fromMonth, $toMonth);

        $rows = [];
        foreach ($query->get() as $row) {
            $marked = (int) $row->present + (int) $row->late + (int) $row->absent;
            $rows[] = [
                'month' => $row->ym,
                'month_label' => date('M Y', strtotime($row->ym . '-01')),
                'class_id' => (int) $row->class_id,
                'class_name' => $classNames[(int) $row->class_id] ?? ('Class ' . $row->class_id),
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'absent' => (int) $row->absent,
                'marked' => $marked,
                // Null when nothing was marked: no percentage is shown,
                // and no division by zero can occur.
                'pct' => $marked > 0
                    ? (int) round(((int) $row->present + (int) $row->late) / $marked * 100)
                    : null,
            ];
        }

        return $rows;
    }

    protected function feeRows(array $scopeClassIds, $fromMonth, $toMonth)
    {
        if (empty($scopeClassIds)) {
            return [];
        }

        $monthExpr = $this->monthExpr('payment_records.created_at');
        $query = DB::table('payment_records')
            ->join('student_records', 'student_records.user_id', '=', 'payment_records.student_id')
            ->whereIn('student_records.my_class_id', $scopeClassIds)
            ->where('payment_records.paid', 1)
            ->selectRaw("{$monthExpr} as ym, COALESCE(SUM(payment_records.amt_paid), 0) as total, COUNT(*) as receipts")
            ->groupBy('ym')
            ->orderBy('ym');
        $this->applyMonthRange($query, $monthExpr, $fromMonth, $toMonth);

        $rows = [];
        foreach ($query->get() as $row) {
            $rows[] = [
                'month' => $row->ym,
                'month_label' => date('M Y', strtotime($row->ym . '-01')),
                'total' => (int) $row->total,
                'receipts' => (int) $row->receipts,
            ];
        }

        return $rows;
    }

    protected function feeOutstanding(array $scopeClassIds, array $classNames)
    {
        if (empty($scopeClassIds)) {
            return [];
        }

        $rows = DB::table('payment_records')
            ->join('student_records', 'student_records.user_id', '=', 'payment_records.student_id')
            ->whereIn('student_records.my_class_id', $scopeClassIds)
            ->selectRaw('student_records.my_class_id as class_id, COALESCE(SUM(payment_records.balance), 0) as outstanding')
            ->groupBy('student_records.my_class_id')
            ->orderBy('student_records.my_class_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'class_id' => (int) $row->class_id,
                'class_name' => $classNames[(int) $row->class_id] ?? ('Class ' . $row->class_id),
                'outstanding' => (int) $row->outstanding,
            ];
        }

        return $out;
    }

    protected function marksRows(array $scopeClassIds, $examId, array $classNames)
    {
        if (empty($scopeClassIds)) {
            return [];
        }

        $rows = DB::table('exam_records')
            ->join('exams', 'exams.id', '=', 'exam_records.exam_id')
            ->whereIn('exam_records.my_class_id', $scopeClassIds)
            ->when($examId, function ($q) use ($examId) {
                return $q->where('exam_records.exam_id', $examId);
            })
            ->whereNotNull('exam_records.ave')
            ->selectRaw('exam_records.exam_id as exam_id, exams.name as exam_name, exam_records.my_class_id as class_id')
            ->selectRaw('AVG(CAST(exam_records.ave AS REAL)) as avg_ave, COUNT(*) as students')
            ->groupBy('exam_records.exam_id', 'exams.name', 'exam_records.my_class_id')
            ->orderBy('exam_records.exam_id')
            ->orderBy('exam_records.my_class_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'exam_id' => (int) $row->exam_id,
                'exam_name' => $row->exam_name,
                'class_id' => (int) $row->class_id,
                'class_name' => $classNames[(int) $row->class_id] ?? ('Class ' . $row->class_id),
                'avg' => $row->avg_ave !== null ? round((float) $row->avg_ave, 2) : null,
                'students' => (int) $row->students,
            ];
        }

        return $out;
    }
}
