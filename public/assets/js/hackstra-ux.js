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
/* ------------------------------------------------------------------
 * Item 16 (owner, 2026-11-07 late): bfcache guard. A page restored
 * from the browser's back/forward cache comes back exactly as it
 * was left — including form CSRF tokens from BEFORE the latest
 * sign-in (every sign-in rotates the session token), so submitting
 * a restored form 419s. Reloading on a persisted pageshow makes a
 * restored page re-render fresh with a live token; it also closes
 * the stale-form loop behind the old 419 "Go back" at the source
 * (the 419 page's fresh-return link, Item 15, stays as the second
 * line of defence). A normal first load has persisted === false
 * and is untouched.
 * ------------------------------------------------------------------ */
window.addEventListener('pageshow', function (event) {
    if (event.persisted) { window.location.reload(); }
});

/* ------------------------------------------------------------------
 * ROUND 5 SLICE 1 — P7: the one shared debounce for the whole app.
 * Defined before everything else in this file: the sidebar scroll
 * saver below and page scripts (payments search) consume it, and no
 * page grows another hand-rolled timer.
 * ------------------------------------------------------------------ */
window.hkDebounce = function (fn, ms) {
    var timer = null;
    function debounced() {
        var self = this, args = arguments;
        if (timer) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () {
            timer = null;
            fn.apply(self, args);
        }, ms || 300);
    }
    debounced.cancel = function () {
        if (timer) { window.clearTimeout(timer); timer = null; }
    };
    return debounced;
};

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
        // A second submit of the SAME form while the first is in flight is
        // the double-submit this guard exists to stop. Track it with our
        // own flag: the old "any submit button disabled -> preventDefault"
        // test also fired when a PAGE script disabled its button inside
        // this very submit event (its own loading state — e.g. the marks
        // selector), which silently cancelled legitimate first submits and
        // left the button spinning forever.
        if (form.dataset.hxSubmitting === '1') { event.preventDefault(); return; }
        form.dataset.hxSubmitting = '1';
        form.dataset.hxSubmitAt = String(Date.now());
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        buttons.forEach(function (button) {
            button.classList.add('is-loading');
            // Delay disabling by a tick so the button's own name/value
            // is still included in the submitted form data.
            window.setTimeout(function () { button.disabled = true; }, 0);
        });
        // Safety reset (Round 5 Slice 1, P8): if the browser never
        // leaves the page (hung request, blocked download), the guard
        // releases the form after 20 s instead of locking it until a
        // manual refresh, and says why.
        window.setTimeout(function () {
            if (form.dataset.hxSubmitting !== '1') { return; }
            delete form.dataset.hxSubmitting;
            buttons.forEach(function (button) {
                button.classList.remove('is-loading');
                button.disabled = false;
            });
            if (typeof window.flash === 'function') {
                window.flash({ msg: 'That is taking longer than usual — if nothing happens, check your connection and try again.', type: 'warning' });
            }
        }, 20000);
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
        el.addEventListener('scroll', window.hkDebounce(save, 150), { passive: true });
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

/* Loop 120 — mobile drawer backdrop: tapping the dimmed page area closes
   the open sidebar drawer (the in-drawer "Navigation" strip was removed
   at the owner's request; the topbar hamburger remains the toggle). */
document.addEventListener('click', function (e) {
  if (e.target && e.target.classList && e.target.classList.contains('hk-sidebar-backdrop')) {
    document.body.classList.remove('sidebar-mobile-main');
  }
});

/* ------------------------------------------------------------------
 * ROUND 5 SLICE 1 — shared interaction core.
 * P1 hkOptimistic (optimistic updates that always roll back),
 * P8 the programmatic-.submit() bridge + standalone-button guard,
 * P2 hkSkel / hkSelectLoading (skeleton renderer; markup mirrors
 * partials/patterns/skeleton.blade.php),
 * P20 connectivity notices (the offline/online half of the error
 * floor — the message half lives in custom_js as hkAjaxError),
 * P13 the keyboard shortcut registry (Ctrl+K, ?, Ctrl+S on opted-in
 * forms, and the per-role single-key navigation the server renders
 * into window.hkShortcutNav from partials/shortcuts.blade.php).
 * window.flash (custom_js) is referenced lazily at call time.
 * ------------------------------------------------------------------ */
