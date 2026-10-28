<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\Concession;
use App\Models\FeeHead;
use App\Models\FeeInstallment;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\StudentRecord;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Wave 2 — Fee Structure Builder (admin-only writes).
 *
 * Reads (index, show, net views) are teamSA (admin + super admin);
 * every write is teamSAWrite (admin only), enforced here in the
 * constructor and repeated on the route lines for the coordinator.
 *
 * Route parameters use distinct names ({fee_head}, {installment},
 * {concession}, {student_record}) — only a parameter literally named
 * {id} is hash-bound, so these arrive as plain ids and blades pass
 * plain ids to route(). Nothing here is hashed.
 *
 * Installment-balance rule (documented choice: REFUSE, no
 * auto-balancing): a head saved with installments must balance
 * exactly — installments must sum to the head amount, otherwise the
 * save is refused. Single installments added/edited afterwards must
 * not push the head's installment total OVER the head amount; a head
 * left under-balanced by a deletion is flagged on its show page, not
 * silently fixed.
 *
 * Concession approver: approved_by is set server-side to the acting
 * admin on creation (it is not user input) and is left untouched by
 * later edits.
 *
 * Apply is idempotent per head: a Payment row is created only when
 * no Payment with the same title + my_class_id + year exists yet.
 * Net figures are computed live on every view — nothing is stored.
 */
class FeeStructureController extends Controller
{
    public function __construct()
    {
        $this->middleware('teamSA', ['only' => ['index', 'show', 'netIndex', 'net']]);
        $this->middleware('teamSAWrite', ['except' => ['index', 'show', 'netIndex', 'net']]);
    }

    public function index()
    {
        $d['heads'] = FeeHead::with(['my_class', 'installments'])
            ->orderBy('year', 'desc')->orderBy('my_class_id')->orderBy('title')->get();
        $d['my_classes'] = MyClass::orderBy('id')->get();

        return view('pages.support_team.fee_structure.index', $d);
    }

    public function create()
    {
        $d['my_classes'] = MyClass::orderBy('id')->get();
        $d['year'] = Qs::getCurrentSession();

        return view('pages.support_team.fee_structure.create', $d);
    }

    public function store(Request $req)
    {
        $data = $this->validateHead($req);
        $installments = $this->validateInstallments($req, (int) $data['amount']);

        $head = FeeHead::create($data);
        $this->saveInstallments($head, $installments);
        Audit::log('fee_structure.head_created', $head, 'Fee head created: ' . $head->title . ' (' . $head->amount . ')');

        return Qs::storeOk('fee_structure.index');
    }

    public function show($fee_head)
    {
        $d['head'] = $head = FeeHead::with(['my_class', 'installments', 'concessions.student_record.user'])
            ->findOrFail($fee_head);
        $d['installments_total'] = (int) $head->installments->sum('amount');
        $d['balanced'] = $d['installments_total'] === (int) $head->amount;
        $d['applied'] = Payment::where('title', $head->title)
            ->where('my_class_id', $head->my_class_id)
            ->where('year', $head->year)->exists();
        $d['students'] = StudentRecord::with('user')
            ->where('my_class_id', $head->my_class_id)->where('grad', 0)
            ->get()->sortBy(function ($sr) { return optional($sr->user)->name; });

        return view('pages.support_team.fee_structure.show', $d);
    }

    public function edit($fee_head)
    {
        $d['head'] = FeeHead::with('installments')->findOrFail($fee_head);
        $d['my_classes'] = MyClass::orderBy('id')->get();

        return view('pages.support_team.fee_structure.edit', $d);
    }

    public function update(Request $req, $fee_head)
    {
        $head = FeeHead::findOrFail($fee_head);
        $data = $this->validateHead($req);
        $installments = $this->validateInstallments($req, (int) $data['amount']);

        $head->update($data);
        $head->installments()->delete();
        $this->saveInstallments($head, $installments);
        Audit::log('fee_structure.head_updated', $head, 'Fee head updated: ' . $head->title . ' (' . $head->amount . ')');

        return Qs::updateOk('fee_structure.index');
    }

    public function destroy($fee_head)
    {
        $head = FeeHead::findOrFail($fee_head);
        if (Payment::where('title', $head->title)
            ->where('my_class_id', $head->my_class_id)
            ->where('year', $head->year)->exists()) {
            return back()->with('flash_danger', 'This fee head has already been applied as a Payment — it cannot be deleted. Applied fees are part of the ledger history.');
        }
        $head->delete();
        Audit::log('fee_structure.head_deleted', $head, 'Fee head deleted: ' . $head->title);

        return Qs::deleteOk('fee_structure.index');
    }

