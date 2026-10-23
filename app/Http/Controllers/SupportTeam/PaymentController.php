<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Support\CsvExport;
use App\Support\Audit;
use App\Helpers\Pay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PaymentCreate;
use App\Http\Requests\Payment\PaymentUpdate;
use App\Http\Requests\Payment\PayNowRequest;
use App\Http\Requests\Payment\SelectClassRequest;
use App\Models\GatewayTransaction;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\Setting;
use App\Models\StudentRecord;
use App\Repositories\MyClassRepo;
use App\Repositories\PaymentRepo;
use App\Repositories\StudentRepo;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PDF;

class PaymentController extends Controller
{
    protected $my_class, $pay, $student, $year;

    public function __construct(MyClassRepo $my_class, PaymentRepo $pay, StudentRepo $student)
    {
        $this->my_class = $my_class;
        $this->pay = $pay;
        $this->year = Qs::getCurrentSession();
        $this->student = $student;

        $this->middleware('teamAccount');
        // Workstream CM: fee writes (record payment, reset, fee CRUD)
        // belong to the school's Admin + Accountant. SA keeps every
        // read/document above (index, invoice, receipts, exports).
        $this->middleware('teamAccountWrite', ['only' => ['create', 'store', 'edit', 'update', 'destroy', 'pay_now', 'reset_record']]);
    }

    public function index()
    {
        $d['years'] = $this->pay->getPaymentYears();

        // Loop 94: land on the current session's figures by default —
        // the session picker (select_year → show) still switches.
        // A school with no fees and no receipts yet for the current
        // session keeps the legacy "select a year" landing.
        $hasData = $this->pay->getPayment(['year' => $this->year])->count() > 0
            || Receipt::where('year', $this->year)->exists();

        if (!$hasData) {
            $d['selected'] = false;
            return view('pages.support_team.payments.index', $d);
        }

        return view('pages.support_team.payments.index',
            array_merge($d, $this->sessionPageData($this->year)));
    }

    public function show($year)
    {
        $p = $this->pay->getPayment(['year' => $year])->get();

        if(($p->count() < 1)){
            return Qs::goWithDanger('payments.index');
        }

        $d = $this->sessionPageData($year);
        $d['years'] = $this->pay->getPaymentYears();

        return view('pages.support_team.payments.index', $d);

    }

