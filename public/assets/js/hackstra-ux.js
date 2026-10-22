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

/*
 * 3) Sidebar scroll persistence (Loop 91).
 *
 * ROOT CAUSE of the "sidebar jumps to the top" bug: the app uses
 * full-page Laravel navigations, so the whole sidebar DOM — including
 * its one scroll container, `.sidebar .card-sidebar-mobile` (the only
 * overflow-y:auto element in the rail; Loop 35 DOM-measured it) — is
 * recreated by the server on every page load, and a fresh element's
 * scrollTop is 0. Nothing was resetting it; it was simply never
 * carried across the navigation.
 *
 * FIX: carry it explicitly. The position is stored in sessionStorage
 * (per-tab, dies with the tab) under one key and restored by an
 * inline stub at the end of partials/menu.blade.php the moment the
 * sidebar markup has been parsed — before the content area paints —
 * and re-asserted here once the layout settles, so the user never
 * sees the rail at the top and then jumping.
 *
 * Guards that keep this honest:
 *  - A saved value is only ever written from a REAL position of the
 *    live scroller (link click, debounced user scroll, beforeunload)
 *    — initialisation never writes, so a stored deep position can
 *    never be overwritten with 0 by a page that has not restored yet.
 *  - Re-assertion stops the moment the user actually scrolls (a
 *    wheel / touch / pointer / key interaction followed by a scroll
 *    that disagrees with the restored value marks the position as
 *    user-owned).
 *  - When nothing is stored (first visit, fresh tab), the only
 *    adjustment is keeping the ACTIVE item visible
 *    (scrollIntoView block:'nearest', and only if it is outside the
 *    visible box) — the exception the design calls for.
 *  - The collapsed rail uses the same scroller element; nothing
 *    about the container changes between states.
 */
(function () {
    'use strict';

    var KEY = 'hackstra-sidebar-scroll';

    function scroller() {
        return document.querySelector('.sidebar .card-sidebar-mobile') ||
               document.querySelector('.sidebar .sidebar-content');
    }

    function read() {
        try {
            var v = sessionStorage.getItem(KEY);
            if (v === null) { return null; }
            var n = parseInt(v, 10);
            return isNaN(n) ? null : n;
        } catch (e) { return null; }
    }

    function save() {
        var el = scroller();
        if (!el) { return; }
        try { sessionStorage.setItem(KEY, String(el.scrollTop)); } catch (e) {}
    }

    var el = scroller();
    if (!el) { return; }

    var saved = read();
    var restored = null;      // value we are holding the rail at
    var userTouched = false;  // real scroll intent seen on the scroller
    var userOwns = false;     // user has taken over the position

    if (saved !== null && saved > 0) {
        // The inline stub in partials/menu normally restored this
        // already; re-apply in case anything re laid out since.
        if (el.scrollTop !== saved) { el.scrollTop = saved; }
        restored = saved;
    }

    function reassert() {
        if (restored === null || userOwns) { return; }
        var cur = scroller();
        if (cur && Math.abs(cur.scrollTop - restored) > 1) {
            cur.scrollTop = restored;
        }
    }

    ['wheel', 'touchstart', 'pointerdown', 'keydown'].forEach(function (evt) {
        el.addEventListener(evt, function () { userTouched = true; }, { passive: true });
    });
    el.addEventListener('scroll', function () {
        if (restored !== null && userTouched &&
            Math.abs(el.scrollTop - restored) > 2) {
            userOwns = true;
        }
    }, { passive: true });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reassert);
    } else {
        reassert();
    }

    window.addEventListener('load', function () {
        reassert();
        // Layout has settled: from here the stored value simply tracks
        // the user's own scrolling (debounced), so exits that are not
        // sidebar clicks (topbar links, refresh, address bar) keep it.
        var timer = null;
        el.addEventListener('scroll', function () {
            if (timer) { return; }
            timer = setTimeout(function () { timer = null; save(); }, 150);
        }, { passive: true });
    });

    // Save on the way out: a click on any sidebar link (capture phase,
    // before the browser leaves) …
    var nav = document.querySelector('.sidebar .nav-sidebar');
    if (nav) {
        nav.addEventListener('click', function (e) {
            if (e.target && e.target.closest && e.target.closest('a.nav-link')) {
                save();
            }
        }, true);
    }
    // … and any other navigation away from the page.
    window.addEventListener('beforeunload', save);

    // First visit (nothing stored): default position, except that the
    // active item must be visible.
    if (saved === null) {
        var reveal = function () {
            var box = scroller();
            if (!box) { return; }
            var active = box.querySelector('.nav-link.active');
            if (!active) { return; }
            var ar = active.getBoundingClientRect();
            var cr = box.getBoundingClientRect();
            if (ar.top < cr.top || ar.bottom > cr.bottom) {
                active.scrollIntoView({ block: 'nearest' });
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', reveal);
        } else {
            reveal();
        }
    }
})();
