{{--
    Payslip document for PDF rendering (Loop 89). Mirrors the Loop 71
    print document (payroll/payslip.blade.php — untouched) figure for
    figure, with a table-based layout + embedded styles so dompdf
    renders the same document: letterhead, watermark monogram, staff
    block, earnings/deductions tables, net band, foot + genline.
    Expects: $payroll, $school_name, $school_address,
    $current_session, $net_words.
--}}
@php
    $psDocInitials = '';
    foreach (preg_split('/\s+/', trim((string) $school_name)) as $psDocWord) {
        if ($psDocWord !== '') {
            $psDocInitials .= strtoupper(substr($psDocWord, 0, 1));
            if (strlen($psDocInitials) >= 2) { break; }
        }
    }
    if ($psDocInitials === '') { $psDocInitials = 'S'; }
    $psDocLogo = Qs::getSetting('logo');
    $psDocLogoSrc = null;
    if ($psDocLogo) {
        $psDocPath = public_path(ltrim((string) parse_url($psDocLogo, PHP_URL_PATH), '/'));
        if (is_file($psDocPath)) { $psDocLogoSrc = $psDocPath; }
    }
@endphp
<div class="psdoc">
    @if($psDocLogoSrc)
        <img class="psdoc-watermark-img" src="{{ $psDocLogoSrc }}" alt="">
    @else
        <div class="psdoc-watermark" aria-hidden="true">{{ strtoupper((string) Qs::getAppCode()) }}</div>
    @endif

    <table class="psdoc-head">
        <tr>
            <td class="psdoc-head-main">
                <table class="psdoc-brand">
                    <tr>
                        <td class="psdoc-brand-tile">
                            @if($psDocLogoSrc)
                                <img class="psdoc-logo" src="{{ $psDocLogoSrc }}" alt="">
                            @else
                                <span class="psdoc-mono">{{ $psDocInitials }}</span>
                            @endif
                        </td>
                        <td class="psdoc-brand-text">
                            <div class="psdoc-school">{{ $school_name }}</div>
                            @if($school_address)<div class="psdoc-muted">{{ $school_address }}</div>@endif
                            <div class="psdoc-muted">Session: {{ $current_session }}</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="psdoc-head-side">
                <div class="psdoc-title">PAYSLIP</div>
                <div>{{ $payroll->monthName() }} {{ $payroll->year }}</div>
                @if($payroll->isPaid())
                    <span class="psdoc-stamp psdoc-stamp-paid">PAID</span>
                @else
                    <span class="psdoc-stamp psdoc-stamp-pending">PENDING</span>
                @endif
            </td>
        </tr>
    </table>

    <table class="psdoc-staff">
        <tr>
            <td><span>Staff Name</span><strong>{{ $payroll->user->name }}</strong></td>
            <td><span>Staff Code</span><strong>{{ $payroll->user->code ?: '—' }}</strong></td>
        </tr>
        <tr>
            <td><span>Role</span><strong>{{ ucwords(str_replace('_', ' ', $payroll->user->user_type)) }}</strong></td>
            <td><span>Email</span><strong>{{ $payroll->user->email }}</strong></td>
        </tr>
    </table>

    <table class="psdoc-table">
        <tr><th class="psdoc-th-main">Earnings</th><th class="psdoc-th-amt">Amount</th></tr>
        <tr><td>Basic Salary</td><td class="psdoc-amt">{{ \App\Helpers\Money::inr($payroll->basic_salary) }}</td></tr>
        <tr><td>Allowances</td><td class="psdoc-amt">{{ \App\Helpers\Money::inr($payroll->allowances) }}</td></tr>
        <tr class="psdoc-subtotal"><td>Total Earnings</td><td class="psdoc-amt">{{ \App\Helpers\Money::inr((float) $payroll->basic_salary + (float) $payroll->allowances) }}</td></tr>
        <tr><th class="psdoc-th-main">Deductions</th><th class="psdoc-th-amt">Amount</th></tr>
        <tr><td>Deductions</td><td class="psdoc-amt">{{ \App\Helpers\Money::inr($payroll->deductions) }}</td></tr>
        <tr class="psdoc-subtotal"><td>Total Deductions</td><td class="psdoc-amt">{{ \App\Helpers\Money::inr($payroll->deductions) }}</td></tr>
    </table>

    <table class="psdoc-net">
        <tr>
            <td class="psdoc-net-main">
                <span>Net Pay</span>
                <strong>{{ \App\Helpers\Money::inr($payroll->net_pay) }}</strong>
            </td>
            <td class="psdoc-net-words">Rupees {{ $net_words }} Only</td>
        </tr>
    </table>

    @if($payroll->remarks)
        <p class="psdoc-remarks"><strong>Remarks:</strong> {{ $payroll->remarks }}</p>
    @endif

    <table class="psdoc-foot">
        <tr>
            <td>
                @if($payroll->isPaid() && $payroll->paid_at)
                    Paid on {{ $payroll->paid_at->format('d M Y') }}
                @else
                    Payment pending
                @endif
                @if($payroll->creator) · Recorded by {{ $payroll->creator->name }} @endif
            </td>
            <td class="psdoc-foot-right">This is a system-generated payslip.</td>
        </tr>
    </table>

    <div class="psdoc-genline">Generated by Hackstra</div>
</div>
