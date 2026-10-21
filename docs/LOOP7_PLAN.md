# Loop 7 — Professional Polish, Role Features & Responsive Pass

User directive (2026-10-21): "UI/UX properly sahi karo, sab alignment sahi,
properly center align karo, image dekho, graph banao, more options/features
do Super Admin, Teachers, Students, Parents, Accountant ko — properly
designed professional site, sahi se dikhe, sahi se access ho, mobile
responsive bhi sahi se ho. Detail plan banao, different agents for each
subtopic."

## Live recon (2026-10-21, assistant-verified on https://hackastra.onrender.com)
- Super Admin home: rich (54 KB) — rebuilt dashboard, charts present.
- Admin home: rich (53 KB).
- Teacher home: works (48 KB) but thin on role-specific value.
- Parent home: **sparsest page on the site** (20 KB, no chart, ~4 menu items).
- Student home: bare (legacy default), no personal summary.
- Accountant home: no dedicated fee-focused home.
- All pages: 0 mixed-content refs (https fix verified). Images on home pages: only logo/avatar.

## Workstreams (parallel agents, disjoint file ownership)

### A — Role homes & features  (branch: feature/loop7-roles)
Owner files: `HomeController`, role home views, role menus. NOT qs.css, NOT the Super Admin dashboard.
- **Teacher home:** my classes/sections, today's timetable periods, quick actions
  (take attendance, enter marks, my timetable), upcoming events.
- **Student home:** my attendance % (own records), latest marks/grades summary,
  upcoming events, fee status from own payment records, quick links.
- **Parent home (biggest gap):** one card per child — class/section, attendance %,
  fee status (paid/pending from payment records), quick link to child details;
  upcoming events strip.
- **Accountant home:** today's collection, this month's collection (Money helper,
  Indian format), pending payments count, recent payments table, quick links.
- Rules: real data only (existing models/repos), honest empty states when no
  data, same card/table visual language as the Super Admin dashboard, every
  link must resolve to a real route.

### B — Alignment, centering & responsive  (branch: feature/loop7-visual)
Owner files: `public/assets/css/qs.css` (append "Loop 7" section), layout
partials, Super Admin dashboard view classes. NOT controllers, NOT role homes.
- Consistent page-header alignment, centered auth screens, equal-height stat
  cards, table vertical alignment, form label/input alignment, sidebar/topbar
  alignment, button groups.
- Responsive at 375 / 768 / 1024 px: dashboard grids stack cleanly, sidebar
  collapses, topbar wraps, wide tables scroll horizontally inside wrappers
  (never break the page), charts scale to container width.
- Images: avatars fall back to initial-letter circles; `img{max-width:100%}`.

### C — Charts, images & site-wide QA  (branch: feature/loop7-qa)
Owner files: chart partials/helpers, list-page views outside A/B scope, this
plan's defect log. NOT qs.css, NOT HomeController.
- Verify SA/Admin dashboard charts render with real demo data (non-empty SVG).
- Add one reusable pure-SVG bar chart partial if a role home needs it (data
  passed in, no JS library).
- Crawl key routes on the live site as Super Admin: log any 500/404, http://
  refs, broken local images; fix view-level defects in C's scope, report the
  rest in the plan log.
- Inline-SVG empty states for main list pages that lack them.

## Workstream log

### A — Role homes & features (branch `feature/loop7-roles`) — built, pending parent review/push
Before this loop every role landed on one view: TeamSAT saw the school-wide
dashboard and student / parent / accountant / librarian got a bare
"Welcome back" panel. `HomeController@dashboard` now dispatches by
`user_type` (Admin / Super Admin flow is byte-identical to before):
- **Teacher** (`pages.teacher.home`): stat cards for own subjects, classes,
  students taught and sections led (class-teacher); today's periods from
  the real class timetables for subjects they teach; My Subjects table;
  quick actions (Take Attendance, Enter Marks, My Timetable, My Account);
  upcoming events.
