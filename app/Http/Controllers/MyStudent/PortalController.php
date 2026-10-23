<?php

namespace App\Http\Controllers\MyStudent;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\BookRequest;
use App\Models\LibraryFine;
use App\Models\SchoolEvent;
use App\Models\Setting;
use App\Repositories\PaymentRepo;
use App\Repositories\StudentRepo;
use Illuminate\Support\Facades\Auth;

/**
 * Student self-service portal (2026-10-23) — the three surfaces the
 * full-site audit found missing for students (parents already had a
 * fees portal; students could not see even their own library loans):
 * own fees, own library, school events. Every page is read-only and
 * scoped to the signed-in student on the server: no request
 * parameter selects a student anywhere in this controller.
 */
class PortalController extends Controller
{
    protected $student, $pay;

    public function __construct(StudentRepo $student, PaymentRepo $pay)
    {
        $this->student = $student;
        $this->pay = $pay;
    }

    /**
     * Own fee statement for the current session — the parent's
     * feesShow presentation, for the student themself. Payment
     * actions stay with parents and the school office; this page
     * never takes money.
     */
    public function fees()
    {
        $sr = $this->student->findByUserId(Auth::user()->id)->first();
        if (!$sr) {
            abort(404);
        }

        $year = Qs::getCurrentSession();
        $records = $this->pay->getAllMyPR(Auth::user()->id, $year)->get();
        $records->load('receipt');

        $present = function ($r) {
            return $this->presentRecord($r);
        };

        $d['sr'] = $sr;
        $d['year'] = $year;
        $d['uncleared'] = $records->where('paid', 0)->map($present)->values();
        $d['cleared'] = $records->where('paid', 1)->map($present)->values();
        $d['total_due'] = $records->where('paid', 0)->sum(function ($r) {
            return $r->balance ?: $r->payment->amount;
        });

        return view('pages.student.fees', $d);
    }

    /**
     * Printable receipt for one of the student's OWN payment
     * records. PaymentRecordPolicy@view passes for a student only
     * when the record's student_id is their own id — the same
     * receipt view the staff and parent flows render.
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

    /** Own library loans and own fines, read-only. */
    public function library()
    {
        $d['loans'] = BookRequest::with('book')
            ->where('user_id', Auth::user()->id)
            ->latest()->get();
        $d['fines'] = LibraryFine::with('book_request.book')
            ->where('user_id', Auth::user()->id)
            ->latest()->get();

        return view('pages.student.library', $d);
    }

    /** School events — upcoming first, then recent past. Read-only. */
    public function events()
    {
        $today = now()->startOfDay();
        $d['upcoming'] = SchoolEvent::whereDate('starts_at', '>=', $today)
            ->orderBy('starts_at')->get();
        $d['past'] = SchoolEvent::whereDate('starts_at', '<', $today)
            ->orderByDesc('starts_at')->limit(20)->get();

        return view('pages.student.events', $d);
    }

    /**
     * The parent's record presentation, unchanged (MyController
     * Workstream O / audit R-01): total = the fee amount, paid =
     * amt_paid, balance = fee − amt_paid (never the stored balance
     * column, whose 0 used to read as "nothing stored").
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