    public function installmentStore(Request $req, $fee_head)
    {
        $head = FeeHead::findOrFail($fee_head);
        $data = $req->validate([
            'installment_no' => 'required|integer|min:1',
            'due_date' => 'nullable|date',
            'amount' => 'required|integer|min:0',
        ]);
        $total = (int) $head->installments()->sum('amount') + (int) $data['amount'];
        if ($total > (int) $head->amount) {
            throw ValidationException::withMessages([
                'amount' => 'Installments would total ' . $total . ', exceeding the head amount (' . $head->amount . ').',
            ]);
        }
        $data['fee_head_id'] = $head->id;
        $inst = FeeInstallment::create($data);
        Audit::log('fee_structure.installment_created', $inst, 'Installment added to fee head: ' . $head->title);

        return back()->with('flash_success', __('msg.store_ok'));
    }

    public function installmentUpdate(Request $req, $fee_head, $installment)
    {
        $head = FeeHead::findOrFail($fee_head);
        $inst = FeeInstallment::where('fee_head_id', $head->id)->findOrFail($installment);
        $data = $req->validate([
            'installment_no' => 'required|integer|min:1',
            'due_date' => 'nullable|date',
            'amount' => 'required|integer|min:0',
        ]);
        $total = (int) $head->installments()->where('id', '!=', $inst->id)->sum('amount') + (int) $data['amount'];
        if ($total > (int) $head->amount) {
            throw ValidationException::withMessages([
                'amount' => 'Installments would total ' . $total . ', exceeding the head amount (' . $head->amount . ').',
            ]);
        }
        $inst->update($data);
        Audit::log('fee_structure.installment_updated', $inst, 'Installment updated on fee head: ' . $head->title);

        return back()->with('flash_success', __('msg.update_ok'));
    }

    public function installmentDestroy($fee_head, $installment)
    {
        $head = FeeHead::findOrFail($fee_head);
        $inst = FeeInstallment::where('fee_head_id', $head->id)->findOrFail($installment);
        $inst->delete();
        Audit::log('fee_structure.installment_deleted', $inst, 'Installment deleted from fee head: ' . $head->title);

        return back()->with('flash_success', __('msg.del_ok'));
    }

    public function concessionCreate(Request $req)
    {
        $d['heads'] = FeeHead::with('my_class')->orderBy('year', 'desc')->orderBy('title')->get();
        $d['selected_head'] = $req->query('fee_head') ? FeeHead::find($req->query('fee_head')) : null;
        $d['students'] = StudentRecord::with(['user', 'my_class'])->where('grad', 0)
            ->get()->sortBy(function ($sr) { return optional($sr->user)->name; });

        return view('pages.support_team.fee_structure.concessions.create', $d);
    }

    public function concessionStore(Request $req)
    {
        $data = $this->validateConcession($req);
        $data['approved_by'] = auth()->id();
        $concession = Concession::create($data);
        Audit::log('fee_structure.concession_created', $concession, 'Concession created: ' . $concession->type . ' ' . $concession->value);

        return Qs::storeOk('fee_structure.index');
    }

    public function concessionEdit($concession)
    {
        $d['concession'] = Concession::with(['fee_head', 'student_record.user'])->findOrFail($concession);
        $d['heads'] = FeeHead::with('my_class')->orderBy('year', 'desc')->orderBy('title')->get();
        $d['students'] = StudentRecord::with(['user', 'my_class'])->where('grad', 0)
            ->get()->sortBy(function ($sr) { return optional($sr->user)->name; });

        return view('pages.support_team.fee_structure.concessions.edit', $d);
    }

    public function concessionUpdate(Request $req, $concession)
    {
        $concession = Concession::findOrFail($concession);
        $data = $this->validateConcession($req);
        $concession->update($data);
        Audit::log('fee_structure.concession_updated', $concession, 'Concession updated: ' . $concession->type . ' ' . $concession->value);

        return Qs::updateOk('fee_structure.index');
    }

    public function concessionDestroy($concession)
    {
        $concession = Concession::findOrFail($concession);
        $concession->delete();
        Audit::log('fee_structure.concession_deleted', $concession, 'Concession deleted');

        return Qs::deleteOk('fee_structure.index');
    }

    /**
     * Apply one fee head as a Payment row — idempotent: when a
     * Payment with the same title + my_class_id + year already
     * exists, nothing is created and the run reports "skipped".
     * Payment fillable fields used: title, amount, my_class_id,
     * description, year. ref_no and method are left blank — Apply
     * defines the fee, it does not record a payment against it.
     */
    public function apply($fee_head)
    {
        $head = FeeHead::findOrFail($fee_head);
        $exists = Payment::where('title', $head->title)
            ->where('my_class_id', $head->my_class_id)
            ->where('year', $head->year)->exists();

        if ($exists) {
            return back()->with('flash_success', 'Fee head already applied for this class and session — no duplicate Payment created.');
        }

        $payment = Payment::create([
            'title' => $head->title,
            'amount' => $head->amount,
            'my_class_id' => $head->my_class_id,
            'description' => 'Fee structure: ' . $head->title,
            'year' => $head->year,
            // payments.ref_no is NOT NULL: generated with the app's
            // own helper, exactly as PaymentController@store does.
            // method is left to the DB default ('cash').
            'ref_no' => \App\Helpers\Pay::genRefCode(),
        ]);
        Audit::log('fee_structure.applied', $head, 'Fee head applied as Payment #' . $payment->id . ': ' . $head->title);

        return back()->with('flash_success', 'Fee head applied as a Payment for ' . optional($head->my_class)->name . '.');
    }

