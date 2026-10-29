{{--
    DD/MM/YYYY date inputs (owner order 2026-10-29, second pass).

    A native date input takes its display format from the
    BROWSER's language, not the site's — Chrome set to US
    English shows mm/dd/yyyy no matter what the page asks for,
    and the owner ordered date/month/year everywhere. So every
    date input is converted in place to a text input that always
    shows DD/MM/YYYY, while a hidden sibling keeps posting the
    ISO value (yyyy-mm-dd) every form and script already expects.
    The visible input's .value property is bridged to the ISO
    state, so page scripts that read or set .value (report
    builder filters, leave form) keep working untouched.

    Calendar (owner, 2026-10-29 night — "isme calendar kyu hata
    diya"): the text conversion took the native calendar popup
    away, so each converted field also gains a calendar button
    that opens a month popup — pick a day with the mouse, or
    keep typing with the DD/MM/YYYY mask. Both paths write the
    same bridged value.
--}}
<style>
    .hkdp-wrap { position: relative; display: block; }
    .hkdp-wrap > input { padding-right: 34px; }
    .hkdp-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: transparent; color: #7a8ca3; cursor: pointer; padding: 4px 6px; font-size: 15px; line-height: 1; }
    .hkdp-btn:hover { color: #1765f5; }
    .hkdp-pop { position: fixed; z-index: 2050; background: #fff; color: #10263b; border: 1px solid #c9d6e4; border-radius: 12px; box-shadow: 0 12px 32px rgba(16, 38, 59, .25); padding: 10px; width: 252px; font-size: 13px; }
    .hkdp-head { display: flex; justify-content: space-between; align-items: center; font-weight: 600; margin-bottom: 6px; }
    .hkdp-nav { border: 0; background: #eef4ff; color: #1765f5; width: 26px; height: 26px; border-radius: 7px; cursor: pointer; font-size: 15px; line-height: 1; }
    .hkdp-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; text-align: center; }
    .hkdp-dow { color: #718096; font-size: 11px; font-weight: 600; padding: 3px 0; }
    .hkdp-day { border: 0; background: transparent; padding: 5px 0; border-radius: 7px; cursor: pointer; color: #10263b; font-size: 13px; }
    .hkdp-day:hover { background: #eef4ff; }
    .hkdp-day.hkdp-today { box-shadow: inset 0 0 0 1px #1765f5; }
    .hkdp-day.hkdp-sel { background: #1765f5; color: #fff; }
    .hkdp-day:disabled { color: #c3cedd; cursor: default; background: transparent; }
    @media print { .hkdp-btn, .hkdp-pop { display: none !important; } }
</style>
<script>
(function () {
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function isoToDisplay(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '';
    }
    function displayToIso(text) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec((text || '').trim());
        if (!m) { return null; }
        var d = parseInt(m[1], 10), mo = parseInt(m[2], 10), y = parseInt(m[3], 10);
        if (y < 1900 || y > 2100 || mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }
        var dt = new Date(Date.UTC(y, mo - 1, d));
        if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) { return null; }
        return y + '-' + pad(mo) + '-' + pad(d);
    }
    function mask(text) {
        var digits = (text || '').replace(/\D/g, '').slice(0, 8);
        var out = digits.slice(0, 2);
        if (digits.length > 2) { out += '/' + digits.slice(2, 4); }
        if (digits.length > 4) { out += '/' + digits.slice(4, 8); }
        return out;
    }

    /* ---- Calendar popup (one shared element for the page) ---- */
    var pop = null, popInput = null;
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function closePop() {
        if (pop) { pop.style.display = 'none'; }
        popInput = null;
    }
    function ensurePop() {
        if (pop) { return pop; }
        pop = document.createElement('div');
        pop.className = 'hkdp-pop';
        pop.style.display = 'none';
        document.body.appendChild(pop);
        document.addEventListener('mousedown', function (e) {
            if (!popInput || pop.style.display === 'none') { return; }
            if (pop.contains(e.target)) { return; }
            var btn = popInput._hkBtn;
            if (btn && (e.target === btn || btn.contains(e.target))) { return; }
            if (e.target === popInput) { return; }
            closePop();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closePop(); }
        });
        window.addEventListener('resize', closePop);
        return pop;
    }
    function renderPop(y, m) {
        var input = popInput;
        var minIso = input.dataset.hkMin || '';
        var maxIso = input.dataset.hkMax || '';
        var sel = input.value || '';
        var first = new Date(y, m, 1);
        var startOffset = (first.getDay() + 6) % 7; /* Monday first */
        var days = new Date(y, m + 1, 0).getDate();
        var now = new Date();
        var todayIso = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
        var html = '<div class="hkdp-head"><button type="button" class="hkdp-nav" data-nav="-1">&#8249;</button><span>' + MONTHS[m] + ' ' + y + '</span><button type="button" class="hkdp-nav" data-nav="1">&#8250;</button></div>';
        html += '<div class="hkdp-grid">';
        ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'].forEach(function (d) { html += '<span class="hkdp-dow">' + d + '</span>'; });
        for (var i = 0; i < startOffset; i++) { html += '<span></span>'; }
        for (var d = 1; d <= days; d++) {
            var iso = y + '-' + pad(m + 1) + '-' + pad(d);
            var dis = (minIso && iso < minIso) || (maxIso && iso > maxIso);
            html += '<button type="button" class="hkdp-day' + (iso === sel ? ' hkdp-sel' : '') + (iso === todayIso ? ' hkdp-today' : '') + '"' + (dis ? ' disabled' : '') + ' data-iso="' + iso + '">' + d + '</button>';
        }
        html += '</div>';
        pop.innerHTML = html;
        pop.querySelectorAll('[data-nav]').forEach(function (b) {
            b.addEventListener('click', function () {
                var nm = m + parseInt(b.getAttribute('data-nav'), 10);
                renderPop(y + Math.floor(nm / 12), ((nm % 12) + 12) % 12);
            });
        });
        pop.querySelectorAll('[data-iso]').forEach(function (b) {
            b.addEventListener('click', function () {
                input.value = b.getAttribute('data-iso');
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                closePop();
            });
        });
    }
    function openPop(input) {
        ensurePop();
        if (popInput === input && pop.style.display !== 'none') { closePop(); return; }
        popInput = input;
        var cur = input.value || '';
        var base = /^\d{4}-\d{2}-\d{2}/.test(cur) ? new Date(cur.slice(0, 10) + 'T00:00:00') : new Date();
        renderPop(base.getFullYear(), base.getMonth());
        var r = input.getBoundingClientRect();
        pop.style.display = 'block';
        var w = 252;
        pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - w - 8)) + 'px';
        var yPos = r.bottom + 6;
        if (yPos + 300 > window.innerHeight) { yPos = Math.max(8, r.top - 306); }
        pop.style.top = yPos + 'px';
    }

    function transform(input) {
        if (input.dataset.hkDate === '1') { return; }
        input.dataset.hkDate = '1';

        var minIso = input.getAttribute('min') || '';
        var maxIso = input.getAttribute('max') || '';
        input.removeAttribute('min');
        input.removeAttribute('max');
        input.dataset.hkMin = minIso;
        input.dataset.hkMax = maxIso;

        /* Wrap the field and add the calendar button (see the
           header note). The wrap is a block span, so form layouts
           keep their shape; the hidden sibling lands inside it,
           which changes nothing for the post. */
        var wrap = document.createElement('span');
        wrap.className = 'hkdp-wrap';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        var calBtn = document.createElement('button');
        calBtn.type = 'button';
        calBtn.className = 'hkdp-btn';
        calBtn.tabIndex = -1;
        calBtn.setAttribute('aria-label', 'Open calendar');
        calBtn.innerHTML = '<i class="icon-calendar52"></i>';
        input._hkBtn = calBtn;
        calBtn.addEventListener('click', function (e) { e.preventDefault(); openPop(input); });
        wrap.appendChild(calBtn);

        var state = { iso: '' };
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        if (input.name) { hidden.name = input.name; }
        input.removeAttribute('name');
        input.parentNode.insertBefore(hidden, input.nextSibling);

        function render() {
            input.value = isoToDisplay(state.iso);
            hidden.value = state.iso;
        }
        function commit(text) {
            if (!text || !text.trim()) {
                state.iso = '';
                input.classList.remove('is-invalid');
                hidden.value = '';
                return true;
            }
            var iso = displayToIso(text);
            if (iso === null || (minIso && iso < minIso) || (maxIso && iso > maxIso)) {
                input.classList.add('is-invalid');
                hidden.value = '';
                return false;
            }
            input.classList.remove('is-invalid');
            state.iso = iso;
            hidden.value = iso;
            return true;
        }

        // .value now speaks ISO in both directions: scripts that
        // read it get yyyy-mm-dd; scripts that set it (an ISO
        // string, or a DD/MM/YYYY string) see the field render
        // as DD/MM/YYYY.
        Object.defineProperty(input, 'value', {
            configurable: true,
            get: function () { return state.iso; },
            set: function (v) {
                v = (v === null || v === undefined) ? '' : String(v);
                if (/^\d{4}-\d{2}-\d{2}/.test(v)) { state.iso = v.slice(0, 10); }
                else {
                    var iso = displayToIso(v);
                    state.iso = iso === null ? '' : iso;
                }
                hidden.value = state.iso;
                // Write the visible text without tripping this setter.
                Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')
                    .set.call(input, isoToDisplay(state.iso));
            }
        });

        input.addEventListener('input', function () {
            var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
            var raw = proto.get.call(input);
            var masked = mask(raw);
            if (masked !== raw) { proto.set.call(input, masked); }
            if (masked.length === 10) { commit(masked); }
            else { hidden.value = ''; }
        });
        input.addEventListener('change', function () {
            var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
            commit(proto.get.call(input));
            render();
        });
        input.addEventListener('blur', function () {
            var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
            if (commit(proto.get.call(input))) { render(); }
        });

        var initial = input.getAttribute('value') || '';
        input.setAttribute('type', 'text');
        input.setAttribute('placeholder', 'DD/MM/YYYY');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'off');
        input.removeAttribute('value');
        if (initial) { input.value = initial; }
    }

    function apply(root) {
        (root || document).querySelectorAll('input[type="date"]').forEach(transform);
    }

    window.HkDates = { apply: apply };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { apply(document); });
    } else {
        apply(document);
    }
})();
</script>
