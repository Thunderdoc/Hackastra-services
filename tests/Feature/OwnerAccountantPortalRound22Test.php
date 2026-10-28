<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\StudentRecord;
use App\Models\UserNotification;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Roles audit round 22: accountant portal depth. Gates read first
 * (routes + PaymentController + FeeReportController +
 * ExpenseController + StudentRecordPolicy@viewFees +
 * PaymentRecordPolicy):
 * - PaymentController: teamOrPrincipal:Account on every action
 *   (admin, super_admin, accountant + principal); writes
 *   (create/store/edit/update/destroy/pay_now/reset_record) add
 *   teamAccountWrite (admin + accountant ONLY). SA is overridden in
 *   practice by PlatformOnly (no payments route allowlisted): SA
 *   GETs -> 302 /dashboard, SA non-GETs -> 403, before any team
 *   gate runs.
 * - FeeReportController (defaulters/collection/remind) and
 *   ExpenseController reads: teamAccount ONLY (admin, super_admin,
 *   accountant) — principal is NOT in teamAccount, so principal
 *   bounces to login here even though payments.index opens for
 *   them. Expense writes add teamAccountWrite.
 * - Invoice adds a policy layer: authorize('viewFees') —
 *   super_admin/admin/accountant any record; student own; parent
 *   own children; teacher and principal FALSE. BUT student/parent
 *   never reach the policy through this route: the
 *   teamOrPrincipal:Account middleware fires first and redirects
 *   them to login — so the policy's student/parent viewFees
 *   branches are unreachable via payments.invoice (their fee
 *   surface is /fees/mine). The branches are dead, not a leak;
 *   harmless defense-in-depth, flagged as an observation only.
 * - pay_now: teamAccountWrite + PaymentRecordPolicy@update
 *   (admin/accountant); the amount check is server-side
 *   (payment->amount - amt_paid) — overpaying throws a validation
 *   error, writes nothing, issues no receipt.
 * - Principal's Fees & Finance menu link is payments.manage
 *   (200 for principal); invoice detail is policy-closed for
 *   principal (403) — consistent with the principal spec's
 *   "AUTHORIZED SUMMARY only" finance surface, not a defect.
 */
class OwnerAccountantPortalRound22Test extends TestCase
{
    use DatabaseTransactions;

    protected function admin()
    {
        return User::where('email', 'admin@hackstra.app')->firstOrFail();
    }

    protected function accountant()
    {
        return User::where('email', 'accountant@hackstra.app')->firstOrFail();
    }

    protected function superAdmin()
    {
        return User::where('email', 'superadmin@hackstra.app')->firstOrFail();
    }

    protected function teacher()
    {
        return User::where('email', 'teacher@hackstra.app')->firstOrFail();
    }

    protected function principal()
    {
        return User::where('email', 'principal@hackstra.app')->firstOrFail();
    }

    protected function student()
    {
        return User::where('email', 'student@hackstra.app')->firstOrFail();
    }

    protected function parent()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    protected function librarian()
    {
        return User::where('email', 'librarian@hackstra.app')->firstOrFail();
    }

    protected function foreignStudentRecord()
    {
        // A student with NO parent link — truly foreign to parent@.
        // Never use user 58 here: it IS parent@'s own second child.
        return StudentRecord::whereNull('my_parent_id')
            ->whereNotIn('user_id', [57, 58])
            ->firstOrFail();
    }

    public function test_payments_read_role_shape()
    {
        // Account team + principal read; everyone else bounces.
        $this->actingAs($this->accountant())->get(route('payments.index'))->assertOk();
        $this->actingAs($this->admin())->get(route('payments.index'))->assertOk();
        $this->actingAs($this->principal())->get(route('payments.index'))->assertOk();
        $this->actingAs($this->accountant())->get(route('payments.manage'))->assertOk();
        $this->actingAs($this->principal())->get(route('payments.manage'))->assertOk();

        $this->actingAs($this->teacher())->get(route('payments.index'))->assertRedirect(route('login'));
        $this->actingAs($this->student())->get(route('payments.index'))->assertRedirect(route('login'));
        $this->actingAs($this->parent())->get(route('payments.index'))->assertRedirect(route('login'));
        $this->actingAs($this->librarian())->get(route('payments.index'))->assertRedirect(route('login'));

        // PlatformOnly fires before the team gate for SA.
        $this->actingAs($this->superAdmin())->get(route('payments.index'))->assertRedirect('/dashboard');
    }

