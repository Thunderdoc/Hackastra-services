/*
 * Hackstra global click/UX layer (UI_UX_SWEEP_PLAN.md, Loop 1).
 * 1) Double-submit guard: a form's submit button shows a loading state
 *    and cannot be clicked twice. GET (search/filter) forms are exempt.
 * 2) data-confirm: any element with data-confirm="Message?" asks first.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.method && form.method.toLowerCase() === 'get') { return; }
        if (form.hasAttribute('data-no-loading')) { return; }
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        buttons.forEach(function (button) {
            if (button.disabled) { event.preventDefault(); return; }
            button.classList.add('is-loading');
            // Delay disabling by a tick so the button's own name/value
            // is still included in the submitted form data.
            window.setTimeout(function () { button.disabled = true; }, 0);
        });
    });

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-confirm]');
        if (!trigger) { return; }
        var message = trigger.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(message)) {
            event.preventDefault();
            event.stopPropagation();
        }
    });
})();
