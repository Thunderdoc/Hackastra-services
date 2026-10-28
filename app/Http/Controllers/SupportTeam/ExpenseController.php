<?php

namespace App\Http\Controllers\SupportTeam;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\PaymentRecord;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Expense tracking (Wave 2 feature 7) — finance, admin + accountant.
 *
 * Guard model follows PaymentController: teamAccount reads
 * (admin + super admin + accountant), teamAccountWrite writes
 * (admin + accountant). The Super Admin never reaches these routes
 * in practice: PlatformOnly (web group, last) confines super_admin
 * requests to its allowlist, and these routes are deliberately NOT
 * on that allowlist — nothing here needs SA, and the allowlist is
 * untouched. A teacher is not on teamAccount, so teamAccount
 * redirects them to login.
 *
 * Income-expenditure report: income is ONLY PaymentRecord rows with
 * paid = 1, summed by the month of their created_at; expenses are
 * summed by the month of their expense_date. Net = income - expenses
 * per month and overall. No figure is invented: every number traces
 * to a real row in one of those two tables.
 *
 * This is NOT payroll: salaries live in Payroll and never appear
 * here, in either direction.
 */
class ExpenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamAccount');
        $this->middleware('teamAccountWrite', [
            'only' => ['create', 'store', 'edit', 'update', 'destroy'],
        ]);
    }

    public function index(Request $request)
    {
        $expenses = Expense::with('recorder')
            ->when($request->filled('category'), function ($q) use ($request) {
                $q->where('category', $request->input('category'));
            })
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->get();

        $d['expenses'] = $expenses;
        $d['categories'] = Expense::distinct()->orderBy('category')->pluck('category');
        $d['selected_category'] = $request->input('category');
        $d['total'] = (float) $expenses->sum('amount');

        return view('pages.support_team.expenses.index', $d);
    }

    public function create()
    {
        $d['expense'] = new Expense();
        $d['categories'] = self::categorySuggestions();

        return view('pages.support_team.expenses.form', $d);
    }

    public function store(Request $request)
    {
        $data = $this->validateExpense($request);

        $expense = Expense::create($data + ['recorded_by' => Auth::id()]);

        Audit::log('expense.create', $expense, 'Recorded expense "'
            . $expense->title . '" (' . $expense->category . '), '
            . $expense->amount . ' on ' . $expense->expense_date->format('Y-m-d'));

        return redirect()->route('expenses.index')
            ->with('flash_success', 'Expense recorded.');
    }

    public function edit(Expense $expense)
    {
        $d['expense'] = $expense;
        $d['categories'] = self::categorySuggestions();

        return view('pages.support_team.expenses.form', $d);
    }

    public function update(Request $request, Expense $expense)
    {
        $data = $this->validateExpense($request);

        $expense->update($data);

        Audit::log('expense.update', $expense, 'Updated expense "'
            . $expense->title . '" (' . $expense->category . '), '
            . $expense->amount . ' on ' . $expense->expense_date->format('Y-m-d'));

        return redirect()->route('expenses.index')
            ->with('flash_success', 'Expense updated.');
    }

    public function destroy(Expense $expense)
    {
        $title = $expense->title;
        $expense->delete();

        Audit::log('expense.delete', null, 'Deleted expense "' . $title . '"');

        return redirect()->route('expenses.index')
            ->with('flash_success', 'Expense deleted.');
    }

    /**
     * Income-expenditure report.
     *
     * Optional ?year= narrows BOTH sides to that calendar year
     * (income by created_at year, expenses by expense_date year).
     * Without it, every month present in either table is shown.
     */
    public function report(Request $request)
    {
        $year = $request->input('year');
        $year = ($year !== null && is_numeric($year) && (int) $year >= 2000 && (int) $year <= 2100)
            ? (int) $year
            : null;

        $rows = self::monthlyReport($year);

        $d['rows'] = $rows;
        $d['year'] = $year;
        $d['years'] = self::reportYears();
        $d['totals'] = [
            'income' => round(array_sum(array_column($rows, 'income')), 2),
            'expenses' => round(array_sum(array_column($rows, 'expenses')), 2),
            'net' => round(array_sum(array_column($rows, 'net')), 2),
        ];

        return view('pages.support_team.expenses.report', $d);
    }

    /**
     * One row per month present in either source table:
     * ['month' => 'Y-m', 'income' => float, 'expenses' => float, 'net' => float].
     * Sorted oldest month first. Shared by report(); the test
     * deliberately does NOT call this — it recomputes from raw SQL.
     */
    public static function monthlyReport(?int $year = null): array
    {
        $incomeQuery = PaymentRecord::where('paid', 1)
            ->select('amt_paid', 'created_at');
        $expenseQuery = Expense::select('amount', 'expense_date');
        if ($year !== null) {
            $incomeQuery->whereYear('created_at', $year);
            $expenseQuery->whereYear('expense_date', $year);
        }

        $months = [];
        foreach ($incomeQuery->get() as $record) {
            if (!$record->created_at) {
                continue;
            }
            $key = $record->created_at->format('Y-m');
            $months[$key]['income'] = ($months[$key]['income'] ?? 0) + (float) $record->amt_paid;
            $months[$key]['expenses'] = $months[$key]['expenses'] ?? 0;
        }
        foreach ($expenseQuery->get() as $expense) {
            $key = $expense->expense_date->format('Y-m');
            $months[$key]['expenses'] = ($months[$key]['expenses'] ?? 0) + (float) $expense->amount;
            $months[$key]['income'] = $months[$key]['income'] ?? 0;
        }

        ksort($months);

        $rows = [];
        foreach ($months as $key => $sums) {
            $income = round($sums['income'], 2);
            $expenses = round($sums['expenses'], 2);
            $rows[] = [
                'month' => $key,
                'income' => $income,
                'expenses' => $expenses,
                'net' => round($income - $expenses, 2),
            ];
        }

        return $rows;
    }

    /** Years present in either source table, for the report filter. */
    protected static function reportYears(): array
    {
        $years = PaymentRecord::where('paid', 1)
            ->whereNotNull('created_at')
            ->get()
            ->map(function ($record) {
                return (int) $record->created_at->format('Y');
            })
            ->merge(
                Expense::get()->map(function ($expense) {
                    return (int) $expense->expense_date->format('Y');
                })
            )
            ->unique()
            ->sortDesc()
            ->values()
            ->all();

        if (!in_array((int) now()->year, $years, true)) {
            $years[] = (int) now()->year;
            rsort($years);
        }

        return $years;
    }

    protected function validateExpense(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'amount' => 'required|numeric|gt:0',
            'expense_date' => 'required|date',
            'note' => 'nullable|string',
        ]);
    }

    /** Suggestions for the category field; the field itself is free text. */
    public static function categorySuggestions(): array
    {
        return ['Utilities', 'Maintenance', 'Stationery', 'Transport', 'Events', 'Miscellaneous', 'Other'];
    }
}
