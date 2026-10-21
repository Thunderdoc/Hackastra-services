{{--
    PATTERN · list_page (Loop 28 — shared patterns)
    ---------------------------------------------------------------
    Standard shell for index/list pages: a toolbar (title +
    primary action), an optional filter row, and ONE card wrapping
    the list table. Replaces the legacy "giant card with everything
    stacked inside" layout.

    USAGE (component + slots):

        @component('partials.patterns.list_page')
            @slot('title')
                <i class="icon-users mr-2"></i> Students
            @endslot
            @slot('subtitle') Optional one-line helper text @endslot
            @slot('action')
                <a href="..." class="btn btn-primary">Add New</a>
            @endslot
            @slot('filters')
                <form method="get" class="pat-filter-form">
                    <div class="pat-filter-field">
                        <label>Class</label>
                        <select class="form-control" name="...">...</select>
                    </div>
                    <div class="pat-filter-action">
                        <button class="btn btn-primary" type="submit">Filter</button>
                    </div>
                </form>
            @endslot

            <table class="table"> ... the list table ... </table>
        @endcomponent

    Slots:  title (required) · subtitle, action, filters
            (optional) · default slot = the table markup.
    Styles: public/assets/css/qs.css — "Loop 28" (.pat-* classes).
    The card/table/button styling itself comes from the shared
    .content .card / .table / .btn tokens; this pattern only adds
    the toolbar + filter chrome around them.
--}}
@php
    $patHasSubtitle = isset($subtitle) && trim((string) $subtitle) !== '';
    $patHasAction   = isset($action)   && trim((string) $action) !== '';
    $patHasFilters  = isset($filters)  && trim((string) $filters) !== '';
@endphp
<div class="pat-list">
    <div class="pat-toolbar">
        <div class="pat-toolbar-text">
            <h4 class="pat-toolbar-title">{!! $title !!}</h4>
            @if($patHasSubtitle)
                <div class="pat-toolbar-sub">{!! $subtitle !!}</div>
            @endif
        </div>
        @if($patHasAction)
            <div class="pat-toolbar-actions">{!! $action !!}</div>
        @endif
    </div>

    @if($patHasFilters)
        <div class="pat-filters">{!! $filters !!}</div>
    @endif

    <div class="card pat-card">
        <div class="card-body">
            <div class="table-responsive">
                {!! $slot !!}
            </div>
        </div>
    </div>
</div>