    public function netIndex()
    {
        $d['students'] = StudentRecord::with(['user', 'my_class'])->where('grad', 0)
            ->paginate(25);

        return view('pages.support_team.fee_structure.net_index', $d);
    }

    /**
     * Per-student net view: every fee head for the student's class,
     * each head's concession discount for this student, and the net
     * payable — all computed live, nothing stored.
     */
    public function net($student_record)
    {
        $sr = StudentRecord::with(['user', 'my_class'])->findOrFail($student_record);
        $heads = FeeHead::with(['installments', 'concessions'])
            ->where('my_class_id', $sr->my_class_id)
            ->orderBy('year', 'desc')->orderBy('title')->get();

        $rows = $heads->map(function ($head) use ($sr) {
            $discount = $head->concessionDiscountFor($sr->id);

            return [
                'head' => $head,
                'gross' => (float) $head->amount,
                'discount' => $discount,
                'net' => max(0, (float) $head->amount - $discount),
                'concessions' => $head->concessions->where('student_record_id', $sr->id),
            ];
        });

        $d['sr'] = $sr;
        $d['rows'] = $rows;
        $d['total_gross'] = $rows->sum('gross');
        $d['total_discount'] = $rows->sum('discount');
        $d['total_net'] = $rows->sum('net');

        return view('pages.support_team.fee_structure.net', $d);
    }

    protected function validateHead(Request $req)
    {
        return $req->validate([
            'my_class_id' => 'required|exists:my_classes,id',
            'title' => 'required|string|max:255',
            'amount' => 'required|integer|min:0',
            'year' => 'required|string|max:50',
        ]);
    }

    /**
     * Bulk installments from the head form. When the form sends no
     * installments at all, the head is saved bare (installments can
     * be added one by one afterwards). When it sends any, their sum
     * must equal the head amount exactly — otherwise refuse.
     */
    protected function validateInstallments(Request $req, $amount)
    {
        // Fully blank rows (the form's spare rows) are dropped first,
        // so a bare head can be saved; a partly filled row is a real
        // row and must validate in full.
        $raw = $req->input('installments', []);
        $installments = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $blank = true;
                foreach (['installment_no', 'due_date', 'amount'] as $key) {
                    if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                        $blank = false;
                    }
                }
                if (!$blank) {
                    $installments[] = $row;
                }
            }
        }

        foreach ($installments as $row) {
            \Illuminate\Support\Facades\Validator::make($row, [
                'installment_no' => 'required|integer|min:1',
                'due_date' => 'nullable|date',
                'amount' => 'required|integer|min:0',
            ])->validate();
        }

        if ($installments) {
            $sum = (int) collect($installments)->sum('amount');
            if ($sum !== (int) $amount) {
                throw ValidationException::withMessages([
                    'installments' => 'Installments sum to ' . $sum . ' but the head amount is ' . $amount . '. Installments must sum to the head amount.',
                ]);
            }
        }

        return $installments;
    }

    protected function saveInstallments(FeeHead $head, array $installments)
    {
        foreach ($installments as $i) {
            FeeInstallment::create([
                'fee_head_id' => $head->id,
                'installment_no' => $i['installment_no'],
                'due_date' => $i['due_date'] ?? null,
                'amount' => $i['amount'],
            ]);
        }
    }

    protected function validateConcession(Request $req)
    {
        $data = $req->validate([
            'student_record_id' => 'required|exists:student_records,id',
            'fee_head_id' => 'required|exists:fee_heads,id',
            'type' => 'required|in:amount,percent',
            'value' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $head = FeeHead::findOrFail($data['fee_head_id']);
        if ($data['type'] === 'amount' && (float) $data['value'] > (float) $head->amount) {
            throw ValidationException::withMessages([
                'value' => 'Amount concession (' . $data['value'] . ') exceeds the head amount (' . $head->amount . ').',
            ]);
        }
        if ($data['type'] === 'percent' && (float) $data['value'] > 100) {
            throw ValidationException::withMessages([
                'value' => 'Percent concession cannot exceed 100.',
            ]);
        }

        return $data;
    }
}