(function () {
    'use strict';

    function uxFlash(msg, type) {
        if (typeof window.flash === 'function') {
            window.flash({ msg: msg, type: type || 'info' });
        }
    }

    /* ---- P1: optimistic updates with a guaranteed way back ---- */
    var hkRetryQueue = [];

    window.hkOptimistic = function (opts) {
        opts = opts || {};
        var applied = false;

        function isFailure(resp) {
            if (opts.isOk) { return !opts.isOk(resp); }
            if (!resp) { return false; }
            if (resp.ok === false) { return true; }
            if (typeof resp.status === 'number' && (resp.status < 200 || resp.status >= 300)) { return true; }
            return false;
        }

        function fail(err) {
            if (applied && opts.revert) {
                try { opts.revert(); } catch (e) { /* a revert must never mask the real error */ }
                applied = false;
            }
            var status = (err && typeof err.status === 'number') ? err.status : null;
            var networkDown = !!err && (status === 0 || err instanceof TypeError);
            if (opts.retry && networkDown) { hkRetryQueue.push(run); }
            if (opts.onError) { opts.onError(err); }
            else if (window.hkAjaxError) { window.hkAjaxError(err, opts.errorOpts); }
            else if (opts.errorMsg) { uxFlash(opts.errorMsg, 'danger'); }
            return { ok: false, error: err };
        }

        function run() {
            if (opts.apply && !applied) {
                try { opts.apply(); applied = true; } catch (e) { /* apply errors surface via the request */ }
            }
            var result;
            try { result = opts.request(); }
            catch (e) { return Promise.resolve(fail(e)); }
            return Promise.resolve(result).then(function (resp) {
                if (isFailure(resp)) { return fail(resp); }
                if (opts.onSuccess) { opts.onSuccess(resp); }
                return { ok: true, response: resp };
            }, fail);
        }

        return run();
    };

    /* ---- P20: connectivity changes are never silent either ---- */
    window.addEventListener('online', function () {
        uxFlash('Back online — you can continue working.', 'success');
        if (hkRetryQueue.length) {
            var queue = hkRetryQueue.splice(0, hkRetryQueue.length);
            queue.forEach(function (run) { run(); });
        }
    });
    window.addEventListener('offline', function () {
        uxFlash('You are offline — changes on this page cannot be saved until the connection returns.', 'warning');
    });

    /* ---- P8: programmatic .submit() joins the guard ----
       form.submit() skips the submit event entirely, so neither the
       confirm interceptor nor the double-submit guard ever saw it
       (the report builder's hidden PDF/Word/save forms were the
       known gap). Bridge it: dispatch the event, honour
       preventDefault, submit natively only if it proceeds. A call
       landing within 100 ms of a guarded submit event is the same
       gesture (jQuery's .submit() shorthand ends in a native call
       right after its own dispatch) and goes straight through; a
       later call while a submission is in flight is dropped. */
    if (window.HTMLFormElement && HTMLFormElement.prototype && !HTMLFormElement.prototype.hkSubmitBridge) {
        var nativeFormSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            if (this.dataset && this.dataset.hxSubmitting === '1') {
                var at = parseInt(this.dataset.hxSubmitAt || '0', 10);
                if (Date.now() - at < 100) { return nativeFormSubmit.call(this); }
                return;
            }
            var proceed = false;
            try {
                proceed = this.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            } catch (e) { proceed = true; }
            if (proceed) { return nativeFormSubmit.call(this); }
        };
        HTMLFormElement.prototype.hkSubmitBridge = true;
    }

    /* Standalone buttons that start heavy work outside a form (the
       report builder's download/save buttons) get the same
       double-activation protection: data-hx-guard, with an optional
       data-hx-guard-ms release window (default 20 s, matching the
       form guard). */
    document.addEventListener('click', function (event) {
        var btn = event.target && event.target.closest ? event.target.closest('[data-hx-guard]') : null;
        if (!btn) { return; }
        if (btn.dataset.hxGuarding === '1') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        btn.dataset.hxGuarding = '1';
        btn.classList.add('is-loading');
        var wasDisabled = btn.disabled;
        window.setTimeout(function () { btn.disabled = true; }, 0);
        var ms = parseInt(btn.getAttribute('data-hx-guard-ms') || '20000', 10);
        window.setTimeout(function () {
            if (btn.dataset.hxGuarding !== '1') { return; }
            delete btn.dataset.hxGuarding;
            btn.classList.remove('is-loading');
            btn.disabled = wasDisabled;
        }, ms);
    }, true);

    /* ---- P2: skeleton renderer ---- */
    function hkSkelLines(count, title) {
        var html = '';
        if (title) { html += '<div class="hk-skel hk-skel-line hk-skel-title"></div>'; }
        for (var i = 0; i < count; i++) { html += '<div class="hk-skel hk-skel-line"></div>'; }
        return html;
    }

    window.hkSkelHtml = function (type, rows) {
        rows = rows || 5;
        var html = '<div class="hk-skel-wrap" aria-hidden="true">';
        var i;
        if (type === 'table') {
            for (i = 0; i < rows; i++) {
                html += '<div class="hk-skel-row"><div class="hk-skel" style="width:34px;height:34px;border-radius:50%"></div><div class="hk-skel hk-skel-line" style="flex:2"></div><div class="hk-skel hk-skel-line" style="flex:3"></div><div class="hk-skel hk-skel-line" style="flex:1"></div><div class="hk-skel hk-skel-line" style="flex:1"></div></div>';
            }
        } else if (type === 'stats') {
            html += '<div class="hk-skel-stats">';
            for (i = 0; i < 4; i++) {
                html += '<div class="hk-skel-stat"><div class="hk-skel" style="width:44px;height:44px;border-radius:50%"></div><div style="flex:1">' + hkSkelLines(2) + '</div></div>';
            }
            html += '</div>';
        } else if (type === 'frame') {
            html += '<div class="hk-skel-frame">' + hkSkelLines(rows, true) + '</div>';
        } else {
            html += hkSkelLines(rows, true);
        }
        return html + '</div>';
    };

    window.hkSkel = function (region, on, opts) {
        if (!region) { return; }
        opts = opts || {};
        if (on) {
            if (region.__hkSkelPrev === undefined) { region.__hkSkelPrev = region.innerHTML; }
            region.setAttribute('aria-busy', 'true');
            region.innerHTML = window.hkSkelHtml(opts.type || 'card', opts.rows || 5);
        } else {
            region.removeAttribute('aria-busy');
            if (opts.restore && region.__hkSkelPrev !== undefined) {
                region.innerHTML = region.__hkSkelPrev;
            }
            region.__hkSkelPrev = undefined;
        }
    };

    /* Cascade selects: a visible Loading state while options are in
       flight (P2's state for the cascade family). */
    window.hkSelectLoading = function (select, on) {
        if (!select || !window.jQuery) { return; }
        var $sel = window.jQuery(select);
        if (!$sel.length) { return; }
        if (on) {
            $sel.prop('disabled', true);
            if (!$sel.find('option.hk-skel-loading').length) {
                $sel.prepend('<option value="" class="hk-skel-loading" disabled selected>Loading…</option>');
            }
        } else {
            $sel.find('option.hk-skel-loading').remove();
            $sel.prop('disabled', false);
        }
    };

    /* ---- P13: the keyboard shortcut registry ---- */
    window.hkShortcuts = [
        { combo: 'ctrl+k', label: 'Open command palette' },
        { combo: '?', label: 'Show keyboard shortcuts' },
        { combo: 'ctrl+s', label: 'Save (on the marks-entry and attendance pages)' },
        { combo: 'esc', label: 'Close a dialog' }
    ];
    (window.hkShortcutNav || []).forEach(function (entry) {
        window.hkShortcuts.push({ combo: entry.key, label: entry.label, url: entry.url });
    });

    function hkIsTyping(target) {
        if (!target) { return false; }
        var tag = target.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable === true;
    }

    document.addEventListener('keydown', function (event) {
        var key = event.key || '';
        /* Ctrl/Cmd+K — open the command palette (Round 5 Slice 4,
           P3). The registry still owns this — exactly one owner —
           but the action is now the palette partial's
           window.hkOpenPalette. When the palette is not on the
           page, fall back to Slice 1's behaviour: focus the
           topbar search. */
        if ((event.ctrlKey || event.metaKey) && !event.altKey && (key === 'k' || key === 'K')) {
            event.preventDefault();
            if (typeof window.hkOpenPalette === 'function') { window.hkOpenPalette(); return; }
            var input = document.getElementById('lav-global-search');
            if (input) { input.focus(); input.select(); }
            return;
        }
        /* Ctrl/Cmd+S — only where a form opted in with
           data-hk-save-form; everywhere else the browser keeps it. */
        if ((event.ctrlKey || event.metaKey) && !event.altKey && (key === 's' || key === 'S')) {
            var saveForm = document.querySelector('form[data-hk-save-form]');
            if (saveForm) {
                event.preventDefault();
                if (saveForm.requestSubmit) { saveForm.requestSubmit(); } else { saveForm.submit(); }
            }
            return;
        }
        if (event.ctrlKey || event.metaKey || event.altKey) { return; }
        if (hkIsTyping(event.target)) { return; }
        /* A dialog owns the keyboard while it is open; Esc is left
           to Bootstrap. */
        if (document.querySelector('.modal.show')) { return; }
        if (key === '?') {
            event.preventDefault();
            if (typeof window.hkShowShortcuts === 'function') { window.hkShowShortcuts(); }
            return;
        }
        if (key.length === 1) {
            var wanted = key.toLowerCase();
            var nav = window.hkShortcutNav || [];
            for (var i = 0; i < nav.length; i++) {
                if (nav[i].key === wanted) {
                    event.preventDefault();
                    window.location.href = nav[i].url;
                    return;
                }
            }
        }
    });
})();

