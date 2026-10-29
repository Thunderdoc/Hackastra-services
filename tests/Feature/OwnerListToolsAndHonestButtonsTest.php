<?php

namespace Tests\Feature;

use App\Models\PaymentRecord;
use App\Models\UserNotification;
use App\Support\PublicationReadiness;
use App\User;
use Tests\TestCase;

/**
 * Owner asks 2026-10-29 evening, from two screenshots:
 * (1) Long lists must carry their own tools — S/N indexing,
 *     search/filter, select-all + bulk action — without the
 *     owner having to ask per page. Shown on the publication
 *     page's pending-teachers list (434 rows, one Remind each).
 * (2) "Jo cheez possible hi nahi hai uska button mat dikhao
 *     kisi ko bhi" — never render a button for an action that
 *     cannot happen: the Principal (invoice pages refuse him by
 *     design) gets no "Manage Payments" button, and a fee with
 *     nothing paid gets no Print/Download Receipt items.
 */
class OwnerListToolsAndHonestButtonsTest extends TestCase
{
    protected function userByEmail(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_publication_list_has_indexing_search_filter_and_select_all()
    {
        $exam = \App\Models\Exam::findOrFail(1);
        $this->assertNotEmpty(PublicationReadiness::pendingUnits($exam), 'seed must have pending units');

        $page = $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->get(route('exams.publication', $exam->id));
        $page->assertOk();
        $page->assertSee('id="pub-search"', false);
        $page->assertSee('id="pub-class-filter"', false);
        $page->assertSee('id="pub-select-all"', false);
        $page->assertSee('Remind selected', false);
        $page->assertSee('<th style="width:52px">S/N</th>', false);
    }

    public function test_remind_selected_bells_only_the_picked_units()
    {
        $exam = \App\Models\Exam::findOrFail(1);
        $pending = collect(PublicationReadiness::pendingUnits($exam))
            ->filter(function ($u) { return !empty($u['teacher_id']); })
            ->values();
        $this->assertGreaterThanOrEqual(3, $pending->count(), 'need at least 3 pending units with teachers');

        $picked = $pending->take(2);
        $units = $picked->map(function ($u) { return $u['section_id'] . ':' . $u['subject_id']; })->all();

        $this->actingAs($this->userByEmail('admin@hackstra.app'))
            ->post(route('exams.notify_pending', $exam->id), ['units' => $units])
            ->assertRedirect()
            ->assertSessionHas('flash_success', 'Reminder sent to 2 teachers.');

        $belled = UserNotification::where('type', 'marks_pending')->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing(
            $picked->pluck('teacher_id')->all(),
            $belled,
            'exactly the picked units’ teachers are belled, nobody else'
        );
    }

    public function test_principal_gets_no_manage_payments_button_but_admin_does()
    {
        $url = route('payments.manage', 1);

        $principal = $this->actingAs($this->userByEmail('principal@hackstra.app'))->get($url);
        $principal->assertOk();
        $principal->assertDontSee('Manage Payments', false);

        $admin = $this->actingAs($this->userByEmail('admin@hackstra.app'))->get($url);
        $admin->assertOk();
        $admin->assertSee('Manage Payments', false);
    }

    public function test_receipt_actions_only_where_money_was_received()
    {
        // Any student with uncleared records: the uncleared tab
        // may offer receipt actions exactly on the rows where
        // money was actually received (amt_paid > 0) — never on a
        // zero-paid row.
        $studentId = PaymentRecord::where('paid', 0)
            ->where(function ($q) { $q->whereNull('amt_paid')->orWhere('amt_paid', 0); })
            ->value('student_id');
        $this->assertNotNull($studentId, 'seed must hold an unpaid record');

        $expected = PaymentRecord::where('student_id', $studentId)
            ->where('paid', 0)->where('amt_paid', '>', 0)->count();

        $html = $this->actingAs($this->userByEmail('accountant@hackstra.app'))
            ->get(route('payments.invoice', [\App\Helpers\Qs::hash($studentId)]))
            ->assertOk()
            ->getContent();

        // Scope to the uncleared tab (receipt items also exist in
        // the cleared tab, where they are legitimate).
        $start = strpos($html, 'id="all-uc"');
        $end = strpos($html, 'id="all-cl"');
        $this->assertNotFalse($start);
        $uncleared = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
        $this->assertSame($expected, substr_count($uncleared, 'Print Receipt'));
        $this->assertSame($expected, substr_count($uncleared, 'Download Receipt (PDF)'));
    }
}
