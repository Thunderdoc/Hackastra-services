{{--
    partials/command_palette — the command palette (Round 5
    Slice 4, P3). Included ONCE from layouts/master, beside the
    shortcuts overlay.

    A Bootstrap modal in the confirm-modal / shortcuts-overlay
    idiom (site style only; colours live in the css-p3 fragment,
    both themes). Opened by Ctrl/Cmd+K through the Slice-1
    shortcut registry (window.hkOpenPalette below is what the
    registry calls), or by clicking the topbar search input.
    The topbar form itself is untouched as the no-JS fallback:
    it still submits to the full search page.

    Behaviour: typing debounces ~200ms to GET
    /dashboard/search.json?q= (route name dashboard.search_json).
    Pages/Actions arrive as the role's full server-built
    catalogue and are filtered CLIENT-side with subsequence
    fuzzy scoring; Students/Staff/Classes/Payments are matched
    server-side by the shared DashboardSearch query (the same
    one the HTML search page renders, scoping included).
    Empty query → the Pages+Actions catalogue. ↑/↓ move, Enter
    opens, Esc closes and returns focus to the topbar search.
--}}
<div class="modal fade hkpal-modal" id="hk-palette-modal" tabindex="-1" role="dialog" aria-labelledby="hk-palette-title" aria-hidden="true" data-hk-palette-url="{{ url('dashboard/search.json') }}">
    <div class="modal-dialog modal-dialog-centered hkpal-dialog" role="document">
        <div class="modal-content hkpal-content">
            <h5 class="sr-only" id="hk-palette-title">Command palette</h5>
            <div class="hkpal-head">
                <i class="icon-search4" aria-hidden="true"></i>
                <input type="text" id="hk-palette-input" class="hkpal-input" placeholder="Search pages, actions, students, staff…" autocomplete="off" spellcheck="false"
                       role="combobox" aria-expanded="true" aria-controls="hk-palette-results" aria-activedescendant="" aria-label="Search pages, actions and records">
                <button type="button" class="close hkpal-close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="hkpal-results" id="hk-palette-results" role="listbox" aria-label="Search results"></div>
            <div class="hkpal-foot" aria-hidden="true">
                <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> to move</span>
                <span><kbd>Enter</kbd> to open</span>
                <span><kbd>Esc</kbd> to close</span>
            </div>
        </div>
    </div>
</div>
{{-- No-results state: the shared compact empty-state pattern,
     cloned into the results box by the script when a query
     matches nothing in any group. --}}
<div class="hkpal-empty-template" hidden>
    @include('partials.patterns.empty_state', ['icon' => 'icon-search4', 'message' => 'Nothing matches — try a different search.', 'compact' => true])
