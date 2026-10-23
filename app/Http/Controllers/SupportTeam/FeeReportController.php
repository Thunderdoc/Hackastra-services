<?php

namespace App\Http\Controllers\SupportTeam;

use App\Helpers\Qs;
use App\Http\Controllers\Controller;
use App\Models\MyClass;
use App\Models\PaymentRecord;
use App\Models\Receipt;
use App\Models\Section;
use App\Models\StudentRecord;
use App\Support\CsvExport;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Loop 9 (Workstream O) — Accountant fee reports.
 *
 * Two read-only reports over the same records the invoice page shows,
 * using the invoice page's own balance rule (a record's balance is its
 * stored balance, falling back to the full fee amount when untouched),
 * so a report can never disagree with the student's invoice:
 *
 *  - Fee defaulters: students who still owe money on current-session
 *    fee records, worst first, with their oldest unpaid item.
 *  - Collection report: what actually came in, day by day, from the
 *    receipts ledger (a receipt row is created per payment taken, its
 *    created_at is when the money was recorded).
 *
 * Gated to the accounts team exactly like PaymentController.
 */
class FeeReportController extends Controller
{
    protected $year;

    public function __construct()
    {
        $this->middleware('teamAccount');
        $this->year = Qs::getCurrentSession();
    }

    public function defaulters(Request $request)
    {
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['selected_class'] = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : null; // null = every class
        $d['year'] = $this->year;

        /* Print set — the branded document's data, with exactly the
           pre-redesign semantics (class scope only, worst first). The
           print table in the blade renders from this and nothing else,
           so the printed report can never drift from what it was. */
        $print = $this->defaulterRows($d['selected_class']);
        $d['print_rows'] = $print;
        $d['print_total_due'] = $print->sum('total_due');

        /* Screen set — the same assembly with the screen's combinable
           filters (Loop 95). Summary cards describe this filtered set;
           the table pages through it. */
        $filters = $this->defaulterFilters($request);
        if ($d['selected_class'] && $filters['section_id']) {
            $sec = Section::find($filters['section_id']);
            if (!$sec || $sec->my_class_id !== $d['selected_class']->id) {
                $filters['section_id'] = null; // section of a previously chosen class
            }
        }
        $d['selected_section'] = $filters['section_id']
            ? Section::find($filters['section_id'])
            : null;
        $d['sections'] = $d['selected_class']
            ? Section::where('my_class_id', $d['selected_class']->id)->orderBy('name')->get()
            : collect();
        $d['status'] = $filters['status'] ?: '';
        $d['q'] = $filters['q'] ?: '';
        $d['filters_active'] = $d['selected_class'] || $d['selected_section']
            || $d['status'] !== '' || $d['q'] !== '';

        $filtered = $this->defaulterRows($d['selected_class'], $filters);
        $partial = $filtered->where('status', 'partial');
        $unpaid = $filtered->where('status', 'unpaid');
        $d['summary'] = [
            'count' => $filtered->count(),
            'due' => (int) $filtered->sum('total_due'),
            'partial_count' => $partial->count(),
            'partial_due' => (int) $partial->sum('total_due'),
            'unpaid_count' => $unpaid->count(),
            'unpaid_due' => (int) $unpaid->sum('total_due'),
        ];

        $per_page = 25;
        $page = max(1, (int) $request->query('page', 1));
        $d['rows'] = new LengthAwarePaginator(
            $filtered->forPage($page, $per_page)->values(),
            $filtered->count(),
            $per_page,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pages.support_team.payments.defaulters', $d);
    }

    public function defaultersExport(Request $request)
    {
        $class = $request->query('class_id')
            ? MyClass::find($request->query('class_id'))
            : null;

        $rows = $this->defaulterRows($class, $this->defaulterFilters($request))->map(function ($row) {
            return [
                optional($row['student']->user)->name,
                $row['student']->adm_no,
                optional($row['student']->my_class)->name,
                optional($row['student']->section)->name,
                $row['total_due'],
                $row['oldest_title'],
                $row['oldest_since'] ? $row['oldest_since']->format('d M Y') : '',
            ];
        });

        $filename = 'fee-defaulters'
            . ($class ? '-' . Str::slug($class->name) : '')
            . '-' . $this->year . '.csv';

        return CsvExport::download($filename,
            ['Student', 'Admission No', 'Class', 'Section', 'Total Due', 'Oldest Unpaid Fee', 'Oldest Since'],
            $rows);
    }

    public function collection(Request $request)
    {
        $f = $this->collectionFilters($request);
        if ($f['invalid_range']) {
            return $this->invalidRangeRedirect($f);
        }

        $d = $this->collectionAssembly($f);
        $d['classes'] = MyClass::orderBy('name')->get();
        $d['methods'] = $this->collectionMethods();
        $d['sections'] = $f['class']
            ? \App\Models\Section::where('my_class_id', $f['class']->id)->orderBy('name')->get()
            : collect();

        // Daily details table: newest first, server-paginated, with the
        // filters carried in every page URL.
        $desc = collect($d['rows'])->sortByDesc('day')->values();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 10;
        $d['paged'] = new \Illuminate\Pagination\LengthAwarePaginator(
            $desc->forPage($page, $perPage)->values(),
            $desc->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('pages.support_team.payments.collection', $d);
    }

    /** CSV export of the same filtered daily dataset the screen shows. */
    public function collectionExport(Request $request)
    {
        $f = $this->collectionFilters($request);
        if ($f['invalid_range']) {
            return $this->invalidRangeRedirect($f);
        }

        $d = $this->collectionAssembly($f);

        $rows = collect($d['rows'])->sortByDesc('day')->map(function ($row) {
            return [
                $row['day']->format('d M Y'),
                $row['day']->format('l'),
                $row['receipts_count'],
                $row['total'],
                $row['receipts_count'] ? (int) round($row['total'] / $row['receipts_count']) : 0,
            ];
        })->values()->all();
        $rows[] = ['Total', '', $d['receipts_count'], $d['grand_total'], $d['avg_per_receipt']];

        $filename = 'fee-collection-' . $f['from']->format('Y-m-d') . '-to-' . $f['to']->format('Y-m-d')
            . ($f['class'] ? '-' . Str::slug($f['class']->name) : '') . '.csv';

        return CsvExport::download($filename,
            ['Date', 'Day', 'Receipts', 'Amount Collected', 'Average Amount'],
            $rows);
    }

    /** PDF export of the same filtered dataset (dompdf, multi-page safe). */
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

    public function collectionPdf(Request $request)
    {
        $f = $this->collectionFilters($request);
        if ($f['invalid_range']) {
            return $this->invalidRangeRedirect($f);
        }

        $d = $this->collectionAssembly($f);
        /* Documents Premium pass: the shared document partial renders
           a letterhead logo; the PDF path hands it the Settings logo
           inlined as a data URI (dompdf cannot fetch the URL). */
        $d['doc_logo'] = $this->pdfImageDataUri(Qs::getSetting('logo'));
        $d['rows_desc'] = collect($d['rows'])->sortByDesc('day')->values();

        $filename = 'fee-collection-report-' . $f['from']->format('Y-m-d') . '-to-' . $f['to']->format('Y-m-d') . '.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pages.support_team.payments.collection_pdf', $d);
        $pdf->setPaper('a4', 'portrait');

        // Canvas footer, stamped after render (same pattern as the
        // monthly attendance report PDF).
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans');
        $grey = [0.44, 0.47, 0.53];
        $width = $canvas->get_width();
        $height = $canvas->get_height();

        $canvas->page_text(34, $height - 26,
            'Fee Collection Report - ' . $f['from']->format('d M Y') . ' to ' . $f['to']->format('d M Y'),
            $font, 8, $grey);
        $labelWidth = $metrics->getTextWidth('Page 10 of 10', $font, 8);
        $canvas->page_text($width - 34 - $labelWidth, $height - 26,
            'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, $grey);

        return $pdf->download($filename);
    }

    /**
     * Per-day breakdown: every receipt recorded on one date, under the
     * same class/section filters as the report it was opened from.
     */
    public function collectionDay(Request $request)
    {
        $f = $this->collectionFilters($request);

        $date = null;
        try {
            $date = Carbon::createFromFormat('Y-m-d', (string) $request->query('date'));
        } catch (\Exception $e) {
            $date = null;
        }
        if (!$date) {
            return redirect()->route('payments.collection', $f['query'])
                ->with('flash_danger', 'Choose a day from the collection report to see its receipts.');
        }

        $receipts = $this->collectionReceipts($f)
            ->whereDate('receipts.created_at', $date->format('Y-m-d'))
            ->select('receipts.*')
            ->orderBy('receipts.created_at')
            ->get();

        $studentIds = $receipts->map(function ($r) { return optional($r->pr)->student_id; })->filter()->unique();
        $records = StudentRecord::with(['user', 'my_class', 'section'])
            ->whereIn('user_id', $studentIds->all())->get()->keyBy('user_id');
        $payments = \App\Models\Payment::whereIn('id',
            $receipts->map(function ($r) { return optional($r->pr)->payment_id; })->filter()->unique()->all()
        )->get()->keyBy('id');

        $d['date'] = $date;
        $d['f'] = $f;
        $d['receipts'] = $receipts;
        $d['records'] = $records;
        $d['payments'] = $payments;
        $d['day_total'] = (int) $receipts->sum('amt_paid');
        $d['day_count'] = $receipts->count();

        return view('pages.support_team.payments.collection_day', $d);
    }

    /**
     * Parse + validate the collection report filters.
     * A To date earlier than the From date is an invalid range: the
     * report refuses it (redirect with a clear message) instead of
     * silently swapping the dates the way the legacy code did.
     */
    protected function collectionFilters(Request $request): array
    {
        $now = Carbon::now();
        $from = $this->dateFromRequest($request, 'from', $now->copy()->startOfMonth());
        $to = $this->dateFromRequest($request, 'to', $now->copy());

        $class = $request->query('class_id') ? MyClass::find($request->query('class_id')) : null;
        $section = null;
        if ($class && $request->query('section_id')) {
            $candidate = \App\Models\Section::find($request->query('section_id'));
            if ($candidate && (int) $candidate->my_class_id === (int) $class->id) {
                $section = $candidate;
            }
        }

        $group = in_array($request->query('group'), ['day', 'week', 'month'], true)
            ? $request->query('group') : 'day';

        /* Payment Method filter (Loop 118) — the method a receipt
           resolves to follows the Manage Payments table's own rule:
           a gateway receipt (receipts.method = 'online_razorpay')
           wins; otherwise the fee's own method (payments.method)
           applies. Only values that actually occur in the ledger are
           accepted; anything else degrades to "All Methods". */
        $method = null;
        $rawMethod = $request->query('method');
        if (is_string($rawMethod) && $rawMethod !== '') {
            foreach ($this->collectionMethods() as $value => $label) {
                if (strcasecmp($value, $rawMethod) === 0) {
                    $method = $value;
                    break;
                }
            }
        }

        $query = array_filter([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'class_id' => $class ? $class->id : null,
            'section_id' => $section ? $section->id : null,
            'method' => $method,
            'group' => $group !== 'day' ? $group : null,
        ], function ($v) { return $v !== null; });

        return [
            'from' => $from,
            'to' => $to,
            'class' => $class,
            'section' => $section,
            'method' => $method,
            'group' => $group,
            'invalid_range' => $to->lt($from),
            'query' => $query,
        ];
    }

    /**
     * The Payment Method filter's options: every method value that
     * actually occurs in the ledger, labelled the way the Manage
     * Payments table displays it. Receipts store no status, so no
     * status filter exists — a receipt is a recorded payment.
     */
    protected function collectionMethods(): array
    {
        $options = [];
        $seen = [];
        $stored = \App\Models\Payment::query()->distinct()->orderBy('method')->pluck('method');
        foreach ($stored as $m) {
            $m = trim((string) $m);
            if ($m === '') {
                continue;
            }
            $key = strtolower($m);
            if (isset($seen[$key])) {
                continue; // 'cash' and 'Cash' are one method
            }
            $seen[$key] = true;
            $options[$m] = ucwords(str_replace('_', ' ', $m));
        }
        if (Receipt::where('method', 'online_razorpay')->exists()) {
            $options['online_razorpay'] = 'Online (Razorpay)';
        }

        return $options;
    }

    protected function invalidRangeRedirect(array $f)
    {
        $query = $f['query'];
        unset($query['from'], $query['to']);

        return redirect()->route('payments.collection', $query)
            ->with('flash_danger', 'Invalid date range: the To date must be on or after the From date. Showing the default range instead.');
    }

    /** Receipts query scoped by the report's filters (date + class/section). */
    protected function collectionReceipts(array $f)
    {
        $q = Receipt::query()
            ->join('payment_records', 'payment_records.id', '=', 'receipts.pr_id')
            ->join('student_records', 'student_records.user_id', '=', 'payment_records.student_id')
            ->whereBetween('receipts.created_at', [$f['from']->copy()->startOfDay(), $f['to']->copy()->endOfDay()]);

        if ($f['class']) {
            $q->where('student_records.my_class_id', $f['class']->id);
        }
        if ($f['section']) {
            $q->where('student_records.section_id', $f['section']->id);
        }
        /* Payment Method (Loop 118) — same resolution rule as the
           Manage Payments table: the receipt's gateway method wins;
           otherwise the fee's method applies. */
        if (($f['method'] ?? null) === 'online_razorpay') {
            $q->where('receipts.method', 'online_razorpay');
        } elseif (!empty($f['method'])) {
            $q->join('payments', 'payments.id', '=', 'payment_records.payment_id')
                ->whereRaw('LOWER(payments.method) = ?', [strtolower($f['method'])])
                ->where(function ($w) {
                    $w->whereNull('receipts.method')
                        ->orWhere('receipts.method', '!=', 'online_razorpay');
                });
        }

        return $q;
    }

    /**
     * THE collection data assembly — the screen, the print document,
     * the CSV and the PDF are all fed from here so they can never
     * disagree. Daily rows come from the receipts ledger grouped by
     * the day the money was recorded.
     */
    protected function collectionAssembly(array $f): array
    {
        $rows = $this->collectionReceipts($f)
            ->selectRaw('DATE(receipts.created_at) as day, COUNT(*) as receipts_count, SUM(receipts.amt_paid) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(function ($r) {
                return [
                    'day' => Carbon::parse($r->day),
                    'receipts_count' => (int) $r->receipts_count,
                    'total' => (int) $r->total,
                ];
            })
            ->values()
            ->all();

        $grand = (int) collect($rows)->sum('total');
        $count = (int) collect($rows)->sum('receipts_count');
        $days = count($rows);

        $unique = (int) $this->collectionReceipts($f)
            ->selectRaw('COUNT(DISTINCT payment_records.student_id) as c')
            ->value('c');

        // Highest / lowest collection days — days WITH collections
        // only; ties break to the earliest date, deterministically.
        $highest = null;
        $lowest = null;
        foreach ($rows as $row) {
            if ($highest === null || $row['total'] > $highest['total']) {
                $highest = $row;
            }
            if ($lowest === null || $row['total'] < $lowest['total']) {
                $lowest = $row;
            }
        }

        // Chart buckets: the same daily rows, grouped as asked.
        $buckets = [];
        foreach ($rows as $row) {
            if ($f['group'] === 'week') {
                $start = $row['day']->copy()->startOfWeek();
                $key = $start->format('Y-m-d');
                $label = $start->format('d M') . ' - ' . $start->copy()->endOfWeek()->format('d M Y');
            } elseif ($f['group'] === 'month') {
                $key = $row['day']->format('Y-m');
                $label = $row['day']->format('F Y');
            } else {
                $key = $row['day']->format('Y-m-d');
                $label = $row['day']->format('d M Y');
            }
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'total' => 0, 'receipts_count' => 0];
            }
            $buckets[$key]['total'] += $row['total'];
            $buckets[$key]['receipts_count'] += $row['receipts_count'];
        }