- **Student** (`pages.student.home`): own attendance % (present+late over
  marked days — same formula as the monthly report) and days present;
  fees paid / pending in ₹ from own payment records; latest-exam marks
  table (subject, tests, exam, total = tca + exm, grade); quick links
  (My Marks, My Timetable, My Account); upcoming events.
- **Parent** (`pages.parent.home`): one card per linked child
  (`my_parent_id`) — class/section/adm no, attendance %, fees paid/pending
  with a Paid/Pending pill, View Profile + Marksheet links (same hashed
  links as My Children); honest empty state when no child is linked;
  upcoming events strip; quick links.
- **Accountant** (`pages.accountant.home`): collected today and this month
  (₹, Money helper, same updated_at convention as the main dashboard),
  pending payments count + pending amount, recent payments table,
  fee-focused quick actions; events strip.
- Rules honoured: real data only, no new tables, no invented numbers;
  events are display-only for non-SA roles (the events module is
  teamSA-gated); student/parent fee figures are display-only (the
  payments module is teamAccount-gated). All views reuse the Super Admin
  dashboard's classes — no qs.css changes from this workstream.
  Verified locally: php -l clean, Blade balance on all 4 new views,
  route() cross-check 11/11 referenced names resolve.

## Integration & verification (parent agent)
1. Review each branch (diff + php -l + Blade balance + route cross-check).
2. Batch-push to master (fewest approval taps), Render auto-deploys.
3. Live verification: scripted logins as Super Admin / Teacher / Parent /
   Admin; dashboards 200, 0 http refs, charts present; spot-check inner
   pages (students, payments, library, settings).
4. Report to the user with before/after facts; Railway finish stays queued
   behind Railway's build outage (incident QH8L8B3E).

## Workstream C — completion log (branch: feature/loop7-qa)

Live QA crawl done (23 pages as Super Admin on hackastra.onrender.com);
full table in `docs/LOOP7_QA.md`. Headlines:

