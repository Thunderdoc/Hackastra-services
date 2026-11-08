{{--
    PATTERN · not_published (Publication gate, owner order 2026-11-08)
    ---------------------------------------------------------------
    THE shared "not published yet" empty state for every
    student/parent-facing document the school must publish before
    families can see it: Marksheet (results), Admit Card and Exam
    Date Sheet. One look, one wording, everywhere — a pastel icon
    circle, the heading and the standard line. No document data is
    ever passed to this partial.

    USAGE (inside a card body, or a page that is only this state):

        @include('partials.patterns.not_published', [
            'document' => 'Admit Card',          // optional label
            'icon'     => 'icon-profile',        // optional icomoon class
            'heading'  => 'Not Published Yet',   // optional override
            'detail'   => 'For Term 1 Examination (2026-2027).', // optional
        ])

    Styles: the shared .pat-empty block from partials/patterns/
    empty_state (Loop 28, public/assets/css/qs.css).
--}}
@php
    $npIcon = $icon ?? 'icon-eye-blocked';
    $npHeading = $heading ?? 'Not Published Yet';
    $npDocument = $document ?? 'This document';
@endphp
<div class="pat-empty">
    <div class="pat-empty-icon"><i class="{{ $npIcon }}"></i></div>
    <h4 class="font-weight-bold mb-2">{{ $npHeading }}</h4>
    @if(!empty($detail ?? null))
        <p class="text-muted mb-1">{{ $detail }}</p>
    @endif
    <p class="pat-empty-msg">Your school hasn't published this yet — it will appear here once published.</p>
</div>
