{{--
    PATTERN · empty_state (Loop 28 — shared patterns)
    ---------------------------------------------------------------
    Centered "nothing here yet" block: icon in a soft blue circle,
    a message, and an optional call-to-action. Data-driven (no
    slots) so it is safe to @include anywhere, even inside another
    pattern's slot.

    USAGE:

        @include('partials.patterns.empty_state', [
            'icon'         => 'icon-users',        // optional, any icomoon class in the font
            'message'      => 'No students found for this class yet.',
            'action_url'   => route('students.create'),   // optional
            'action_label' => 'Add Student',              // optional
        ])

    For full control of the action markup, pass 'action_html'
    instead of 'action_url'/'action_label':

        @include('partials.patterns.empty_state', [
            'icon'    => 'icon-books',
            'message' => 'Pick a class above to begin.',
            'action_html' => '<a class="btn btn-primary" href="...">Go</a>',
        ])

    Round 5 Slice 3 (P5): pass 'compact' => true for the compact
    form — same markup, .pat-empty-compact class (18px padding,
    40px medallion, .85rem message) for small in-panel/dashboard
    slots where the full 42px form is too tall. The default form
    is unchanged when the prop is absent.

    THE THREE FORMS (one component, three placements):
      panel          — include it where a list/panel would render
                       (the default; most index pages).
      table-row      — inside a table body, wrap the include in a
                       single colspan cell (precedent: leave/my):
                           <tr><td colspan="7">@include(...)</td></tr>
      filter-no-match — panel or table-row with 'icon' =>
                       'icon-search4', a "matches these filters"
                       message and a Clear-filters 'action_html'
                       pointing at the page's own unfiltered URL.

    Beauty Wave 3 (2026-11-06): pass 'hkEmptyArt' => true to swap
    the icon circle for a small decorative illustration plate (an
    inline SVG scene in site colours — open book, calendar and
    box; no external image). Opt-in only: every existing caller
    renders exactly as before.

    Styles: public/assets/css/qs.css — "Loop 28" (.pat-* classes).
--}}
<div class="pat-empty{{ !empty($compact ?? null) ? ' pat-empty-compact' : '' }}">
    @if(!empty($hkEmptyArt ?? null))
        <div class="pat-empty-art" aria-hidden="true">
            <svg viewBox="0 0 220 124" role="presentation" focusable="false">
                <rect x="6" y="10" width="208" height="104" rx="18" fill="#e9f1ff"/>
                <circle cx="34" cy="30" r="3" fill="#3478f6" opacity=".45"/>
                <circle cx="188" cy="26" r="4" fill="#f2a13b" opacity=".55"/>
                <circle cx="200" cy="96" r="3" fill="#3478f6" opacity=".4"/>
                <path d="M52 26l2.2 5.4L60 33l-5.8 1.6L52 40l-2.2-5.4L44 33l5.8-1.6z" fill="#f2a13b" opacity=".8"/>
                <rect x="88" y="20" width="44" height="40" rx="5" fill="#ffffff" stroke="#c9d9f2" stroke-width="1.5"/>
                <path d="M88 25a5 5 0 015-5h34a5 5 0 015 5v7H88z" fill="#3478f6"/>
                <circle cx="99" cy="43" r="2.6" fill="#9db9e8"/>
                <circle cx="110" cy="43" r="2.6" fill="#9db9e8"/>
                <circle cx="121" cy="43" r="2.6" fill="#9db9e8"/>
                <circle cx="99" cy="52" r="2.6" fill="#c9d9f2"/>
                <circle cx="110" cy="52" r="2.6" fill="#f2a13b"/>
                <path d="M28 96V62c10-4.5 20-4.5 29 0v34c-9-4.5-19-4.5-29 0z" fill="#ffffff" stroke="#2867dc" stroke-width="2"/>
                <path d="M86 96V62c-10-4.5-20-4.5-29 0v34c9-4.5 19-4.5 29 0z" fill="#eef4ff" stroke="#2867dc" stroke-width="2"/>
                <path d="M37 70c5-1.6 9-1.8 13-.6M37 78c5-1.6 9-1.8 13-.6M63 69.4c4-1.2 8-1 13 .6M63 77.4c4-1.2 8-1 13 .6" stroke="#9db9e8" stroke-width="2" stroke-linecap="round" fill="none"/>
                <path d="M148 96l4-22h36l4 22z" fill="#f2a13b"/>
                <path d="M146 74l2-8h44l2 8z" fill="#d97c06"/>
                <path d="M170 66v30" stroke="#b7791f" stroke-width="2.5"/>
                <path d="M160 86h20" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" opacity=".65"/>
                <path d="M24 104h172" stroke="#c9d9f2" stroke-width="3" stroke-linecap="round"/>
            </svg>
        </div>
    @elseif(!empty($icon ?? null))
        <div class="pat-empty-icon"><i class="{{ $icon }}"></i></div>
    @endif
    @if(!empty($message ?? null))
        <p class="pat-empty-msg">{{ $message }}</p>
    @endif
    @if(!empty($action_html ?? null))
        <div class="pat-empty-action">{!! $action_html !!}</div>
    @elseif(!empty($action_url ?? null))
        <div class="pat-empty-action">
            <a href="{{ $action_url }}" class="btn btn-primary">{{ $action_label ?? 'Get Started' }}</a>
        </div>
    @endif
</div>
