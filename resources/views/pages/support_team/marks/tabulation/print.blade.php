<html>
<head>
    <title>Tabulation Sheet - {{ $my_class->name.' '.$section->name.' - '.$ex->name.' ('.$year.')' }}</title>
    <link rel="stylesheet" type="text/css" href="{{ asset('assets/css/print_tabulation.css') }}?v={{ @filemtime(public_path('assets/css/print_tabulation.css')) ?: 1 }}" />
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
    // Repeating print header (owner ask 2026-10-25): the school
    // identity, title band and context strip live INSIDE thead so
    // the browser repeats them on every printed page — a long sheet
    // no longer continues onto page 2 with no header. Column count:
    // # + name + one per subject + total + average + position.
    $tabCols = 2 + $subjects->count() + 3;
    $tabCtxExamSpan = (int) ceil(($tabCols - 3) / 2);
    $tabCtxSessSpan = $tabCols - 3 - $tabCtxExamSpan;
    // Browser-proof pagination (owner ask 2026-10-25 — page 2 lost
    // its header in his print): the sheet is split server-side
    // into pages, each page its own table carrying the full
    // header (school band + title + context + column heads), with
    // a forced page break between pages. A page's header then no
    // longer depends on the browser repeating thead — Safari and
    // other non-repeating printers get it too. Measured capacity
    // on a landscape page: 21 rows under the full header, but the
    // last page also carries summary + signatures, so it is capped
    // at 19 rows (a 20-row last page spilled the signatures alone
    // onto page 3 in verification).
    $tabPages = [];
    $tabRest = $students->values();
    while ($tabRest->count() > 21) {
        $tabPages[] = $tabRest->take(21);
        $tabRest = $tabRest->slice(21)->values();
    }
    if ($tabRest->count() >= 20) {
        $tabPages[] = $tabRest->take(19);
        $tabPages[] = $tabRest->slice(19)->values();
    } else {
        $tabPages[] = $tabRest;
    }
    // No orphan last page (owner ask 2026-10-29): the greedy
    // split can leave the final page carrying a single student
    // (41 students printed as 21 + 19 + 1), so the summary and
    // the Examination Officer / Principal signatures sit almost
    // alone on a near-empty page. When the last page is a stub,
    // rebalance the last two pages so both end well-filled
    // within their caps (21 for a plain page, 19 for the last).
    $tabPageCount = count($tabPages);
    if ($tabPageCount >= 2 && $tabPages[$tabPageCount - 1]->count() < 8) {
        $tabCombined = $tabPages[$tabPageCount - 2]->concat($tabPages[$tabPageCount - 1])->values();
        $tabLastSize = max((int) ceil($tabCombined->count() / 2), $tabCombined->count() - 21);
        $tabLastSize = min(19, $tabLastSize);
        $tabPages[$tabPageCount - 2] = $tabCombined->take($tabCombined->count() - $tabLastSize);
        $tabPages[$tabPageCount - 1] = $tabCombined->slice($tabCombined->count() - $tabLastSize)->values();
    }
    $tabRow = 0;
@endphp
<div class="tab-doc">

    {{-- Watermark: uploaded logo, else the app-code monogram --}}
    @if($tabLogo)
        <img class="tab-watermark" src="{{ $tabLogo }}" alt="">
    @else
        <div class="tab-watermark-mono" style="font-size:{{ strlen($tabCode) > 5 ? 104 : 168 }}px;" aria-hidden="true">{{ $tabCode }}</div>
    @endif

    <div class="tab-body">

        {{-- One table per page — every page carries its own
             full header by construction (see the block above). The thead
             stays as well, so a chunk that ever spills on a short
             page still gets its header repeated in Chrome. --}}
        @foreach($tabPages as $tabChunk)
        <table class="tab-marks" @if(!$loop->last) style="page-break-after: always;" @endif>
            <thead>
            <tr class="tab-hd-school">
                <td colspan="2">
                    @if($tabLogo)
                        <img class="tab-head-logo" src="{{ $tabLogo }}" alt="">
                    @else
                        <span class="tab-logo-tile" aria-hidden="true">{{ $tabInitials }}</span>
                    @endif
                </td>
                <td colspan="{{ $tabCols - 3 }}">
                    <span class="tab-school">{{ Qs::getSetting('system_name') }}</span><br/>
                    <span class="tab-addr">{{ ucwords($tabS['address'] ?? '') }}</span><br/>
                    @if(!empty($tabS['phone']) || !empty($tabS['system_email']))
                        <span class="tab-contact">{{ !empty($tabS['phone']) ? 'Tel: '.$tabS['phone'] : '' }}{{ (!empty($tabS['phone']) && !empty($tabS['system_email'])) ? ' | ' : '' }}{{ !empty($tabS['system_email']) ? 'Email: '.$tabS['system_email'] : '' }}</span>
                    @endif
                </td>
                <td></td>
            </tr>
            <tr class="tab-hd-title">
                <td colspan="{{ $tabCols }}">
                    <div class="tab-title">Tabulation Sheet</div>
                    <div class="tab-subtitle">{{ $my_class->name.' '.$section->name }} &middot; {{ $ex->name }} &middot; {{ $year }}</div>
                </td>
            </tr>
            <tr class="tab-hd-ctx">
                <td colspan="2"><span class="tab-ctx-label">Class</span><span class="tab-ctx-value">{{ $my_class->name }}</span></td>
                <td><span class="tab-ctx-label">Section</span><span class="tab-ctx-value">{{ $section->name }}</span></td>
                <td colspan="{{ $tabCtxExamSpan }}"><span class="tab-ctx-label">Examination</span><span class="tab-ctx-value">{{ $ex->name }}</span></td>
                <td colspan="{{ $tabCtxSessSpan }}"><span class="tab-ctx-label">Session</span><span class="tab-ctx-value">{{ $year }}</span></td>
            </tr>
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
            @foreach($tabChunk as $s)
                @php $tabRow++; @endphp
                <tr>
                    <td class="tab-sn">{{ $tabRow }}</td>
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
        @endforeach

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
