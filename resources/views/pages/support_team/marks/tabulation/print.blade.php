<html>
<head>
    <title>Tabulation Sheet - {{ $my_class->name.' '.$section->name.' - '.$ex->name.' ('.$year.')' }}</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/print_tabulation.css') }}" />
</head>
<body>
{{-- Document Design v2 (Workstream DA, applying Workstream CY's
     system — docs/DOCUMENT_DESIGN_RESEARCH.md): navy #10263b /
     hairline #b9c6d4 / shaded heads #eef2f7 tokens, a settings
     logo header (monogram tile when no logo is uploaded), the
     logo/monogram watermark behind the table, a class/exam
     context strip, a summary strip aggregated from the printed
     columns, signature lines and the generated-by footer.
     Presentation only — every figure below is the same value
     the legacy document printed. --}}
@php
    $tabS = $s;
    $tabLogo = $tabS['logo'] ?? null;
    $tabCode = strtoupper((string) Qs::getAppCode());
    $tabInitials = '';
    foreach (preg_split('/\s+/', trim((string) Qs::getSetting('system_name'))) as $tabWord) {
        if ($tabWord !== '') {
            $tabInitials .= strtoupper(substr($tabWord, 0, 1));
            if (strlen($tabInitials) >= 2) { break; }
        }
    }
    if ($tabInitials === '') { $tabInitials = 'S'; }
    // Summary aggregates over the students actually printed.
    $tabAveSum = 0.0; $tabAveCount = 0; $tabHigh = null; $tabLow = null;
    foreach ($students as $tabSt) {
        $tabRec = $exr->where('student_id', $tabSt->user_id)->first();
        if (!$tabRec) { continue; }
        if ($tabRec->ave !== null && $tabRec->ave !== '') { $tabAveSum += (float) $tabRec->ave; $tabAveCount++; }
        if ($tabRec->total !== null && $tabRec->total !== '') {
            if ($tabHigh === null || (float) $tabRec->total > (float) $tabHigh) { $tabHigh = $tabRec->total; }
            if ($tabLow === null || (float) $tabRec->total < (float) $tabLow) { $tabLow = $tabRec->total; }
        }
    }
@endphp
<div class="tab-doc">

    {{-- Watermark: uploaded logo, else the app-code monogram --}}
    @if($tabLogo)
        <img class="tab-watermark" src="{{ $tabLogo }}" alt="">
    @else
        <div class="tab-watermark-mono" style="font-size:{{ strlen($tabCode) > 5 ? 104 : 168 }}px;" aria-hidden="true">{{ $tabCode }}</div>
    @endif

    <div class="tab-body">

        {{-- Header band: logo/monogram tile + school identity --}}
        <table class="tab-head">
            <tr>
                <td style="width:110px; text-align:left;">
                    @if($tabLogo)
                        <img class="tab-head-logo" src="{{ $tabLogo }}" alt="">
                    @else
                        <span class="tab-logo-tile" aria-hidden="true">{{ $tabInitials }}</span>
                    @endif
                </td>
                <td style="text-align:center;">
                    <span class="tab-school">{{ Qs::getSetting('system_name') }}</span><br/>
                    <span class="tab-addr">{{ ucwords($tabS['address'] ?? '') }}</span><br/>
                    @if(!empty($tabS['phone']) || !empty($tabS['system_email']))
                        <span class="tab-contact">{{ !empty($tabS['phone']) ? 'Tel: '.$tabS['phone'] : '' }}{{ (!empty($tabS['phone']) && !empty($tabS['system_email'])) ? ' | ' : '' }}{{ !empty($tabS['system_email']) ? 'Email: '.$tabS['system_email'] : '' }}</span>
                    @endif
                </td>
                <td style="width:110px;"></td>
            </tr>
        </table>

        {{-- Title band --}}
        <div class="tab-titleband">
            <div class="tab-title">Tabulation Sheet</div>
            <div class="tab-subtitle">{{ $my_class->name.' '.$section->name }} &middot; {{ $ex->name }} &middot; {{ $year }}</div>
        </div>

        {{-- Context strip --}}
        <table class="tab-context">
            <tr>
                <td><span class="tab-ctx-label">Class</span><span class="tab-ctx-value">{{ $my_class->name }}</span></td>
                <td><span class="tab-ctx-label">Section</span><span class="tab-ctx-value">{{ $section->name }}</span></td>
                <td><span class="tab-ctx-label">Examination</span><span class="tab-ctx-value">{{ $ex->name }}</span></td>
                <td><span class="tab-ctx-label">Session</span><span class="tab-ctx-value">{{ $year }}</span></td>
            </tr>
        </table>

        {{-- Tabulation table --}}
        <table class="tab-marks">
            <thead>
            <tr>
                <th class="tab-sn">#</th>
                <th style="text-align: left;">NAMES OF STUDENTS IN CLASS</th>
                @foreach($subjects as $sub)
                    <th>{{ strtoupper($sub->slug ?: $sub->name) }}</th>
                @endforeach
                <th class="tab-total-h">Total</th>
                <th>Average</th>
                <th>Position</th>
            </tr>
            </thead>
            <tbody>
            @foreach($students as $s)
                <tr>
                    <td class="tab-sn">{{ $loop->iteration }}</td>
                    <td class="tab-name">{{ $s->user->name }}</td>
                    @foreach($subjects as $sub)
                        <td>{{ $marks->where('student_id', $s->user_id)->where('subject_id', $sub->id)->first()->$tex ?? '-' ?: '-' }}</td>
                    @endforeach
                    <td class="tab-total">{{ $exr->where('student_id', $s->user_id)->first()->total ?: '-' }}</td>
                    <td class="tab-ave">{{ $exr->where('student_id', $s->user_id)->first()->ave ?: '-' }}</td>
                    <td class="tab-pos">{!! Mk::getSuffix($exr->where('student_id', $s->user_id)->first()->pos) ?: '-' !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{-- Summary strip: aggregates of the printed columns --}}
        <table class="tab-summary">
            <tr>
                <td><span class="tab-sum-label">Students</span><span class="tab-sum-value">{{ $students->count() }}</span></td>
                <td><span class="tab-sum-label">Class Average</span><span class="tab-sum-value">{{ $tabAveCount ? number_format($tabAveSum / $tabAveCount, 2) : '-' }}</span></td>
                <td><span class="tab-sum-label">Highest Total</span><span class="tab-sum-value">{{ $tabHigh !== null ? $tabHigh : '-' }}</span></td>
                <td><span class="tab-sum-label">Lowest Total</span><span class="tab-sum-value">{{ $tabLow !== null ? $tabLow : '-' }}</span></td>
            </tr>
        </table>

        {{-- Signatures --}}
        <table class="tab-sign">
            <tr>
                <td><div class="tab-sign-line"></div><div class="tab-sign-role">EXAMINATION OFFICER</div></td>
                <td><div class="tab-sign-line"></div><div class="tab-sign-role">PRINCIPAL</div></td>
            </tr>
        </table>

        <div class="tab-genline">Generated by Hackstra</div>

    </div>
</div>

<script>
    window.print();
</script>
</body>
</html>