</div>
<script>
    (function () {
        'use strict';

        var modal = document.getElementById('hk-palette-modal');
        var input = document.getElementById('hk-palette-input');
        var box = document.getElementById('hk-palette-results');
        if (!modal || !input || !box) { return; }
        var endpoint = modal.getAttribute('data-hk-palette-url');
        var emptyTemplate = document.querySelector('.hkpal-empty-template');

        var GROUPS = [
            { key: 'pages', label: 'Pages' },
            { key: 'actions', label: 'Actions' },
            { key: 'students', label: 'Students' },
            { key: 'staff', label: 'Staff' },
            { key: 'classes', label: 'Classes' },
            { key: 'payments', label: 'Payments' }
        ];
        var STATIC_KEYS = { pages: true, actions: true };

        var payload = { pages: [], actions: [], students: [], staff: [], classes: [], payments: [] };
        var rows = [];        /* flat list of rendered row elements, in order */
        var active = -1;
        var requestSeq = 0;
        var debounceTimer = null;
        var modalEventsBound = false;

        /* Subsequence fuzzy scoring for the static Pages/Actions
           lists only: every query char must appear in order.
           Earlier starts and tighter runs score higher. Returns
           null on no match. */
        function fuzzyScore(label, query) {
            var text = label.toLowerCase();
            var q = query.toLowerCase();
            if (!q) { return 0; }
            var ti = 0, score = 0, last = -1;
            for (var qi = 0; qi < q.length; qi++) {
                var found = text.indexOf(q.charAt(qi), ti);
                if (found === -1) { return null; }
                score += (last === -1) ? (100 - found) : (found === last + 1 ? 50 : Math.max(1, 30 - (found - last)));
                last = found;
                ti = found + 1;
            }
            return score;
        }

        function visibleItems(groupKey, query) {
            var items = payload[groupKey] || [];
            if (!STATIC_KEYS[groupKey]) { return items; }
            if (!query) { return items; }
            return items.map(function (item) {
                return { item: item, score: fuzzyScore(item.label || '', query) };
            }).filter(function (scored) {
                return scored.score !== null;
            }).sort(function (a, b) {
                return b.score - a.score;
            }).map(function (scored) { return scored.item; });
        }

        function setActive(index) {
            if (!rows.length) { active = -1; input.setAttribute('aria-activedescendant', ''); return; }
            active = Math.max(0, Math.min(index, rows.length - 1));
            rows.forEach(function (row, i) {
                var on = i === active;
                row.classList.toggle('hkpal-active', on);
                row.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            input.setAttribute('aria-activedescendant', rows[active].id);
            if (rows[active].scrollIntoView) { rows[active].scrollIntoView({ block: 'nearest' }); }
        }

        function makeRow(item) {
            var row = document.createElement(item.url ? 'a' : 'div');
            row.className = 'hkpal-row';
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', 'false');
            row.id = 'hkpal-opt-' + rows.length;
            if (item.url) {
                row.href = item.url;
                row.setAttribute('data-hkpal-url', item.url);
            }
            var icon = document.createElement('span');
            icon.className = 'hkpal-icon';
            icon.setAttribute('aria-hidden', 'true');
            var glyph = document.createElement('i');
            glyph.className = item.icon || 'icon-file-text2';
            icon.appendChild(glyph);
            row.appendChild(icon);
            var body = document.createElement('span');
            body.className = 'hkpal-row-body';
            var label = document.createElement('span');
            label.className = 'hkpal-row-label';
            label.textContent = item.label || '';
            body.appendChild(label);
            if (item.meta) {
                var meta = document.createElement('small');
                meta.className = 'hkpal-row-meta';
                meta.textContent = item.meta;
                body.appendChild(meta);
            }
            row.appendChild(body);
            rows.push(row);
            return row;
        }

        function render() {
            var query = input.value.trim();
            box.innerHTML = '';
            rows = [];
            active = -1;

            GROUPS.forEach(function (group) {
                var items = visibleItems(group.key, query);
                if (!items.length) { return; }
                var head = document.createElement('div');
                head.className = 'hkpal-group';
                head.textContent = group.label;
                head.setAttribute('role', 'presentation');
                box.appendChild(head);
                items.forEach(function (item) { box.appendChild(makeRow(item)); });
            });

            if (query.length === 1) {
                var hint = document.createElement('div');
                hint.className = 'hkpal-hint';
                hint.textContent = 'Type at least 2 characters to search students, staff, classes and payments.';
                box.appendChild(hint);
            }

            if (!rows.length) {
                if (emptyTemplate) {
                    var clone = emptyTemplate.cloneNode(true);
                    clone.removeAttribute('hidden');
                    clone.classList.remove('hkpal-empty-template');
                    box.appendChild(clone);
                }
            } else {
                setActive(0);
            }
        }

        function load() {
            var seq = ++requestSeq;
            var url = endpoint + '?q=' + encodeURIComponent(input.value.trim());
            fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (resp) {
                if (!resp.ok) { throw new Error('palette search failed'); }
                return resp.json();
            }).then(function (data) {
                if (seq !== requestSeq) { return; } /* a newer keystroke owns the box */
                payload = {
                    pages: data.pages || [], actions: data.actions || [],
                    students: data.students || [], staff: data.staff || [],
                    classes: data.classes || [], payments: data.payments || []
                };
                render();
            }).catch(function () {
                if (seq !== requestSeq) { return; }
                /* A failed fetch must not strand the user: the
                   static catalogue from the last good payload (if
                   any) still renders, and the no-JS/full-page
                   search remains one Enter away in the topbar. */
                render();
            });
        }

        function scheduleLoad() {
            if (debounceTimer) { window.clearTimeout(debounceTimer); }
            /* Pages/Actions filter instantly client-side while the
               server groups catch up on the debounce. */
            render();
            debounceTimer = window.setTimeout(load, 200);
        }

        function closePalette() {
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
                window.jQuery(modal).modal('hide');
            }
        }

        function bindModalEvents() {
            if (modalEventsBound || !window.jQuery) { return; }
            modalEventsBound = true;
            window.jQuery(modal).on('shown.bs.modal', function () {
                input.value = '';
                input.focus();
                load();
            });
            window.jQuery(modal).on('hidden.bs.modal', function () {
                /* Esc / close returns focus to the trigger. */
                var trigger = document.getElementById('lav-global-search');
                if (trigger) { trigger.focus(); }
            });
        }

        window.hkOpenPalette = function () {
            bindModalEvents();
            if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
                window.jQuery(modal).modal('show');
                /* If the modal was already open, shown.bs.modal
                   does not re-fire — refresh in place instead. */
                if (modal.classList.contains('show')) { input.focus(); load(); }
            } else {
                /* No Bootstrap on the page: fall back to the
                   topbar search, Slice 1's original behaviour. */
                var fallback = document.getElementById('lav-global-search');
                if (fallback) { fallback.focus(); fallback.select(); }
            }
        };

        input.addEventListener('input', scheduleLoad);
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(active - 1);
            } else if (event.key === 'Enter') {
                if (active >= 0 && rows[active]) {
                    var url = rows[active].getAttribute('data-hkpal-url');
                    if (url) { event.preventDefault(); window.location.href = url; }
                }
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                closePalette();
            }
        });

        box.addEventListener('click', function (event) {
            var row = event.target && event.target.closest ? event.target.closest('.hkpal-row') : null;
            if (!row) { return; }
            var url = row.getAttribute('data-hkpal-url');
            if (url) { event.preventDefault(); window.location.href = url; }
        });
        box.addEventListener('mousemove', function (event) {
            var row = event.target && event.target.closest ? event.target.closest('.hkpal-row') : null;
            if (!row) { return; }
            var index = rows.indexOf(row);
            if (index !== -1 && index !== active) { setActive(index); }
        });

        /* Topbar affordance: with JS on, clicking the topbar
           search input opens the palette instead of typing into
           it. The form itself is untouched — with JS off (or if
           this script never runs) it still submits to the search
           page exactly as before. Programmatic focus (the Esc
           return path above) does not fire click, so closing the
           palette never reopens it. */
        var topSearch = document.getElementById('lav-global-search');
        if (topSearch) {
            topSearch.addEventListener('click', function (event) {
                event.preventDefault();
                window.hkOpenPalette();
            });
        }
    })();
</script>
