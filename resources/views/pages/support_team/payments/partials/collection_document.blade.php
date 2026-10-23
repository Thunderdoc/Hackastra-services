{{--
    Fee Collection Report — the PDF document partial (Loop 97).
    Fed by FeeReportController::collectionAssembly — the SAME data
    assembly behind the screen report, the window.print document and
    the CSV export, so the exports can never disagree with the page.
    Table-only layout (dompdf-safe); the thead repeats on every page.
--}}
<style>
    .cr97-doc{font-family:'DejaVu Sans',sans-serif;color:#1c2b3a;font-size:10.5px}
    .cr97-doc-head{border-bottom:2.5px solid #10263b;padding-bottom:8px;margin-bottom:10px}
    .cr97-doc-head table{width:100%;border-collapse:collapse}
    .cr97-doc-school{font-size:17px;font-weight:bold;color:#10263b}
    .cr97-doc-meta{color:#5b6b7f;font-size:9.5px}
    .cr97-doc-title{text-align:right}
    .cr97-doc-title .t{font-size:14px;font-weight:bold;color:#10263b;letter-spacing:.4px}
    .cr97-doc-title .s{color:#5b6b7f;font-size:9.5px}
    .cr97-doc-sum{width:100%;border-collapse:collapse;margin:0 0 12px}
    .cr97-doc-sum td{border:1px solid #d7dee6;background:#f2f6fa;padding:6px 8px;width:33.33%}
    .cr97-doc-sum .k{color:#5b6b7f;font-size:8.5px;text-transform:uppercase;letter-spacing:.5px}
    .cr97-doc-sum .v{font-size:13px;font-weight:bold;color:#10263b}
    .cr97-doc-table{width:100%;border-collapse:collapse}
    .cr97-doc-table thead{display:table-header-group}
    .cr97-doc-table th{background:#10263b;color:#fff;text-align:left;padding:6px 8px;font-size:9.5px}
    .cr97-doc-table td{border-bottom:1px solid #e1e8ef;padding:5px 8px}
    .cr97-doc-table .r{text-align:right}
    .cr97-doc-table tfoot td{border-top:2px solid #10263b;border-bottom:none;font-weight:bold;background:#f2f6fa}
    .cr97-doc-note{color:#718096;font-size:9px;margin-top:10px}
</style>
<div class="cr97-doc">
    @php $crLogo = $doc_logo ?? Qs::getSetting('logo'); @endphp
    <div class="cr97-doc-head">
        <table>
            <tr>
                @if($crLogo)
                <td style="width:52px;vertical-align:middle;padding-right:10px"><img src="{{ $crLogo }}" style="max-height:44px;max-width:48px" alt=""></td>
                @endif
                <td>
                    <div class="cr97-doc-school">{{ Qs::getSystemName() }}</div>
                    <div class="cr97-doc-meta">{{ Qs::getSetting('address') }} &middot; Session: {{ Qs::getCurrentSession() }}</div>
                </td>
                <td class="cr97-doc-title">
                    <div class="t">FEE COLLECTION REPORT</div>
                    <div class="s">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}
                        @if($f['class']) &middot; Class: {{ $f['class']->name }}@endif
                        @if($f['section']) &middot; Section: {{ $f['section']->name }}@endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <table class="cr97-doc-sum">
        <tr>
            <td><div class="k">Total Collection</div><div class="v">{{ \App\Helpers\Money::inr($grand_total) }}</div></td>
            <td><div class="k">Total Receipts</div><div class="v">{{ number_format($receipts_count) }}</div></td>
            <td><div class="k">Students Paid</div><div class="v">{{ number_format($unique_students) }}</div></td>
        </tr>
        <tr>
            <td><div class="k">Average per Receipt</div><div class="v">{{ \App\Helpers\Money::inr($avg_per_receipt) }}</div></td>
            <td><div class="k">Daily Average (days with collections)</div><div class="v">{{ \App\Helpers\Money::inr($daily_average) }}</div></td>
            <td><div class="k">Days with Collections</div><div class="v">{{ number_format($days_with_collections) }}</div></td>
        </tr>
    </table>

    @if(empty($rows))
        <p>No fee collections were recorded in this range for the selected filters.</p>
    @else
        <table class="cr97-doc-table">
            <thead>
            <tr>
                <th style="width:34px">#</th>
                <th>Date</th>
                <th>Day</th>
                <th class="r">Receipts</th>
                <th class="r">Amount Collected</th>
                <th class="r">Average Amount</th>
            </tr>
            </thead>
            <tbody>
            @foreach(collect($rows)->sortByDesc('day')->values() as $row)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $row['day']->format('d M Y') }}</td>
                    <td>{{ $row['day']->format('l') }}</td>
                    <td class="r">{{ $row['receipts_count'] }}</td>
                    <td class="r">{{ \App\Helpers\Money::inr($row['total']) }}</td>
                    <td class="r">{{ \App\Helpers\Money::inr($row['receipts_count'] ? (int) round($row['total'] / $row['receipts_count']) : 0) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
            <tr>
                <td></td>
                <td colspan="2">Grand Total</td>
                <td class="r">{{ number_format($receipts_count) }}</td>
                <td class="r">{{ \App\Helpers\Money::inr($grand_total) }}</td>
                <td class="r">{{ \App\Helpers\Money::inr($avg_per_receipt) }}</td>
            </tr>
            </tfoot>
        </table>
    @endif

    <p class="cr97-doc-note">From the receipts ledger: one receipt is recorded per payment taken, dated the day the money was received. Averages are rounded to the nearest rupee. Generated on {{ now()->format('d M Y, g:i A') }} by {{ auth()->user()->name }} &middot; Generated by Hackstra School Management System.</p>
</div>
