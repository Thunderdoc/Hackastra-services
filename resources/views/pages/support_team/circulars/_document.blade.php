{{-- Loop 75: the single-circular document body — letterhead from
     Settings, the escaped message with paragraph breaks, a plain
     meta table and a footer. Rendered by the browser print view
     and by the dompdf download; figures come straight from the
     record, so print and screen can never disagree. --}}
@php
    $cdocName = Qs::getSystemName();
    $cdocWords = preg_split('/\s+/', trim((string) $cdocName));
    $cdocInitials = strtoupper(substr($cdocWords[0] ?? 'S', 0, 1) . substr($cdocWords[1] ?? '', 0, 1));
@endphp
<div class="cdoc">
    <div class="cdoc-head">
        <table>
            <tr>
                <td style="width:64px">
                    @if(Qs::getSetting('logo'))
                        <img class="cdoc-logo" src="{{ Qs::getSetting('logo') }}" alt="">
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
            <tr><td class="k">Circular No.</td><td class="v">#{{ $circular->id }}</td></tr>
            <tr><td class="k">Audience</td><td class="v">{{ $audiences[$circular->audience] ?? $circular->audience }}</td></tr>
            <tr><td class="k">Priority</td><td class="v">{{ $priorities[$circular->priority] ?? $circular->priority }}</td></tr>
            <tr><td class="k">Published by</td><td class="v">{{ $circular->publisher->name ?? 'School Office' }}</td></tr>
            <tr><td class="k">Published on</td><td class="v">{{ $circular->created_at->format('d M Y') }}</td></tr>
            <tr><td class="k">Available from</td><td class="v">{{ $circular->starts_at ? $circular->starts_at->format('d M Y') : 'Immediately' }}</td></tr>
            <tr><td class="k">Valid until</td><td class="v">{{ $circular->expires_at ? $circular->expires_at->format('d M Y') : 'No end date' }}</td></tr>
        </table>
    </div>

    <div class="cdoc-body">{!! nl2br(e($circular->body)) !!}</div>

    <div class="cdoc-foot">
        <table>
            <tr>
                <td>{{ $cdocName }} &middot; Circular #{{ $circular->id }}</td>
                <td class="r">Printed {{ now()->format('d M Y, h:i A') }}</td>
            </tr>
        </table>
    </div>
</div>
