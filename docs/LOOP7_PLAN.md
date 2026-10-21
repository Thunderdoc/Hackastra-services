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

## Loop 8

### K — Admit Card (hall ticket) module (branch: feature/loop8-admit-card) — built, pending parent review/push
User directive: admit card download + print in the correct format, straight
from the software — zero pen-and-paper.

- **Data (all real, no invented schedule):** exam name/term/session from
  `exams`; student name/photo (`users.photo`, default-avatar fallback),
  `student_records.adm_no`, class/section from `student_records` relations;
  the subject/date/time rows come from the **exam timetable**
  (`time_table_records` for exam+class+year → `time_tables.exam_date` +
  `time_slots.full`). When a class has no exam timetable yet, cards fall
  back to the class subject list with no dates (plus an on-card note and a
  staff-side alert) — dates are never fabricated.
- **Staff screen** (`admit_cards.index`, teamSAT): pick exam + class +
  optional section → roster with per-student **Print Card** and
  **Print All Cards** (`admit_cards.print`), 2 cards per A4 page via a
  print block in the print view. Linked from the Exams sidebar submenu,
  and per-exam from the Exam List dropdown.
- **Self-service** (`admit_cards.my`, `admit_cards.my_card`): students see
  their own exams, parents see each linked child's; the card route enforces
  ownership (own id / `Qs::userIsMyChild` / teamSAT) and aborts 403
  otherwise. Menu items added to the student and parent sidebars.
- **Card design:** settings-driven school header (name, address, phone,
  email, logo when set), ADMIT CARD band with exam + term + session, photo
  box, Name/Admission No/Class/Section grid, subjects table with an
  Invigilator's Sign column, 4 standard instructions, Class Teacher +
  Principal signature lines and a School Seal box.
- **Schema limits (honest):** there is **no roll-number field** on
  `student_records`, so no Roll column is printed; admission number is the
  student identifier on the card.
- Files: new `AdmitCardController`, 4 new views under
  `pages/support_team/exams/` (selector/roster, print sheet, card partial,
  self-service list); routes added before the resource block;
  `partials/menu.blade.php` edit was based on remote master (it was ahead
  of this branch — carries the Attendance Report item).

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

## Loop 8

### J — Exports & printing everywhere (branch: feature/loop8-exports) — built, pending parent review/push

User directive: a school must download/print EVERYTHING from inside the
software — no pen and paper. `composer.json` has **no maatwebsite/excel**,
so exports are streamed CSV downloads (a .csv opens directly in Excel);
no new dependency was added.

- **New helper `App\Support\CsvExport`**: `download(filename, headings, rows)`
  streams via `response()->streamDownload` (nothing written to disk);
  `write(stream, ...)` is the framework-free core. Files start with a
  UTF-8 BOM (₹/accents render in Excel), use `fputcsv` escaping, and
  neutralise spreadsheet formula injection (leading `= + - @` cells get a
  protective `'` prefix). Amounts stay raw numbers so columns still sum.
- **Endpoints + header buttons** (Print = `btn-light`, Export = `btn-primary`,
  download icon + label, card-header placement per the Loop 5 Books pattern):
  - Students list → `students.export` (GET `students/export/{class_id}/{section_id?}`,
    route-gated `teamSAT` exactly like `students.list`): Name, Admission No,
    Class, Section, Gender, Parent Name, Parent Phone, Parent Email.
    Header button exports the class; each section tab has its own
    "Export Section" link. View base was synced from remote master first
    (it carried the per-section Marksheet fix).
  - Attendance monthly report → `attendance.export` (GET
    `attendance/report/export`, controller `teamSAT`): Student, Section,
    Present, Absent, Late, Marked, Attendance % + a TOTAL row. Mirrors
    `report()`'s computation exactly; `report()` itself untouched (the
    controller was first re-based on remote master, which carries the
    hotfixed `report()` this branch had lost).
  - Payments manage (class filter) → `payments.export` (GET
    `payments/export/{class_id}`, controller `teamAccount`): Receipt No,
    Student, Title, Amount Paid, Balance, Date for every record of the
    class in the current session year, sorted by student name.
  - Student payments (invoice page) → `payments.export_student` (GET
    `payments/export_student/{id}/{year?}`): same columns for one
    student's cleared + uncleared records. The invoice links carry a
    *hashed* user id while `invoice()` never decodes it — the export
    resolves raw-id first, then `Qs::decodeHash`, so it returns the right
    rows either way (flagged for Workstream F: the invoice page itself
    likely suffers from the same undecoded hash). `invoice()` now also
    passes `$year` to its view so the button respects the year filter.