    public function test_payments_create_write_shape()
    {
        $payload = ['title' => 'R22-ACC-FEE', 'amount' => 5000, 'my_class_id' => 1];

        $this->actingAs($this->accountant())->post(route('payments.store'), $payload)
            ->assertRedirect(route('payments.index'));
        $this->assertDatabaseHas('payments', ['title' => 'R22-ACC-FEE', 'amount' => 5000]);

        $this->actingAs($this->teacher())->post(route('payments.store'), ['title' => 'R22-TEACH-FEE', 'amount' => 5000])
            ->assertRedirect(route('login'));
        $this->actingAs($this->principal())->post(route('payments.store'), ['title' => 'R22-PRIN-FEE', 'amount' => 5000])
            ->assertRedirect(route('login'));
        $this->actingAs($this->superAdmin())->post(route('payments.store'), ['title' => 'R22-SA-FEE', 'amount' => 5000])
            ->assertForbidden();
        $this->assertDatabaseMissing('payments', ['title' => 'R22-TEACH-FEE']);
        $this->assertDatabaseMissing('payments', ['title' => 'R22-PRIN-FEE']);
        $this->assertDatabaseMissing('payments', ['title' => 'R22-SA-FEE']);
    }

    public function test_pay_now_overpay_refused_and_partial_works()
    {
        // Seeded owing anchor: record 4, student 58, paid 7200,
        // balance 10800 (payment 1 = 18000).
        $pr = PaymentRecord::findOrFail(4);
        $payment = Payment::findOrFail($pr->payment_id);
        $outstanding = $payment->amount - $pr->amt_paid;
        $this->assertSame(10800, $outstanding);
        $receiptsBefore = Receipt::where('pr_id', 4)->count();

        // Overpay: validation error, record + receipts untouched.
        // pay_now's route param is {id} — hash-bound by
        // RouteServiceProvider (decodeHash). The invoice blade posts
        // Qs::hash($uc->id); a RAW id decodes to '' and 404s. That
        // 404 is test-craft error, not an app bug — post hashed.
        $this->actingAs($this->accountant())
            ->post(route('payments.pay_now', Qs::hash(4)), ['amt_paid' => $outstanding + 1])
            ->assertSessionHasErrors('amt_paid');
        $this->assertSame(7200, PaymentRecord::find(4)->amt_paid);
        $this->assertSame($receiptsBefore, Receipt::where('pr_id', 4)->count());

        // Partial payment at/below balance: JSON ok, record + receipt land.
        $this->actingAs($this->accountant())
            ->post(route('payments.pay_now', Qs::hash(4)), ['amt_paid' => 100])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(7300, PaymentRecord::find(4)->amt_paid);
        $this->assertSame($receiptsBefore + 1, Receipt::where('pr_id', 4)->count());

        // Teacher cannot record at all: middleware fires first.
        $this->actingAs($this->teacher())
            ->post(route('payments.pay_now', Qs::hash(4)), ['amt_paid' => 100])
            ->assertRedirect(route('login'));
        $this->assertSame(7300, PaymentRecord::find(4)->amt_paid);
    }

    public function test_defaulters_and_collection_role_shape()
    {
        $this->actingAs($this->accountant())->get(route('payments.defaulters'))->assertOk();
        $this->actingAs($this->accountant())->get(route('payments.collection'))->assertOk();
        $this->actingAs($this->admin())->get(route('payments.defaulters'))->assertOk();

        // teamAccount only: principal + teacher bounce to login,
        // SA bounces to /dashboard via PlatformOnly.
        $this->actingAs($this->teacher())->get(route('payments.defaulters'))->assertRedirect(route('login'));
        $this->actingAs($this->principal())->get(route('payments.defaulters'))->assertRedirect(route('login'));
        $this->actingAs($this->principal())->get(route('payments.collection'))->assertRedirect(route('login'));
        $this->actingAs($this->superAdmin())->get(route('payments.defaulters'))->assertRedirect('/dashboard');
    }

