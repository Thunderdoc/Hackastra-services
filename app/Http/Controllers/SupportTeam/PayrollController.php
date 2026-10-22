<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Support\Audit;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Payroll (Workstream AI) — staff salary management + payslips.
 *
 * Management (index/store/update/generate/pay/payslip) is gated to
 * the accounts team (super admin, admin, accountant) with the same
 * teamAccount middleware PaymentController uses. Self-service
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
        $this->middleware('teamAccount')->except(['myIndex', 'myPayslip']);
        // Workstream CM: payroll writes (create/edit/generate/mark
        // paid) belong to Admin + Accountant; SA keeps the month view
        // and payslip oversight.
        $this->middleware('teamAccountWrite', ['only' => ['store', 'update', 'generate', 'pay']]);
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

        $payroll = Payroll::create($data + [
            'created_by' => Auth::id(),
            'status' => 'pending',
        ]);

        Audit::log('payroll.create', $payroll, 'Generated payroll for ' . $staff->name
            . ' (' . $payroll->monthName() . ' ' . $payroll->year . '), net ' . $payroll->net_pay);

        return back()->with('flash_success', 'Payroll generated for ' . $staff->name . '.');
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

            Payroll::firstOrCreate(
                ['user_id' => $member->id, 'year' => $year, 'month' => $month],
                [
                    'basic_salary' => $last ? $last->basic_salary : 0,
                    'allowances' => $last ? $last->allowances : 0,
                    'deductions' => $last ? $last->deductions : 0,
                    'status' => 'pending',
                    'created_by' => Auth::id(),
                ]
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

    public function myIndex()
    {
        abort_unless(in_array(Auth::user()->user_type, Qs::getStaff()), 403);

        $d['records'] = Payroll::where('user_id', Auth::id())
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
        $d['total_received'] = (float) $d['records']->where('status', 'paid')->sum('net_pay');

        return view('pages.support_team.payroll.my_index', $d);
    }

    public function myPayslip(Payroll $payroll)
    {
        abort_unless($payroll->user_id === Auth::id(), 403);

        return $this->renderPayslip($payroll, route('my_payslips.index'));
    }

    /* --------------------------- Helpers --------------------------- */

    protected function renderPayslip(Payroll $payroll, string $backUrl)
    {
        $payroll->loadMissing('user');
        $net = (float) $payroll->net_pay;

        $d['payroll'] = $payroll;
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