- **Print triggers**: the Loop 4 `@media print` block in qs.css already
  hides `.btn`, `.list-icons`, sidebar/topbar and card-header elements,
  so the list tables print clean — the pages only lacked a trigger.
  Standard `window.print()` Print buttons were added to the students
  list, the attendance report, and the payments manage page (shown once
  a class is selected). The invoice page already prints per-record
  receipts, so it received the Export button only.
- **Deliberately left as a proposal — marks tabulation export:** the
  tabulation *view* belongs to Workstream E, so no button was placed.
  Proposed endpoint for E/parent to wire: `marks.export_tabulation`
  (GET, same teamSAT/exam scoping as `marks.tabulation`), columns =
  Student, Admission No, then per subject `<Subject> Total` +
  `<Subject> Grade`, then Overall Total, Average, Position — built by
  the MarkController tabulation query, streamed through
  `CsvExport::download('tabulation-<class>-<exam>.csv', ...)`. The
  helper and pattern are drop-in ready.
- **Verification**: php -l clean on all 5 changed PHP files; CSV harness
  (standalone, exercises `CsvExport::write`) 10/10 — BOM present, commas,
  quotes, a multiline cell and ₹ amounts round-trip through `fgetcsv`,
  formula/`+`-leading cells neutralised; Blade balance 4/4 edited views;
  route cross-check 179 defined / 154 referenced / **0 unresolved**, all
  4 new names defined and referenced. Not runnable locally (no vendor/):
  full HTTP click-through — parent to verify post-push on the live site.
- **Process note**: this branch's `routes/web.php` additions were swept
  into sibling commit `a92e900` (Loop 8 K) by the shared working tree —
  all 4 export routes are present in HEAD; this workstream's own commit
  carries the helper, controllers, views and this log. The attendance
  report view (untracked on this lineage) was restored from remote
  master before its buttons were added.

### G — Customization: "har cheez customize hona chahiye"  (branch: feature/loop7-customization)
Audit first (live-verified): a Super Admin can edit school name, acronym
(system_title), phone, email, address, current session, term dates,
lock_exam, next-term fees per class type, and upload a logo — but branding
only partially flowed from those settings.
- Title bar on every page used `config('app.name')` = "Laravel", not the
  school. Fixed: master layout title suffix + meta author, login layout
  title, and stock auth layout title/brand now read the settings
  (`Qs::getAppCode()` / `Qs::getSystemName()`).
- Login page wordmark was a hardcoded `<h3>Hackstra</h3>`; now the
  settings acronym, and the uploaded logo (when set) replaces the icon mark.
- The uploaded logo was displayed NOWHERE in the app shell (only settings
  preview + print views). Sidebar brand now uses it via the new
  `Qs::getLogo()` helper with the bundled icon as fallback; settings
  preview uses the same helper (previously `<img src="">` when unset).
- `Qs::getSetting()` is now null-safe (a missing settings row no longer
  fatals every page that reads a setting).
- Settings page session dropdown never contained the live session
  (2018-2019 is outside its -3/+1 year window — live page confirmed: no
  option selected). The dropdown now always includes + selects the current
  value; option generation de-tangled (same label set, explicit values).
  Also fixed the "Update System Settungs" typo.
- Settings validation blocked real schools: school name `min:10` and
  address `min:15` rejected legitimate short values ("KV School"). Relaxed
  to min:3 / min:8 in SettingUpdate.
- Verified, no change needed: grades CRUD (resource routes, GradeCreate /
  GradeUpdate, teamSA + super-admin-only destroy) works and marksheet code
  (MarkRepo::getGrade/getGrade2) reads bands from the `grades` DB table
  with class-type → global fallback, matching GradeCalculator semantics.
  Current session flows via Qs::getCurrentSession() into dashboards,
  payments, promotions, timetables and the sidebar chip; no hardcoded
  session strings exist in app code (only the seeder default).