        return [
            'from' => $f['from'],
            'to' => $f['to'],
            'f' => $f,
            'rows' => $rows,
            'buckets' => array_values($buckets),
            'grand_total' => $grand,
            'receipts_count' => $count,
            'unique_students' => $unique,
            'days_with_collections' => $days,
            'avg_per_receipt' => $count ? (int) round($grand / $count) : 0,
            'daily_average' => $days ? (int) round($grand / $days) : 0,
            'highest_day' => $highest,
            'lowest_day' => $lowest,
        ];
    }

    /**
     * The screen's combinable filters, parsed once for the page and
     * the CSV export so both always describe the same set. Unknown
     * values degrade to "no filter" rather than an empty report.
     */
    protected function defaulterFilters(Request $request): array
    {
        $status = $request->query('status');

        return [
            'section_id' => $request->query('section_id') ? (int) $request->query('section_id') : null,
            'status' => in_array($status, ['unpaid', 'partial'], true) ? $status : null,
            'q' => trim((string) $request->query('q')) ?: null,
        ];
    }

    /**
     * Students with money still owing on current-session fee records.
     * A record counts while paid = 0; its owing amount follows the
     * invoice rule ($record->balance ?: fee amount), so a partially
     * paid record contributes only its remaining balance.
     *
     * This is the single assembly behind the screen, the printed
     * report and the CSV export. Called with no filters it returns
     * exactly the set it always has; the optional filters (section,
     * paid-status, name/admission search) only narrow it. Each row
     * also carries the student's session totals (total_fees /
     * total_paid / status) computed from the same records — additive
     * keys the print document and export simply do not read.
     *
     * The computation itself lives in App\Support\FeeDefaulter — the
     * school's single defaulter definition, shared with the document
     * block (owner ruling 2026-10-22) so the two can never disagree.
     */
    protected function defaulterRows($selected_class, array $filters = [])
    {
        return \App\Support\FeeDefaulter::rows($this->year, $selected_class, $filters);
    }

    /** Parse a Y-m-d query date, falling back to the given default. */
    protected function dateFromRequest(Request $request, string $key, Carbon $default): Carbon
    {
        $value = $request->query($key);
        if (!$value) {
            return $default;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Exception $e) {
            return $default;
        }
    }
}
