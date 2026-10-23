{{--
    Payslip document (premium redesign, 2026-10-22) — the ONE
    payslip template for every surface: the on-screen payslip page
    (payroll.payslip / my_payslips.payslip) renders it inside the
    preview paper, the browser print of that page prints it, and the
    dompdf shell (payroll/payslip_pdf) wraps it unchanged. Figures
    can never diverge between screen, print and PDF.

    Expects (PayrollController@renderPayslip): $payroll (user +
    creator loaded), $school_name, $school_address,
    $current_session, $net_words.

    Data honesty — the payroll record stores TOTALS ONLY
    (basic_salary, allowances, deductions, net_pay recomputed on
    save), so the earnings panel shows exactly Basic Salary +
    Allowances and the deductions panel the single Deductions
    line. Itemised HRA/DA/PF/TDS rows are NEVER invented by
    splitting a stored total. School identity, contacts and the
    logo come from Settings, like every v2 document; the payslip
    identifier is presented as PS-{year}-{month}-{id} — the record's
    own year, month and id, no numbering scheme is fabricated.

    One-page engineering: A4 portrait. The layout is table-only
    (dompdf-safe: no flex/grid/transforms), compact paddings, and
    the whole document is ~half the printable height, so a real
    record can never spill to a second page; nothing is clipped
    or hidden to force the fit.
--}}
<style>
    .pslip-doc{position:relative;background:#fff;color:#243b53;font-size:12.5px;line-height:1.35;padding:4px 2px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    .pslip-doc .ps-watermark{position:absolute;top:46%;left:50%;margin-left:-190px;width:380px;opacity:.07;z-index:0;}
    .pslip-doc .ps-watermark-mono{position:absolute;top:40%;left:0;width:100%;text-align:center;font-size:170px;font-weight:800;letter-spacing:.04em;color:#10263b;opacity:.05;z-index:0;line-height:1;}
    .pslip-doc .ps-body{position:relative;z-index:1;}
    .pslip-doc table{border-collapse:collapse;width:100%;}
    /* Header */
    .pslip-doc .ps-head td{vertical-align:top;}
    .pslip-doc .ps-logo{max-height:58px;max-width:120px;}
    .pslip-doc .ps-mono-tile{display:inline-block;width:52px;height:52px;line-height:52px;text-align:center;background:#10263b;color:#fff;font-size:19px;font-weight:800;letter-spacing:.03em;border-radius:9px;}
    .pslip-doc .ps-school{color:#10263b;font-size:22px;font-weight:bold;letter-spacing:.2px;line-height:1.15;}
    .pslip-doc .ps-addr{color:#44546a;font-size:11.5px;margin-top:2px;}
    .pslip-doc .ps-contact{color:#64748b;font-size:11px;margin-top:1px;}
    .pslip-doc .ps-session{color:#64748b;font-size:11px;margin-top:1px;}
    .pslip-doc .ps-titlebox{background:#10263b;color:#fff;padding:10px 14px 11px;text-align:left;border-radius:6px;}
    .pslip-doc .ps-title{font-size:19px;font-weight:bold;letter-spacing:.22em;color:#fff;}
    .pslip-doc .ps-period{font-size:12px;color:#dbe7fb;margin-top:2px;}
    .pslip-doc .ps-pill{display:inline-block;margin-top:7px;padding:2px 12px;border-radius:10px;font-size:10.5px;font-weight:bold;letter-spacing:.08em;color:#fff;}
    .pslip-doc .ps-pill-paid{background:#15803d;}
    .pslip-doc .ps-pill-pending{background:#b45309;}
    .pslip-doc .ps-headrule{border-bottom:2.5px solid #10263b;height:8px;}
    /* Employee strip */
    .pslip-doc .ps-emp{margin-top:10px;border:1px solid #d7e0ec;}
    .pslip-doc .ps-emp td{width:50%;padding:6px 10px 7px;border-bottom:1px solid #e4ebf2;vertical-align:top;}
    .pslip-doc .ps-emp tr:last-child td{border-bottom:0;}
    .pslip-doc .ps-emp .ps-lab{color:#718096;font-size:10px;letter-spacing:.1em;text-transform:uppercase;}
    .pslip-doc .ps-emp .ps-val{color:#172536;font-size:13px;font-weight:bold;margin-top:1px;}
    /* Earnings / deductions panels */
    .pslip-doc .ps-panels{margin-top:12px;}
    .pslip-doc .ps-panels>tbody>tr>td{vertical-align:top;}
    .pslip-doc .ps-panel{border:1px solid #d7e0ec;}
    .pslip-doc .ps-panel th{padding:6px 10px;font-size:11.5px;letter-spacing:.08em;text-align:left;}
    .pslip-doc .ps-panel th.ps-amt-head, .pslip-doc .ps-panel td.ps-amt{text-align:right;}
    .pslip-doc .ps-panel td{padding:5px 10px;border-top:1px solid #e4ebf2;font-size:12.5px;}
    .pslip-doc .ps-panel .ps-total td{font-weight:bold;font-size:13px;border-top:1.5px solid #b9c6d4;}
    .pslip-doc .ps-earn th{background:#eef2f7;color:#10263b;}
    .pslip-doc .ps-earn .ps-total td{background:#eef2f7;color:#10263b;}
    .pslip-doc .ps-ded th{background:#fbeeee;color:#8a3030;}
    .pslip-doc .ps-ded .ps-total td{background:#fbeeee;color:#8a3030;}
    /* Net pay panel */
    .pslip-doc .ps-net{margin-top:12px;background:#eaf1fb;border:1px solid #c7d8f2;}
    .pslip-doc .ps-net td{padding:10px 14px 11px;vertical-align:middle;}
    .pslip-doc .ps-net .ps-net-lab{color:#10263b;font-size:10.5px;font-weight:bold;letter-spacing:.14em;}
    .pslip-doc .ps-net .ps-net-amt{color:#10263b;font-size:25px;font-weight:bold;line-height:1.2;}
    .pslip-doc .ps-net .ps-net-words{color:#44546a;font-size:12px;font-style:italic;margin-top:2px;}
    .pslip-doc .ps-net .ps-net-tile{width:52px;}
    .pslip-doc .ps-net .ps-net-glyph{display:inline-block;width:44px;height:44px;line-height:44px;text-align:center;background:#2563eb;color:#fff;font-size:22px;font-weight:bold;border-radius:9px;}
    /* Payment strip */
    .pslip-doc .ps-pay{margin-top:12px;border-top:1px solid #d7e0ec;border-bottom:1px solid #d7e0ec;}
    .pslip-doc .ps-pay td{width:25%;padding:7px 8px 8px;vertical-align:top;}
    .pslip-doc .ps-pay .ps-lab{color:#718096;font-size:10px;letter-spacing:.1em;text-transform:uppercase;}
    .pslip-doc .ps-pay .ps-val{color:#172536;font-size:12.5px;font-weight:bold;margin-top:2px;}
    .pslip-doc .ps-pay .ps-pill{margin-top:2px;}
    /* Remarks + notes */
    .pslip-doc .ps-remarks{margin-top:10px;color:#44546a;font-size:12px;}
    .pslip-doc .ps-notes{margin-top:12px;background:#f4f7fb;border:1px solid #e1e9f2;padding:9px 12px 10px;}
    .pslip-doc .ps-notes .ps-notes-title{color:#10263b;font-size:12.5px;font-weight:bold;margin-bottom:4px;}
    .pslip-doc .ps-notes .ps-note{color:#44546a;font-size:11.5px;padding:1.5px 0;}
    /* Footer */
    .pslip-doc .ps-foot{margin-top:14px;border-top:1px solid #b9c6d4;padding-top:6px;}
    .pslip-doc .ps-foot td{font-size:11px;color:#44546a;padding:0;vertical-align:top;}
    .pslip-doc .ps-foot .ps-gen{text-align:right;color:#718096;font-size:9.5px;}
    .pslip-doc .ps-sysline{text-align:center;color:#718096;font-size:10px;margin-top:8px;}
    @media print{
        .pslip-doc{padding:0;font-size:12px;}
        .pslip-doc .ps-panel td{padding:4px 10px;}
        .pslip-doc .ps-net td{padding:8px 14px 9px;}
    }
</style>

@php
    $psLogo = $logo_pdf ?? Qs::getSetting('logo');
    $psPhone = Qs::getSetting('phone');
    $psEmail = Qs::getSetting('system_email');
    $psInitials = '';
    foreach (preg_split('/\s+/', trim((string) $school_name)) as $psWord) {
        if ($psWord !== '') {
            $psInitials .= strtoupper(substr($psWord, 0, 1));
            if (strlen($psInitials) >= 2) { break; }
        }
    }
    if ($psInitials === '') { $psInitials = 'S'; }
    $psTotalEarnings = (float) $payroll->basic_salary + (float) $payroll->allowances;
    // Loop 119: the reference document prints every amount with two
    // decimals ("45,000.00"). Money::indianFormat drops a zero
    // fraction, so the document formats its own amounts here —
    // Indian grouping, always two decimals, values unchanged.
    $psDocAmt = function ($v) {
        $v = (float) $v;
        $neg = $v < 0 ? '-' : '';
        $parts = explode('.', number_format(abs($v), 2, '.', ''));
        $whole = $parts[0];
        if (strlen($whole) > 3) {
            $lastThree = substr($whole, -3);
            $rest = substr($whole, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $lastThree;
        }
        return $neg . $whole . '.' . $parts[1];
    };
@endphp

<div class="pslip-doc">
    @if($psLogo)
        <img class="ps-watermark" src="{{ $psLogo }}" alt="">
    @else
        <div class="ps-watermark-mono" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <div class="ps-body">
        {{-- Header: school identity from Settings (v2 rule) + title block --}}
        <table class="ps-head">
            <tr>
                <td style="width:62px;">
                    @if($psLogo)
                        <img class="ps-logo" src="{{ $psLogo }}" alt="">
                    @else
                        <span class="ps-mono-tile" aria-hidden="true">{{ $psInitials }}</span>
                    @endif
                </td>
                <td style="padding-left:10px;">
                    <div class="ps-school">{{ $school_name }}</div>
                    @if($school_address)<div class="ps-addr">{{ $school_address }}</div>@endif
                    @if($psPhone || $psEmail)
                        <div class="ps-contact">{{ $psPhone ? 'Tel: '.$psPhone : '' }}{{ ($psPhone && $psEmail) ? ' | ' : '' }}{{ $psEmail ? 'Email: '.$psEmail : '' }}</div>
                    @endif
                    <div class="ps-session">Session: {{ $current_session }}</div>
                </td>
                <td style="width:218px;">
                    <div class="ps-titlebox">
                        <div class="ps-title">PAYSLIP</div>
                        <div class="ps-period">{{ $payroll->monthName() }} {{ $payroll->year }}</div>
                        @if($payroll->isPaid())
                            <span class="ps-pill ps-pill-paid">PAID</span>
                        @else
                            <span class="ps-pill ps-pill-pending">PENDING</span>
                        @endif
                    </div>
                </td>
            </tr>
            <tr><td colspan="3" class="ps-headrule"></td></tr>
        </table>

        {{-- Employee details: the staff member's real user record --}}
        <table class="ps-emp">
            <tr>
                <td>
                    <div class="ps-lab">Staff Name</div>
                    <div class="ps-val">{{ $payroll->user->name }}</div>
                </td>
                <td>
                    <div class="ps-lab">Staff Code</div>
                    <div class="ps-val">{{ $payroll->user->code ?: '—' }}</div>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="ps-lab">Role</div>
                    <div class="ps-val">{{ ucwords(str_replace('_', ' ', $payroll->user->user_type)) }}</div>
                </td>
                <td>
                    <div class="ps-lab">Email</div>
                    <div class="ps-val">{{ $payroll->user->email }}</div>
                </td>
            </tr>
        </table>

        {{-- Earnings / deductions: the record's stored totals only --}}
        <table class="ps-panels">
            <tr>
                <td style="width:49%;">
                    <table class="ps-panel ps-earn">
                        <tr><th>EARNINGS</th><th class="ps-amt-head">AMOUNT (₹)</th></tr>
                        <tr><td>Basic Salary</td><td class="ps-amt">{{ $psDocAmt($payroll->basic_salary) }}</td></tr>
                        <tr><td>Allowances</td><td class="ps-amt">{{ $psDocAmt($payroll->allowances) }}</td></tr>
                        <tr class="ps-total"><td>Total Earnings</td><td class="ps-amt">{{ $psDocAmt($psTotalEarnings) }}</td></tr>
                    </table>
                </td>
                <td style="width:2%;"></td>
                <td style="width:49%;">
                    <table class="ps-panel ps-ded">
                        <tr><th>DEDUCTIONS</th><th class="ps-amt-head">AMOUNT (₹)</th></tr>
                        <tr><td>Deductions</td><td class="ps-amt">{{ $psDocAmt($payroll->deductions) }}</td></tr>
                        <tr><td>&nbsp;</td><td class="ps-amt">&nbsp;</td></tr>
                        <tr class="ps-total"><td>Total Deductions</td><td class="ps-amt">{{ $psDocAmt($payroll->deductions) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>

        {{-- Net pay: the record's stored, authoritative net --}}
        <table class="ps-net">
            <tr>
                <td class="ps-net-tile"><span class="ps-net-glyph" aria-hidden="true">₹</span></td>
                <td>
                    <div class="ps-net-lab">NET PAY</div>
                    <div class="ps-net-amt">₹ {{ $psDocAmt($payroll->net_pay) }}</div>
                    <div class="ps-net-words">Rupees {{ $net_words }} Only</div>
                </td>
            </tr>
        </table>

        {{-- Payment information strip --}}
        <table class="ps-pay">
            <tr>
                <td>
                    <div class="ps-lab">Pay Period</div>
                    <div class="ps-val">{{ $payroll->monthName() }} {{ $payroll->year }}</div>
                </td>
                <td>
                    <div class="ps-lab">Payment Date</div>
                    <div class="ps-val">{{ ($payroll->isPaid() && $payroll->paid_at) ? $payroll->paid_at->format('d M Y') : '—' }}</div>
                </td>
                <td>
                    <div class="ps-lab">Payment Status</div>
                    @if($payroll->isPaid())
                        <span class="ps-pill ps-pill-paid">PAID</span>
                    @else
                        <span class="ps-pill ps-pill-pending">PENDING</span>
                    @endif
                </td>
                <td>
                    <div class="ps-lab">Payslip ID</div>
                    <div class="ps-val">PS-{{ $payroll->year }}-{{ str_pad((string) $payroll->month, 2, '0', STR_PAD_LEFT) }}-{{ str_pad((string) $payroll->id, 3, '0', STR_PAD_LEFT) }}</div>
                </td>
            </tr>
        </table>

        @if($payroll->remarks)
            <p class="ps-remarks"><strong>Remarks:</strong> {{ $payroll->remarks }}</p>
        @endif

        {{-- Notes --}}
        <div class="ps-notes">
            <div class="ps-notes-title">Important Notes</div>
            <div class="ps-note">• This is a computer-generated payslip and does not require a signature.</div>
            <div class="ps-note">• For any salary-related queries or discrepancies, please contact the school administration.</div>
            <div class="ps-note">• All amounts are in Indian Rupees (INR).</div>
            <div class="ps-note">• Keep this payslip for your personal records.</div>
        </div>

        {{-- Footer: real generation date + the recorded-by fact + the
             app-standard system line (annual card convention). No
             signature or seal is rendered: no such asset exists in
             Settings and none may be fabricated. --}}
        <table class="ps-foot">
            <tr>
                <td>
                    Generated on {{ ($payroll->paid_at ?: $payroll->created_at)->format('d M Y') }}@if($payroll->creator) by {{ $payroll->creator->name }}@endif
                </td>
                <td class="ps-gen">Generated by Hackstra School Management System</td>
            </tr>
        </table>
        <div class="ps-sysline">This is a system-generated payslip.</div>
    </div>
</div>
