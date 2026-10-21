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

    Styles: public/assets/css/qs.css — "Loop 28" (.pat-* classes).
--}}
<div class="pat-empty">
    @if(!empty($icon ?? null))
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