    /**
     * Loop 94 — everything the Manage Payments page shows for one
     * session: the fee definitions (as before), the four stat-card
     * aggregates over the receipts ledger, and the session's payment
     * records (receipts, latest first, paginated). Read-only: no
     * money state is written or recomputed here — dues follow the
     * Fee Defaulters report's own rule (see sessionDues()).
     */
    protected function sessionPageData($year)
    {
        $d['selected'] = true;
        $d['year'] = $year;
        $d['payments'] = $this->pay->getPayment(['year' => $year])->get();
        $d['my_classes'] = $this->my_class->all();

        $d['stat_total_payments'] = Receipt::where('year', $year)->count();
        $d['stat_total_collected'] = (int) Receipt::where('year', $year)->sum('amt_paid');
        [$d['stat_pending_dues'], $d['stat_defaulters']] = $this->sessionDues($year);

        $d['records'] = Receipt::where('year', $year)
            ->with(['pr.payment', 'pr.student'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(15);

        $studentIds = $d['records']->getCollection()
            ->pluck('pr.student_id')->filter()->unique()->all();
        $d['record_students'] = StudentRecord::with(['my_class', 'section'])
            ->whereIn('user_id', $studentIds)->get()->keyBy('user_id');

        return $d;
    }

    /**
     * Outstanding dues for a session, by the Fee Defaulters report's
     * rule (FeeReportController::defaulterRows) run for the given
     * year: non-graduated students owing on unpaid records; a record
     * owes its stored balance, or the full fee amount when untouched.
     * Returns [total_due, defaulter_count].
     */
    protected function sessionDues($year)
    {
        $studentIds = StudentRecord::where('grad', 0)->pluck('user_id');

        $owing = PaymentRecord::with('payment')
            ->where('year', $year)
            ->where('paid', 0)
            ->whereIn('student_id', $studentIds->all())
            ->get()
            ->groupBy('student_id');

        $total = 0;
        $count = 0;
        foreach ($owing as $records) {
            $due = (int) $records->sum(function ($r) {
                return $r->balance ?: ($r->payment ? $r->payment->amount : 0);
            });
            if ($due > 0) {
                $total += $due;
                $count++;
            }
        }

        return [$total, $count];
    }

    public function select_year(Request $req)
    {
        return Qs::goToRoute(['payments.show', $req->year]);
    }

    /**
     * Collect Fee (Workstream BJ): the counter search behind the
     * payments landing panel. Partial match on student name, admission
     * number or class name; each result links straight to the student's
     * payment-records (invoice) page. Gated by the same teamAccount
     * middleware as every other payments action — no wider access.
     */
    public function student_search(Request $req)
    {
        $q = trim((string) $req->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . $q . '%';
        $students = StudentRecord::where('student_records.grad', 0)
            ->join('users', 'users.id', '=', 'student_records.user_id')
            ->join('my_classes', 'my_classes.id', '=', 'student_records.my_class_id')
            ->where(function ($w) use ($like) {
                $w->where('users.name', 'like', $like)
                  ->orWhere('student_records.adm_no', 'like', $like)
                  ->orWhere('my_classes.name', 'like', $like);
            })
            ->orderBy('users.name')
            ->limit(8)
            ->select('student_records.*')
            ->with(['user', 'my_class', 'section'])
            ->get();

        // Outstanding for the current session, by the invoice page's
        // rule: an untouched record owes its full payment amount.
        $outstanding = [];
        if ($students->count()) {
            $records = PaymentRecord::whereIn('student_id', $students->pluck('user_id')->all())
                ->where('year', $this->year)->where('paid', 0)->with('payment')->get();
            foreach ($records as $r) {
                $due = $r->balance ?: ($r->payment ? $r->payment->amount : 0);
                $outstanding[$r->student_id] = ($outstanding[$r->student_id] ?? 0) + $due;
            }
        }

        $results = $students->map(function ($sr) use ($outstanding) {
            $due = $outstanding[$sr->user_id] ?? 0;
            return [
                'name' => $sr->user->name,
                'adm_no' => $sr->adm_no,
                'class' => $sr->my_class->name,
                'section' => $sr->section ? $sr->section->name : '',
                'photo' => $sr->user->photo,
                'outstanding' => \App\Helpers\Money::inr($due),
                'has_outstanding' => $due > 0,
                'url' => route('payments.invoice', [Qs::hash($sr->user_id)]),
            ];
        });

        return response()->json(['results' => $results]);
    }

    public function create()
    {
        $d['my_classes'] = $this->my_class->all();
        return view('pages.support_team.payments.create', $d);
    }

    public function invoice($st_id, $year = NULL)
    {
        if(!$st_id) {return Qs::goWithDanger();}

        // Invoice links carry a hashed user id; resolve it to the plain
        // id first (mirrors PaymentController@export_student), and apply
        // the same record-level check the receipt actions use.
        $sr = $this->student->findByUserId($st_id)->first();
        if (is_null($sr)) {
            $decoded = Qs::decodeHash($st_id);
            if ($decoded && (string) $decoded !== (string) $st_id) {
                $candidate = $this->student->findByUserId($decoded)->first();
                if ($candidate) { $sr = $candidate; $st_id = $decoded; }
            }
        }
        if (is_null($sr)) { return Qs::goWithDanger(); }
        // Batch S: an invoice is a fee surface, not the academic
        // profile — authorize the fee-domain ability so accountants
        // keep their legitimate invoice access even though
        // StudentRecordPolicy@view no longer grants them profiles.
        $this->authorize('viewFees', $sr);

        $inv = $year ? $this->pay->getAllMyPR($st_id, $year) : $this->pay->getAllMyPR($st_id);

        $d['sr'] = $sr;
        $d['year'] = $year;
        $pr = $inv->get();
        $d['uncleared'] = $pr->where('paid', 0);
        $d['cleared'] = $pr->where('paid', 1);

        return view('pages.support_team.payments.invoice', $d);
    }

    /**
     * CSV export of one student's payment records (Loop 8) — the same
     * records the invoice page lists, cleared and uncleared together.
     * The invoice links carry a hashed id; resolve it to the plain user
     * id first so the export is correct either way.
     */
    public function export_student($st_id, $year = NULL)
    {
        if (!$st_id) { return Qs::goWithDanger(); }

        $sr = $this->student->findByUserId($st_id)->first();
        if (is_null($sr)) {
            $decoded = Qs::decodeHash($st_id);
            if ($decoded && (string) $decoded !== (string) $st_id) {
                $sr = $this->student->findByUserId($decoded)->first();
                if ($sr) { $st_id = $decoded; }
            }
        }

        $records = ($year ? $this->pay->getAllMyPR($st_id, $year) : $this->pay->getAllMyPR($st_id))->get();
        $name = $sr ? optional($sr->user)->name : '';

        $rows = $records->map(function ($pr) use ($name) {
            return $this->exportRow($pr, $name);
        });

        $filename = 'payments-' . Str::slug($name ?: 'student') . ($year ? '-' . $year : '') . '.csv';

        return CsvExport::download($filename,
            ['Receipt No', 'Student', 'Title', 'Amount Paid', 'Balance', 'Date'],
            $rows);
    }

    public function receipts($pr_id)
    {
        if(!$pr_id) {return Qs::goWithDanger();}

        try {
             $d['pr'] = $pr = $this->pay->getRecord(['id' => $pr_id])->with('receipt')->first();
             $this->authorize('view', $pr);
        } catch (ModelNotFoundException $ex) {
            return back()->with('flash_danger', __('msg.rnf'));
        }
        $d['receipts'] = $pr->receipt;
        $d['payment'] = $pr->payment;
        $d['sr'] = $this->student->findByUserId($pr->student_id)->first();
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        return view('pages.support_team.payments.receipt', $d);
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

    public function pdf_receipts($pr_id)
    {
        if(!$pr_id) {return Qs::goWithDanger();}

        try {
            $d['pr'] = $pr = $this->pay->getRecord(['id' => $pr_id])->with('receipt')->first();
            $this->authorize('view', $pr);
        } catch (ModelNotFoundException $ex) {
            return back()->with('flash_danger', __('msg.rnf'));
        }
        $d['receipts'] = $pr->receipt;
        $d['payment'] = $pr->payment;
        $d['sr'] = $sr =$this->student->findByUserId($pr->student_id)->first();
        $d['s'] = Setting::all()->flatMap(function($s){
            return [$s->type => $s->description];
        });

        /* Documents Premium pass: dompdf cannot fetch the logo URL
           (remote images disabled), so the PDF path inlines the
           Settings logo as a data URI — header + watermark render the
           school's CURRENT logo; the screen view keeps the URL. */
        if (!empty($d['s']['logo'])) {
            $d['s']['logo'] = $this->pdfImageDataUri($d['s']['logo']);
        }

        $pdf_name = 'Receipt_'.$pr->ref_no;

        return PDF::loadView('pages.support_team.payments.receipt', $d)->download($pdf_name);

        //return $this->downloadReceipt('pages.support_team.payments.receipt', $d, $pdf_name);
    }

    protected function downloadReceipt($page, $data, $name = NULL){
        $path = 'receipts/file.html';
        $disk = Storage::disk('local');
        $disk->put($path, view($page, $data) );
        $html = $disk->get($path);
        return PDF::loadHTML($html)->download($name);
    }

    public function pay_now(PayNowRequest $req, $pr_id)
    {
        $pr = $this->pay->findRecord($pr_id);
        $this->authorize('update', $pr);
        $payment = $this->pay->find($pr->payment_id);
        // A recorded payment must never exceed the outstanding balance:
        // overpaying drove balances negative (and negative amounts unwound
        // real payments while still issuing receipts). Refuse gracefully.
        $outstanding = $payment->amount - $pr->amt_paid;
        if ($req->amt_paid > $outstanding) {
            throw ValidationException::withMessages([
                'amt_paid' => 'Amount exceeds the outstanding balance ('.number_format($outstanding).').',
            ]);
        }
        $d['amt_paid'] = $amt_p = $pr->amt_paid + $req->amt_paid;
        $d['balance'] = $bal = $payment->amount - $amt_p;
        $d['paid'] = $bal < 1 ? 1 : 0;

        $this->pay->updateRecord($pr_id, $d);

        // Library fine mirror (owner, 2026-10-23): when the record
        // being paid is a library fine's fee entry, settle the fine.
        \App\Support\LibraryFines::syncFromPaymentRecord($this->pay->findRecord($pr_id));

        $d2['amt_paid'] = $req->amt_paid;
        $d2['balance'] = $bal;
        $d2['pr_id'] = $pr_id;
        $d2['year'] = $this->year;

        $this->pay->createReceipt($d2);
        Audit::log('payment.recorded', $pr, 'Payment recorded: '.$req->amt_paid.'; balance '.$bal);

        // Workstream BJ: same ok/msg contract as Qs::jsonUpdateOk(),
        // plus the receipt links for THIS record so the invoice page
        // can offer Print / PDF the moment the payment lands.
        return response()->json([
            'ok' => true,
            'msg' => __('msg.update_ok'),
            'amt_paid' => $req->amt_paid,
            'amt_paid_formatted' => \App\Helpers\Money::inr($req->amt_paid),
            'title' => $payment->title,
            'balance' => $bal,
            'balance_formatted' => \App\Helpers\Money::inr($bal),
            'receipt_url' => route('payments.receipts', Qs::hash($pr_id)),
            'pdf_url' => route('payments.pdf_receipts', Qs::hash($pr_id)),
        ]);
    }

    public function manage($class_id = NULL)
    {
        $d['my_classes'] = $this->my_class->all();
        $d['selected'] = false;

        if($class_id){
            $d['students'] = $st = $this->student->getRecord(['my_class_id' => $class_id])->get()->sortBy('user.name');
            if($st->count() < 1){
                return Qs::goWithDanger('payments.manage');
            }
            $d['selected'] = true;
            $d['my_class_id'] = $class_id;

            // Loop 107: print assembly for the class list's A4 document.
            // Per-student fee totals for the current session, derived
            // exactly like exportRow() (fee - amt_paid per record; the
            // stored balance column is unreliable). Feeds ONLY the
            // print-only document table in the manage blade; the screen
            // table keeps its original data.
            $records = $this->pay->getRecord(['year' => $this->year])
                ->whereIn('student_id', $st->pluck('user_id')->all())
                ->with('payment')->get();
            $byStudent = $records->groupBy('student_id');
            $print_rows = $st->map(function ($s) use ($byStudent) {
                $recs = $byStudent->get($s->user_id, collect());
                $total = $recs->sum(function ($r) { return optional($r->payment)->amount ?: 0; });
                $paid = $recs->sum(function ($r) { return $r->amt_paid ?: 0; });
                $balance = $total - $paid;
                $status = $recs->isEmpty() ? 'No fees recorded'
                    : ($balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid'));
                return [
                    'adm_no' => $s->adm_no,
                    'name' => optional($s->user)->name,
                    'class_section' => optional($s->my_class)->name . ($s->section ? ' / ' . optional($s->section)->name : ''),
                    'total' => $total,
                    'paid' => $paid,
                    'balance' => $balance,
                    'status' => $status,
                ];
            });
            $d['print_rows'] = $print_rows;
            $d['print_totals'] = [
                'total' => $print_rows->sum('total'),
                'paid' => $print_rows->sum('paid'),
                'balance' => $print_rows->sum('balance'),
            ];
        }

        return view('pages.support_team.payments.manage', $d);
    }

    /**
     * CSV export of payment records for a whole class (Loop 8) — the
     * manage page's current filter: this class, current session year.
     */
    public function export($class_id)
    {
        $students = $this->student->getRecord(['my_class_id' => $class_id])->get();
        if ($students->count() < 1) {
            return Qs::goWithDanger('payments.manage');
        }

        $names = $students->mapWithKeys(function ($s) {
            return [$s->user_id => optional($s->user)->name];
        });

        $records = $this->pay->getRecord(['year' => $this->year])
            ->whereIn('student_id', $students->pluck('user_id')->all())
            ->get()
            ->sortBy(function ($pr) use ($names) { return $names[$pr->student_id] ?? ''; })
            ->values();

        $rows = $records->map(function ($pr) use ($names) {
            return $this->exportRow($pr, $names[$pr->student_id] ?? '');
        });

        $mc = $this->my_class->getMC(['id' => $class_id])->first();
        $filename = 'payments-' . Str::slug(optional($mc)->name ?? 'class') . '-' . $this->year . '.csv';

        return CsvExport::download($filename,
            ['Receipt No', 'Student', 'Title', 'Amount Paid', 'Balance', 'Date'],
            $rows);
    }

    /**
     * One CSV row for a payment record, shared by both payment exports.
     * Balance is the record's true remaining balance — fee minus amount
     * paid, the same derivation pay_now() stores when a payment lands.
     * The stored balance column cannot be read with ?: here: a fully
     * paid record stores 0 (which ?: misreads as "no balance" and
     * printed the full fee), while a reset record also stores 0 yet
     * owes the full fee again, as the invoice page shows. Fee minus
     * amount paid matches the invoice page in every state: untouched
     * record = full fee, partial = stored balance, fully paid = 0.
     */
    protected function exportRow($pr, $studentName)
    {
        $fee = optional($pr->payment)->amount ?: 0;

        return [
            $pr->ref_no,
            $studentName,
            optional($pr->payment)->title,
            $pr->amt_paid ?: 0,
            $fee - ($pr->amt_paid ?: 0),
            optional($pr->updated_at)->format('d M Y'),
        ];
    }

    public function select_class(SelectClassRequest $req)
    {
        $wh['my_class_id'] = $class_id = $req->my_class_id;

        $pay1 = $this->pay->getPayment(['my_class_id' => $class_id, 'year' => $this->year])->get();
        $pay2 = $this->pay->getGeneralPayment(['year' => $this->year])->get();
        $payments = $pay2->count() ? $pay1->merge($pay2) : $pay1;
        $students = $this->student->getRecord($wh)->get();

        if($payments->count() && $students->count()){
            foreach($payments as $p){
                foreach($students as $st){
                    $pr['student_id'] = $st->user_id;
                    $pr['payment_id'] = $p->id;
                    $pr['year'] = $this->year;
                    $rec = $this->pay->createRecord($pr);
                    $rec->ref_no ?: $rec->update(['ref_no' => mt_rand(100000, 99999999)]);

                }
            }
        }

        return Qs::goToRoute(['payments.manage', $class_id]);
    }

    public function store(PaymentCreate $req)
    {
        $data = $req->all();
        $data['year'] = $this->year;
        $data['ref_no'] = Pay::genRefCode();
        $this->pay->create($data);

        // Loop 112: post-save lands on the fees index (owner ruling) —
        // was Qs::jsonStoreOk() (AJAX stay-on-form).
        return Qs::storeOk('payments.index');
    }

    public function edit($id)
    {
        $d['payment'] = $pay = $this->pay->find($id);

        return is_null($pay) ? Qs::goWithDanger('payments.index') : view('pages.support_team.payments.edit', $d);
    }

    public function update(PaymentUpdate $req, $id)
    {
        $data = $req->all();
        $this->pay->update($id, $data);

        // Loop 112: post-save lands on the fees index (owner ruling) —
        // was Qs::jsonUpdateOk() (AJAX stay-on-form).
        return Qs::updateOk('payments.index');
    }

    public function destroy($id)
    {
        $payment = $this->pay->find($id);
        if ($payment) { $payment->delete(); Audit::log('payment.deleted', $payment, 'Payment definition deleted'); }

        return Qs::deleteOk('payments.index');
    }

    /**
     * Workstream CU — online (Razorpay) transactions for staff: every
     * gateway attempt, captured or not, with a link to the receipt a
     * captured payment generated. Read-only; recording happens only
     * through the verified callback/webhook path.
     */
    public function gateway_transactions()
    {
        $d['txns'] = GatewayTransaction::with('student')->latest()->get();

        return view('pages.support_team.payments.gateway_transactions', $d);
    }

    public function reset_record($id)
    {
        $record = $this->pay->findRecord($id);
        if ($record) { $this->authorize('update', $record); }
        $pr['amt_paid'] = $pr['paid'] = $pr['balance'] = 0;
        $this->pay->updateRecord($id, $pr);
        Audit::log('payment.reset', $record ?? null, 'Payment record reset to zero');
        $this->pay->deleteReceipts(['pr_id' => $id]);

        return back()->with('flash_success', __('msg.update_ok'));
    }
}
