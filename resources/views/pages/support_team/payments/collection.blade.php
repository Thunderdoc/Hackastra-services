@extends('layouts.master')
@section('page_title', 'Fee Collection Report')
@section('page_breadcrumb')
    <a href="{{ route('dashboard') }}">Dashboard</a> &nbsp;/&nbsp; Payments &nbsp;/&nbsp; Fee Collection Report
@endsection
@section('content')

    {{-- Loop 97 (redesign pass): premium Fee Collection Report screen.
         ONE data assembly (FeeReportController::collectionAssembly)
         feeds the KPI cards, the trend chart, the summary panel, the
         daily table, the window.print document, the CSV export and
         the PDF export — so every surface shows the same figures for
         the same filters. Receipts carry no status, so no status
         filter is offered. Loop 118 adds the Payment Method filter:
         a receipt's method resolves exactly the way the Manage
         Payments table displays it — the gateway method stored on
         the receipt wins, otherwise the fee's own method applies —
         and the filter is wired through the same assembly, so the
         screen, CSV and PDF all honour it. --}}

    {{-- Print document head/foot (Workstream CL / Loop 71 v2): hidden
         on screen, shown by window.print(). Kept as-is. --}}
    @php
        $dbInitials = '';
        foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $dbWord) {
            if ($dbWord !== '') {
                $dbInitials .= strtoupper(substr($dbWord, 0, 1));
                if (strlen($dbInitials) >= 2) { break; }
            }
        }
        if ($dbInitials === '') { $dbInitials = 'S'; }
        $crMax = collect($buckets)->max('total') ?: 0;
    @endphp
    <div class="print-doc-head">
        @if(Qs::getSetting('logo'))
            <img src="{{ Qs::getSetting('logo') }}" alt="">
        @else
            <span class="print-doc-mono" aria-hidden="true">{{ $dbInitials }}</span>
        @endif
        <div>
            <h1>{{ Qs::getSystemName() }}</h1>
            <p>Fee Collection Report &mdash; {{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} &middot; {{ $receipts_count }} receipt(s) &middot; {{ \App\Helpers\Money::inr($grand_total) }} collected
                @if($f['class']) &middot; Class: {{ $f['class']->name }}@endif
                @if($f['section']) &middot; Section: {{ $f['section']->name }}@endif
            </p>
        </div>
    </div>
    @if(Qs::getSetting('logo'))
        <img class="print-doc-watermark" src="{{ Qs::getSetting('logo') }}" alt="">
    @else
        <div class="print-doc-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <div class="cr97-wrap">
    @component('partials.patterns.list_page')
        @slot('subtitle')
            View and analyze fee collections with detailed filters and insights.
        @endslot
        @slot('action')
            <button type="button" class="btn btn-light btn-sm" onclick="window.print()"><i class="icon-printer mr-1"></i> Print Report</button>
            <a href="{{ route('payments.collection.export', $f['query']) }}" class="btn btn-light btn-sm"><i class="icon-file-download mr-1"></i> Export CSV</a>
            <a href="{{ route('payments.collection.pdf', $f['query']) }}" class="btn btn-primary btn-sm"><i class="icon-file-pdf mr-1"></i> Export PDF</a>
        @endslot
        @slot('stats')
            <div class="row dashboard-stats">
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-green">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-wallet"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Total Collection</span>
                            <strong>{{ \App\Helpers\Money::inr($grand_total) }}</strong>
                            <small>{{ number_format($receipts_count) }} {{ \Illuminate\Support\Str::plural('receipt', $receipts_count) }} recorded</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-purple">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-file-text2"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Average per Receipt</span>
                            <strong>{{ \App\Helpers\Money::inr($avg_per_receipt) }}</strong>
                            <small>{{ \App\Helpers\Money::indianFormat($grand_total) }} &divide; {{ number_format($receipts_count) }}</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-blue">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-users"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Students Paid</span>
                            <strong>{{ number_format($unique_students) }}</strong>
                            <small>Unique students</small>
                        </span>
                    </div>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <div class="dashboard-stat-card dashboard-stat-orange">
                        <span class="dashboard-stat-top"><span class="dashboard-stat-icon"><i class="icon-calendar3"></i></span></span>
                        <span class="dashboard-stat-copy">
                            <span class="dashboard-stat-label">Daily Average</span>
                            <strong>{{ \App\Helpers\Money::inr($daily_average) }}</strong>
                            <small>Over {{ number_format($days_with_collections) }} {{ \Illuminate\Support\Str::plural('day', $days_with_collections) }}</small>
                        </span>
                    </div>
                </div>
            </div>
        @endslot
        @slot('filters')
            <form method="GET" action="{{ route('payments.collection') }}" class="pat-filter-form">
                <div class="pat-filter-field">
                    <label for="cr97-from">From Date</label>
                    <input type="date" id="cr97-from" name="from" class="form-control" value="{{ $from->format('Y-m-d') }}">
                </div>
                <div class="pat-filter-field">
                    <label for="cr97-to">To Date</label>
                    <input type="date" id="cr97-to" name="to" class="form-control" value="{{ $to->format('Y-m-d') }}">
                </div>
                <div class="pat-filter-field">
                    <label for="cr97-class">Class</label>
                    <select id="cr97-class" name="class_id" class="form-control">
                        <option value="">All Classes</option>
                        @foreach($classes as $class)
                            <option value="{{ $class->id }}" {{ $f['class'] && $f['class']->id === $class->id ? 'selected' : '' }}>{{ $class->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="cr97-section">Section</label>
                    <select id="cr97-section" name="section_id" class="form-control">
                        <option value="">All Sections</option>
                        @foreach($sections as $section)
                            <option value="{{ $section->id }}" {{ $f['section'] && $f['section']->id === $section->id ? 'selected' : '' }}>{{ $section->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-field">
                    <label for="cr97-method">Payment Method</label>
                    <select id="cr97-method" name="method" class="form-control">
                        <option value="">All Methods</option>
                        @foreach($methods as $methodValue => $methodLabel)
                            <option value="{{ $methodValue }}" {{ ($f['method'] ?? null) === $methodValue ? 'selected' : '' }}>{{ $methodLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="pat-filter-action">
                    <button type="submit" class="btn btn-primary"><i class="icon-filter3 mr-1"></i> Filter</button>
                    <a href="{{ route('payments.collection') }}" class="btn btn-light"><i class="icon-reset mr-1"></i> Reset</a>
                </div>
            </form>
        @endslot

        {{-- Print-only summary strip: the screen's KPI cards, chart
             and filters stay off paper; the figures print here. --}}
        <div class="cr97-print-summary">
            Total Collection: <strong>{{ \App\Helpers\Money::inr($grand_total) }}</strong> &middot;
            Receipts: <strong>{{ number_format($receipts_count) }}</strong> &middot;
            Students Paid: <strong>{{ number_format($unique_students) }}</strong> &middot;
            Average per Receipt: <strong>{{ \App\Helpers\Money::inr($avg_per_receipt) }}</strong> &middot;
            Daily Average (days with collections): <strong>{{ \App\Helpers\Money::inr($daily_average) }}</strong>
        </div>

        <div class="cr97-panels print-hide">
            <div class="cr97-chart-panel">
                <div class="cr97-panel-head">
                    <div>
                        <h5><i class="icon-stats-bars2 mr-2"></i>Collection Trend</h5>
                        <p>Daily fee collection with number of receipts.</p>
                    </div>
                    <div class="btn-group btn-group-sm cr97-group-toggle" role="group" aria-label="Chart grouping">
                        @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $g => $label)
                            <a href="{{ route('payments.collection', array_merge($f['query'], ['group' => $g])) }}" class="btn {{ $f['group'] === $g ? 'btn-primary' : 'btn-light' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                @if(empty($buckets))
                    <div class="cr97-chart-empty">No collections in this range — the trend will appear as payments are recorded.</div>
                @else
                    <div class="cr97-chart" role="img" aria-label="Collection trend chart">
                        @foreach($buckets as $bucket)
                            <div class="cr97-bar-col" title="{{ $bucket['label'] }}: {{ \App\Helpers\Money::inr($bucket['total']) }} ({{ $bucket['receipts_count'] }} receipts)">
                                <span class="cr97-bar-value">{{ \App\Helpers\Money::indianFormat($bucket['total']) }}</span>
                                <div class="cr97-bar-track"><div class="cr97-bar" style="height: {{ $crMax ? max(3, round($bucket['total'] / $crMax * 100)) : 0 }}%"></div></div>
                                <span class="cr97-bar-label">{{ $bucket['label'] }}</span>
                                <span class="cr97-bar-count">{{ $bucket['receipts_count'] }} rcpt</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="cr97-summary-panel">
                <h5><i class="icon-clipboard3 mr-2"></i>Collection Summary</h5>
                <ul class="cr97-summary-list">
                    <li><span>Total Amount Collected</span><strong>{{ \App\Helpers\Money::inr($grand_total) }}</strong></li>
                    <li><span>Total Receipts</span><strong>{{ number_format($receipts_count) }}</strong></li>
                    <li><span>Unique Students</span><strong>{{ number_format($unique_students) }}</strong></li>
                    <li><span>Average per Receipt</span><strong>{{ \App\Helpers\Money::inr($avg_per_receipt) }}</strong></li>
                    <li><span>Highest Collection (Single Day)</span><strong>{{ $highest_day ? \App\Helpers\Money::inr($highest_day['total']) : '—' }}</strong>@if($highest_day)<small>{{ $highest_day['day']->format('d M Y') }}</small>@endif</li>
                    <li><span>Lowest Collection (Single Day)</span><strong>{{ $lowest_day ? \App\Helpers\Money::inr($lowest_day['total']) : '—' }}</strong>@if($lowest_day)<small>{{ $lowest_day['day']->format('d M Y') }}</small>@endif</li>
                </ul>
                <p class="cr97-summary-note">Highest and lowest are taken over days with collections only; days with no receipts are not counted as zero days.</p>
            </div>
        </div>

        <div class="cr97-table-head">
            <div>
                <h5><i class="icon-list2 mr-2"></i>Daily Collection Details</h5>
                <p>Breakdown of collections by date, newest first.</p>
            </div>
        </div>

        @if($paged->total() === 0)
            @include('partials.patterns.empty_state', [
                'icon' => 'icon-coins',
                'message' => 'No fee collections were recorded between ' . $from->format('d M Y') . ' and ' . $to->format('d M Y') . ' for the selected filters.',
            ])
        @else
            <table class="table">
                <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Date</th>
                    <th>Day</th>
                    <th class="text-right">No. of Receipts</th>
                    <th class="text-right">Amount Collected (₹)</th>
                    <th class="text-right">Average Amount</th>
                    <th class="text-right print-hide">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($paged as $row)
                    <tr>
                        <td>{{ $paged->firstItem() + $loop->index }}</td>
                        <td><strong>{{ $row['day']->format('d M Y') }}</strong></td>
                        <td>{{ $row['day']->format('l') }}</td>
                        <td class="text-right">{{ $row['receipts_count'] }}</td>
                        <td class="text-right font-weight-bold">{{ \App\Helpers\Money::inr($row['total']) }}</td>
                        <td class="text-right">{{ \App\Helpers\Money::inr($row['receipts_count'] ? (int) round($row['total'] / $row['receipts_count']) : 0) }}</td>
                        <td class="text-right print-hide">
                            <a href="{{ route('payments.collection.day', array_merge($f['query'], ['date' => $row['day']->format('Y-m-d')])) }}" class="btn btn-light btn-sm"><i class="icon-eye mr-1"></i> View Details</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <th></th>
                    <th colspan="2">Grand Total (all pages)</th>
                    <th class="text-right">{{ number_format($receipts_count) }}</th>
                    <th class="text-right">{{ \App\Helpers\Money::inr($grand_total) }}</th>
                    <th class="text-right">{{ \App\Helpers\Money::inr($avg_per_receipt) }}</th>
                    <th class="print-hide"></th>
                </tr>
                </tfoot>
            </table>

            @if($paged->lastPage() > 1)
                @php
                    $crCur = $paged->currentPage(); $crLast = $paged->lastPage();
                    $crFrom = max(1, $crCur - 2); $crTo = min($crLast, $crCur + 2);
                @endphp
                <div class="cr97-pagination print-hide">
                    <span class="cr97-pagination-info">Showing {{ $paged->firstItem() }} to {{ $paged->lastItem() }} of {{ $paged->total() }} days</span>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item {{ $crCur <= 1 ? 'disabled' : '' }}"><a class="page-link" href="{{ $crCur > 1 ? $paged->url($crCur - 1) : '#' }}">&laquo; Previous</a></li>
                        @if($crFrom > 1)
                            <li class="page-item"><a class="page-link" href="{{ $paged->url(1) }}">1</a></li>
                            @if($crFrom > 2)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                        @endif
                        @for($cri = $crFrom; $cri <= $crTo; $cri++)
                            <li class="page-item {{ $cri === $crCur ? 'active' : '' }}"><a class="page-link" href="{{ $paged->url($cri) }}">{{ $cri }}</a></li>
                        @endfor
                        @if($crTo < $crLast)
                            @if($crTo < $crLast - 1)<li class="page-item disabled"><span class="page-link">&hellip;</span></li>@endif
                            <li class="page-item"><a class="page-link" href="{{ $paged->url($crLast) }}">{{ $crLast }}</a></li>
                        @endif
                        <li class="page-item {{ $crCur >= $crLast ? 'disabled' : '' }}"><a class="page-link" href="{{ $crCur < $crLast ? $paged->url($crCur + 1) : '#' }}">Next &raquo;</a></li>
                    </ul>
                </div>
            @else
                <div class="cr97-pagination print-hide">
                    <span class="cr97-pagination-info">Showing {{ $paged->total() }} of {{ $paged->total() }} days</span>
                </div>
            @endif
            <p class="text-muted mb-0 mt-2 print-hide"><small>From the receipts ledger: one receipt is recorded per payment taken, dated the day the money was received. Averages are rounded to the nearest rupee.</small></p>
        @endif
    @endcomponent
    </div>

    <div class="print-doc-foot">
        <span>Printed on {{ now()->format('d M Y, g:i A') }} &middot; {{ Qs::getSystemName() }}</span>
        <span>Fee Collection Report &middot; Generated by Hackstra</span>
    </div>

@endsection

@section('scripts')
    <script>
        /* Section cascade: changing the class immediately re-offers
           only that class's sections (same get_class_sections endpoint
           the rest of the app uses). The server scopes again on
           submit, so a stale cross-class section can never survive. */
        (function () {
            var classSel = document.getElementById('cr97-class');
            var sectionSel = document.getElementById('cr97-section');
            if (!classSel || !sectionSel) { return; }
            classSel.addEventListener('change', function () {
                var url = '{{ route('get_class_sections', [':id']) }}'.replace(':id', classSel.value || '0');
                sectionSel.innerHTML = '<option value="">All Sections</option>';
                if (!classSel.value) { return; }
                fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        resp.forEach(function (s) {
                            var opt = document.createElement('option');
                            opt.value = s.id;
                            opt.textContent = s.name;
                            sectionSel.appendChild(opt);
                        });
                    })
                    .catch(function () { /* server-side scoping on submit still applies */ });
            });
        })();
    </script>
@endsection
