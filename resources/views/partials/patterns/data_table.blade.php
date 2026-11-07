{{--
    PATTERN · data_table (Round 5 Slice 3, P11 — shared patterns)
    ---------------------------------------------------------------
    The one reusable data table: the house .hk-std standard
    (fixed layout, a colgroup whose widths sum to 100%, hk-nw on
    short fields, hk-ellip on long ones, a screen-only min-width
    inside the .table-responsive scrollport so phones scroll
    sideways instead of squeezing columns) as a component instead
    of a copy-pasted ritual. Replaces the hand-rolled table plus
    colgroup plus thead every list page used to re-invent, and
    retires the private colour-only skins (def95-table, l111-table
    and the l108-stack treatment on data lists) from the tables
    that migrate to it. Settled card surfaces (Circulars, student
    My Assignments, the leave queue) are not tables and never use
    this pattern.

    USAGE (Blade component with a default slot; pass the props in
    the component data array, write only the body rows as the
    slot content):

        component data:
            columns   (required) array of column definitions:
                        key      string, caller bookkeeping only
                        label    string, the header text
                        width    int, percent of the table width;
                                 every width together MUST sum
                                 to 100 (the component still
                                 renders when they do not — the
                                 per-page tests pin the 100 sum)
                        class    optional, hk-nw or hk-ellip,
                                 applied to the header cell
                        th_class optional, extra header-only
                                 classes (for example print-hide)
                        align    optional, right or center
                        sortable optional, the sort GET param
                                 value this column sorts by
            variant   (required) suffix of the min-width class:
                      the table carries hk-std and
                      hk-std-VARIANT; the matching rule
                      .table-responsive > .hk-std-VARIANT with
                      min-width MIN_WIDTH px lives in qs.css
                      under its screen media block and is added
                      once per variant, never per page
            min_width (required with variant) int, px — recorded
                      here as the contract for that qs.css rule;
                      the component itself emits no inline style
            caption   optional string, rendered as the table
                      caption element
            is_empty  optional bool — when true the body is ONE
                      row whose single cell spans every column
                      and renders the empty_state pattern (the
                      leave/my table-row precedent)
            empty     optional array in the empty_state prop
                      shape (icon, message, action_url,
                      action_label, or action_html), used only
                      when is_empty is true
            table_class optional string, extra classes appended
                      after the hk-std variant class (for example
                      table-striped)
            table_id  optional string, the table element id

        slot content:
            default   the tbody rows only, as tr elements; row
                      markup (badges, actions, forms) stays with
                      the caller — actions differ too much to
                      abstract
            tfoot     optional named slot: tr elements rendered
                      inside a tfoot element (totals rows)

    Sortable headers: a column with a sortable value renders its
    label as a link that toggles the sort and dir GET params,
    preserving the rest of the current query string (the current
    request input minus sort/dir, merged with the new pair). The
    active column shows its direction with a ▲ or ▼ glyph after
    the label. Sorting itself is whitelisted server-side per
    list; this pattern only renders the link.

    Wrapping: the component emits exactly ONE table-responsive
    wrapper around the table. Pages using the list_page shell
    must pass pat_no_wrap to that shell so the slot is not
    wrapped twice.

    Phone rule: hk-std plus sideways scroll is the default for
    data lists. The l108-stack card treatment is reserved for
    short, label-friendly record lists and is never combined
    with this component.
    Styles: public/assets/css/qs.css — the hk-std block (Batch 4)
    plus one screen min-width rule per variant.
--}}
@php
    $dtColumns = $columns ?? [];
    $dtVariant = isset($variant) && trim((string) $variant) !== '' ? trim((string) $variant) : null;
    $dtTableClass = 'table hk-std'
        . ($dtVariant !== null ? ' hk-std-' . $dtVariant : '')
        . (!empty($table_class ?? null) ? ' ' . $table_class : '');
    $dtIsEmpty = !empty($is_empty ?? null);
    $dtColCount = max(1, count($dtColumns));
    $dtSort = request('sort');
    $dtDir = strtolower((string) request('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
    $dtHasTfoot = isset($tfoot) && trim((string) $tfoot) !== '';
    $dtColgroup = '<colgroup>';
    foreach ($dtColumns as $dtColDef) {
        $dtColgroup .= isset($dtColDef['width'])
            ? '<col style="width:' . (int) $dtColDef['width'] . '%">'
            : '<col>';
    }
    $dtColgroup .= '</colgroup>';
@endphp
<div class="table-responsive">
    <table class="{{ $dtTableClass }}"@if(!empty($table_id ?? null)) id="{{ $table_id }}"@endif>
        @if(!empty($caption ?? null))
            <caption>{{ $caption }}</caption>
        @endif
        {!! $dtColgroup !!}
        <thead>
        <tr>
            @foreach($dtColumns as $dtCol)
                @php
                    $dtAlign = $dtCol['align'] ?? null;
                    $dtThClass = trim(implode(' ', array_filter([
                        $dtAlign === 'right' ? 'text-right' : ($dtAlign === 'center' ? 'text-center' : ''),
                        $dtCol['th_class'] ?? '',
                        $dtCol['class'] ?? '',
                    ])));
                    $dtSortable = $dtCol['sortable'] ?? null;
                    $dtIsActive = $dtSortable !== null && $dtSort !== null && (string) $dtSort === (string) $dtSortable;
                    $dtNextDir = $dtIsActive && $dtDir === 'asc' ? 'desc' : 'asc';
                    $dtHref = $dtSortable !== null
                        ? url()->current() . '?' . http_build_query(array_merge(request()->except(['sort', 'dir']), ['sort' => $dtSortable, 'dir' => $dtNextDir]))
                        : null;
                    $dtThAttrs = ($dtThClass !== '' ? ' class="' . $dtThClass . '"' : '')
                        . ($dtIsActive ? ' aria-sort="' . ($dtDir === 'asc' ? 'ascending' : 'descending') . '"' : '');
                @endphp
                <th{!! $dtThAttrs !!}>@if($dtSortable !== null)<a href="{{ $dtHref }}">{{ $dtCol['label'] ?? '' }}@if($dtIsActive) {{ $dtDir === 'asc' ? '▲' : '▼' }}@endif</a>@else{{ $dtCol['label'] ?? '' }}@endif</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @if($dtIsEmpty)
            <tr><td colspan="{{ $dtColCount }}">@include('partials.patterns.empty_state', $empty ?? ['message' => 'Nothing to show yet.'])</td></tr>
        @else
            {!! $slot ?? '' !!}
        @endif
        </tbody>
        @if($dtHasTfoot)
            <tfoot>{!! $tfoot !!}</tfoot>
        @endif
    </table>
</div>
