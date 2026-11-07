{{--
    Round 5 Slice 2 (P9): HkValidate — a live HINT layer only.

    Server-side validation stays the only authority. This script
    never blocks a submit and never marks a value truly valid; on
    blur it checks a field against the constraints already written
    on the element itself (required, minlength, maxlength, min,
    max, type, pattern, plus any data-v- attributes a form already
    carries) and writes a plain hint into the field's field-error
    slot (the span the shared errors field partial renders, or one
    created here). Once a field has shown a hint, it re-checks on
    input. Text the server rendered into a slot after a failed
    submit is left alone until the user actually interacts with
    that field. Included once from the master layout, next to the
    hk_date partial. Dependency-free vanilla JS.
--}}
<script>
(function () {
    if (!document || !document.addEventListener) { return; }

    var shown = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;
    var shownList = [];
    function markShown(el) { if (shown) { shown.add(el); } else if (shownList.indexOf(el) === -1) { shownList.push(el); } }
    function isShown(el) { return shown ? shown.has(el) : shownList.indexOf(el) !== -1; }
    function unmarkShown(el) { if (shown) { shown.delete(el); } var i = shownList.indexOf(el); if (i !== -1) { shownList.splice(i, 1); } }

    function labelFor(el) {
        var text = '';
        if (el.id) {
            var lab = document.querySelector('label[for="' + el.id + '"]');
            if (lab) { text = (lab.textContent || '').replace(/\s+/g, ' ').trim(); }
        }
        if (!text) {
            var wrapLab = el.closest ? el.closest('label') : null;
            if (wrapLab) { text = (wrapLab.textContent || '').replace(/\s+/g, ' ').trim(); }
        }
        text = text.replace(/\s*\*\s*$/, '').replace(/:$/, '').trim();
        if (text.length > 60) { text = ''; }
        return text;
    }

    function anchorOf(el) {
        var p = el.parentNode;
        if (p && p.classList && p.classList.contains('hkdp-wrap')) { return p; }
        return el;
    }

    function slotFor(el, create) {
        var anchor = anchorOf(el);
        var n = anchor.nextElementSibling;
        while (n) {
            if (n.classList && n.classList.contains('field-error')) { return n; }
            if (n.tagName === 'INPUT' || n.tagName === 'SELECT' || n.tagName === 'TEXTAREA') { break; }
            n = n.nextElementSibling;
        }
        var box = el.closest ? (el.closest('.form-group, .pat-field, .form-row') || el.parentNode) : el.parentNode;
        if (box && box.querySelectorAll) {
            var controls = box.querySelectorAll('input[name], select[name], textarea[name]');
            if (controls.length <= 1) {
                var s = box.querySelector('.field-error');
                if (s) { return s; }
            }
        }
        if (!create) { return null; }
        var span = document.createElement('span');
        span.className = 'field-error';
        span.setAttribute('role', 'alert');
        span.setAttribute('data-hk-hint', '1');
        anchor.parentNode.insertBefore(span, anchor.nextSibling);
        return span;
    }

    function hintFor(el) {
        var label = labelFor(el);
        var name = label || 'This field';
        var value = (el.value === null || el.value === undefined) ? '' : String(el.value);
        var trimmed = value.trim();
        var required = el.required || el.getAttribute('data-v-required') !== null;
        if (required && trimmed === '') { return name + ' is required'; }
        if (trimmed === '') { return ''; }

        var type = (el.getAttribute('type') || '').toLowerCase();
        if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed)) {
            return 'Enter a valid email address';
        }
        if (type === 'url' && !/^[a-z][a-z0-9+.-]*:\/\/\S+$/i.test(trimmed) && trimmed.indexOf('.') === -1) {
            return 'Enter a valid web address';
        }

        var minLen = el.getAttribute('minlength') || el.getAttribute('data-v-minlength');
        if (minLen !== null && minLen !== '' && trimmed.length < parseInt(minLen, 10)) {
            return 'Must be at least ' + minLen + ' characters';
        }
        var maxLen = el.getAttribute('maxlength') || el.getAttribute('data-v-maxlength');
        if (maxLen !== null && maxLen !== '' && trimmed.length > parseInt(maxLen, 10)) {
            return 'Must be at most ' + maxLen + ' characters';
        }

        var min = el.getAttribute('min') || el.getAttribute('data-v-min');
        var max = el.getAttribute('max') || el.getAttribute('data-v-max');
        if (type === 'number') {
            var num = Number(trimmed);
            if (isNaN(num)) { return 'Enter a number'; }
            if (min !== null && min !== '' && num < Number(min)) { return 'Must be at least ' + min; }
            if (max !== null && max !== '' && num > Number(max)) { return 'Must be at most ' + max; }
        } else {
            if (min !== null && min !== '' && trimmed < String(min)) { return name + ' must be on or after ' + min; }
            if (max !== null && max !== '' && trimmed > String(max)) { return name + ' must be on or before ' + max; }
        }

        var pattern = el.getAttribute('pattern') || el.getAttribute('data-v-pattern');
        if (pattern) {
            try {
                if (!(new RegExp('^(?:' + pattern + ')$')).test(value)) {
                    return name + ' does not match the required format';
                }
            } catch (e) { /* a pattern we cannot compile is not a hint */ }
        }
        return '';
    }

    function showHint(el, msg) {
        var slot = slotFor(el, true);
        if (slot) { slot.textContent = msg; slot.setAttribute('data-hk-hint', '1'); }
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        markShown(el);
    }

    function clearHint(el, force) {
        var slot = slotFor(el, false);
        if (slot && (force || slot.getAttribute('data-hk-hint') === '1')) {
            slot.textContent = '';
            slot.removeAttribute('data-hk-hint');
        }
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
        unmarkShown(el);
    }

    function validateField(el, fromBlur) {
        var msg = hintFor(el);
        if (msg) { showHint(el, msg); return false; }
        /* Valid by the element's own constraints: clear our hint,
           and clear a server message only once the user has
           actually edited this field. */
        clearHint(el, el._hkDirty === true);
        return true;
    }

    function eligible(el) {
        if (!el || !el.tagName) { return false; }
        if (el.tagName !== 'INPUT' && el.tagName !== 'TEXTAREA' && el.tagName !== 'SELECT') { return false; }
        if (el.disabled || el.readOnly) { return false; }
        var type = (el.getAttribute('type') || '').toLowerCase();
        if (['hidden', 'submit', 'button', 'reset', 'file', 'image', 'checkbox', 'radio'].indexOf(type) !== -1) { return false; }
        if (!el.closest || !el.closest('form')) { return false; }
        return true;
    }

    document.addEventListener('focus', function (e) {
        if (eligible(e.target)) { e.target._hkDirty = false; }
    }, true);

    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!eligible(el)) { return; }
        el._hkDirty = true;
        if (isShown(el)) { validateField(el, false); }
    }, true);

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!eligible(el)) { return; }
        if (el.tagName === 'SELECT') {
            el._hkDirty = true;
            if (isShown(el)) { validateField(el, false); }
        }
    }, true);

    document.addEventListener('blur', function (e) {
        var el = e.target;
        if (!eligible(el)) { return; }
        validateField(el, true);
    }, true);

    window.HkValidate = { validateField: validateField, hintFor: hintFor };
})();
</script>
