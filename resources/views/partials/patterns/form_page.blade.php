{{--
    PATTERN · form_page (Loop 28 — shared patterns)
    ---------------------------------------------------------------
    Standard shell for create/edit pages: ONE form card split into
    titled sections, fields in a responsive grid (Loop 39 density:
    3 per row on wide screens, 2 on medium, 1 on small), and a
    sticky action bar (Cancel + Save) pinned to the card's bottom.

    USAGE (component + slots):

        @component('partials.patterns.form_page', [
            'form_action'  => route('things.update', $id),   // required
            'form_method'  => 'PUT',                          // optional, default POST (GET also honoured)
            'form_enctype' => 'multipart/form-data',          // optional
            'form_class'   => 'ajax-store',                   // optional, extra class on the <form> (AJAX hooks)
            'form_reload'  => '#page-header',                 // optional, emitted as data-reload (ajax-update forms)
            'back'         => route('things.index'),          // optional Cancel link
            'submit_label' => 'Save Changes',                 // optional
        ])
            @slot('title') Edit Thing @endslot
            @slot('subtitle') Optional helper text @endslot

            <div class="pat-form-section">
                <div class="pat-form-section-title">Basic Details</div>
                <div class="pat-form-section-sub">Optional section note.</div>
                <div class="pat-fields">
                    <div class="pat-field">
                        <label for="name">Name</label>
                        <input id="name" name="name" class="form-control" ...>
                    </div>
                    <div class="pat-field pat-field-span2">
                        <label for="address">Address</label>
                        <input id="address" name="address" class="form-control" ...>
                    </div>
                    <div class="pat-field pat-field-wide">
                        <label for="notes">Notes</label>
                        <textarea id="notes" name="notes" class="form-control"></textarea>
                    </div>
                </div>
            </div>

    Field spans (Loop 39): .pat-field = one track;
            .pat-field-span2 = two tracks (of 3 on wide screens,
            the full row on medium); .pat-field-wide = whole row.
            Use span2 for mid-length fields (address, full name),
            wide for textareas, file uploads and photo rows.
            ... more .pat-form-section blocks ...

            @slot('actions')  (optional: replaces the default Cancel/Save bar content)
                <button class="btn btn-primary" type="submit">Publish</button>
            @endslot
        @endcomponent

    Slots:  subtitle, actions (optional) · default slot = the
            .pat-form-section blocks. The 'title' slot is RETIRED
            (Loop 46): the common layout header renders the page title
            once, so this pattern prints no heading of its own.
    Styles: public/assets/css/qs.css — "Loop 28" (.pat-* classes).
    Field controls keep the shared .form-control / label tokens.
--}}
@php
    $patMethod      = strtoupper($form_method ?? 'POST');
    $patHasTitle    = isset($title)   && trim((string) $title) !== '';
    $patHasSubtitle = isset($subtitle) && trim((string) $subtitle) !== '';
    $patHasActions  = isset($actions) && trim((string) $actions) !== '';
@endphp
<form method="{{ $patMethod === 'GET' ? 'GET' : 'POST' }}" action="{{ $form_action ?? '' }}" class="pat-form{{ !empty($form_class ?? null) ? ' '.$form_class : '' }}"
      @if(!empty($form_enctype ?? null)) enctype="{{ $form_enctype }}" @endif
      @if(!empty($form_reload ?? null)) data-reload="{{ $form_reload }}" @endif>
    @if($patMethod !== 'GET')
        @csrf
    @endif
    @if(!in_array($patMethod, ['GET', 'POST']))
        @method($patMethod)
    @endif

    <div class="card pat-form-card">
        {{-- Loop 46 (Workstream BS): no title heading here — the common
             layout header (partials/header) renders the page's title
             once. The retired 'title' slot is still accepted so
             existing pages keep working; use 'subtitle' for helper
             text that is not the page title. --}}
        @if($patHasSubtitle)
            <div class="pat-form-head">
                <div class="pat-form-sub">{!! $subtitle !!}</div>
            </div>
        @endif

        {!! $slot !!}

        <div class="pat-actionbar">
            @if($patHasActions)
                {!! $actions !!}
            @else
                @if(!empty($back ?? null))
                    <a href="{{ $back }}" class="btn btn-light">Cancel</a>
                @endif
                <button type="submit" class="btn btn-primary">{{ $submit_label ?? 'Save Changes' }}</button>
            @endif
        </div>
    </div>
</form>
