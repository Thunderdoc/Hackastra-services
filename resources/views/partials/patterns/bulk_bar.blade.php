{{--
    PATTERN · bulk_bar (Round 5 Slice 3, P10 — shared patterns)
    ---------------------------------------------------------------
    The one shared bulk-actions bar for list pages, generalising
    the two hand-rolled precedents: the students list bulk-delete
    bar (list.blade.php) and the SA feedback bulk-close bar
    (feedback/sa_index.blade.php, the audit's model implementation
    — live count + select-all + disabled-at-zero).

    CONTRACT (props passed in the component/include data):
        bulk_id      (required) DOM id prefix for this page's bar,
                     e.g. 'users'. Also scopes nothing by itself —
                     the JS module pairs ONE bar with the page's
                     .hk-bulk-check rows.
        bulk_actions (required) list of action definitions:
                       label          button text
                       form           id of the hidden POST (or GET,
                                      for the defaulters export) form
                                      THIS PAGE renders itself — one
                                      form per action, with @csrf.
                                      The partial renders NO forms:
                                      a form wrapping the table would
                                      nest the rows' own forms (invalid
                                      HTML — the students precedent).
                       class          optional button classes
                                      (default 'btn-light btn-sm')
                       icon           optional icon class, e.g.
                                      'icon-user-block'
                       confirm        optional message template; the
                                      literal {count} is replaced with
                                      the live selection count by the
                                      JS module at click time and the
                                      prompt goes through the P19
                                      standard window.hackstraConfirm.
                                      (The shared data-confirm bridge
                                      cannot substitute counts, so bulk
                                      buttons carry data-hk-bulk-confirm,
                                      never data-confirm.)
                       confirm_title  optional modal title
                       confirm_action optional modal confirm label
                       danger         optional bool — red confirm

    ROW CONTRACT (the calling page renders these):
        <input type="checkbox" class="hk-bulk-check" value="{{ $id }}">
    The checkbox carries NO name attribute — the JS module appends
    hidden ids[] inputs to the target form on submit (exactly the
    students precedent), so checked state never leaks into any
    other form on the page.

    SELECTION SCOPE — page only, said out loud: the bar states
    "Selection covers this page only." Server pagination means a
    tick is one rendered row; there is deliberately NO "select all
    matching the filter" claim (audit §10: that is a later,
    server-token step — never faked client-side).

    JS: the P10 section appended to public/assets/js/hackstra-ux.js
    (data-hk-bulk-bar) wires every bar on the page: select-page
    checkbox, live "N selected" count, action + Clear buttons
    disabled while N = 0, Clear unticks everything, and on an
    action click the module confirms (when asked) then injects
    ids[] into the action's form and submits it.
--}}
@php
    $bbId = isset($bulk_id) && trim((string) $bulk_id) !== '' ? trim((string) $bulk_id) : 'bulk';
    $bbActions = $bulk_actions ?? [];
@endphp
<div class="hk-bulk-bar print-hide d-flex align-items-center flex-wrap mb-2" data-hk-bulk-bar="{{ $bbId }}" style="gap:8px">
    <label class="mb-0 hk-bulk-select-page">
        <input type="checkbox" data-hk-bulk-select-all> Select page
    </label>
    <span class="text-muted" data-hk-bulk-count-wrap><span data-hk-bulk-count>0</span> selected</span>
    @foreach($bbActions as $bbAction)
        <button type="button"
                class="btn {{ $bbAction['class'] ?? 'btn-light btn-sm' }}"
                data-hk-bulk-action
                data-hk-bulk-form="{{ $bbAction['form'] }}"
                @if(!empty($bbAction['confirm'])) data-hk-bulk-confirm="{{ $bbAction['confirm'] }}" @endif
                @if(!empty($bbAction['confirm_title'])) data-hk-bulk-confirm-title="{{ $bbAction['confirm_title'] }}" @endif
                @if(!empty($bbAction['confirm_action'])) data-hk-bulk-confirm-action="{{ $bbAction['confirm_action'] }}" @endif
                @if(!empty($bbAction['danger'])) data-hk-bulk-danger="1" @endif
                disabled>
            @if(!empty($bbAction['icon']))<i class="{{ $bbAction['icon'] }} mr-1"></i>@endif{{ $bbAction['label'] }}
        </button>
    @endforeach
    <button type="button" class="btn btn-light btn-sm" data-hk-bulk-clear disabled>Clear</button>
    <span class="text-muted">Selection covers this page only.</span>
</div>