- **Critical (reported, not fixed — controller is outside C's file scope):**
  `/attendance/report` → **500**. Route + view + sidebar link are live on
  master, but `AttendanceController` has **no `report()` method**
  (BadMethodCallException). QA doc lists the exact variables the view
  expects; ~30-line method using the existing `Attendance` model.
- **Charts verified from live HTML:** enrollment SVG renders with real
  data (6 points, May–Oct, 37 students). Fee ring and attendance donut
  render correctly but show 0 — the demo seed has **no payment records
  and no attendance rows** (data gap, not a markup bug; optional seeder
  follow-up for the parent).
- **Fixed in C scope:** inline-SVG empty states added to 9 list views
  (exams — which rendered a bare header-only table live — classes,
  sections, subjects, grades, dorms, timetables, users, students list
  ×2 loops), all via @forelse/@empty in the Loop 5 Books pattern.
  `students/list` was synced from remote master first (Loop 2 fix).
- **New partial:** `partials/charts/bar.blade.php` — reusable pure-SVG
  bar chart (labels+values in, app palette, empty state included),
  ready for Workstream A role homes.
- Site-wide: **0 http:// refs and 0 broken local images on all 23
  pages**; only other notes are /pins 302 → /dashboard (info) and the
  seed-data gaps above. Blade balance + php -l verification in QA doc.

## Loop 7 progress log

### B — Alignment, centering & responsive (branch: feature/loop7-visual) — DONE (static verification only; parent live-verifies)
Files changed: `public/assets/css/qs.css` (append-only "Loop 7" section),
`resources/views/partials/top_menu.blade.php` + `resources/views/partials/menu.blade.php`
(avatar fallback; both first synced from remote master — local copies were stale),
`resources/views/pages/support_team/dashboard.blade.php` (`l7-table-scroll` added to the
two existing `.table-responsive` wrappers), `resources/views/auth/login.blade.php` (480px pass).

Alignment fixes (grouped):
- Page header: `#page-header .page-header-content` is now a centered flex row —
  title left, `.header-elements` pushed right (`margin-left:auto`), title padding tightened.
- Equal heights: grid-row panels fill their column (`height:calc(100% - 1.5rem)`),
  stat-card columns are flex so all four stat cards in a row share one height;
  `.dashboard-stats` gained `row-gap` for the wrapped 2x2 layout.
- Tables: `thead th` vertically centered like body cells; new `.l7-table-scroll`
  utility (overflow-x auto, inner table min-width 600px) for wrapper-based scrolling.
- Forms: `.form-group > label` gets a consistent margin/rhythm above its input.
- Sidebar/topbar: nav links align icon+label on one center line (line-height fixes);
  topbar is `align-items:center` with a fixed gap in the right cluster.
- Buttons: new `.l7-btn-row` utility (flex, wrap, 8px gap) for action clusters.
- Images/charts: `.content img{max-width:100%;height:auto}`, svg/canvas/video capped;
  `.l7-chart` utility + dashboard line-chart container capped and clipped.
- Avatars: new `.l7-avatar` initial-letter circle (`.l7-avatar-sm` topbar, `.l7-avatar-md`
  sidebar user block); the photo renders on top and removes itself via `onerror`
  if it fails, revealing the initial.

Responsive rules (by breakpoint):
- `max-width:1024px` — content/panel padding tightens to 18px; fee summary padding matched.
- `min-width:768px and max-width:991.98px` — sidebar becomes an off-canvas drawer
  (fixed, 260px, `transform:translateX(-105%)`, slides in under the app's EXISTING
  `body.sidebar-mobile-main` class set by the `.sidebar-mobile-main-toggle` in
  `public/assets/js/app.js`); content/topbar margins zeroed; mobile topbar toggle
  shown, desktop collapse toggle hidden; in-sidebar mobile toggler re-enabled.
  Below 768 the pre-existing theme/qs.css mobile collapse already applied and is
  untouched; at 992+ the fixed sidebar is unchanged. Net effect: off-canvas under 992.
- `max-width:768px` — topbar wraps (`flex-wrap`, auto height), search flexes,
  fee summary stacks centered, dashboard tables keep a 540px min-width inside
  their scroll wrappers, page-title padding tightens.
- `max-width:480px` (covers 375px phones) — content padding 12px, smaller hero
  title, compact stat cards/icons, tighter panel headings and action/insight
  grids, chart gaps shrink, notification menu capped at 88vw. Login view also
  got a 480px block in its inline styles (12px page padding, tighter form).
- `prefers-reduced-motion` — EXTENDED (not duplicated): adds `scroll-behavior:auto`,
  kills the drawer transition and the stat-card hover lift; Loop 1's global
  animation/transition reset still stands.

Verification: qs.css braces balanced (362/362); `l7-` classes are new (grep-confirmed
no collisions with earlier qs.css); Blade directive balance on edited views confirmed
(@forelse/@endforelse 3/3 on the dashboard; apparent mismatches are pre-existing
patterns: inline `@php()` calls, inline `@section('page_title', ...)` form, and
CSS `}}` inside the login `<style>` block).

Could not do in CSS alone / notes for parent:
- The settings page (`pages/super_admin/settings.blade.php`) contains no tables,
  so there was nothing to wrap there; un-wrapped legacy tables on other list pages
  belong to workstream C's views — `.l7-table-scroll` is ready for them to apply.
- Register/password auth screens use the legacy Limitless layout (their
  `partials/login/inc_top` does not load qs.css), so only the rebuilt login view
  is covered by this pass.
- The 768–991 drawer overrides qs.css's own earlier `min-width:768px` fixed-sidebar
  rules by later-file order + higher specificity — worth a live look at 800px and
  375px after push (no browser verification was possible from this workstream).
- The shared working tree currently also carries other workstreams' uncommitted
  changes (HomeController, list-page views); this commit includes ONLY the
  Workstream B files listed above.