    public function test_expenses_write_shape()
    {
        $payload = ['title' => 'R22-EXP', 'category' => 'Utilities', 'amount' => 250, 'expense_date' => now()->format('Y-m-d')];

        $this->actingAs($this->accountant())->post(route('expenses.store'), $payload)
            ->assertRedirect(route('expenses.index'));
        $this->assertDatabaseHas('expenses', ['title' => 'R22-EXP', 'amount' => 250]);

        $this->actingAs($this->teacher())->post(route('expenses.store'), ['title' => 'R22-EXP-T', 'category' => 'Utilities', 'amount' => 250, 'expense_date' => now()->format('Y-m-d')])
            ->assertRedirect(route('login'));
        $this->actingAs($this->principal())->post(route('expenses.store'), ['title' => 'R22-EXP-P', 'category' => 'Utilities', 'amount' => 250, 'expense_date' => now()->format('Y-m-d')])
            ->assertRedirect(route('login'));
        $this->actingAs($this->superAdmin())->post(route('expenses.store'), ['title' => 'R22-EXP-SA', 'category' => 'Utilities', 'amount' => 250, 'expense_date' => now()->format('Y-m-d')])
            ->assertForbidden();
        $this->assertDatabaseMissing('expenses', ['title' => 'R22-EXP-T']);
        $this->assertDatabaseMissing('expenses', ['title' => 'R22-EXP-P']);
        $this->assertDatabaseMissing('expenses', ['title' => 'R22-EXP-SA']);

        $this->actingAs($this->accountant())->get(route('expenses.index'))->assertOk();
        $this->actingAs($this->teacher())->get(route('expenses.index'))->assertRedirect(route('login'));
    }

    public function test_invoice_policy_shape()
    {
        $foreign = $this->foreignStudentRecord();

        // Accountant: school-wide, any student.
        $this->actingAs($this->accountant())->get(route('payments.invoice', Qs::hash(57)))->assertOk();
        $this->actingAs($this->accountant())->get(route('payments.invoice', Qs::hash($foreign->user_id)))->assertOk();
        $this->actingAs($this->admin())->get(route('payments.invoice', Qs::hash(57)))->assertOk();

        // Teacher never reaches the policy: middleware redirects.
        $this->actingAs($this->teacher())->get(route('payments.invoice', Qs::hash(57)))->assertRedirect(route('login'));

        // Principal passes the middleware but fails viewFees: 403.
        $this->actingAs($this->principal())->get(route('payments.invoice', Qs::hash(57)))->assertForbidden();

        // Student/parent never reach the policy either: middleware.
        $this->actingAs($this->student())->get(route('payments.invoice', Qs::hash(57)))->assertRedirect(route('login'));
        $this->actingAs($this->parent())->get(route('payments.invoice', Qs::hash(57)))->assertRedirect(route('login'));

        // SA: PlatformOnly before the team gate.
        $this->actingAs($this->superAdmin())->get(route('payments.invoice', Qs::hash(57)))->assertRedirect('/dashboard');
    }

    public function test_fee_reminders_accountant_only()
    {
        $before = UserNotification::count();

        $this->actingAs($this->accountant())->post(route('payments.defaulters.remind'))
            ->assertRedirect();
        $this->assertNotNull(session('flash_success'));
        $this->assertStringContainsString('Fee reminders sent to', session('flash_success'));
        $this->assertGreaterThan($before, UserNotification::count());

        $mid = UserNotification::count();
        $this->actingAs($this->teacher())->post(route('payments.defaulters.remind'))
            ->assertRedirect(route('login'));
        $this->assertSame($mid, UserNotification::count());
    }

    public function test_dashboard_search_accountant_closed()
    {
        // Round-18 control, re-proven: accountants cannot search.
        $this->actingAs($this->accountant())->get(route('dashboard.search', ['q' => 'Aarav']))->assertForbidden();
    }
}
