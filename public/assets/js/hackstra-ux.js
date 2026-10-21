/*
 * Hackstra global click/UX layer (UI_UX_SWEEP_PLAN.md, Loop 1 + Loop 37).
 * 1) Styled confirmations (Loop 37): the browser's native confirmation
 *    dialog is never used. Any element or form carrying
 *    data-confirm="Message?" (optional data-confirm-title,
 *    data-confirm-action, data-confirm-danger) is confirmed through the
 *    in-app modal in partials/confirm_modal. Page scripts may call
 *    window.hackstraConfirm(message, opts) and get a Promise<boolean>.
 * 2) Double-submit guard: a form's submit button shows a loading state
 *    and cannot be clicked twice. GET (search/filter) forms are exempt.
 *    The confirmation interceptor is registered BEFORE the guard so a
 *    form waiting on the modal never enters its loading state.
 */
(function () {
    'use strict';

    /* ---------------- Styled confirmation modal ---------------- */

    var modalEl = null;       // #hackstra-confirm-modal element
    var parts = null;         // cached modal sub-elements
    var settlePending = null; // resolver of the currently open prompt
    var pendingOk = false;    // true only when the OK button was clicked
    var confirmedTriggers = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;

    function getParts() {
        if (parts) { return parts; }
        modalEl = document.getElementById('hackstra-confirm-modal');
        if (!modalEl) { return null; }
        parts = {
            title: modalEl.querySelector('#hxc-title'),
            message: modalEl.querySelector('#hxc-message'),
            iconWrap: modalEl.querySelector('#hxc-icon'),
            iconGlyph: modalEl.querySelector('#hxc-glyph'),
            ok: modalEl.querySelector('#hxc-ok'),
            cancel: modalEl.querySelector('.hxc-cancel')
        };
        return parts;
    }

    function optsFrom(el, fallback) {
        var opts = fallback || {};
        if (!el) { return opts; }
        var title = el.getAttribute('data-confirm-title');
        var action = el.getAttribute('data-confirm-action');
        if (title) { opts.title = title; }
        if (action) { opts.action = action; }
        if (el.hasAttribute('data-confirm-danger')) { opts.danger = true; }
        return opts;
    }

    function settle(value) {
        var resolve = settlePending;
        settlePending = null;
        if (resolve) { resolve(value); }
    }

    window.hackstraConfirm = function (message, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var p = getParts();
            if (!p || !window.jQuery || !window.jQuery.fn || !window.jQuery.fn.modal) {
                // Modal unavailable: fail safe — the action is NOT taken.
                resolve(false);
                return;
            }
            // A new prompt supersedes any prompt still open.
            if (settlePending) {
                settle(false);
                window.jQuery(modalEl).modal('hide');
            }
            settlePending = resolve;
            pendingOk = false;

            p.title.textContent = opts.title || 'Are you sure?';
            p.message.textContent = message || 'Are you sure?';
            p.ok.textContent = opts.action || 'Confirm';
            if (opts.danger) {
                modalEl.classList.add('hxc-danger');
                p.ok.classList.remove('btn-primary');
                p.ok.classList.add('btn-danger');
                p.iconGlyph.className = 'icon-warning';
            } else {
                modalEl.classList.remove('hxc-danger');
                p.ok.classList.remove('btn-danger');
                p.ok.classList.add('btn-primary');
                p.iconGlyph.className = 'icon-question6';
            }
            window.jQuery(modalEl).modal('show');
        });
    };

    // Wire the modal's buttons/events once. This file loads at the end
    // of <body>, so the modal markup already exists.
    (function wireModal() {
        var p = getParts();
        if (!p || !window.jQuery) { return; }
        p.ok.addEventListener('click', function () {
            pendingOk = true;
            window.jQuery(modalEl).modal('hide');
        });
        window.jQuery(modalEl).on('hidden.bs.modal', function () {
            settle(pendingOk);
            pendingOk = false;
        });
        window.jQuery(modalEl).on('shown.bs.modal', function () {
            // Destructive prompts land on Cancel; safe ones on Confirm.
            var target = modalEl.classList.contains('hxc-danger') ? p.cancel : p.ok;
            if (target && target.focus) { target.focus(); }
        });
    })();

    function submitForm(form, submitter) {
        form.dataset.hxcConfirmed = '1';
        if (form.requestSubmit) {
            try {
                if (submitter) { form.requestSubmit(submitter); return; }
                form.requestSubmit();
                return;
            } catch (e) { /* fall through to form.submit() */ }
        }
        form.submit();
    }

    function proceedAfterConfirm(trigger) {
        var formSelector = trigger.getAttribute('data-confirm-form');
        if (formSelector) {
            var target = document.querySelector(formSelector);
            if (target) { submitForm(target, null); }
            return;
        }
        if (trigger.tagName === 'A') {
            var href = trigger.getAttribute('href');
            if (href && href !== '#') { window.location.href = href; }
            return;
        }
        var form = trigger.closest ? trigger.closest('form') : null;
        if (form) {
            var isSubmitter = (trigger.type === 'submit') ||
                (trigger.tagName === 'BUTTON' && (trigger.type === '' || trigger.type === 'submit'));
            submitForm(form, isSubmitter ? trigger : null);
            return;
        }
        // A standalone button: replay its click once, bypassing this
        // interceptor, so the page's own handlers can run.
        if (confirmedTriggers) { confirmedTriggers.add(trigger); }
        trigger.click();
    }

    /* Click pattern: buttons / links carrying data-confirm. */
    document.addEventListener('click', function (event) {
        var trigger = event.target.closest ? event.target.closest('[data-confirm]') : null;
        if (!trigger) { return; }
        if (confirmedTriggers && confirmedTriggers.has(trigger)) { return; }
        if (trigger.tagName === 'FORM') { return; } // the submit listener owns forms
        event.preventDefault();
        event.stopPropagation();
        var message = trigger.getAttribute('data-confirm') || 'Are you sure?';
        window.hackstraConfirm(message, optsFrom(trigger)).then(function (ok) {
            if (ok) { proceedAfterConfirm(trigger); }
        });
    });

    /* Submit pattern: forms carrying data-confirm. Registered before
       the double-submit guard below; stopImmediatePropagation keeps a
       form parked on the modal out of the guard's loading state. */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute || !form.hasAttribute('data-confirm')) { return; }
        if (form.dataset.hxcConfirmed === '1') {
            delete form.dataset.hxcConfirmed;
            return; // already confirmed: let it through untouched
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var submitter = event.submitter || null;
        var message = form.getAttribute('data-confirm') || 'Are you sure?';
        window.hackstraConfirm(message, optsFrom(form)).then(function (ok) {
            if (ok) { submitForm(form, submitter); }
        });
    });

    /* ---------------- Double-submit guard (Loop 1) ---------------- */

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
})();
