# Hackstra — UI/UX + Every-Click Sweep Plan

Owner directive (2026-10-21): *"fix every button, UI UX and other things,
every click to everything — make a detailed plan and continuously work on
it in a loop."* This document is that plan and its living checklist.
Work proceeds loop by loop; each loop ends with verification + a push.

## Baseline inventory (measured 2026-10-21)

- 102 Blade views, ~172 routes (104 route statements incl. resources)
- 237 anchor tags, 67 `<button>` elements, 80 forms across the views
- Every one of those clicks must: land somewhere real, respect the user's
  role, give feedback, and look like it belongs to one design system.

## Design system (one source of truth)

- Palette: navy `#10263b`, primary blue `#3478f6`, ink `#172536`,
  muted `#718096`, surface `#ffffff`, app background `#f4f7fb`,
  danger `#e3342f`-family, success green, warning amber (Bootstrap 4 set).
- Radius: cards 12–16px, buttons/inputs 9px. Shadows: soft, single level.
- Type: one scale — page titles 1.25rem/700, card titles .95rem/600,
  body .85rem, meta .78rem muted.
- All of it lives in `public/assets/css/qs.css` (the "lav" layer); pages
  must not carry one-off inline styles except truly page-specific blocks
  (login). New components go into the shared layer, never per-page copies.

## Button standard (every button, no exceptions)

1. **Variants:** primary (blue, one per view max as the main action),
   secondary (white/border), danger (destructive only), ghost/icon.
2. **States:** default, hover, `:focus-visible` ring (3px blue halo),
   disabled (60% opacity, not-allowed cursor), loading (spinner +
   disabled, text stays readable).
3. **Size/tap:** min height 40px (36px for `.btn-sm`), icon buttons
   40×40 touch target; table row actions grouped, never scattered.
4. **Behaviour:** form submits are double-submit-guarded globally
   (`assets/js/hackstra-ux.js`); destructive actions require a confirm
   (`data-confirm` or the existing SweetAlert flows); no button may be a
   dead `#` link — it must route, open a real modal, or be removed.
5. **Labels:** verb + object ("Add Student", "Pay Now"), sentence case,
   icon + text for primary actions, icon-only only with `title`/`aria-label`.

## Every-click audit method (per module, in loop order)

For each screen: list every clickable element → check (a) route exists
and resolves, (b) role/permission is correct (policies + middleware),
(c) success AND failure feedback exists (flash/JSON/field errors),
(d) empty state exists when there is no data, (e) keyboard focus is
visible, (f) it works at 375px without overlap, (g) destructive clicks
confirm first. Findings are fixed in the same loop, not logged-and-left.

Module order: Dashboard → Students → Users/Staff → Classes/Sections/
Subjects → Exams/Marks → Payments/Receipts → Attendance → Events →
Library/Dorms → Timetables → Settings/My Account → Auth pages →
Print views (marksheet, receipts, tabulation).

## AI image asset pipeline

AI-generated illustrations are used where an image genuinely helps:
login brand panel, empty states, error pages, dashboard welcome strip.
**QC rules (hard):** no watermark, no baked-in text/letters, palette must
match the design system, ≤ ~350 KB per asset, stored under
`public/assets/img/ai/`, always decorative (`alt=""`) behind real content.

- [x] `login-campus.jpg` — login brand panel (navy/blue school scene).
  Generated + QC-passed (no watermark/text) and wired with a graceful
  gradient fallback; the binary itself rides the next git-capable push
  (the GitHub connector used for code pushes is text-only).
- [ ] Empty-state illustration (students/payments/search "no results")
- [ ] 404/500 error page illustration
- [ ] Dashboard welcome-strip illustration (subtle, wide crop)

## The loop

1. **Audit** the next module (click inventory + code read).
2. **Fix** in one batch: UI, buttons, dead clicks, permissions, feedback.
3. **Verify:** PHP parse, Blade balance, route references resolve to
   named routes, CSS/JS syntax, secret scan on the diff.
4. **Ship:** one batched push; note what changed in this file.
5. Repeat. Deployment health (Railway) is checked between loops —
   a red deploy pauses new features until the build is green again.

## Loop log

### Loop 1 — 2026-10-21 (foundation + functional depth)
- [x] Global inner-page UI layer across all legacy screens (previous batch)
- [x] Login rebuilt; AI campus illustration wired into the brand panel
- [x] GradeCalculator wired into the real marks flow (`MarkRepo::getGrade*`)
- [x] Policies enforced: student edit/update/delete; payment receipt view,
      pay_now, reset_record (closes receipt IDOR — any logged-in user could
      previously open any receipt by changing the ID)
- [x] Topbar bell = real "Recent activity" dropdown for staff
      (latest payments + admissions, real links; no fake counts)
- [x] Attendance monthly report (per-student present/absent/late + %)
- [x] Global button/click layer: focus rings, disabled + loading states,
      double-submit guard, `data-confirm` handler
- [x] Students module click audit (Loop 2, 2026-10-21): all route() names
  resolve (the 7 flagged names are Auth::routes()); one true dead click
  found and fixed — the Marksheet item in students/list (second table)
  now opens the real marksheet year-selector; fake tab anchors on
  student/user profile pages converted to spans; header "Current
  Session" is a link only for Super Admin (was an empty-href reload for
  everyone else); Librarian "Books" menu item removed — BookController
  is an empty scaffold with no routes/views (Library module = own loop)
- [x] Payments module click audit (Loop 2, 2026-10-21): every action is
  a real route or a confirmed JS action (confirmDelete/confirmReset are
  defined in partials/js/custom_js); no typeless buttons inside forms
- [x] Loop 3 click audit (2026-10-21): Exams/Marks, Classes, Users,
  Sections, Subjects, Grades, Dorms, Timetables — no dead clicks found
  (every action is a real route or a confirmed JS action)
- [x] Loop 4 (2026-10-21): print stylesheet added (@media print — chrome
  hidden, clean tables, page margins; covers marksheet/tabulation/reports
  in-browser printing); audit log built end-to-end (audit_logs table,
  App\Support\Audit, login listener, wired into payment record/reset/
  delete, student delete, marks update; Super Admin viewer page);
  PSR-4 namespaces fixed for Book/BookRequest (build warnings cleared)
- [x] Loop 5 (2026-10-21): Library module built end-to-end — the empty
      Book/BookRequest scaffolds are now real. Catalogue CRUD with search
      + pagination (`books`); issue/return loan flow (`book_requests` +
      PATCH `book_requests.return`) with copy-count integrity kept inside
      transactions; overdue flagging; empty states; `data-confirm` on
      delete and mark-returned. Role gating is Super Admin / Admin /
      Librarian via a new `teamLibrary` middleware + `Qs::userIsTeamLibrary()`
      mirroring the TeamSA pattern; delete stays Super Admin only
      (mirroring Events) and is blocked while copies are out on loan.
      The Librarian "Books" item removed in Loop 2 is restored, pointing
      at `books.index`; Super Admin gains a Library item too. No new
      migration — the 2018 books/book_requests tables already exist.
      Built on branch `feature/library-module`, verified locally
      (php -l, Blade balance, route-name cross-check); pending the
      parent agent's batch push + live click pass.
- [ ] Responsive pass 375 / 768 / 1024 on the live URL (needs deploy green)
- [ ] Responsive pass 375 / 768 / 1024 on the live URL (needs deploy green)
- [x] Audit log for login / marks / payment / role changes (Loop 4)
- [ ] Form Requests for the largest legacy forms