- Findings left as proposals: (1) fee "titles" are free-text per payment
  (PaymentCreate validates title/amount; no fee-head catalogue) — a
  catalogue table is a feature, not a patch; (2) term_ends / term_begins /
  next_term_fees are editable but have no live consumer beyond print-array
  passthrough; (3) marksheet/tabulation/receipt print views still emit
  `<img src="">` when no logo is uploaded — one-line fix in those views
  (other workstreams' files): use Qs::getLogo() for $s['logo'];
  (4) email_host/email_pass settings rows exist but have no UI fields.

### H — Attendance workflow depth  (branch: feature/loop7-attendance)
Owner files: `AttendanceController`, attendance views/routes, the attendance
menu items. NOT qs.css, NOT HomeController, NOT role homes.
- **Restored `AttendanceController@report`** — the `attendance.report` route
  and `report.blade.php` view existed on master but the controller method
  did not (the route would 500). Method rebuilt to the view's exact data
  contract (rows + totals, shared % rule); the view file itself was also
  missing from the local base and was synced from remote master.
- **Entry page:** class/date summary header chips (class, date, total
  students, marked X of Y, present/absent/late counts — dashboard badge
  style); "Mark all present" button (dependency-free inline script in the
  scripts section, rows stay individually editable); status radios
  restructured into three aligned, centred columns (Present/Absent/Late);
  student-name column sticky on horizontal scroll (page-scoped style).
  Save feedback (`flash_success`) verified present end-to-end.
- **Defaulter list** (`attendance.defaulters`, menu link under Attendance
  Report, same teamSAT gating): selectable month, class filter incl.
  "All Classes", threshold select 50/60/70/75/80/90 (default 75). Rows:
  student, class, section, present/absent/late/marked, % with progress
  bar, sorted % ascending. Rule identical to the report: % = (present +
  late) / marked; students with zero records in the month are excluded,
  never shown as 0%. Worked check: 6 present + 1 late of 10 marked =
  70% → listed under 75%; 8/10 = 80% → excluded; 0 marked → excluded.
- **Student/Parent visibility:** GAP FOUND — neither role could see any
  attendance (student menu: marksheet only; parent `my_children` page:
  no attendance). Minimal wiring added: `attendance.my` (the only
  teamSAT-exempt method) — a student sees their own month (summary
  chips, % bar, day-by-day table); a parent sees each linked child's
  month with a child switcher; staff are redirected to the report,
  other roles to the dashboard; honest empty states for unlinked
  accounts and record-free months. STILL NEEDED at integration:
  one-line menu links in `pages/student/menu.blade.php` and
  `pages/parent/menu.blade.php` (Workstream A files) pointing to
  `route('attendance.my')` so the page is discoverable.
- Verification: php -l PASS (controller, routes); Blade directive
  balance PASS on index/defaulters/my/report/menu (index's section/php
  deltas are the same inline-statement artifacts present in the
  original); route cross-check 5/5 attendance route names referenced
  in views exist in routes/web.php, including both new names.

### Integration batch 2 (parent, 2026-10-21) — D/E/F/G/H/I/J/K/L merged to master
Hand-merged shared files: routes/web.php (H defaulters/my + K admit + J exports on top of master), AttendanceController (H superset + J export()), StudentRecordController (master hotfixes + J export()), PaymentController (master + J exports + invoice hash-resolution + authorize('view')), partials/menu (master B-avatar + G logo + H defaulters item + K admit item), auth/login (B 480px + G logo/acronym), student menu (E year_selector fix + K admit + My Attendance), parent menu (K admit + Attendance), payments/invoice (F rebuild + J export button). Docs added: CASE_STUDY.md (D), UX_STANDARDS.md (L), UPGRADE_PLAN.md + LIBRARY_AUDIT.md (I). Hotfixes in the same window: GET /students 500 (missing index), GET /users/create + 6 modules' create/show 500s, student store() null-guard.

### E — Marksheet & results workflow  (branch: feature/loop7-marksheet)
Owner files: marks pipeline views (print/show/tabulation) only. MarkController
logic and its dynamic-field update() untouched, per prior-loop note.
- **Print marksheet is now a complete document:** school header gains a
  phone/email contact line (settings-backed, prints only when set); the
  identity block shows Name / Adm No / Class / **Section** (student's own
  section via `$sr->section`) / Term / Academic Year / House / Age, left
  aligned; subjects table — subject + remark columns left-aligned, all
  score columns centered, totals row bold and now includes **Position**
  (`exam_records.pos`, suffix-rendered) alongside Total / Final Average /
  Class Average; **grade scale legend re-enabled** (the print.grading
  include was commented out — legend + distinction/credit/pass/fail counts
  from the grades table via `Mk::getGradeList`); class teacher + principal
  comments with blank-line fallbacks (unchanged); next-term fees symbol
  fixed from the legacy Naira mark to ₹; skills include null-guarded.
- **On-screen marksheet (student/parent facing):** title cleaned (stray
  `=>` removed; header shows the student's own section, not the class's
  first section); new identity strip (Adm No / Class / Section / Session);
  sheet table wrapped in a real `.table-responsive` div (class was on the
  `<table>`, where it does nothing) so it scrolls on mobile; totals row
  gains Position, values bold; a **Grade Scale** legend card renders once
  per page from the grades table; the staff skills-entry forms are now
  gated to TeamSAT (they were rendered to students/parents, whose submits
  the teamSAT route middleware would have rejected anyway).
- **Tabulation (screen + print):** consistent alignment — student names
  left, every score column centered, Total/Average/Position bold (screen:
  Bootstrap text-danger/primary/success classes replace inline colors);
  `NAMES_OF_STUDENTS_IN_CLASS` header de-underscored; bogus `rowspan="2"`
  on single-row headers removed; screen table wrapped in
  `.table-responsive` div; print sheet restores the school logo in the
  header (was commented out) for the same header treatment as the
  marksheet.
- **Student/parent visibility audit:** students CAN reach their published
  marks (menu → year selector → marks.show, ownership + PIN lock enforced
  in the controller; parents via My Children → per-child Marksheet, which
  already used the correct route). One defect fixed: the **student menu
  linked to the POST route name `marks.year_select`** — same URL as the
  GET `marks.year_selector`, so it worked by accident; corrected to
  `marks.year_selector` (identical URL, zero behavior change). Noted only:
  the year-selector page itself is reachable for any logged-in user, but
  it exposes nothing beyond exam years; marks.show/print enforce access.
- **Live check (2026-10-21):** scripted Super Admin login on
  hackastra.onrender.com — /marks, /exams, /marks/tabulation all 200;
  year selector + marksheet show respond with the app's no-record redirect
  for every demo student (the demo seed contains no exam/mark records),
  so the templates were verified in code — local copies are byte-identical
  to remote master for all 13 pipeline files. Incidental: GET /students
  returns 500 on the demo while /students/list/{class} is 200 (outside
  this workstream's scope — flagged for the parent).
- Verification: Blade balance 8/8 edited views OK; every route() name in
  edited views resolves (marks.print, marks.print_tabulation,
  marks.tabulation_select, marks.year_selector); 0 PHP files changed.

### F — Payments & receipts workflow  (branch: feature/loop7-payments) — DONE
Owner files: payments views, Payment model fillable, App\Support. Audit of the
full money flow (live crawl as Super Admin + code read) found:
1. Demo seed has NO payments/payment records at all (no PaymentsTableSeeder
   exists) — index year picker has zero options and every invoice is empty on
   the live demo; the flow can only be exercised once payments are created.
2. PDF receipt links were commented out in invoice.blade.php (both tabs) —
   the working pdf_receipts route was unreachable from the UI.
3. The shared `.download-receipt` JS handler swallows downloads (AJAX GET +
   toast, file discarded) — the restored links deliberately avoid that class;
   the commented-out cleared-tab link also used a stale `$uc` variable.
4. All amounts rendered raw (no Indian grouping, no ₹) in index/invoice/
   receipt; legacy Naira `<del>N</del>` marks in receipt headers + create/
   edit amount labels.
5. Receipt had no amount-in-words, no totals, no payment method, and relied
   on an external receipt.css that dompdf cannot fetch (PDF risked rendering
   unstyled); settings phone/email unused; empty logo produced a broken img.
6. Invoice "Incomplete" tab could not distinguish Not Paid vs Partially
   Paid; neither tab had totals or honest empty states (students with no
   records saw two bare DataTables).
7. `payments.method` was silently dropped on save (missing from Payment
   $fillable) — the create form's method choice never persisted.
8. Parent/student fee visibility does not exist: no routes/views for them
   (payments are teamAccount-only; the only parent route is my_children).
   Reported, not built — needs its own scoped feature.
9. PaymentController::invoice has no authorize() call (receipts/pdf do) and
   PaymentRecord hashes rotate daily (Hashids salt includes the date).
   Reported for a parent decision; authorization was not changed.

Fixes shipped: every display amount in payments views now uses Money::inr
(form inputs and JS data-amount attributes stay raw on purpose — the pay-now
JS reads data-amount and rewrites cell text unformatted after an in-page
payment; cosmetic, shared-JS file, noted); ₹ labels replace Naira marks;
status badges (Not Paid / Partially Paid / Paid); totals rows on index,
per-class tabs and both invoice tabs; honest empty states; PDF receipt
links restored in both tabs; receipt rebuilt as a professional
self-contained printable document (inline CSS so web print and dompdf PDF
are identical, settings header with phone/email, receipt no/date/session,
student + payment blocks incl. method, installment table with Total Paid,
summary with CLEARED / BALANCE DUE stamp, amount in words, signatory line);
new App\Support\NumberToWords (Indian system: Lakh/Crore, paise support)
with tests/Unit/NumberToWordsTest.php (4 methods, 23 assertions, executed
green on real PHP via the TEST_REPORT stub-TestCase approach); 'method'
added to Payment $fillable. Live pages re-verified 200: /payments,
/payments/create, /payments/manage, /payments/manage/{class}, invoice.
Receipt page itself could not be opened live — no payment records exist
in the demo data (finding 1); it is verified by code read + Blade balance
+ route cross-check, and should be click-tested once a payment exists.
