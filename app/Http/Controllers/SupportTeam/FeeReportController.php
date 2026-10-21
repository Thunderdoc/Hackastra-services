<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\StudentRecord;
use App\Support\CsvExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Loop 9 (Workstream O) — Accountant fee reports.
 *
 * Two read-only reports over the same records the invoice page shows,
 * using the invoice page's own balance rule (a record's balance is its
 * stored balance, falling back to the full fee amount when untouched),
 * so a report can never disagree with the student's invoice:
 *
 *  - Fee defaulters: students who still owe money on current-session
 *    fee records, worst first, with their oldest unpaid item.
 *  - Collection report: what actually came in, day by day, from the
 *    receipts ledger (a receipt row is created per payment taken, its
 *    created_at is when the money was recorded).
 *
 * Gated to the accounts team exactly like PaymentController.
 */
class FeeReportController extends Controller
{
    protected $year;

    public function __construct()
    {
        $this->middleware('teamAccount');
        $this->year = Qs::getCurrentSession();
    }

    public function defaulters(Request $request)
    {
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['selected_class'] = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : null; // null = every class
        $d['year'] = $this->year;
        $d['rows'] = $this->defaulterRows($d['selected_class']);
        $d['total_due'] = $d['rows']->sum('total_due');

        return view('pages.support_team.payments.defaulters', $d);
    }

    public function defaultersExport(Request $request)
    {
        $class = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : null;

        $rows = $this->defaulterRows($class)->map(function ($row) {
            return [
                optional($row['student']->user)->name,
                $row['student']->adm_no,
                optional($row['student']->my_class)->name,
                optional($row['student']->section)->name,
                $row['total_due'],
                $row['oldest_title'],
                $row['oldest_since'] ? $row['oldest_since']->format('d M Y') : '',
            ];
        });

        $filename = 'fee-defaulters'
            . ($class ? '-' . Str::slug($class->name) : '')
            . '-' . $this->year . '.csv';

        return CsvExport::download($filename,
            ['Student', 'Admission No', 'Class', 'Section', 'Total Due', 'Oldest Unpaid Fee', 'Oldest Since'],
            $rows);
    }

    public function collection(Request $request)
    {
        $now = Carbon::now();
        $from = $this->dateFromRequest($request, 'from', $now->copy()->startOfMonth());
        $to = $this->dateFromRequest($request, 'to', $now->copy());

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $d['from'] = $from;
        $d['to'] = $to;
        $d['rows'] = Receipt::whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as receipts_count, SUM(amt_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get();
        $d['grand_total'] = (int) $d['rows']->sum('total');
        $d['receipts_count'] = (int) $d['rows']->sum('receipts_count');

        return view('pages.support_team.payments.collection', $d);
    }

    /**
     * Students with money still owing on current-session fee records.
     * A record counts while paid = 0; its owing amount follows the
     * invoice rule ($record->balance ?: fee amount), so a partially
     * paid record contributes only its remaining balance.
     */
    protected function defaulterRows($selected_class)
    {
        $students = StudentRecord::with(['user', 'my_class', 'section'])
            ->where('grad', 0)
            ->when($selected_class, function ($q) use ($selected_class) {
                return $q->where('my_class_id', $selected_class->id);
            })
            ->orderBy('id')
            ->get();

        $owing = PaymentRecord::with('payment')
            ->where('year', $this->year)
            ->where('paid', 0)
            ->whereIn('student_id', $students->pluck('user_id')->all())
            ->get()
            ->groupBy('student_id');

        return $students
            ->map(function ($student) use ($owing) {
                $records = $owing->get($student->user_id);
                if (!$records || $records->isEmpty()) {
                    return null;
                }

                $total_due = (int) $records->sum(function ($r) {
                    return $r->balance ?: $r->payment->amount;
                });
                if ($total_due <= 0) {
                    return null;
                }

                $oldest = $records->sortBy('created_at')->first();

                return [
                    'student' => $student,
                    'total_due' => $total_due,
                    'items' => $records->count(),
                    'oldest_title' => optional($oldest->payment)->title,
                    'oldest_since' => $oldest->created_at,
                ];
            })
            ->filter()
            ->sortByDesc('total_due')
            ->values();
    }

    /** Parse a Y-m-d query date, falling back to the given default. */
    protected function dateFromRequest(Request $request, string $key, Carbon $default): Carbon
    {
        $value = $request->query($key);
        if (!$value) {
            return $default;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Exception $e) {
            return $default;
        }
    }
}
