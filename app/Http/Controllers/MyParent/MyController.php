<?php

namespace App\Http\Controllers\MyParent;
use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Repositories\PaymentRepo;
use App\Repositories\StudentRepo;
use App\Support\RazorpayGateway;
use Illuminate\Support\Facades\Auth;

class MyController extends Controller
{
    protected $student, $pay;
    public function __construct(StudentRepo $student, PaymentRepo $pay)
    {
        $this->student = $student;
        $this->pay = $pay;
    }

    public function children()
    {
        $data['students'] = $this->student->getRecord(['my_parent_id' => Auth::user()->id])->with(['my_class', 'section'])->get();

        return view('pages.parent.children', $data);
    }

    /**
     * Loop 9 (Workstream O) — Parent fees portal: every fee record of the
     * current session for each linked child. Read-only.
     *
     * Isolation: the child list itself is the scope — it is loaded with
     * my_parent_id = the signed-in parent's id, and each child's records
     * are then fetched by that child's user id only. No request parameter
     * can widen the set of students or records shown.
     */
    public function fees()
    {
        $year = Qs::getCurrentSession();

        $children = $this->student->getRecord(['my_parent_id' => Auth::user()->id])
            ->with(['my_class', 'section'])->get();

        $d['year'] = $year;
        $d['gateway_enabled'] = RazorpayGateway::enabled();
        $d['children'] = $children->map(function ($sr) use ($year) {
            $records = $this->pay->getAllMyPR($sr->user_id, $year)->get();
            $records->load('receipt');

            return [
                'sr' => $sr,
                'rows' => $records->map(function ($r) {
                    return $this->presentRecord($r);
                }),
                'total' => $records->sum(function ($r) {
                    return $r->payment->amount;
                }),
                'paid' => $records->sum('amt_paid'),
                'due' => $records->where('paid', 0)->sum(function ($r) {
                    return $r->balance ?: $r->payment->amount;
                }),
            ];
        });

        return view('pages.parent.fees', $d);
    }

    /**
     * Loop 9 (Workstream O) — One child's fee detail for the current
     * session, split into incomplete / completed exactly like the staff
     * invoice page, minus the payment actions (parents are read-only).
     */
    public function feesShow($student_id)
    {
        if (!Qs::userIsMyChild($student_id, Auth::user()->id)) {
            abort(403);
        }

        $sr = $this->student->findByUserId($student_id)->first();
        if (!$sr) {
            abort(404);
        }
        $this->authorize('view', $sr);

        $year = Qs::getCurrentSession();
        $records = $this->pay->getAllMyPR($student_id, $year)->get();
        $records->load('receipt');

        $present = function ($r) {
            return $this->presentRecord($r);
        };

        $d['sr'] = $sr;
        $d['year'] = $year;
        $d['gateway_enabled'] = RazorpayGateway::enabled();
        $d['uncleared'] = $records->where('paid', 0)->map($present)->values();
        $d['cleared'] = $records->where('paid', 1)->map($present)->values();
        $d['total_due'] = $records->where('paid', 0)->sum(function ($r) {
            return $r->balance ?: $r->payment->amount;
        });

        return view('pages.parent.fees_show', $d);
    }

    /**
     * Loop 9 (Workstream O) — Printable receipt for one of the parent's
     * children's payment records. Renders the same receipt view the
     * staff side uses, with the same data PaymentController@receipts
     * prepares. The staff route itself sits behind teamAccount, so
     * parents get their own entry point; authorization is identical:
     * PaymentRecordPolicy@view only passes when the record's student
     * is a child of the signed-in parent.
     */
    public function feesReceipt($pr_id)
    {
        $pr = $this->pay->findRecord($pr_id);
        $this->authorize('view', $pr);

        $pr->load('receipt');
        $d['pr'] = $pr;
        $d['receipts'] = $pr->receipt;
        $d['payment'] = $pr->payment;
        $d['sr'] = $this->student->findByUserId($pr->student_id)->first();
        $d['s'] = Setting::all()->flatMap(function ($s) {
            return [$s->type => $s->description];
        });

        return view('pages.support_team.payments.receipt', $d);
    }

    /**
     * One payment record presented exactly as the staff invoice page
     * presents it: total = the fee amount, paid = amt_paid (0 when
     * untouched), balance = fee − amt_paid. That difference IS the
     * stored balance in every state the pay flow maintains (audit
     * Fix Wave 1, R-01): fully paid → 0, partially paid → the stored
     * remainder, untouched → the full fee. The old rule
     * ($record->balance ?: fee amount) treated a stored balance of 0
     * as "no balance stored" and showed the FULL fee on fully-paid
     * rows, contradicting the same card's ₹0 total.
     */
    protected function presentRecord($record): array
    {
        $paid = (int) ($record->amt_paid ?: 0);

        if ((int) $record->paid === 1) {
            $status = 'Paid';
            $badge = 'badge-success';
        } elseif ($paid > 0) {
            $status = 'Partially Paid';
            $badge = 'badge-warning';
        } else {
            $status = 'Not Paid';
            $badge = 'badge-danger';
        }

        return [
            'record' => $record,
            'title' => $record->payment->title,
            'total' => (int) $record->payment->amount,
            'paid' => $paid,
            'balance' => max(0, (int) $record->payment->amount - $paid),
            'status' => $status,
            'badge' => $badge,
            'has_receipts' => $record->receipt->isNotEmpty(),
        ];
    }

}
