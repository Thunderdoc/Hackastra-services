<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Support\Audit;
use App\Support\LeaveBalance;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Payroll (Workstream AI) — staff salary management + payslips.
 *
 * Management (index/store/update/generate/pay/payslip) is gated to
 * the Admin team (teamSA reads, teamSAWrite writes): payroll
 * ADMINISTRATION — creating/editing/generating/marking-paid salary
 * records and viewing other staff members' salary details — belongs
 * to the school Admin who runs the school. The accountant's domain
 * is FEES (PaymentController), not salaries; an accountant reaches
 * only their OWN record through self-service. (Owner role ruling,
 * fix/accountant-scope — previously teamAccount let the accountant
 * read and write every staff member's salary.)
 * Self-service
 * (myIndex/myPayslip) derives everything from Auth::id() — there is
 * deliberately no user_id input anywhere in those two actions, and
 * myPayslip enforces record ownership server-side.
 *
 * Honest scope: this module tracks salary records and their paid
 * status. There is NO bank/payout gateway — "Mark Paid" records that
 * the salary was disbursed, it does not move money.
 */
class PayrollController extends Controller
{
    /** User types that receive a salary (super_admin is the platform owner, excluded). */
    public const STAFF_TYPES = ['admin', 'teacher', 'accountant', 'librarian'];

    public function __construct()
    {
        $this->middleware('teamSA')->except(['myIndex', 'myPayslip', 'myPayslipPdf', 'myPayslipsPdfAll']);
        // Payroll administration belongs to the Admin team alone:
        // reads (month view + any staff payslip) are teamSA, writes
        // (create/edit/generate/mark paid) are teamSAWrite — the same
        // semantics as the other school-administration modules. SA's
        // platform-only redirect still applies on top, unchanged.
        $this->middleware('teamSAWrite', ['only' => ['store', 'update', 'generate', 'pay']]);
    }

    /* ------------------------- Management ------------------------- */

    public function index(Request $request)
    {
        [$year, $month] = $this->resolvePeriod($request);

        $staff = User::whereIn('user_type', self::STAFF_TYPES)
            ->orderBy('name')
            ->get();

        $records = Payroll::forMonth($year, $month)
            ->get()
            ->keyBy('user_id');

        $d['staff'] = $staff;
        $d['records'] = $records;
        $d['year'] = $year;
        $d['month'] = $month;
        $d['months'] = $this->monthOptions();
        $d['years'] = $this->yearOptions($year);
        $d['summary'] = [
            'staff_count' => $staff->count(),
            'generated' => $records->count(),
            'total_net' => (float) $records->sum('net_pay'),
            'paid_total' => (float) $records->where('status', 'paid')->sum('net_pay'),
            'pending_total' => (float) $records->where('status', 'pending')->sum('net_pay'),
            'paid_count' => $records->where('status', 'paid')->count(),
        ];
        $d['current_session'] = Qs::getSetting('current_session');

        return view('pages.support_team.payroll.index', $d);
    }

    /** Leave figures for one staff member's payroll month
     *  (owner orders 2026-10-30): approved leave days in the
     *  month, how many fall beyond the paid quota, and the
     *  deduction those extra days produce at this gross. */
    protected function leaveFigures(int $userId, int $year, int $month, float $gross): array
    {
        return [
            'leave_days' => LeaveBalance::usedInMonth($userId, $year, $month),
            'leave_extra_days' => LeaveBalance::extraInMonth($userId, $year, $month),
            'leave_deduction' => LeaveBalance::deductionFor($userId, $year, $month, $gross),
        ];
    }

    public function store(Request $request)
    {
        $data = $this->validatePayroll($request, true);

        $staff = User::whereIn('user_type', self::STAFF_TYPES)->find($data['user_id']);
        if (!$staff) {
            return back()->with('flash_danger', 'Please choose a staff member.');
        }

        $exists = Payroll::forMonth($data['year'], $data['month'])
            ->where('user_id', $staff->id)
            ->exists();
        if ($exists) {
            return back()->with('flash_danger', 'A payroll record already exists for '
                . $staff->name . ' for this month — use Edit instead.');
        }

        $leave = $this->leaveFigures($staff->id, (int) $data['year'], (int) $data['month'],
            (float) $data['basic_salary'] + (float) $data['allowances']);
        $data['deductions'] = (float) $data['deductions'] + $leave['leave_deduction'];

        $payroll = Payroll::create($data + $leave + [
            'created_by' => Auth::id(),
            'status' => 'pending',
        ]);

        Audit::log('payroll.create', $payroll, 'Generated payroll for ' . $staff->name
            . ' (' . $payroll->monthName() . ' ' . $payroll->year . '), net ' . $payroll->net_pay);

        $note = $leave['leave_deduction'] > 0
            ? ' Includes a leave deduction of ₹' . number_format($leave['leave_deduction'], 2)
                . ' for ' . $leave['leave_extra_days'] . ' extra (unpaid) leave day(s).'
            : '';

        return back()->with('flash_success', 'Payroll generated for ' . $staff->name . '.' . $note);
    }

    public function update(Request $request, Payroll $payroll)
    {
        $data = $this->validatePayroll($request, false);
        $payroll->update($data);

        Audit::log('payroll.update', $payroll, 'Updated payroll for '
            . optional($payroll->user)->name . ' (' . $payroll->monthName() . ' '
            . $payroll->year . '), net ' . $payroll->net_pay);

        return back()->with('flash_success', 'Payroll updated for ' . optional($payroll->user)->name . '.');
    }

    /** Create pending records for every staff member missing one this month. */
    public function generate(Request $request)
    {
        [$year, $month] = $this->resolvePeriod($request);

        $existing = Payroll::forMonth($year, $month)->pluck('user_id')->all();
        $staff = User::whereIn('user_type', self::STAFF_TYPES)
            ->whereNotIn('id', $existing)
            ->orderBy('name')
            ->get();

        $created = 0;
        foreach ($staff as $member) {
            // Prefill from this staff member's most recent earlier record.
            $last = Payroll::where('user_id', $member->id)
                ->orderByDesc('year')->orderByDesc('month')
                ->first();

            $basic = $last ? (float) $last->basic_salary : 0;
            $allowances = $last ? (float) $last->allowances : 0;
            // The prefilled deductions carry the member's usual
            // deductions forward WITHOUT last month's leave
            // deduction — this month's is computed fresh below, so
            // a leave deduction never double-charges by riding the
            // prefill from one month into the next.
            $baseDeductions = $last
                ? max(0.0, (float) $last->deductions - (float) ($last->leave_deduction ?? 0))
                : 0;
            $leave = $this->leaveFigures($member->id, $year, $month, $basic + $allowances);

            Payroll::firstOrCreate(
                ['user_id' => $member->id, 'year' => $year, 'month' => $month],
                [
                    'basic_salary' => $basic,
                    'allowances' => $allowances,
                    'deductions' => $baseDeductions + $leave['leave_deduction'],
                    'status' => 'pending',
                    'created_by' => Auth::id(),
                ] + $leave
            );
            $created++;
        }

        Audit::log('payroll.bulk_generate', null, "Bulk-generated {$created} payroll record(s) for "
            . date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year);

        return back()->with('flash_success', $created
            ? "Generated payroll for {$created} staff member(s). Amounts are prefilled from each member's last record — review and edit before marking paid."
            : 'Every staff member already has a payroll record for this month.');
    }

    public function pay(Payroll $payroll)
    {
        if ($payroll->isPaid()) {
            return back()->with('flash_info', 'This salary is already marked as paid.');
        }

        $payroll->update(['status' => 'paid', 'paid_at' => now()]);

        Audit::log('payroll.paid', $payroll, 'Marked salary paid for '
            . optional($payroll->user)->name . ' (' . $payroll->monthName() . ' '
            . $payroll->year . '), net ' . $payroll->net_pay);

        return back()->with('flash_success', 'Salary marked as paid for ' . optional($payroll->user)->name . '.');
    }

    public function payslip(Payroll $payroll)
    {
        return $this->renderPayslip($payroll, route('payroll.index', [
            'year' => $payroll->year, 'month' => $payroll->month,
        ]));
    }

    /* ------------------------ Self-service ------------------------ */

    public function myIndex(Request $request)
    {
        abort_unless(in_array(Auth::user()->user_type, Qs::getStaff()), 403);

        $all = Payroll::where('user_id', Auth::id())
            ->orderByDesc('year')->orderByDesc('month')
            ->get();

        // Year options come from the user's OWN records only; the
        // default scope is their latest recorded year (payroll years
        // need not match the calendar year).
        $years = $all->pluck('year')->unique()->sortDesc()->values();
        $year = (int) $request->input('year', $years->first() ?? now()->year);
        if (!$years->contains($year)) {
            $year = (int) ($years->first() ?? now()->year);
        }
        $month = (int) $request->input('month', 0);
        if ($month < 0 || $month > 12) {
            $month = 0;
        }

        // Summary cards describe the selected YEAR (Loop 89): money
        // received counts PAID records only — pending amounts are
        // never reported as received.
        $inYear = $all->where('year', $year)->values();
        $paid = $inYear->where('status', 'paid')->values();
        $paidTotal = (float) $paid->sum('net_pay');
        $latestPaid = $paid->sortByDesc(function ($p) {
            return $p->paid_at ? $p->paid_at->timestamp : 0;
        })->first();

        $d['summary'] = [
            'year' => $year,
            'total_received' => $paidTotal,
            'count' => $inYear->count(),
            'paid_count' => $paid->count(),
            'average' => $paid->count() ? round($paidTotal / $paid->count(), 2) : null,
            'latest_paid' => $latestPaid,
        ];
        $d['years'] = $years;
        $d['months'] = $this->monthOptions();
        $d['selected_year'] = $year;
        $d['selected_month'] = $month;
        $d['has_records'] = $all->isNotEmpty();

        $filtered = $month ? $inYear->where('month', $month)->values() : $inYear;
        $perPage = 12;
        $page = max(1, (int) $request->input('page', 1));
        $d['records'] = new \Illuminate\Pagination\LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pages.support_team.payroll.my_index', $d);
    }

    public function myPayslip(Payroll $payroll)
    {
        abort_unless($payroll->user_id === Auth::id(), 403);

        return $this->renderPayslip($payroll, route('my_payslips.index'));
    }

    /* Map a stored upload URL (/storage/...) to a base64 data URI read
       from the local file, for renderers that cannot fetch URLs
       (dompdf). Anything that is not a local storage file is returned
       unchanged. (Same helper as MarkController/CircularController.) */
    protected function pdfImageDataUri($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path && \Illuminate\Support\Str::startsWith($path, '/storage/')) {
            $local = storage_path('app/public/' . \Illuminate\Support\Str::after($path, '/storage/'));
            if (is_file($local)) {
                $mime = mime_content_type($local) ?: 'image/png';
                return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($local));
            }
        }
        return $url;
    }

    /** Own-payslip PDF (Loop 89): same document as the print view. */
    public function myPayslipPdf(Payroll $payroll)
    {
        abort_unless($payroll->user_id === Auth::id(), 403);

        $d = $this->payslipDocData($payroll);

        /* Documents Premium pass: the shared payslip_document partial
           reads the logo from Settings as a URL, which dompdf cannot
           fetch — the PDF paths hand it an inlined data URI instead
           (the partial prefers $logo_pdf when present). */
        $d['logo_pdf'] = $this->pdfImageDataUri(Qs::getSetting('logo'));

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.payroll.payslip_pdf', $d)
            ->download('Payslip_' . $payroll->monthName() . '_' . $payroll->year . '.pdf');
    }

    /** Combined PDF of the user's own payslips for one year (Loop 89). */
    public function myPayslipsPdfAll(Request $request)
    {
        abort_unless(in_array(Auth::user()->user_type, Qs::getStaff()), 403);

        $years = Payroll::where('user_id', Auth::id())->distinct()->pluck('year');
        $year = (int) $request->input('year', $years->max() ?? now()->year);

        $records = Payroll::where('user_id', Auth::id())
            ->where('year', $year)
            ->orderBy('month')
            ->with('user')
            ->get();

        if ($records->isEmpty()) {
            return back()->with('flash_info', 'You have no payslips to download for ' . $year . '.');
        }

        $d['records'] = $records;
        $d['year'] = $year;
        $d['school_name'] = Qs::getSystemName();
        $d['school_address'] = Qs::getSetting('address');
        $d['current_session'] = Qs::getSetting('current_session');
        $d['logo_pdf'] = $this->pdfImageDataUri(Qs::getSetting('logo'));

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.payroll.payslips_pdf_all', $d)
            ->download('Payslips_' . $year . '.pdf');
    }

    /* --------------------------- Helpers --------------------------- */

    /** Data shared by the payslip document's PDF renderings (Loop 89). */
    protected function payslipDocData(Payroll $payroll): array
    {
        $payroll->loadMissing('user');

        return [
            'payroll' => $payroll,
            'school_name' => Qs::getSystemName(),
            'school_address' => Qs::getSetting('address'),
            'current_session' => Qs::getSetting('current_session'),
            'net_words' => \App\Support\NumberToWords::convert((int) round((float) $payroll->net_pay)),
        ];
    }

    protected function renderPayslip(Payroll $payroll, string $backUrl)
    {
        $payroll->loadMissing('user');
        $net = (float) $payroll->net_pay;

        $d['payroll'] = $payroll;
        // Leave summary for the payslip (owner order 2026-10-30):
        // this month's approved leave, the year's quota position.
        $d['leave_summary'] = LeaveBalance::summary((int) $payroll->user_id, (int) $payroll->year)
            + ['month_days' => LeaveBalance::usedInMonth((int) $payroll->user_id, (int) $payroll->year, (int) $payroll->month)];
        $d['back_url'] = $backUrl;
        $d['school_name'] = Qs::getSystemName();
        $d['school_address'] = Qs::getSetting('address');
        $d['current_session'] = Qs::getSetting('current_session');
        $d['net_words'] = \App\Support\NumberToWords::convert((int) round($net));

        return view('pages.support_team.payroll.payslip', $d);
    }

    protected function resolvePeriod(Request $request): array
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        if ($month < 1 || $month > 12) {
            $month = now()->month;
        }
        if ($year < 2000 || $year > 2100) {
            $year = now()->year;
        }

        return [$year, $month];
    }

    protected function validatePayroll(Request $request, bool $creating): array
    {
        $rules = [
            'basic_salary' => 'required|numeric|min:0',
            'allowances' => 'nullable|numeric|min:0',
            'deductions' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:255',
        ];
        if ($creating) {
            $rules['user_id'] = 'required|integer';
            $rules['year'] = 'required|integer|min:2000|max:2100';
            $rules['month'] = 'required|integer|min:1|max:12';
        }

        $data = $request->validate($rules);
        $data['allowances'] = $data['allowances'] ?? 0;
        $data['deductions'] = $data['deductions'] ?? 0;

        return $data;
    }

    protected function monthOptions(): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = date('F', mktime(0, 0, 0, $m, 1));
        }

        return $months;
    }

    protected function yearOptions(int $selected): array
    {
        $years = Payroll::distinct()->pluck('year')
            ->push(now()->year)->push($selected)
            ->unique()->sort()->values()->all();

        return $years;
    }
}
