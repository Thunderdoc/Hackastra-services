{{--
    DD/MM/YYYY date inputs (owner order 2026-10-29, second pass).

    A native <input type="date"> takes its display format from
    the BROWSER's language, not the site's — Chrome set to US
    English shows mm/dd/yyyy no matter what the page asks for,
    and the owner ordered date/month/year everywhere. So every
    date input is converted in place to a text input that always
    shows DD/MM/YYYY, while a hidden sibling keeps posting the
    ISO value (yyyy-mm-dd) every form and script already expects.
    The visible input's .value property is bridged to the ISO
    state, so page scripts that read or set .value (report
    builder filters, leave form) keep working untouched.
--}}
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

    function transform(input) {
        if (input.dataset.hkDate === '1') { return; }
        input.dataset.hkDate = '1';

        var state = { iso: '' };
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        if (input.name) { hidden.name = input.name; }
        input.removeAttribute('name');
        input.parentNode.insertBefore(hidden, input.nextSibling);

        var minIso = input.getAttribute('min') || '';
        var maxIso = input.getAttribute('max') || '';
        input.removeAttribute('min');
        input.removeAttribute('max');

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
