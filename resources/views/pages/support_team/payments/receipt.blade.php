<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt_{{ $pr->ref_no.'_'.$sr->user->name }}</title>
    {{-- Self-contained styles: this view is rendered both in the browser and
         by dompdf (PDF download). dompdf cannot fetch remote stylesheets, so
         everything the receipt needs lives in this block — never in an
         external file — to keep web print and PDF identical. --}}
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; color: #172536; margin: 0; padding: 24px; font-size: 13px; }
        .receipt { position: relative; max-width: 760px; margin: 0 auto; border: 1px solid #b9c6d4; overflow: hidden; }
        /* Document design v2 (Workstream DB): watermark behind the
           content — the uploaded logo, else a large app-code
           monogram — plus the monogram/logo tile in the header
           band and the generated-by line. Same Loop 68 tokens:
           navy #10263b, hairline #b9c6d4, shaded heads #eef2f7. */
        .wm { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 340px; max-height: 420px; opacity: .10; z-index: 0; }
        .wm-mono { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-18deg); font-size: 128px; font-weight: 800; letter-spacing: .04em; line-height: 1; color: #10263b; opacity: .055; z-index: 0; white-space: nowrap; }
        .receipt > *:not(.wm):not(.wm-mono) { position: relative; z-index: 1; }
        .header { background: #10263b; color: #fff; padding: 18px 22px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .header table { width: 100%; border-collapse: collapse; }
        .header .tile-cell { width: 64px; vertical-align: middle; }
        .header .tile { display: block; width: 52px; height: 52px; background: #fff; color: #10263b; font-size: 19px; font-weight: 800; text-align: center; line-height: 52px; border-radius: 8px; }
        .header .logo { max-height: 56px; max-width: 120px; }
        .header .school { font-size: 22px; font-weight: bold; letter-spacing: .02em; }
        .header .meta { color: #c7d6e8; font-size: 11px; margin-top: 4px; line-height: 1.5; }
        .genline { text-align: center; color: #718096; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; margin: 2px 22px 16px; }
        .titlebar { width: 100%; border-collapse: collapse; border-bottom: 2px solid #10263b; }
        .titlebar td { padding: 12px 22px; vertical-align: top; }
        .titlebar .doc-title { font-size: 19px; font-weight: bold; color: #10263b; letter-spacing: .04em; }
        .refbox { text-align: right; font-size: 12px; line-height: 1.7; }
        .refbox .ref-no { font-size: 16px; font-weight: bold; color: #10263b; }
        .section { padding: 14px 22px 4px; }
        .section h3 { margin: 0 0 8px; font-size: 12px; letter-spacing: .08em; color: #10263b; text-transform: uppercase; }
        table.info, table.items { width: 100%; border-collapse: collapse; }
        table.info td { padding: 5px 8px; font-size: 13px; vertical-align: top; }
        table.info td.k { color: #718096; width: 110px; font-weight: bold; }
        table.items th { background: #eef2f7; border: 1px solid #b9c6d4; padding: 8px; font-size: 12px; text-align: left; color: #10263b; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.items td { border: 1px solid #b9c6d4; padding: 8px; font-size: 13px; }
        table.items td.num, table.items th.num { text-align: right; }
        table.items tfoot td { background: #eef2f7; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .summary { width: 300px; margin-left: auto; border-collapse: collapse; }
        .summary td { padding: 6px 8px; font-size: 13px; border-bottom: 1px solid #edf1f5; }
        .summary td.num { text-align: right; font-weight: bold; }
        .summary .due td { color: #c0392b; font-size: 14px; }
        .summary .cleared td { color: #188b66; font-size: 14px; }
        .words { margin: 14px 22px 0; background: #f8faff; border: 1px solid #e4eaf1; padding: 10px 12px; font-size: 13px; }
        .words span { color: #718096; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 3px; }
        .footer { width: 100%; border-collapse: collapse; margin-top: 26px; }
        .footer td { padding: 0 22px 18px; font-size: 11px; color: #718096; vertical-align: bottom; }
        .sign { text-align: right; color: #172536; }
        .sign .line { display: inline-block; width: 180px; border-top: 1px solid #172536; margin-top: 34px; padding-top: 4px; font-size: 12px; }
        .stamp { display: inline-block; border: 2px solid #188b66; color: #188b66; font-weight: bold; padding: 4px 12px; letter-spacing: .08em; }
        .stamp.due { border-color: #c0392b; color: #c0392b; }
        @media print { body { padding: 0; } .receipt { border: none; } }
    </style>
</head>
<body>
<div class="receipt">
    {{-- Watermark (v2): uploaded logo, else the app-code monogram --}}
    @php $rLogo = $s['logo'] ?? Qs::getSetting('logo'); @endphp
    @if($rLogo)
        <img class="wm" src="{{ $rLogo }}" alt="">
    @else
        <div class="wm-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    {{-- School header (from Settings) --}}
    <div class="header">
        <table>
            <tr>
                <td class="tile-cell">
                    @if($rLogo)
                        <img class="logo" src="{{ $rLogo }}" alt="">
                    @else
                        @php
                            $rInitials = '';
                            foreach (preg_split('/\s+/', trim((string) ($s['system_name'] ?? Qs::getSetting('system_name')))) as $rWord) {
                                if ($rWord !== '') {
                                    $rInitials .= strtoupper(substr($rWord, 0, 1));
                                    if (strlen($rInitials) >= 2) { break; }
                                }
                            }
                            if ($rInitials === '') { $rInitials = 'S'; }
                        @endphp
                        <span class="tile" aria-hidden="true">{{ $rInitials }}</span>
                    @endif
                </td>
                <td>
                    <div class="school">{{ strtoupper($s['system_name'] ?? Qs::getSetting('system_name')) }}</div>
                    <div class="meta">
                        {{ $s['address'] ?? '' }}<br>
                        @if(!empty($s['phone'])) Tel: {{ $s['phone'] }} @endif
                        @if(!empty($s['system_email'])) &nbsp;|&nbsp; Email: {{ $s['system_email'] }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Title + receipt reference --}}
    <table class="titlebar">
        <tr>
            <td>
                <div class="doc-title">FEE RECEIPT</div>
                @if($pr->paid)
                    <div style="margin-top:8px;"><span class="stamp">CLEARED</span></div>
                @else
                    <div style="margin-top:8px;"><span class="stamp due">BALANCE DUE</span></div>
                @endif
            </td>
            <td class="refbox">
                Receipt No.<br>
                <span class="ref-no">{{ $pr->ref_no }}</span><br>
                Date: {{ date('D, j F, Y', strtotime(optional($receipts->last())->created_at ?? now())) }}<br>
                Session: {{ $pr->year }}
            </td>
        </tr>
    </table>

    {{-- Student information --}}
    <div class="section">
        <h3>Student Information</h3>
        <table class="info">
            <tr>
                <td class="k">Name</td><td>{{ $sr->user->name }}</td>
                <td class="k">Adm. No.</td><td>{{ $sr->adm_no ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Class</td><td>{{ $sr->my_class->name }}</td>
                <td class="k">Session</td><td>{{ $pr->year }}</td>
            </tr>
        </table>
    </div>

    {{-- Payment information --}}
    <div class="section">
        <h3>Payment Information</h3>
        <table class="info">
            <tr>
                <td class="k">Fee Title</td><td>{{ $payment->title }}</td>
                <td class="k">Payment Ref.</td><td>{{ $payment->ref_no }}</td>
            </tr>
            <tr>
                <td class="k">Method</td><td>{{ ucwords($payment->method ?? 'Cash') }}</td>
                <td class="k">Description</td><td>{{ $payment->description ?: '—' }}</td>
            </tr>
        </table>
    </div>

    {{-- Installments --}}
    <div class="section">
        <h3>Payments Received</h3>
        <table class="items">
            <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Date</th>
                <th class="num">Amount Paid</th>
                <th class="num">Balance After</th>
            </tr>
            </thead>
            <tbody>
            @forelse($receipts as $r)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ date('D, j F, Y', strtotime($r->created_at)) }}</td>
                    <td class="num">{{ \App\Helpers\Money::inr($r->amt_paid) }}</td>
                    <td class="num">{{ \App\Helpers\Money::inr($r->balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center; color:#718096;">No payment has been recorded against this fee yet.</td></tr>
            @endforelse
            </tbody>
            <tfoot>
            <tr>
                <td colspan="2">Total Paid</td>
                <td class="num">{{ \App\Helpers\Money::inr($receipts->sum('amt_paid')) }}</td>
                <td></td>
            </tr>
            </tfoot>
        </table>
    </div>

    {{-- Summary --}}
    <div class="section">
        <table class="summary">
            <tr><td>Total Fee</td><td class="num">{{ \App\Helpers\Money::inr($payment->amount) }}</td></tr>
            <tr><td>Total Paid</td><td class="num">{{ \App\Helpers\Money::inr($pr->amt_paid) }}</td></tr>
            @if($pr->paid)
                <tr class="cleared"><td>Status</td><td class="num">CLEARED</td></tr>
            @else
                <tr class="due"><td>Balance Due</td><td class="num">{{ \App\Helpers\Money::inr($pr->balance) }}</td></tr>
            @endif
        </table>
    </div>

    {{-- Amount in words --}}
    <div class="words">
        <span>Amount received in words</span>
        Received with thanks: <strong>{{ \App\Support\NumberToWords::inRupees($pr->amt_paid) }}</strong>
    </div>

    {{-- Footer --}}
    <table class="footer">
        <tr>
            <td>This is a computer-generated receipt.<br>Please keep it as proof of payment.</td>
            <td class="sign"><span class="line">Authorised Signatory</span></td>
        </tr>
    </table>
    <div class="genline">Generated by Hackstra</div>
</div>
<script>
window.print();
</script>
</body>
</html>
