<?php

namespace Tests\Feature;

use App\Helpers\Money;
use App\Http\Controllers\SupportTeam\ExpenseController;
use App\Models\Expense;
use App\Models\PaymentRecord;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Expense tracking (Wave 2 feature 7): CRUD + validation +
 * income-expenditure report + role gates.
 *
 * Routes are NOT in routes/web.php yet (the coordinator integrates
 * them centrally afterwards), so this test registers the exact
 * proposed routes itself in setUp — same URIs, controller methods,
 * route names and middleware as the lines reported to the
 * coordinator. After integration these duplicate registrations point
 * at the identical targets, so the tests keep passing unchanged.
 *
 * Report totals are NEVER taken from the controller: they are
 * recomputed here from raw SQL over both source tables, so a bug in
 * the controller's grouping cannot hide behind its own helper.
 */
class OwnerExpenseTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])->group(function () {
            Route::group(['prefix' => 'expenses'], function () {
                Route::get('/', [ExpenseController::class, 'index'])
                    ->name('expenses.index')->middleware('teamAccount');
                Route::get('create', [ExpenseController::class, 'create'])
                    ->name('expenses.create')->middleware('teamAccountWrite');
                Route::post('/', [ExpenseController::class, 'store'])
                    ->name('expenses.store')->middleware('teamAccountWrite');
                Route::get('report', [ExpenseController::class, 'report'])
                    ->name('expenses.report')->middleware('teamAccount');
                Route::get('{expense}/edit', [ExpenseController::class, 'edit'])
                    ->name('expenses.edit')->middleware('teamAccountWrite');
                Route::put('{expense}', [ExpenseController::class, 'update'])
                    ->name('expenses.update')->middleware('teamAccountWrite');
                Route::delete('{expense}', [ExpenseController::class, 'destroy'])
                    ->name('expenses.destroy')->middleware('teamAccountWrite');
            });
        });

        // Routes added after boot are counted but NOT name-indexed
        // until the lookup is refreshed (verified by probe 2026-10-28:
        // count rose while has() stayed false, and
        // refreshNameLookups() fixed both has() and route()).
        Route::getRoutes()->refreshNameLookups();
    }

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    /** Raw-SQL hand computation of the report, independent of the controller. */
    protected function handComputedReport(): array
    {
        $incomeRows = DB::select(
            "SELECT strftime('%Y-%m', created_at) AS m, COALESCE(SUM(amt_paid), 0) AS s"
            . " FROM payment_records WHERE paid = 1 GROUP BY m"
        );
        $expenseRows = DB::select(
            "SELECT strftime('%Y-%m', expense_date) AS m, COALESCE(SUM(amount), 0) AS s"
            . " FROM expenses GROUP BY m"
        );

        $months = [];
        foreach ($incomeRows as $row) {
            if ($row->m === null) {
                continue;
            }
            $months[$row->m]['income'] = (float) $row->s;
            $months[$row->m]['expenses'] = 0.0;
        }
        foreach ($expenseRows as $row) {
            $months[$row->m]['expenses'] = (float) $row->s;
            $months[$row->m]['income'] = $months[$row->m]['income'] ?? 0.0;
        }
        ksort($months);

        $rows = [];
        foreach ($months as $key => $sums) {
            $rows[$key] = [
                'income' => round($sums['income'], 2),
                'expenses' => round($sums['expenses'], 2),
                'net' => round($sums['income'] - $sums['expenses'], 2),
            ];
        }

        return [
            'rows' => $rows,
            'income' => round(array_sum(array_column($rows, 'income')), 2),
            'expenses' => round(array_sum(array_column($rows, 'expenses')), 2),
            'net' => round(array_sum(array_column($rows, 'net')), 2),
        ];
    }

    /** A paid record cloned from a real one, so FKs (if enforced) hold. */
    protected function seedPaidRecord(float $amount, string $createdAt): PaymentRecord
    {
        $anchor = PaymentRecord::where('paid', 1)->firstOrFail();
        $record = PaymentRecord::create([
            'student_id' => $anchor->student_id,
            'payment_id' => $anchor->payment_id,
            'amt_paid' => (int) $amount,
            'year' => $anchor->year,
            'paid' => 1,
            'balance' => 0,
            'ref_no' => 'EXP-PROBE-' . uniqid(),
        ]);
        DB::table('payment_records')->where('id', $record->id)->update(['created_at' => $createdAt]);

        return $record->fresh();
    }

    public function test_admin_can_create_and_list_expense()
    {
        $response = $this->actingAs($this->admin())->post(route('expenses.store'), [
            'title' => 'Probe Electricity Bill',
            'category' => 'Utilities',
            'amount' => 12500,
            'expense_date' => '2026-03-10',
            'note' => 'March bill',
        ]);
        $response->assertRedirect(route('expenses.index'));

        $expense = Expense::where('title', 'Probe Electricity Bill')->firstOrFail();
        $this->assertSame('Utilities', $expense->category);
        $this->assertEquals(12500, (float) $expense->amount);
        $this->assertSame('2026-03-10', $expense->expense_date->format('Y-m-d'));
        $this->assertSame($this->admin()->id, (int) $expense->recorded_by);

        $this->actingAs($this->admin())->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('Probe Electricity Bill')
            ->assertSee('Utilities');
    }

    public function test_expense_validation_rejects_bad_amount_and_missing_date()
    {
        $base = [
            'title' => 'Probe Bad Expense',
            'category' => 'Maintenance',
            'amount' => 100,
            'expense_date' => '2026-03-10',
        ];

        $this->actingAs($this->admin())->post(route('expenses.store'), array_merge($base, ['amount' => 0]))
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin())->post(route('expenses.store'), array_merge($base, ['amount' => -50]))
            ->assertSessionHasErrors('amount');
        $this->actingAs($this->admin())->post(route('expenses.store'), array_merge($base, ['expense_date' => null]))
            ->assertSessionHasErrors('expense_date');
        $this->actingAs($this->admin())->post(route('expenses.store'), array_merge($base, ['title' => null]))
            ->assertSessionHasErrors('title');

        $this->assertDatabaseMissing('expenses', ['title' => 'Probe Bad Expense']);
    }

    public function test_admin_can_update_and_delete_expense()
    {
        $expense = Expense::create([
            'title' => 'Probe Old Title',
            'category' => 'Stationery',
            'amount' => 500,
            'expense_date' => '2026-03-05',
            'recorded_by' => $this->admin()->id,
        ]);

        $this->actingAs($this->admin())->get(route('expenses.edit', $expense))
            ->assertOk()
            ->assertSee('Edit Expense');

        $this->actingAs($this->admin())->put(route('expenses.update', $expense), [
            'title' => 'Probe New Title',
            'category' => 'Stationery',
            'amount' => 750,
            'expense_date' => '2026-03-06',
            'note' => null,
        ])->assertRedirect(route('expenses.index'));

        $expense->refresh();
        $this->assertSame('Probe New Title', $expense->title);
        $this->assertEquals(750, (float) $expense->amount);

        $this->actingAs($this->admin())->delete(route('expenses.destroy', $expense))
            ->assertRedirect(route('expenses.index'));
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_accountant_can_manage_expenses()
    {
        $this->actingAs($this->accountant())->get(route('expenses.index'))->assertOk();
        $this->actingAs($this->accountant())->get(route('expenses.create'))->assertOk();

        $this->actingAs($this->accountant())->post(route('expenses.store'), [
            'title' => 'Probe Accountant Expense',
            'category' => 'Transport',
            'amount' => 2000,
            'expense_date' => '2026-04-01',
        ])->assertRedirect(route('expenses.index'));

        $this->assertDatabaseHas('expenses', [
            'title' => 'Probe Accountant Expense',
            'recorded_by' => $this->accountant()->id,
        ]);
    }

    public function test_teacher_is_denied_expense_pages_and_writes()
    {
        // teamAccount is a redirect-to-login gate, not a 403 gate.
        $this->actingAs($this->teacher())->get(route('expenses.index'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->get(route('expenses.report'))
            ->assertRedirect(route('login'));
        $this->actingAs($this->teacher())->post(route('expenses.store'), [
            'title' => 'Probe Teacher Expense',
            'category' => 'Events',
            'amount' => 100,
            'expense_date' => '2026-03-10',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('expenses', ['title' => 'Probe Teacher Expense']);
    }

    public function test_report_totals_match_hand_computed_raw_sums()
    {
        // Distinct months, plus an UNPAID record that must NOT count
        // as income: paid = 1 only.
        $this->seedPaidRecord(10000, '2026-03-15 10:00:00');
        $anchor = PaymentRecord::where('paid', 1)->firstOrFail();
        $unpaid = PaymentRecord::create([
            'student_id' => $anchor->student_id,
            'payment_id' => $anchor->payment_id,
            'amt_paid' => 999999,
            'year' => $anchor->year,
            'paid' => 0,
            'balance' => 999999,
            'ref_no' => 'EXP-PROBE-UNPAID-' . uniqid(),
        ]);
        DB::table('payment_records')->where('id', $unpaid->id)->update(['created_at' => '2026-03-16 10:00:00']);
        Expense::create([
            'title' => 'Probe March Expense',
            'category' => 'Utilities',
            'amount' => 4000,
            'expense_date' => '2026-03-20',
            'recorded_by' => $this->admin()->id,
        ]);
        Expense::create([
            'title' => 'Probe April Expense',
            'category' => 'Maintenance',
            'amount' => 1500,
            'expense_date' => '2026-04-02',
            'recorded_by' => $this->admin()->id,
        ]);

        $expected = $this->handComputedReport();
        $this->assertArrayHasKey('2026-03', $expected['rows']);
        $this->assertArrayHasKey('2026-04', $expected['rows']);
        // Exclusion proof: March income from ALL rows minus March
        // income from UNPAID rows must equal the paid-only figure
        // the report shows — i.e. the unpaid 999999 (and any other
        // unpaid March rows) contribute exactly nothing to it.
        $allMarch = (float) DB::selectOne(
            "SELECT COALESCE(SUM(amt_paid), 0) AS s FROM payment_records"
            . " WHERE strftime('%Y-%m', created_at) = '2026-03'"
        )->s;
        $unpaidMarch = (float) DB::selectOne(
            "SELECT COALESCE(SUM(amt_paid), 0) AS s FROM payment_records"
            . " WHERE paid = 0 AND strftime('%Y-%m', created_at) = '2026-03'"
        )->s;
        $this->assertGreaterThanOrEqual(999999, $unpaidMarch);
        $this->assertEquals($allMarch - $unpaidMarch, $expected['rows']['2026-03']['income']);

        $response = $this->actingAs($this->accountant())->get(route('expenses.report'));
        $response->assertOk();
        $response->assertSee('March 2026');
        $response->assertSee('April 2026');
        $response->assertSee(Money::inr($expected['income']));
        $response->assertSee(Money::inr($expected['expenses']));
        $response->assertSee(Money::inr($expected['net']));
        $response->assertSee(Money::inr($expected['rows']['2026-03']['income']));
        $response->assertSee(Money::inr($expected['rows']['2026-03']['expenses']));
        $response->assertSee(Money::inr($expected['rows']['2026-03']['net']));
    }
}
