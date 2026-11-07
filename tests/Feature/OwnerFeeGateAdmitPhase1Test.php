<?php

namespace Tests\Feature;

use App\Helpers\Qs;
use App\Models\Exam;
use App\Models\PaymentRecord;
use App\Models\StudentRecord;
use App\Support\FeeDefaulter;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Round 3 Phase 1 — fee-gate list fix (R4 scoping section 6).
 *
 * Anchors are resolved through student_records 1 and 2, never by
 * hardcoded user id: record 1 is fully paid for the current
 * session (outstanding 0) and record 2 owes 14,400
 * (10,800 + 3,000 + 600). Both belong to parent@hackstra.app.
 * Preconditions are asserted in setUp so a seed change fails
 * loudly as a seed failure, not a phantom gate failure.
 *
 * Covered: R4 section 6 tests 1, 2, 3, 4, 5 (list state incl.
 * student self-view), 6 (direct-URL enforcement — proven by
 * tests 2 and 3, which type the card URL directly), 9
 * (partial-payment precision), 10 (no-records fail-open), 11
 * (dateless exam), 12 (publication orthogonality). Test 8
 * (bulk partition) is intentionally skipped — office/bulk
 * printing stays exactly as-is by owner decision.
 */
class OwnerFeeGateAdmitPhase1Test extends TestCase
{
    use DatabaseTransactions;

    protected $sr1;
    protected $sr2;
    protected $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sr1 = StudentRecord::findOrFail(1);
        $this->sr2 = StudentRecord::findOrFail(2);
        $this->session = Qs::getCurrentSession();

