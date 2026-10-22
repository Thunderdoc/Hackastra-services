{{-- Loop 75: the single-circular document body — letterhead from
     Settings, the escaped message with paragraph breaks, a plain
     meta table and a footer. Rendered by the browser print view
     and by the dompdf download; figures come straight from the
     record, so print and screen can never disagree. --}}
@php
    $cdocName = Qs::getSystemName();
    $cdocWords = preg_split('/\s+/', trim((string) $cdocName));
    $cdocInitials = strtoupper(substr($cdocWords[0] ?? 'S', 0, 1) . substr($cdocWords[1] ?? '', 0, 1));
    // Loop 105: the PDF controller passes the letterhead/signature/
    // seal as data URIs (dompdf cannot fetch URLs); the browser
    // print view falls back to the Settings values directly.
    $cdocLogo = $doc_logo ?? Qs::getSetting('logo');
    $cdocSignature = $doc_signature ?? Qs::getSetting('principal_signature');
    $cdocStamp = $doc_stamp ?? Qs::getSetting('school_stamp');
    $cdocPrincipalName = Qs::getSetting('principal_name');
    $cdocReference = $circular->reference();
@endphp
<div class="cdoc">
    <div class="cdoc-head">
        <table>
            <tr>
                <td style="width:64px">
                    @if($cdocLogo)
                        <img class="cdoc-logo" src="{{ $cdocLogo }}" alt="">
                    @else
                        <span class="cdoc-mono">{{ $cdocInitials }}</span>
                    @endif
                </td>
                <td>
                    <p class="cdoc-school">{{ $cdocName }}</p>
                    @if(Qs::getSetting('address'))
                        <p class="cdoc-addr">{{ ucwords(Qs::getSetting('address')) }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <p class="cdoc-label">Circular</p>
    <h1 class="cdoc-title">{{ $circular->title }}</h1>

    <div class="cdoc-meta">
        <table>
            <tr><td class="k">Reference No.</td><td class="v">{{ $cdocReference }}</td></tr>
            <tr><td class="k">Audience</td><td class="v">{{ $audiences[$circular->audience] ?? $circular->audience }}</td></tr>
            <tr><td class="k">Priority</td><td class="v">{{ $priorities[$circular->priority] ?? $circular->priority }}</td></tr>
            <tr><td class="k">Published by</td><td class="v">{{ $circular->publisher->name ?? 'School Office' }}</td></tr>
            <tr><td class="k">Published on</td><td class="v">{{ $circular->created_at->format('d M Y') }}</td></tr>
            <tr><td class="k">Available from</td><td class="v">{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : 'Immediately' }}</td></tr>
            <tr><td class="k">Valid until</td><td class="v">{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</td></tr>
            @if($circular->attachments->isNotEmpty())
                <tr><td class="k">Attachments</td><td class="v">{{ $circular->attachments->pluck('original_name')->implode(', ') }}</td></tr>
            @endif
        </table>
    </div>

    <div class="cdoc-body">{!! nl2br(e($circular->body)) !!}</div>

    {{-- Loop 105: signatory block — the same rule as the report
         cards. The seal and the Principal's signature render only
         while uploaded in Settings; nothing is ever faked, and
         without them the blank lines print exactly as before. --}}
    <div class="cdoc-sign">
        <table>
            <tr>
                <td class="cdoc-sign-cell">
                    @if($cdocStamp)
                        <img class="cdoc-seal" src="{{ $cdocStamp }}" alt="School seal">
                    @endif
                    <p class="cdoc-sign-role">School Seal</p>
                </td>
                <td class="cdoc-sign-cell cdoc-sign-right">
                    @if($cdocSignature)
                        <img class="cdoc-sig" src="{{ $cdocSignature }}" alt="Principal's signature">
                    @endif
                    <p class="cdoc-sign-line"></p>
                    <p class="cdoc-sign-role">Principal</p>
                    @if($cdocPrincipalName)
                        <p class="cdoc-sign-name">{{ $cdocPrincipalName }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="cdoc-foot">
        <table>
            <tr>
                <td>{{ $cdocName }} &middot; Ref {{ $cdocReference }}</td>
                <td class="r">Printed {{ now()->format('d M Y, h:i A') }}</td>
            </tr>
        </table>
    </div>
</div>