/* ------------------------------------------------------------------
 * ROUND 5 SLICE 2 — P6: hk-draft autosave (form drafts in localStorage).
 *
 * Opt-in: a form carries data-hk-draft="<key>", where the BLADE
 * renders the FULL per-user key (e.g. data-hk-draft="u42-circular-7")
 * — this helper stays free of user knowledge. Forms built on the
 * shared form_page pattern render their <form> tag inside that
 * partial, so those blades place a marker element carrying the same
 * attribute inside the form instead; the marker resolves to its
 * closest form here and the key is mirrored onto the form element.
 *
 * Semantics mirror the Report Builder's localStorage draft
 * (reports/builder.blade.php, Round 3): a per-user key, a JSON
 * envelope saved on change, restore announced in place (never
 * silent), a discard path back to the server-rendered values, and
 * the key cleared when the form is submitted / saved. On top of
 * the builder's shape this helper adds the audit's guardrails: a
 * timestamped envelope with a 7-day cap, and a hard denylist so
 * secrets never reach storage at rest.
 *
 * Envelope: localStorage['hk-draft:' + key] =
 *   { ts: <epoch ms>, data: { <field name>: <value>, ... } }
 * Checkbox / radio state rides as data[name + '::checked'] =
 * '1' | '0' alongside the field's value, so an unchecked box is
 * restored unchecked instead of resurrecting its rendered state.
 *
 * Serialisation NEVER includes: type=file, type=password,
 * type=hidden, type=submit / button / reset / image fields, any
 * field carrying data-hk-draft-skip, and any field whose name
 * matches hkDraftDeny below (pass|card|cvv|secret) — the token,
 * method and other hidden framework fields are skipped by the
 * type=hidden rule.
 *
 * Restore (on page load): a draft is applied only when it exists,
 * is younger than 7 days, and DIFFERS from the server-rendered
 * values (a draft equal to the render is just dropped). Applying
 * a draft shows the restore prompt in the note; Discard clears
 * the key and reloads the page, so the server values return
 * exactly as rendered. Stale drafts (> 7 days) are deleted
 * silently. After each field is restored a bubbling 'change'
 * event is dispatched so Select2 and other widgets notice, the
 * same way the builder's applyConfig re-renders its controls.
 *
 * Dates (Item 15, 2026-11-07): hk_date fields need two bridges.
 * hk-draft's init runs BEFORE hk_date transforms the native date
 * inputs (both wait for DOMContentLoaded; this file registers
 * first), so at restore time a date is still a named native input:
 * applyData sets its value ATTRIBUTE as well as the property,
 * because hk_date's transform takes its initial value from the
 * attribute — a property-only restore would be overwritten by the
 * server-rendered date. Serialise runs again on every later save,
 * by which time hk_date HAS transformed the field (visible input
 * nameless, the name moved to a hidden sibling this helper skips),
 * so serialise also reads transformed pairs directly: the visible
 * input's value getter speaks ISO, and its name is the hidden
 * sibling's. Restoring a transformed pair sets the visible input's
 * value, whose setter re-renders the display text and the hidden
 * sibling together.
 *
 * Save note: one <p class="hk-draft-note text-muted small"> per
 * form, created by this helper and placed right before the form's
 * submit row (the form_page .pat-actionbar when present, else
 * after the form). After each debounced save it reads
 * "Draft saved HH:MM"; after a restore it carries the prompt
 * "Unsaved draft from HH:MM restored" plus a Discard button.
 * On the form's submit event the key is cleared — a validation
 * bounce repopulates via old() and must not fight a stale draft.
 * ------------------------------------------------------------------ */