        $this->assertSame(0, FeeDefaulter::outstandingFor($this->sr1->user_id, $this->session),
            'Precondition: record 1 must owe 0 for the current session');
        $this->assertSame(14400, FeeDefaulter::outstandingFor($this->sr2->user_id, $this->session),
            'Precondition: record 2 must owe 14,400 for the current session');
    }

    protected function user1()
    {
        return User::findOrFail($this->sr1->user_id);
    }

    protected function user2()
    {
        return User::findOrFail($this->sr2->user_id);
    }

    protected function parentUser()
    {
        return User::where('email', 'parent@hackstra.app')->firstOrFail();
    }

    // Test 1 — paid student renders the real card (S4).
    public function test_paid_student_renders_card()
    {
        $response = $this->actingAs($this->user1())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_print');
        $response->assertSee($this->user1()->name, false);
        $response->assertDontSee('Cannot Be Generated', false);
    }

    // Test 2 — owing student is blocked on the direct card URL (S4).
    // Together with test 3 this is also test 6 (direct-URL
    // enforcement): the typed URL is blocked even though the
    // list no longer offers the link.
    public function test_owing_student_blocked_on_direct_card_url()
    {
        $response = $this->actingAs($this->user2())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.fee_blocked');
        $response->assertSee('Admit Card Cannot Be Generated Yet', false);
        $response->assertSee('14,400', false);
        $response->assertSee($this->session, false);
        $response->assertSee('Please ask your parent to pay the fee', false);
        $response->assertDontSee('Pay the Fee', false);
    }

    // Test 3 — owing child is blocked for the parent, with the
    // Pay the Fee action pointing at that child's fees page (S4).
    public function test_owing_child_blocked_for_parent_with_pay_link()
    {
        $response = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.fee_blocked');
        $response->assertSee('Admit Card Cannot Be Generated Yet', false);
        $response->assertSee('14,400', false);
        $response->assertSee('Pay the Fee', false);
        $response->assertSee(route('my_fees.show', $this->sr2->user_id), false);
    }

    // Test 4 — mixed family: the same parent still gets the paid
    // child's real card (per-child isolation).
    public function test_parent_paid_child_still_renders_card()
    {
        $response = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_print');
        $response->assertSee($this->user1()->name, false);
        $response->assertDontSee('Cannot Be Generated', false);
    }

    // Test 5a — list state for the parent (S3): the owing child
    // shows the fee-due state with amount, session and Pay link
    // and no card link; the paid child keeps View / Print.
    public function test_list_parent_shows_due_state_for_owing_child_only()
    {
        $response = $this->actingAs($this->parentUser())
            ->get(route('admit_cards.my'));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_my');
        $response->assertSee('Fees pending', false);
        $response->assertSee('14,400', false);
        $response->assertSee($this->session, false);
        $response->assertSee('The admit card unlocks as soon as the fees are paid.', false);
        $response->assertSee('Pay the Fee', false);
        $response->assertSee(route('my_fees.show', $this->sr2->user_id), false);
        $response->assertDontSee(route('admit_cards.my_card', [1, $this->sr2->user_id]), false);
        $response->assertSee(route('admit_cards.my_card', [1, $this->sr1->user_id]), false);
        $response->assertSee('View / Print', false);
    }

    // Test 5b — list state for the owing student themself (S3):
    // due state shows, but students never get a pay action.
    public function test_list_owing_student_shows_due_state_without_pay_button()
    {
        $response = $this->actingAs($this->user2())
            ->get(route('admit_cards.my'));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_my');
        $response->assertSee('Fees pending', false);
        $response->assertSee('14,400', false);
        $response->assertSee('The admit card unlocks as soon as the fees are paid.', false);
        $response->assertDontSee('Pay the Fee', false);
        $response->assertDontSee(route('admit_cards.my_card', [1, $this->sr2->user_id]), false);
    }

    // Test 5c — list state for the paid student (S3): rows render
    // exactly as before, with the card link and no due state.
    public function test_list_paid_student_keeps_print_link()
    {
        $response = $this->actingAs($this->user1())
            ->get(route('admit_cards.my'));

        $response->assertStatus(200);
        $response->assertSee(route('admit_cards.my_card', [1, $this->sr1->user_id]), false);
        $response->assertSee('View / Print', false);
        $response->assertDontSee('Fees pending', false);
    }

    // Test 9 — partial-payment precision: clearing one record
    // drops the blocked figure to the remainder; clearing all
    // renders the card.
    public function test_partial_payment_drops_due_to_remainder_then_clears()
    {
        $records = PaymentRecord::where('student_id', $this->sr2->user_id)
            ->where('year', $this->session)
            ->where('paid', 0)
            ->get();
        $this->assertNotEmpty($records);

        $largest = $records->sortByDesc(function ($r) {
            return (int) ($r->balance ?: optional($r->payment)->amount);
        })->first();
        $cleared = (int) ($largest->balance ?: optional($largest->payment)->amount);
        $this->assertGreaterThan(0, $cleared);

        $largest->update(['paid' => 1, 'balance' => 0]);

        $remainder = 14400 - $cleared;
        $this->assertSame($remainder, FeeDefaulter::outstandingFor($this->sr2->user_id, $this->session));

        $blocked = $this->actingAs($this->user2())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));
        $blocked->assertStatus(200);
        $blocked->assertViewIs('pages.support_team.fee_blocked');
        $blocked->assertSee(\App\Helpers\Money::inr($remainder), false);

        PaymentRecord::where('student_id', $this->sr2->user_id)
            ->where('year', $this->session)
            ->update(['paid' => 1, 'balance' => 0]);
        $this->assertSame(0, FeeDefaulter::outstandingFor($this->sr2->user_id, $this->session));

        $card = $this->actingAs($this->user2())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr2->user_id]));
        $card->assertStatus(200);
        $card->assertViewIs('pages.support_team.exams.admit_print');
        $card->assertDontSee('Cannot Be Generated', false);
    }

    // Test 10 — no fee records at all: fail-open, the card renders.
    public function test_student_with_no_fee_records_renders_card()
    {
        $user = User::create([
            'name' => 'Phase1 No Records Pupil',
            'email' => null,
            'phone' => null,
            'dob' => null,
            'gender' => 'Female',
            'address' => 'Test Address',
            'user_type' => 'student',
            'code' => strtoupper(Str::random(10)),
            'password' => Hash::make('student'),
            'username' => 'TEST/P1/' . strtoupper(Str::random(6)),
        ]);
        StudentRecord::create([
            'user_id' => $user->id,
            'my_class_id' => $this->sr1->my_class_id,
            'section_id' => $this->sr1->section_id,
            'adm_no' => 'TEST/P1/' . $user->id,
            'session' => $this->session,
            'year_admitted' => '2026',
            'grad' => 0,
        ]);

        $this->assertSame(0, PaymentRecord::where('student_id', $user->id)->count());
        $this->assertSame(0, FeeDefaulter::outstandingFor($user->id, $this->session));

        $response = $this->actingAs($user)
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $user->id]));
        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_print');
        $response->assertSee('Phase1 No Records Pupil', false);
        $response->assertDontSee('Cannot Be Generated', false);
    }

    // Test 11 — dateless exam: the gate fires before the schedule,
    // so the paid student gets the fallback card while the owing
    // student still gets the fee block for the same exam.
    public function test_dateless_exam_paid_gets_fallback_owing_gets_blocked()
    {
        $exam = Exam::create([
            'name' => 'Phase1 Dateless Exam',
            'term' => 2,
            'year' => $this->session,
            'results_published' => true,
            'my_class_id' => 0,
        ]);

        $paid = $this->actingAs($this->user1())
            ->get(route('admit_cards.my_card', ['exam' => $exam->id, 'student_id' => $this->sr1->user_id]));
        $paid->assertStatus(200);
        $paid->assertViewIs('pages.support_team.exams.admit_print');
        $paid->assertSee('will be announced', false);
        $paid->assertDontSee('Cannot Be Generated', false);

        $owing = $this->actingAs($this->user2())
            ->get(route('admit_cards.my_card', ['exam' => $exam->id, 'student_id' => $this->sr2->user_id]));
        $owing->assertStatus(200);
        $owing->assertViewIs('pages.support_team.fee_blocked');
        $owing->assertSee('14,400', false);
    }

    // Test 12 — publication orthogonality: results_published=false
    // does not gate the admit card for a paid student.
    public function test_unpublished_results_exam_still_renders_for_paid()
    {
        Exam::findOrFail(1)->update(['results_published' => false]);

        $response = $this->actingAs($this->user1())
            ->get(route('admit_cards.my_card', ['exam' => 1, 'student_id' => $this->sr1->user_id]));

        $response->assertStatus(200);
        $response->assertViewIs('pages.support_team.exams.admit_print');
        $response->assertSee($this->user1()->name, false);
        $response->assertDontSee('Cannot Be Generated', false);
    }
}