(function () {
    'use strict';

    var PREFIX = 'hk-draft:';
    var MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000; /* 7 days */
    var hkDraftDeny = /pass|card|cvv|secret/i;

    function storageKey(key) { return PREFIX + key; }

    function formatTime(ts) {
        var d = new Date(ts);
        var hh = d.getHours();
        var mm = d.getMinutes();
        return (hh < 10 ? '0' : '') + hh + ':' + (mm < 10 ? '0' : '') + mm;
    }

    function isSkipped(field) {
        if (!field.name) { return true; }
        if (field.hasAttribute && field.hasAttribute('data-hk-draft-skip')) { return true; }
        if (hkDraftDeny.test(field.name)) { return true; }
        var type = (field.type || '').toLowerCase();
        if (type === 'file' || type === 'password' || type === 'hidden' ||
            type === 'submit' || type === 'button' || type === 'reset' ||
            type === 'image') { return true; }
        return false;
    }

    function fieldsOf(form) {
        var out = [];
        var nodes = form.querySelectorAll('input, select, textarea');
        for (var i = 0; i < nodes.length; i++) {
            if (!isSkipped(nodes[i])) { out.push(nodes[i]); }
        }
        return out;
    }

    /* hk_date-transformed fields (see the header note): the
       visible input carries data-hk-date="1" and no name; the name
       lives on the hidden sibling inside the same .hkdp-wrap. */
    function datePairs(form) {
        var out = [];
        form.querySelectorAll('input[data-hk-date="1"]').forEach(function (input) {
            var wrap = input.closest ? input.closest('.hkdp-wrap') : null;
            var hidden = wrap ? wrap.querySelector('input[type="hidden"]') : null;
            if (hidden && hidden.name) { out.push({ name: hidden.name, input: input }); }
        });
        return out;
    }

    function serialise(form) {
        var data = {};
        fieldsOf(form).forEach(function (field) {
            var type = (field.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                data[field.name] = field.value;
                data[field.name + '::checked'] = field.checked ? '1' : '0';
            } else if (field.tagName === 'SELECT' && field.multiple) {
                var vals = [];
                for (var i = 0; i < field.options.length; i++) {
                    if (field.options[i].selected) { vals.push(field.options[i].value); }
                }
                data[field.name] = vals.join(',');
            } else {
                data[field.name] = field.value;
            }
        });
        /* Transformed date pairs: the visible input's value
           getter returns the ISO date (see the header note). */
        datePairs(form).forEach(function (pair) {
            data[pair.name] = pair.input.value;
        });
        return data;
    }

    function sameData(a, b) {
        var ka = Object.keys(a);
        var kb = Object.keys(b);
        if (ka.length !== kb.length) { return false; }
        for (var i = 0; i < ka.length; i++) {
            if (String(a[ka[i]]) !== String(b[ka[i]])) { return false; }
        }
        return true;
    }

    function applyData(form, data) {
        fieldsOf(form).forEach(function (field) {
            if (!Object.prototype.hasOwnProperty.call(data, field.name)) { return; }
            var type = (field.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                /* A radio group shares one name: only the member
                   whose value matches the stored value may claim
                   the stored checked state. */
                if (String(field.value) === String(data[field.name])) {
                    field.checked = data[field.name + '::checked'] === '1';
                } else if (type === 'radio') {
                    field.checked = false;
                }
            } else if (field.tagName === 'SELECT' && field.multiple) {
                var wanted = String(data[field.name]).split(',');
                for (var i = 0; i < field.options.length; i++) {
                    field.options[i].selected = wanted.indexOf(field.options[i].value) !== -1;
                }
            } else {
                field.value = data[field.name];
                /* A still-native date input: hk_date's transform
                   (which runs after this restore) takes its initial
                   value from the ATTRIBUTE, so mirror the restored
                   ISO date there too or the server date wins. */
                if (type === 'date') { field.setAttribute('value', data[field.name]); }
            }
            /* Widgets (Select2 and friends) key off change: let
               them notice the restored value, like the builder's
               restore re-renders its controls. */
            field.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.jQuery) { window.jQuery(field).trigger('change'); }
        });
        /* Transformed date pairs (init ran late on this page):
           setting the visible input's value re-renders its
           display text and hidden sibling together. */
        datePairs(form).forEach(function (pair) {
            if (!Object.prototype.hasOwnProperty.call(data, pair.name)) { return; }
            pair.input.value = data[pair.name];
            pair.input.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.jQuery) { window.jQuery(pair.input).trigger('change'); }
        });
    }

    function ensureNote(form) {
        var note = form.querySelector('.hk-draft-note');
        if (note) { return note; }
        note = document.createElement('p');
        note.className = 'hk-draft-note text-muted small';
        note.setAttribute('role', 'status');
        note.style.display = 'none';
        var bar = form.querySelector('.pat-actionbar');
        if (bar && bar.parentNode) {
            bar.parentNode.insertBefore(note, bar);
        } else if (form.parentNode) {
            form.parentNode.insertBefore(note, form.nextSibling);
        } else {
            form.appendChild(note);
        }
        return note;
    }

    function initForm(form, key) {
        var store = storageKey(key);
        var note = ensureNote(form);
        var serverData = serialise(form);

        function clearDraft() {
            try { localStorage.removeItem(store); } catch (e) { /* storage blocked */ }
        }

        function showSaved(ts) {
            note.innerHTML = '';
            note.appendChild(document.createTextNode('Draft saved ' + formatTime(ts)));
            note.style.display = '';
        }

        function showRestored(ts) {
            note.innerHTML = '';
            note.appendChild(document.createTextNode('Unsaved draft from ' + formatTime(ts) + ' restored'));
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-link btn-sm p-0 ml-2 hk-draft-discard';
            btn.textContent = 'Discard';
            btn.addEventListener('click', function () {
                /* Discard = drop the draft and reload: the server
                   values come back exactly as rendered. */
                clearDraft();
                window.location.reload();
            });
            note.appendChild(btn);
            note.style.display = '';
        }

        var save = window.hkDebounce(function () {
            var ts = Date.now();
            try {
                localStorage.setItem(store, JSON.stringify({ ts: ts, data: serialise(form) }));
                showSaved(ts);
            } catch (e) { /* storage full / blocked — editing still works */ }
        }, 400);
        form.addEventListener('input', save);
        form.addEventListener('change', save);

        /* Submit clears the key: the server takes over from here,
           and a validation bounce repopulates via old() — a stale
           draft must never fight it on the next load. */
        form.addEventListener('submit', function () {
            if (save.cancel) { save.cancel(); }
            clearDraft();
        });

        /* Restore on load (see the header comment for the rules). */
        var raw = null;
        try { raw = localStorage.getItem(store); } catch (e) { raw = null; }
        if (!raw) { return; }
        var envelope = null;
        try { envelope = JSON.parse(raw); } catch (e) { envelope = null; }
        if (!envelope || !envelope.ts || !envelope.data) { clearDraft(); return; }
        if (Date.now() - envelope.ts > MAX_AGE_MS) { clearDraft(); return; }
        if (sameData(envelope.data, serverData)) { clearDraft(); return; }
        applyData(form, envelope.data);
        showRestored(envelope.ts);
    }

    function init() {
        var seen = [];
        document.querySelectorAll('[data-hk-draft]').forEach(function (el) {
            var key = el.getAttribute('data-hk-draft');
            if (!key) { return; }
            var form = el.tagName === 'FORM' ? el : (el.closest ? el.closest('form') : null);
            if (!form || seen.indexOf(form) !== -1) { return; }
            seen.push(form);
            /* Mirror the key onto the form itself, so the opt-in
               reads the same wherever the attribute was placed. */
            if (!form.hasAttribute('data-hk-draft')) { form.setAttribute('data-hk-draft', key); }
            initForm(form, key);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* ------------------------------------------------------------------
 * ROUND 5 SLICE 3 — P10: hk-bulk, the shared bulk-actions bar.
 *
 * One module for every list that opts into selection, generalising
 * the students bulk-delete script and the SA-feedback bulk-close
 * script (the audit's two hand-rolled precedents). Pages render:
 *   - row checkboxes: <input type="checkbox" class="hk-bulk-check"
 *     value="ID"> with NO name attribute;
 *   - the bar: partials/patterns/bulk_bar.blade.php, carrying
 *     data-hk-bulk-bar="<page prefix>";
 *   - one hidden form per action (rendered by the page itself,
 *     with CSRF); each bar button names its form via
 *     data-hk-bulk-form="<form id>".
 *
 * Behaviour: the select-page checkbox ticks every enabled row on
 * the page (selection is page-scoped — the bar says so); the live
 * count enables the action buttons and Clear only while N > 0;
 * Clear unticks everything. On an action click the module gathers
 * the checked ids and — when the button carries
 * data-hk-bulk-confirm — substitutes the literal {count} in that
 * template and confirms through the P19 standard
 * window.hackstraConfirm directly (the shared data-confirm bridge
 * reads its message verbatim and cannot substitute a count, so
 * bulk buttons never carry data-confirm). Only then does it
 * append hidden ids[] inputs (marked data-hk-bulk-id, replaced
 * fresh on every submit) to the target form and submit it — the
 * students precedent, so no checked state ever leaks into another
 * form and no form ever wraps the table.
 * ------------------------------------------------------------------ */
(function () {
    'use strict';

    function initBar(bar) {
        var selectAll = bar.querySelector('[data-hk-bulk-select-all]');
        var countEl = bar.querySelector('[data-hk-bulk-count]');
        var clearBtn = bar.querySelector('[data-hk-bulk-clear]');
        var actionBtns = Array.prototype.slice.call(bar.querySelectorAll('[data-hk-bulk-action]'));
        if (!countEl) { return; }

        function boxes() {
            return Array.prototype.slice.call(document.querySelectorAll('.hk-bulk-check'));
        }
        function enabledBoxes() {
            return boxes().filter(function (c) { return !c.disabled; });
        }
        function checkedBoxes() {
            return boxes().filter(function (c) { return c.checked && !c.disabled; });
        }

        function refresh() {
            var n = checkedBoxes().length;
            countEl.textContent = String(n);
            actionBtns.forEach(function (b) { b.disabled = n === 0; });
            if (clearBtn) { clearBtn.disabled = n === 0; }
            if (selectAll) {
                var enabled = enabledBoxes();
                selectAll.checked = enabled.length > 0 && n === enabled.length;
                selectAll.indeterminate = n > 0 && n < enabled.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                enabledBoxes().forEach(function (c) { c.checked = selectAll.checked; });
                refresh();
            });
        }
        document.addEventListener('change', function (event) {
            if (event.target && event.target.classList &&
                event.target.classList.contains('hk-bulk-check')) {
                refresh();
            }
        });
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                boxes().forEach(function (c) { c.checked = false; });
                refresh();
            });
        }

        function submitWithIds(btn) {
            var form = document.getElementById(btn.getAttribute('data-hk-bulk-form') || '');
            if (!form) { return; }
            var ids = checkedBoxes().map(function (c) { return c.value; });
            if (!ids.length) { return; }
            Array.prototype.slice.call(form.querySelectorAll('input[data-hk-bulk-id]'))
                .forEach(function (h) { h.parentNode.removeChild(h); });
            ids.forEach(function (id) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'ids[]';
                h.value = id;
                h.setAttribute('data-hk-bulk-id', '1');
                form.appendChild(h);
            });
            if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
        }

        actionBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var ids = checkedBoxes();
                if (!ids.length) { return; }
                var template = btn.getAttribute('data-hk-bulk-confirm');
                if (template && typeof window.hackstraConfirm === 'function') {
                    var message = template.split('{count}').join(String(ids.length));
                    var opts = { danger: btn.hasAttribute('data-hk-bulk-danger') };
                    var title = btn.getAttribute('data-hk-bulk-confirm-title');
                    var action = btn.getAttribute('data-hk-bulk-confirm-action');
                    if (title) { opts.title = title; }
                    if (action) { opts.action = action; }
                    window.hackstraConfirm(message, opts).then(function (ok) {
                        if (ok) { submitWithIds(btn); }
                    });
                    return;
                }
                submitWithIds(btn);
            });
        });

        refresh();
    }

    function init() {
        document.querySelectorAll('[data-hk-bulk-bar]').forEach(initBar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
