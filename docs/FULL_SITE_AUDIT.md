# FULL-SITE AUDIT — Hackstra (owner directive 2026-10-23 01:17 IST; mobile bar raised 01:30; clipped-controls class added 01:31)

**Tree audited:** `hackastra-batch146` @ `deec715` (== live master + Exam Date-Sheet), fresh `migrate:fresh --seed`, app served locally on :8937. **qa-smoke: PASS** before probing.
**Method:** independent re-derivation from the running app — 5 parallel audit clusters (C1 SA/dashboards/chrome, C2 students/CRUD, C3 exams/marks/timetables, C4 attendance/fees/payroll, C5 comms/LMS/settings), ~350 rendered pages captured across all 7 roles + anon, every money figure reconciled to sqlite, dropdown contents quoted from rendered HTML, phone verdicts derived structurally from rendered HTML + `qs.css` rules (named mechanism per row). No app file was edited; no form was submitted. Full per-page evidence: Appendices A–E (the cluster files, verbatim).
**Limitations (stated once, applies throughout):** (1) No browser/CDP is available on this VM (Chrome 152 Local-Network-Access blocks loopback), so FIT/phone verdicts are structural, not pixel-measured — each names the CSS/markup mechanism so a fix squad can verify in a real browser. (2) The seed's `feedback_items` is empty → feedback *detail* pages are unverifiable with data (list/empty states audited). (3) No non-Live circular exists in the seed → the circulars `show()` Live-only question remains unverifiable here. (4) The owner's live-only timetable record "term 1" does not exist in a fresh seed (live DB reseeds) → see Register note R-23.

**Verdicts:** MATCH (correct vs reference/system standard) · DEVIATION (works, falls short in a named way) · DEFECT (wrong/clipped/dead/misleading) · BROKEN (500 / wrong response type). KNOWN = already queued before this audit.

## Summary counts

| Cluster | Rows | MATCH | DEVIATION | DEFECT | BROKEN | Notes |
|---|---|---|---|---|---|---|
| C1 SA + dashboards + My Account + chrome | 34 (+7 menu-sweep, +7 clipped-class) | 22 | 10 | 5 | 1 | Phone: 14 MATCH · 7 DEV · 2 DEFECT |
| C2 Students + users + academics CRUD | 51 | 37 | 8 | 4 | 2 | Phone: 21 MATCH · 24 DEV · 4 DEFECT · 2 BROKEN |
| C3 Exams + marks + date-sheet + timetables | 65 (+3 clipped, +10 phone) | 47 | 7 (+1 partial) | 4 | 1 | + KNOWN 4 · 1 KNOWN suspicion CLEARED |
| C4 Attendance + fees + payroll + parent fees | 82 | 69 | 11 | 2 | 0 | Every row carries a separate phone verdict |
| C5 Circulars + feedback + Ask + LMS + library + settings | 59 desktop / 18 phone | 50 / 11 | 7 / 5 | 0 / 2 | 0 / 0 | 2 desktop rows unverifiable (empty seed) |

**Anchors reconciled exactly, everywhere they appear:** 55 users / 37 students · fee defaulters **11 / ₹2,79,700** (Partial 7 / ₹1,62,700 · Unpaid 4 / ₹1,17,000) · collections **99 receipts / ₹10,31,300**, avg ₹10,417, 33 students · annual report (Aarav) **Total 127 / Avg 63.5 / Class Avg 69.5 / Position 4th** on screen, print AND PDF · payslip (Ananya Sharma, Oct 2026) **₹45,000 + ₹6,500 − ₹2,500 = net ₹49,000**, paid 05 Oct 2026 · circulars chips per role exact vs DB · assignments/materials/library counts exact vs DB · menu sweep **177 hrefs, 0× 404/500, 0× →/login**. One seed artefact (not an app defect): this fresh seed dates receipts **23 Oct 2026** (a prior seed said 22 Oct); DB and every page agree with each other.

---

## DEFECT REGISTER — the fix-wave list (DEFECT + BROKEN only)

### Money / data correctness
| # | Page × role | Defect |
|---|---|---|
| R-01 | Parent `/my_fees` | **Balance column wrong on every fully-paid row**: shows the full fee (₹18,000 / ₹6,000 / ₹1,500) while the same card's Total row says Balance ₹0 "Fees clear" and DB `payment_records.balance` = 0 for all six records. Contradictory money on one screen. The `/my_fees/{id}` detail page is correct — defect is local to the index cards. (C4) |
| R-02 | Student profile + both ID Cards (all roles) | **Stock silhouette shown as the student's photo.** All 55/55 users have `photo = …/global_assets/images/user.png` (the stock silhouette). Profile renders it directly; the ID cards layer the same `<img>` absolutely over the `.idc-initial` initials span (qs.css `.idc-photo img{position:absolute;object-fit:cover}`), so the initials fallback never shows and `onerror` never fires. The students *list* does it correctly (initials tiles). Reference rule "photo = real or initials, never a stock silhouette" is violated wherever the seeded/default photo value is the stock file. (C2-D1) |

### Clipped-controls class (owner-named, 2026-10-23 01:31 IST) — one mechanism, seven surfaces
Row-action dropdowns in the **right-most table column** whose menus open/clip wrongly, or whose parent is a horizontal-scroll container that swallows the menu:
| # | Page × role | Mechanism |
|---|---|---|
| R-03 | `/users` (SA + admin), all 18 rows | `dropdown-menu dropdown-menu-left` in the right-most column, 0 `dropdown-menu-right`, inside `.table-responsive` → menu escapes at 390px, clipped by the scroll container on desktop. (C1) |
| R-04 | `/students/list/{class}` (admin + teacher) | Row-action menus `dropdown-menu-left` in the rightmost column → escape/clip. The same page's Export menu correctly uses `dropdown-menu-right`. (C2-D2) |
| R-05 | `/payments/manage/{class}` (admin + accountant) **KNOWN page** | Per-student "Manage Payments" dropdown renders `dropdown-menu-left`; qs.css contains **zero** `dropdown-menu-left` rules to rescue it → on phone the menu escapes the viewport (owner's screenshot complaint, mechanism pinned). (C4) |
| R-06 | `/exams` Manage Exams (admin) — **the owner's trigger instance** | "Actions" is the last column of a plain table inside `.table-responsive` with the dropdown parented inside that scroll container; `dropdown-menu-right` IS present but cannot save it — no min-width/sticky action column/phone rule exists for this legacy page in qs.css. (C3-C1) |
| R-07 | Marks entry grid kebab menus (teacher/admin) | Same scroll-container parenting mechanism as R-06. (C3-C2) |
| R-08 | `/timetables` "More actions" (admin) | Dropdown inside `.l7-table-scroll` (`min-width:600px`) — menu trapped by the scroll container. (C3-C3) |
| R-09 | Circular create + edit (admin) @390px | Sticky `.pat-actionbar` is flex `justify-content:flex-end`, `gap:10px`, **no flex-wrap** in base or ≤767 rules; Bootstrap `.btn{white-space:nowrap}`; the 3 buttons (Save as Draft / Cancel / Publish Circular ≈340–360px min-content) exceed the ~330px inside the bar → **Save as Draft clips off the left edge**. (C5) |
| R-10 | Assignment show `/assignments/{id}` (teacher) @390px | Submissions table carries inline `style="min-width:290px"` on the "Marks & Feedback" column holding the marks/feedback/Save grading controls → the primary grading action sits behind horizontal scroll on phone. (C5) |
| R-11 | Global topbar @390px (all roles) | Right cluster is unshrinkable: `.lav-clock` (nowrap, ~150px) + session chip (`flex:none`) + theme/bell/avatar ≈ 390–430px; grep-verified **no @media rule** hides/shrinks the clock or wraps `.lav-topbar-right`. (C1) |

### Broken responses
| # | Page × role | Defect |
|---|---|---|
| R-12 | `/users/{raw-id}/edit` (admin/SA) | **500** Ignition ViewException ("Attempt to read property id on null", edit.blade.php:12) when the id is raw instead of `Qs::hash`ed. In-app (hashed) links work — but any hand-typed/bookmarked raw URL 500s instead of 404ing. (C1/C2) |
| R-13 | `/exams/{Qs-hash}/edit` (admin) | **500** "Attempt to read property 'name' on null" — the inverse failure: this route does NOT decode the hash its sibling pages use. Part of the route-parameter chaos class (see Cross-cutting X-2). (C3) |
| R-14 | `/timetables/mine/pdf` + `/print` (student, parent) | Fall through to the list page: **PDF route returns `text/html`**, not a PDF. Teacher equivalents are a real document/PDF. (Mitigation: the on-page Print button uses `records/print`, which works and is correctly scoped.) (C3-F55) |

### Wrong surface / missing function / silent truncation
| # | Page × role | Defect |
|---|---|---|
| R-15 | `/users/create` (admin + SA) | **`user_type` select cannot create a Librarian** — options verbatim: Accountant, Parent, Teacher, Admin. A whole role is un-creatable from the UI. (C2-D3) |
| R-16 | SA platform | **SA has no settings surface**: GET `/super_admin/settings` as SA redirects to /dashboard, while the school **Admin** opens the same URL with 200 (admin's session chip even links there). Inversion of the platform boundary. (C1) |
| R-17 | SA sidebar | **3 of SA's 11 menu links are dead ends**: Ask Hackstra, Give Feedback, My Feedback all redirect to /dashboard. (C1) |
| R-18 | SA platform mail | Recipient select lists **all 55 users including all 37 students** under copy that says "Message **platform accounts**" — contradicts the platform boundary on a send-capable form. (C1) |
| R-19 | SA `/super_admin/audit-logs` | **Silently truncates**: renders exactly 12 rows, no pagination / no "showing X of Y", while the DB held 44 rows at seed (130 after audit logins). Older platform audit history is unreachable. (C1) |
| R-20 | `/marks/show/...` (student/parent) @phone | Report-card tables have **no scroll wrapper** and only `@media print` treatment → uncontrolled phone rendering of the widest document table students actually open. (C3) |
| R-21 | `/subjects` Manage Subjects @phone **KNOWN** | Mechanism pinned: 6-column table in double-nested `.table-responsive`; `.content .table thead th` has no `nowrap`/min-width and zero `@media` rules target the page → the owner's "SHOR T NAME" wrap + clipped TEACHER column. (Desktop content now MATCH: Action column exists; Class select = class names only; Teacher select = the 4 teachers only — no dropdown-collection defect here.) (C2-P1) |
| R-22 | Fix Mark Errors — menu label **"Marks Correction"**, `/marks/batch_fix` **KNOWN, owner ordered removal 01:10 IST** | Still live in this tree: page returns 200 with a working form. Removal is queued in batch145; recorded here so the picture is complete. (C3) |
| R-23 | Timetable records edit — owner's "Ankit kumar in Class dropdown" screenshot | **Suspicion CLEARED on seed evidence**: rendered `/timetables/records/edit/1` Class select = the 10 class names with "Nursery 1" selected (no person); Type select = "Class Timetable" / "Term 1 Examination" (so the screenshot's Type value was the record's legitimate exam-type label). Caveat: the screenshot's record ("term 1") was created on the live demo DB, which reseeds — it does not exist in any fresh seed, so its stored values cannot be re-derived. If it recurs, it is stored-data, not form wiring. (C3) |

---

## DEVIATION themes (detail in appendices)

- **Reference fidelity:** Teacher dashboard renders **5 KPI cards vs the Loop 79 reference's 4** ("Assignments to Grade" is extra; all five values are DB-true and scope-correct: 6 subjects / 5 sections / 16 students). Marks grid **published-state banner never renders** (`.l86-pub-banner` exists in qs.css, 0 occurrences in the HTML; exam 1 is unpublished and the grid says nothing). Annual-report URLs with the literal segment `2018` silently redirect to /dashboard — only `2018-2019` works. `/marks` selector's step indicator hides all steps at ≤767px unless JS adds `.is-current`/`.is-done` (none in rendered HTML).
- **Inconsistent rules between sibling pages:** attendance report legend uses **Critical <60%** while Low Attendance/defaulters use the settled **Critical <50%**; `/attendance/periods/export` silently exports only the default JSS 2/English scope (**3 rows**) instead of the 36 sessions the page lists; accountant/librarian get a redirect (not the settled 403) on `/timetables/mine`; admin dashboard "Today's Attendance 100% / 26" never names its denominator's scope.
- **Dead/blank furniture:** `/users` Phone column empty on all 18 rows (DB `users.phone` NULL for all 55); SA config checklist says "4 of 5" but silently excludes `school_stamp=''` and `principal_name=''` from its item set; `/payments/{id}` show is a dead-end redirect; `/payments/manage/{Qs::hash}` silently falls back to the picker (this route takes raw class ids, unlike `{id}` routes); `/my_children` renders a blank table skeleton with no empty-state text for unlinked parents; My Feedback has **no status filter chips/form at all** (Loop 87 expectation); Study Materials repeats empty "Mathematics" groups ×3 (per class×subject grouping); assignment download for an attachment-less assignment returns a misleading **403** (no UI button exposes it, so impact is low); materials link-download silently bounces via `back()` with no visible error; students cannot see even their own library loans (`/book_requests` redirects home); Settings renders the `ai_api_key` field id/name **twice** (duplicate IDs across two forms); **public registration is open** — stock un-redesigned Laravel scaffold form with a live POST route (recorded factually; product decision).
- **Phone pattern (systemic):** qs.css carries 174 `@media` blocks, and dashboards/stat grids are well covered — but **zero `@media` rules target any academics-CRUD table**: every Classes/Sections/Subjects/Grades/Dorms/Events index is a desktop table behind horizontal scroll at 390px (24 phone DEVIATIONs in C2 alone). Loops **79, 80, 86, 87, 88, 89, 93, 95, 96 contain no `@media` at all** in their own blocks (Teacher Dashboard, Monthly Report screen, My Feedback, Ask, Payslips, Collect Fee, Fee Defaulters, Parent Dashboard) — their phone behaviour is inherited, not designed. Phone DEFECTs beyond the clipped class: R-09, R-10, R-11, R-20, R-21.

## Cross-cutting findings (auditor's own re-derivation)

- **X-1 qs.css integrity: VERIFIED.** Whole-file brace balance diff **0** (4,217 / 4,217); all **7** "Loop 91 repair" markers present; Loop blocks 75–103 present (98/101 were numbering skips in the original work, not missing CSS). The structural-repair claim holds on this tree.
- **X-2 Route-parameter binding is inconsistent per module** (the `{id}` → `Qs::decodeHash` rule is applied unevenly): users/students REQUIRE the hash (raw → 500, R-12), exams edit BREAKS on the hash (R-13), grades **500 on the hash**, events **404 on the hash**, classes/sections/subjects/dorms **silently return the index** on the hash, `/payments/manage` takes raw ids. In-app links are generated correctly (menu sweep: 0 dead), so users hit this via bookmarks, hand-typed URLs, and cross-page links — but a paying school's staff will. One convention, enforced everywhere, is the fix shape.
- **X-3 No reference leakage:** the string "Bangalore" (the invented address on the owner's Monthly-Report reference image) appears **nowhere** in any blade; monthly-report letterhead is settings-driven; signature/stamp render only when configured (seed has neither → blank lines, honestly). The 8 `session('flash_` hits in the tree are the single central flash renderer — no per-page flash blocks survive.
- **X-4 Theme:** dark is the server-rendered default (`<html class="hk-dark">` from the per-account preference, default `'dark'`); light is a stored per-account choice. All authenticated pages probed render `hk-dark` on fresh login.
- **X-5 Security scoping held everywhere probed:** SA refused from school modules (302 per settled law); teacher has no marksheet/admit-card menu items and is refused at the routes; student/parent foreign-record access refused (403/redirect); parent foreign receipt `pr_id` → 403; accountant gated out of payroll while fee flows stay open; teacher/librarian refused foreign payslips.

## KNOWN items — verified current state

| Known item | State in this tree |
|---|---|
| SA platform pages old-style | Confirmed (legacy `.card` chrome on audit-logs, platform-mail, users/create) + new specifics R-16/R-17/R-18/R-19 |
| Manage Exams old-style | Confirmed (legacy markers, C3) + trigger defect R-06 |
| Manage Subjects old-style | Desktop content MATCH; phone clipping confirmed with mechanism (R-21) |
| Fix Mark Errors removal (owner-ordered) | Still live as "Marks Correction" (R-22) |
| Timetable edit dropdown suspicion | **CLEARED** on seed evidence (R-23) |
| Phone-fit overflows | Confirmed as a pattern (see Phone theme) with per-page mechanisms in appendices |
| Payments class-list A4 print | Confirmed: Print = `window.print()` of the screen table, no print-only document (unlike Fee Defaulters' designed one-pager) (C4) |
| My ID Card old design | Confirmed old markers; plus the stock-photo defect R-02 lives on it |

---


---

# APPENDIX A — Cluster C1: SA platform, dashboards, My Account, global chrome/menus

# Cluster C1 Audit — SA Platform + All Dashboards + My Account + Global Chrome/Menus
App: http://127.0.0.1:8937 (batch146, fresh seed) · Probe: /tmp/audit/probe.py · HTML evidence: /tmp/audit/html/<role>__<slug>.html · Sweep data: /tmp/audit/c1_sweep.json
DB anchors verified in sqlite: 55 users (SA 1, admin 4, teacher 4, student 37, parent 4, accountant 4, librarian 1); receipts table = 99 rows / ₹10,31,300 (matches money anchor); payment_records = 111 rows (78 paid ₹9,08,000 + 33 unpaid ₹1,23,300, total ₹10,31,300); defaulters = 11 distinct students / SUM(balance>0) ₹2,79,700 ✓; settings logo='' , school_stamp='' , principal_name='' ; session 2018-2019.

Verdict key: MATCH / DEVIATION / DEFECT / BROKEN. Phone = structural verdict at 390px from HTML + qs.css @media rules (no browser available).

## 1. Super Admin platform (role sa)

| Page (URL) | Role | Verdict | Phone | Evidence / defect |
|---|---|---|---|---|
| /home (Platform Overview) | sa | MATCH | MATCH | 200, title "Platform Overview \| HA", H1 greeting ≠ title (no title-echo). KPIs reconcile: "Total Users 55" = DB 55; Users-by-Role table 1/4/4/37/4/4/1 = DB user_type counts exactly; School Snapshot "Active students 37, Teachers 4, Total fee collection ₹10,31,300" = receipts SUM ✓ and is labelled read-only ("Day-to-day school operations are managed by the school Admin"). KPI row uses Bootstrap `.row.dashboard-stats > .col-sm-6.col-xl-3` → 2-across at 576–1199px, 1-across <576px; 3 tables all inside `.table-responsive` (3 wrappers). |
| /home — Configuration Checklist | sa | DEVIATION | MATCH | Shows "4 of 5 key settings completed · Missing: School logo" with pills: School name Set / School logo **Missing** / Contact phone Set / School email Set / Current session Set — logo flag is HONEST (settings.logo=''). But checklist scope silently excludes two other empty settings in DB: school_stamp='' and principal_name='' are not checklist items at all, so "4 of 5" overstates platform configuration completeness. `.platform-snap-grid` collapses 2→1 col in qs.css (media rule present). |
| /dashboard | sa | MATCH | MATCH | 200, final /dashboard, body identical to /home (Platform Overview, 43.5–44.8 KB) — SA has one platform home under both URLs, no separate school dashboard leaks in. |
| /super_admin/audit-logs | sa | DEFECT | DEVIATION (KNOWN old-style) | 200, title "Audit Log \| HA". Page renders exactly **12 data rows** (13 `<tr>` incl. header) while DB audit_logs held 44 rows at seed and 130 after audit logins; **no pagination markup, no "Showing X of Y", no load-more** anywhere in the HTML — older entries are unreachable from this page (SA home's "View All" leads here). Legacy chrome: content uses a plain Bootstrap `.card` (1 legacy `.card`, 0 hk-/Loop classes in content). Phone: single table inside 1 `.table-responsive` wrapper → desktop table dumped on phone with horizontal scroll; only generic `@media 767px` cell-padding rule (qs.css:155) applies, no phone layout. |
| /super_admin/platform-mail | sa | DEVIATION | DEVIATION | 200 "Send Platform Mail". Honest SMTP banner: "SMTP is not configured on this deployment (no mail host set), so messages will be written to the mail log instead of being delivered." BUT audience copy says "Message **platform accounts**" while the Recipient `<select>` lists **all 55 users including all 37 students** (e.g. "Aarav Sharma (student@hackstra.app) — Student") — contradicts the platform-accounts framing and the SA view-only/platform boundary. Legacy `.card` chrome (KNOWN old-style family). Phone: 3 native `<select>`s — safe from escape, but recipient option text ("Name (email) — Role", up to ~60 chars) truncates in the closed field at 390px; form gets only generic card padding @767. |
| /super_admin/platform-mail/sent | sa | MATCH | MATCH | 200 "Sent Mail \| HA". Honest empty state: "No platform mail has been sent yet. Sends from the mail composer will appear here — delivered, log-recorded, or failed." + Compose Mail link (real href). No fabricated send history. |
| /feedback/school | sa | MATCH | MATCH | 200 "School Feedback \| HA". All counters 0 and honest empty state "Nothing forwarded yet. School Admins forward compiled, de-duplicated reports here." Filter selects (Handling/Type/Area/Priority) + Filter/Reset present. Phone: filter form uses `.ma-filter-form` which has a real phone rule — qs.css:4447 `@media …{.ma-filter-form{flex-direction:column;align-items:stretch}.ma-filter-action .btn{width:100%}}` and `.ma-toprow{flex-wrap:wrap}` (4438) → stacks safely. Legacy `.card` present (KNOWN old-style). |
| /super_admin/settings | sa | DEFECT | n/a (redirect) | GET as SA → **redirects to /dashboard** (probe final=/dashboard, body = Platform Overview). SA therefore has **NO working platform-settings surface of its own**, despite (a) the SA sidebar/topbar implying platform administration and (b) the session chip on ADMIN's topbar being an `<a href="/super_admin/settings" title="Open settings to change the current session">` — and as **admin** the same URL returns 200 on itself (verified in sweep). Inversion: the school Admin can open the settings page the platform owner cannot. |
| /payments, /students, /exams (PlatformOnly probes) | sa | MATCH | n/a | All three → final **/dashboard** (302 followed to 200 Platform Overview), exactly the settled law (SA school GET → 302 /dashboard). No school data leaks into SA responses. |
| /my_account | sa | MATCH | MATCH | 200 (see §4 — same Loop v3 build; SA variant renders). |

## 2. Platform Users (/users)

| Page (URL) | Role | Verdict | Phone | Evidence / defect |
|---|---|---|---|---|
| /users | sa | DEVIATION | DEFECT (see §7) | 200 "Manage Users \| HA", header "18 users shown" = 55 − 37 students exactly (all non-student accounts). Composition: teachers 4, admins 4, parents 4, accountants 4, librarian 1, super_admin 1. Settled owner expectation is "SA sees platform accounts only" — SA actually sees **every school staff account** (teachers/parents/accountants included), although its row actions are correctly scoped: Edit/Reset/Suspend/Terminate/Delete appear ONLY on admin rows + SA's own row; teacher/parent/accountant/librarian rows offer only "View Profile". Filter tabs include "Super Admins" (admin's view omits it). |
| /users | admin | MATCH | DEFECT (see §7) | 200, same 18 rows. Boundary correct in this direction: the SA row (Ankit Kumar) shows ONLY "View Profile" — admin cannot edit/reset/delete the SA account; all school-staff rows carry full actions. Columns both roles: S/N, Photo, Name, Username, Phone, Email, Status, Action. |
| /users — Phone column | sa + admin | DEVIATION | — | The **Phone column is empty for all 18 rows**; DB check: `users.phone` is NULL/'' for all 55 users in the seed. Column is dead weight on both views (renders an empty cell per row). Data-true to the seed, but a permanently blank column on a flagship list. |
| /users/create | sa | DEVIATION | DEVIATION (KNOWN old-style) | 200 "Create New User \| HA" as SA — SA can open the school user-creation form (Role select: Accountant/Parent/Teacher/Admin — no Student, no Super Admin). Sits oddly with SA view-only-inside-schools law: SA is blocked from editing a teacher (below) yet can open create. Legacy form_page chrome (plain `.card`). Phone: long multi-section legacy form, only generic @767 card padding; Nationality `<select>` with ~190 long country-name options (native select, no escape, closed-field truncation at 390px). |
| /users/create | admin | MATCH | DEVIATION (KNOWN) | 200, same form — correct surface for school Admin user management. |
| /users/{hash}/edit (teacher user 3, hash 5vYzrgwM81emy9) | admin | MATCH | MATCH | 200 "Edit User \| HA" — hashed link from the list works end-to-end. |
| /users/{hash}/edit (teacher user 3) | sa | MATCH (boundary) | n/a | Redirects to /home — SA cannot edit a school teacher account; consistent with SA view-only + platform-accounts-only editing (SA CAN open /users/WaPO7Z1Lgbd16A/edit for admin user 2 → 200 "Edit User"). |
| /users/3/edit (RAW id) | admin | BROKEN | n/a | **500** — body is an Ignition ViewException page: "Attempt to read property "id" on null in …/pages/support_team/users/edit.blade.php on line 12". Qs::decodeHash on a raw id yields null and the edit view dereferences it instead of 404ing. In-app links are hashed so normal navigation is safe, but any hand-edited/bookmarked raw-id URL 500s with a stack-trace page. |

## 3. Dashboards (/home per role; admin also /dashboard)

| Page (URL) | Role | Verdict | Phone | Evidence / defect |
|---|---|---|---|---|
| /home + /dashboard | admin | MATCH | MATCH | Both 200 "Dashboard \| HA", near-identical bodies (66,659 vs 66,665 B). KPIs reconcile to DB: Total Students **37** ✓, Teaching Staff **4** ✓, Parents **4** ✓, Administrators **5** (= 4 admin + 1 super_admin — defensible count, labelled "System administrators"). Fee Collection Overview: Total ₹13,11,000 = Collected ₹10,31,300 (= receipts SUM ✓) + Pending ₹2,79,700 (= defaulter balance SUM ✓), 79% ring = 1031300/1311000 ✓. Calendar widget renders real Oct 2026 grid; Enrollment Trend ends at 37 ✓; Recent activity / Upcoming Events (PTM 26 Oct, Sports Day 29 Oct) are real seeded records. Phone: `.dashboard-action-grid` → 2 cols @767 (qs.css:60), `.dashboard-insight-grid` → 1 col @767, hero stacks @767 (qs.css:22), stat cards shrink @480 (qs.css:287ff); tables in `.table-responsive`. |
| /home — Today's Attendance widget | admin | DEVIATION | — | Widget claims "100% — Present 26, Absent 0, Late 0, Total 26". 26 is period-register entries scope, not 37 enrolled students; the widget never says which scope, so "100% attendance" reads as whole-school while 11 students have no mark in its denominator. Numbers are internally consistent; the labelling is the deviation. |
| /home (Loop 79 reference) | teacher | DEVIATION | MATCH | 200. All KPI **values** reconcile to DB scope for teacher user 3 (Ananya Sharma): My Subjects **6** = subjects.teacher_id=3 ✓; My Classes **5** = sections.teacher_id=3 ✓ ("· 3 classes" = distinct my_class_id ✓); Total Students **16** = student_records in classes 1+2+4 (7+6+3) ✓; Today's Periods **6** ("2 submitted · 4 to take") ✓. **Deviation: the KPI grid renders FIVE cards, not the Loop 79 reference four** — `.t10-kpi-grid` contains a 5th card "Assignments to Grade 2 · Ungraded submissions" (qs.css base rule is literally `grid-template-columns:repeat(5,…)`). Values are real (2 ungraded submissions, also in Today's Tasks + Notifications), so this is a spec-count deviation, not fake data. Minor: "My Subjects" card is a `<div>` while the other four KPI cards are `<a>` links. Today's Timetable table has Status pills (Completed/Upcoming) + View/Take actions with real hrefs ✓; 2×2 Quick Actions ✓ (Take Attendance / Enter Marks / View Timetable / Manage Students); Today's Tasks are derived pending-work links (4 attendance + grading) ✓; circulars panel is headed "Notifications" and mixes a grading alert with the 2 circulars (no priority badges shown on this panel, unlike student/parent panels which render Normal/Important badges). Accepted deltas not counted: greeting icon, Take Attendance buttons, no Tasks "View All". Phone: `.t10-kpi-grid` collapses 5→3 (@1399) →2 (@767) →1 (@575) and `.t10-main` → 1 col — genuine collapse rules exist. |
| /home (Loop 99) | student | MATCH | MATCH | 200. Aarav Sharma, Nursery 1 · Gold ✓ (student_records id 1: class 1, section 1). Fee Status "Paid in full — ₹25,500 paid · nothing outstanding" = 18,000+6,000+1,500 receipts ✓; My Attendance 100% (4 marked days: Present 4 / Late 0 / Absent 0); Subjects in My Class **2** ✓ (Nursery 1 has English Language + Mathematics, both shown with teacher Ananya Sharma ✓); Today's Classes table with Upcoming pills ✓; Recent Circulars render priority badges (Normal) ✓. Minor wording tangle in one KPI sublabel: "Open Assignments 0 — All caught up — 1 open, already submitted" (0 unsubmitted vs 1 open-but-submitted; the Assignments Due panel correctly shows that 1 as "Submitted"). Phone: `.stu99-kpis` → 2 cols @1199 → 1 col @479; `.stu99-asg-row` stacks @575; `.stu-stats-row` → 2 @767 → 1 @479. |
| /home (Loop 96) | parent | MATCH | MATCH | 200. "2 children linked" ✓ — DB student_records.my_parent_id=4 → exactly 2 (Aarav Sharma user 18, Layla Anderson V user 19), both rendered as child cards with correct class/section (Nursery 1 · Gold), Adm Nos (HA/N/2026/99128 ✓ matches student My Account), "Paid in full", attendance 100% 4/4 each. Fees Paid ₹51,000 = 2 × ₹25,500 ✓; Fees Due ₹0 ✓. **Child switcher verified by links**: /my_fees/18 and /my_fees/19 both 200 on themselves; /attendance/my?student_id=1 and =2 both 200; /timetables/mine?child=1 and =2 both 200 — switcher targets are real and per-child. Recent Circulars with Normal/Important badges ✓. Phone: `.p96-kpi-grid` 5→3 @1399 →2 @767 →1 @575; `.p96-children`/`.p96-bottom` → 1 col @1199; `.p96-child-links` → 2 @767; welcome stacks @767 (qs.css:5751–5762). |
| /home (Loop 92) | accountant | MATCH | MATCH | 200. **Anchor reconciles exactly**: Collected Today ₹10,31,300 "99 payments recorded", Collected This Month ₹10,31,300 (October 2026), Total Collected ₹10,31,300, Outstanding Fees ₹2,79,700 "11 students with fees due", Payments Today 99 receipts — all = receipts table (99 rows, SUM ₹10,31,300) and defaulter anchors ✓. (Underlying payment_records has 111 rows incl. 33 unpaid; the page consistently uses the receipts ledger and says so: "from the receipts ledger".) Recent Payments table rows match receipt records (DEMO-RCPT-01-018 etc., ₹18,000/₹6,000/₹1,500 Cash, Cleared) ✓. Caveat, data-true: all 99 receipts are seed-dated today, so Today = Month = Total is honest for this seed, not a bug. Phone: `.acc-stat-grid` → 3 @1399 → 2 @767 → 1 @480; `.acc-action-grid` → 1 @480; receipts table in `.table-responsive`. Session string note in §5. |
| /home (Loop 100) | librarian | MATCH | MATCH | 200. Total Books **23** = SUM(books.total_copies) ✓ "7 titles" = COUNT(books)=7 ✓; Books Issued **1** = SUM(issued_copies)=1 / book_requests issued ✓; Due Today 0, Overdue 0 with honest Needs-Attention empty state ("nothing overdue, nothing due today, and every title has a copy on the shelf"); Recently Issued row (English Grammar and Composition → Aarav Sharma, 20 Oct → 03 Nov, Issued, "Mark Returned" action) matches book_requests; Book Type donut percentages reconcile (10/23=43%, 9/23=39%, 2/23=9%, 2/23=9%) ✓. Phone: KPI row is Bootstrap `.row.dashboard-stats > .col-sm-6.col-xl-3` (2-across ≤1199, 1-across <576); `.l100-actions` → 2 cols @575; `.l100-donut-wrap` wraps + centers @575. |

## 4. My Account (/my_account) — Loop v3 standard

| Page (URL) | Role | Verdict | Phone | Evidence / defect |
|---|---|---|---|---|
| /my_account | admin | MATCH | MATCH | 200 "My Account \| HA", H1 "My Account" = title stem (standard page-title pattern, not an in-card echo). Hero stat chips REAL: Students **37** ✓ Teachers **4** ✓ Classes **10** ✓ (DB my_classes count). Tabs all present: Profile Information / Change Password / Notifications / Preferences ✓. Locked fields honestly labelled ("Assigned by your school — contact the Admin to change it", "Fixed once set"). |
| /my_account | teacher | MATCH | MATCH | Stat chips REAL and role-scoped: Subjects **6** ✓ Classes **3** (distinct) ✓ Students **16** ✓ — identical scope math to the teacher dashboard. Tabs all 4 present; Notifications tab includes Exam & Marks Reminders (role-appropriate). |
| /my_account | student | MATCH | MATCH | Identity chips instead of counts: Member since 23 Oct 2026 / Session 2018-2019 / Class Nursery 1 / Section Gold / Admission No HA/N/2026/99128 — every value matches DB + parent dashboard ✓. Tabs all 4 present + Assignment & Internal Marks Alerts toggle (role-appropriate). Session appears twice on this page total: once in topbar chrome + once as this hero chip (content, not chrome duplication). |
| /my_account/download | admin, teacher, student | MATCH | n/a | GET returns **200 application/json** for all three roles; body contains exported_at, school "Hackstra Academy", session "2018-2019" and the caller's own account block (verified per-role names/emails). "Download My Data — Export your profile, preferences and sign-ins as JSON" link is real. |
| /my_account (phone, all roles) | — | — | MATCH | `.acct-rail` (3 side cards) → 2 cols @1199 → **1 col @991** (qs.css:1874) and the older @767 rule agrees; `.acct-banner` is flex-wrap; `.acct-tabs` is a pill track with `overflow-x:auto` + `white-space:nowrap` tabs (scrollable by design, nothing clipped); `.acct-stat` flexes 1 1 140px @767. No fixed width >390px in the acct-* CSS (`.acct-page{max-width:1400px}` is a cap, not a floor). |

## 5. Global chrome, anon pages, search

| Page (URL) | Role | Verdict | Phone | Evidence / defect |
|---|---|---|---|---|
| Chrome — dark default | all | MATCH | — | Every authenticated page server-renders `<html lang="en" class="hk-dark">` on fresh login (verified in all 7 role /home HTML files + My Account). |
| Chrome — session in topbar | all | MATCH | — | "Current Session: 2018-2019" appears **exactly once** in chrome on every role's /home (count of "2018-2019" = 1 in full HTML for sa/admin/teacher/student/parent/librarian). Exceptions are content, not chrome: accountant /home totals 4 (topbar chip + "Session 2018-2019" KPI sublabel + chart subtitle "Session 2018-2019, from the receipts ledger" + SVG donut centre text) and student /my_account totals 2 (chip + hero stat). No page shows the session twice in chrome. |
| Chrome — title vs heading | all C1 | MATCH | — | No title-echo defect on C1 pages: dashboard titles are "Dashboard \| HA" / "Platform Overview \| HA" while on-page H1s are greetings ("Good morning, … 👋"); list pages ("Manage Users", "Audit Log", "Sent Mail") use the title as the page heading once, with no duplicate in-card re-print found. |
| /login | anon | MATCH | DEVIATION | 200, title "Hackstra Academy". Form fields are exactly `identity` + `password` (+ remember, _token) ✓; "Forgot password?" is a real link; footer Privacy Policy / Terms of Use links are real. Dark pre-paint: `<html>` itself carries **no** hk-dark class in server HTML, but an inline pre-paint script adds it (`localStorage.getItem('hk-theme')!=='light' → documentElement.classList.add('hk-dark')`) — dark-by-default holds pre-paint. Dead links: footer brand line "© 2026. Hackstra Academy by Hackstra" — **both brand anchors are href="#"** (2 dead links; the third href="#" on the page is the Options dropdown toggle). Phone: no qs.css @media treatment targets the public/login layout classes and the page ships legacy Limitless CSS; nothing fixed-width was found in the login HTML (no inline width >390px), so the verdict is DEVIATION (unverified legacy responsive behaviour, split marketing/form panel has no phone-specific rule in qs.css), not a proven clip. |
| /privacy-policy | anon | MATCH | DEVIATION | 200. Single H1 "PRIVACY POLICY" — **no duplicate heading echo** (the historical defect does not reproduce). Title is generic "Hackstra Academy" (no page-specific title). Body is the untouched stock policy ("Last modified: November 4, 2019", website URL interpolates to http://127.0.0.1:8937). Phone: same legacy public layout as /login — no phone-specific rule in qs.css. |
| /terms-of-use | anon | MATCH | DEVIATION | 200. Single H1 "TERMS OF USE", no duplicate echo. Same stock 2019 text and generic title as privacy page. |
| /home | anon | MATCH | — | Redirects to /login (probe final=/login, title "Hackstra Academy") — no authenticated content leaks to anon. |
| /dashboard/search?q=Aarav | admin | MATCH | MATCH | 200 "Search \| HA". Real scoped result: Students table lists **Aarav Sharma, student@hackstra.app, Nursery 1, Gold** with a View link; Staff/Classes/Payments sections honestly report "No staff match / No classes match / No payments match" (Aarav has receipts, but payments search is by payment title — no false hits invented). |
| Unauthorized-role refusals | teacher, student | MATCH | — | As teacher: /users, /super_admin/audit-logs, /super_admin/settings, /payments all redirect to /home (200 dashboard body, no data leak). As student: /users, /super_admin/audit-logs → /home. Refusal pattern is redirect-to-own-home, never a 403/500 and never the target page's data. |

## 6. Menu-integrity sweep (every unique sidebar/topbar href in each role's /home, GET as that role — 177 links total)

| Role | Links checked | 404/500 | → /login | Redirect-to-dashboard dead ends |
|---|---|---|---|---|
| sa | 11 (+logout, excluded) | 0 | 0 | **3 — see DEFECT below** |
| admin | 52 | 0 | 0 | 0 — every href resolves to itself, incl. /super_admin/settings and /super_admin/audit-logs |
| teacher | 36 | 0 | 0 | 8 topbar-only — see DEVIATION below |
| parent | 26 | 0 | 0 | 0 — incl. both child-switcher variants (§3) |
| accountant | 26 | 0 | 0 | 4 topbar-only — see DEVIATION below |
| librarian | 14 | 0 | 0 | 0 |
| student | 18 | 0 | 0 | 0 |

| Page (URL) | Role | Verdict | Evidence / defect |
|---|---|---|---|
| SA sidebar: /ask, /feedback/create, /feedback/mine | sa | DEFECT | SA's own sidebar renders "Ask Hackstra", "Give Feedback" and "My Feedback" as normal menu items, but all three GETs **redirect to /dashboard** (Platform Overview body). Three of the eleven links in SA's menu are dead ends dressed as working items — either the items should not be in the SA menu or the pages should serve SA. (SA's working feedback surface is /feedback/school, which IS in the menu and works.) |
| Topbar "Recent activity" dropdown items | teacher, accountant | DEVIATION | The shared topbar activity bell lists the school's latest activity for every role, but its item links are not role-scoped: as teacher, all 4 `/payments/receipts/{hash}` items redirect to /home and all 4 `/students/{hash}` items redirect to /dashboard; as accountant, the 4 `/students/{hash}` items redirect to /dashboard. The dropdown itself is correctly built (see §7 safe list) — the items are dead for these roles. Admin/student/parent-facing items resolve correctly. |

## 7. Clipped / off-screen / escaping-menu control class (owner order 2026-10-23 01:31 IST)

| Page (URL) | Role | Verdict | Evidence / defect (mechanism) |
|---|---|---|---|
| /users — every row's Actions control | sa + admin | **DEFECT** | The Action cell (right-most column) of all **18 rows** is `<div class="dropdown"><button class="list-icons-item btn btn-link p-0" data-toggle="dropdown"><i class="icon-menu9"></i></button><div class="dropdown-menu dropdown-menu-left">…` — **18 menus, 0 `dropdown-menu-right`** on the page. Two stacked mechanisms: (1) a left-aligned menu opening from a button at the table's right edge extends rightward past the viewport edge at 390px (and past the card edge on desktop); (2) the table sits inside `.table-responsive` (overflow-x:auto), which clips absolutely-positioned dropdowns — the open menu is cut off by the scroll container instead of overlaying the page. Same class as the owner's Manage Exams Actions complaint. Items inside are real (View Profile / Edit / Reset password / Suspend / Terminate / Delete), so the control looks functional but renders clipped. |
| Global topbar at 390px — right cluster | all roles | **DEFECT (phone)** | `.lav-topbar-right` is a no-wrap flex row containing: `.lav-clock` (`white-space:nowrap`, "01:29:51 AM · Fri, 23 Oct", ~150px) + `.lav-session-chip` (`flex:none`, ~105px even after its label hides @767) + theme toggle 36px + activity bell 36–40px (+ reminders bell on teacher) + avatar/chevron ~55px ≈ **390–430px of unshrinkable content for a 390px viewport**. qs.css has wrap for the bar itself (@768 `flex-wrap:wrap`), user-meta hides @991, session label hides @767, search flexes — but **no @media rule anywhere hides/shrinks `.lav-clock` or wraps `.lav-topbar-right`** (verified by grep: `.lav-clock` occurs only at qs.css:3068–3072 + dark variants). The cluster cannot compress; the clock or the avatar end is pushed to/past the right edge on a 390px phone. |
| Global topbar — bell + avatar dropdowns | all roles | SAFE (checked) | Both menus carry `dropdown-menu dropdown-menu-right` (bell menu `.lav-notif-menu`, profile menu `.lav-user-menu`). `.lav-notif-menu{width:340px;max-width:92vw}` (qs.css:1772), tightened to 88vw @480 (qs.css:298) — right-aligned and viewport-capped; cannot escape at 390px. |
| Global topbar — search / chip / toggles ≤991px | all roles | SAFE (checked) | @991: `.lav-user-meta{display:none}` (avatar-only chip), topbar-right gap → 2px. @768: bar `flex-wrap:wrap`, search `flex:1 1 130px;min-width:0`, kbd hint hidden, `.lav-topbar-toggle{display:none}` and `.lav-topbar-mobile{display:block}` (hamburger swaps in — exactly one nav toggle visible at every width). @767: session label hides, chip padding shrinks, controls get fixed 36px heights (qs.css:2103 block). Session chip itself is a `<span>` for SA and an `<a href="/super_admin/settings">` for admin — both fit their bars at desktop; the residual risk is only the cluster-sum defect above. |
| Sidebar / drawer | all roles | SAFE (checked) | 768–991.98px: sidebar is an off-canvas drawer `transform:translateX(-105%);width:260px;z-index:1040`, sliding in under `body.sidebar-mobile-main` with shadow (qs.css:265) — 260px < 390px, never wider than the phone screen. <768: same drawer via `.sidebar-mobile-main-toggle` in the topbar + in-sidebar `.sidebar-mobile-toggler`/expand buttons (href="#" JS toggles with real targets). Submenu parents are href="#" `nav-item-submenu` expanders that open inline `nav-group-sub` lists — nothing positions off-canvas. No session card remains in the sidebar (removed at source in an earlier loop; the only session control is the topbar chip). No dropdown menus exist inside the sidebar. |
| C1 page content — all other buttons | all roles | SAFE (checked) | Content-area dropdown toggles outside /users: **zero** on SA /home, admin /home, teacher /home, /feedback/school (grep: `data-toggle="dropdown"` count 0 in content-wrapper). Dashboard action rows wrap or grid-collapse (§3 phone notes); card headers pair a title with a single short "View All" link (`.p96-view-all{white-space:nowrap}` but ~70px, opposite a min-width:0 title — cannot push off); My Account tabs scroll-x by design; platform-mail/feedback controls are native selects + wrapping flex forms (§1). No sticky/fixed bottom bars exist on any C1 page (grep "sticky" in content = 0; the only sticky element is the topbar itself, `position:sticky;top:0` at natural height). |
| href="#" census, all C1 pages | all roles | MATCH | Every href="#" on C1 authenticated pages is a legitimate JS toggle: sidebar submenu parents, the topbar profile `dropdown-toggle` (data-toggle + menu present), sidebar mobile toggler/expand. Non-nav dead href="#" count = **0** on all 20 C1 page files checked. Sole exception is the anon /login footer brand links (§5). |

## 8. FIT summary (structural, 390px)

No C1 content element carries an inline/fixed width >390px (inline `style="width:"` hits on C1 pages are 40px avatar thumbnails on /users only). qs.css fixed widths >390px are breakpoint/container caps inside @media or print rules. Tables on every C1 page sit in `.table-responsive` wrappers (SA home 3/3, accountant 1/1, admin home 2/2, users 1/1) — safe from page-level overflow, at the cost of the desktop-table-on-phone pattern flagged per page above. Phone KNOWN-queue cross-ref: the "phone-fit overflows" known item does **not** reproduce as page-level horizontal overflow on C1 dashboards (all major grids have verified collapse rules); the two live phone defects in this cluster are the /users Actions-menu clipping and the topbar right-cluster sum (§7).

## Counts

- Rows audited: 34 page×role rows + 7 sweep rows + 7 clipped-class rows.
- MATCH: 22 · DEVIATION: 10 · DEFECT: 5 (/super_admin/settings for SA, audit-logs truncation, SA sidebar 3 dead menu items, /users Actions menus, topbar right-cluster @390px) · BROKEN: 1 (/users/{raw-id}/edit 500).
- Phone verdicts: MATCH 14 · DEVIATION 7 · DEFECT 2 (/users menus, topbar cluster) · n/a (redirect/download rows) rest.
- Menu sweep: 177 unique hrefs fetched, 0 × 404/500, 0 × →/login; dead-end redirects: SA 3, teacher 8 (topbar items), accountant 4 (topbar items).
- All headline numbers on all 7 dashboards + SA overview reconcile to DB/anchors (55 users, 37 students, ₹10,31,300 / 99 receipts, ₹2,79,700 / 11 defaulters, teacher scope 6/5/16, parent 2 children / ₹51,000, librarian 23 copies / 7 titles / 1 issued).


---

# APPENDIX B — Cluster C2: Students, admin user forms, academics CRUD

# C2 AUDIT — Students + Admin User Forms + Academics CRUD (PURE AUDIT, NO FIXES)

- **App:** http://127.0.0.1:8937 — worktree `~/workspace/projects/hackastra-batch146`, fresh seed (55 users, 37 students, session 2018-2019)
- **Method:** `probe.py` per role (status/title/final URL + saved HTML in `/tmp/audit/html/`), structural HTML analysis (selects/options, table headers, forms, hrefs), read-only sqlite, `qs.css` grep (6,089 lines, 174 `@media` blocks). Downloads verified with an authenticated session (headers + body).
- **Verdicts:** MATCH / DEVIATION / DEFECT / BROKEN, plus a separate **Phone** verdict per row (structural, 390px standard: wrappers, `@media` treatment, fixed widths — no browser available).
- **Design markers used:** Loop/pattern markers = `pat-card`, `pat-form`, `pat-actionbar`, `l83-*` (student list), `qa102-*` (Quick Admit), `stu-stats-row`, `idc-*` (ID card). Legacy markers = Limitless `row/col-md-*` + `rounded-circle` stock photo card, plain `.table` with no column rules, embedded create-form-under-table CRUD pages.

## SUMMARY COUNTS

| Scope | Rows | MATCH | DEVIATION | DEFECT | BROKEN |
|---|---|---|---|---|---|
| Desktop verdicts | 51 | 37 | 8 | 4 | 2 |
| Phone verdicts | 51 | 21 | 24 | 4 | 2 |

Headline defects: (1) stock-silhouette photo rendered on Student Profile + both ID Cards; (2) students-list row-action dropdowns open the wrong way (`dropdown-menu-left` in the rightmost column); (3) Manage Subjects phone clipping reproduced structurally (owner screenshot); (4) `/users/create` cannot create a Librarian; (5) hashed-ID URLs 500 on `/grades/{hash}/edit` and raw-ID URL 500s on `/users/18/edit`.

---

## 1. STUDENTS

| # | Page × Role | Status / Evidence | Desktop | Phone |
|---|---|---|---|---|
| R1 | `/students` — admin | 200 → final `/students/list/6` ("Student Information — JSS 2") | MATCH | DEFECT (lands on R5 page; same mechanism) |
| R2 | `/students` — teacher | 200 → final `/students/list/1` (teacher's own class, Nursery 1) — server-side scoping works | MATCH | DEVIATION (lands on R7 page) |
| R3 | `/students/list` (picker) — admin | 200. Class select: 11 opts = "Choose a class.." + 10 class names ONLY ✓. **Section select: 13 opts = "All Sections" + all 12 sections of ALL classes** (e.g. "JSS 3 A", "Nursery 1 Gold", "JSS 2 Blue") on initial render — not scoped to a chosen class; relies on JS cascade | DEVIATION | MATCH — single filter card, `form-control` selects are width:100%, "View Students" button wraps; no fixed widths, no table |
| R4 | `/students/list` — teacher | 200, same picker; exactly **1 menu href** to `/students/list` labelled "Student Information" (also exactly 1 for admin — Loop 83/132 verified in rendered menu HTML for both roles; other 3 text occurrences are title/heading/breadcrumb) | MATCH | MATCH (as R3) |
| R5 | `/students/list/6` — admin | 200. Headers (9 cols): `''` (checkbox), S/N, Photo, Name, ADM_No, Gender, Section, Email, Action. Stats: "Total Students 3 — In JSS 2", **"Boys — Gender not recorded", "Girls — Gender not recorded"** — DB confirms all 3 class-6 users (Adolphus Miller, Christopher Runte, Elenor Bartoletti DDS) have empty gender, so no fake % is computed — reference spec honoured. Photo cells = `l7-avatar` **initials tiles** "AM/CR/EB", no `<img>` silhouette in the table ✓. Bulk is REAL: `<form id="bulk-delete-form" method="post" action="/students/bulk_delete">`, `bulk-check` per row (values 23/24/25) + `bulk-check-all` + "0 selected / Delete Selected" bar — spec assumption "no bulk action exists" is outdated; checkboxes are supported. Row actions real: View Profile `/students/Lb9KGpV4Zz0N5l`, Edit `…/edit`, ID Card `/students/id_card/…`, plus POST `reset_pass` (data-confirm) and POST delete forms. Add Student + Import CSV + Export (CSV — Whole class / Current filter) + Print (`?print=1`) all present for admin | MATCH (but see D2 — its row-action dropdown is a named DEFECT) | **DEFECT** — see D2 + P1: 9-col `.table` in a single `.table-responsive`; qs.css `.content .table thead th` sets only size/letter-spacing/uppercase — **no `white-space:nowrap`, no min-width on any column**; no `@media` rule in qs.css targets `.l83-*` tables (only `.l83-toolbar`/`.l83-pagination` flex rules exist). At 390px the Email + Action columns fall off the right edge behind horizontal scroll with no scroll affordance, and headers compress/wrap |
| R6 | `/students/list/6` — teacher | **403** "Access not allowed / You do not have access to this page" — hard refusal for a class the teacher does not own (contrast R9 soft bounce; both are refusals, mechanism is inconsistent) | MATCH | MATCH (plain refusal page, no table/controls) |
| R7 | `/students/list/1` — teacher | 200 "Student Information — Nursery 1", 7 students. **No Add Student, no Import CSV, no bulk checkboxes/form** — create controls correctly limited to roles that can create. Row action = View Profile only (no Edit / ID Card). Print + Export CSV retained | MATCH | DEVIATION — same wide `.table` in `.table-responsive` scroll wrapper as R5, no phone card layout and no `.l83-*` `@media` treatment in qs.css; no dropdown defect (no dropdowns rendered) |
| R8 | `/students/create` — admin | 200 "Admit Student". Two tabs (`qa102-tab`): **Quick Admit** = 8 fields only (Full Name*, Gender*, Class*, Section*, Year Admitted*, Admission Number, Parent Email, Parent Phone) → POST `/students/quick_store`; **Full Admission Form** = 20 labelled fields → POST `/students`. Green action present verbatim: `<a class="btn btn-success" href="/students/import">Import Students (CSV)</a>`. "Incomplete profile" concept present: `show.blade.php` renders `<span class="badge badge-warning qa102-pill">Incomplete profile</span>` (rendered live on Aarav's profile, R12) and the post-Quick-Admit success banner says "The profile is still incomplete — finish the remaining details anytime." Class selects (both forms) = 10 class names only ✓; section selects start "Select Class First"/empty (AJAX cascade) ✓; parent select in full form = 4 parent names only ✓; dorm select = 5 hostels ✓ | MATCH | DEVIATION — `qa102-tabs` wrap (`display:flex;flex-wrap:wrap`) and selects are full-width, so nothing escapes; but the Full Admission Form is the desktop multi-field form dumped at phone width with no `@media` collapse specific to it (only global `.pat-actionbar`/`@media767` cell-padding rules apply) — cramped, long-scroll, below premium standard |
| R9 | `/students/create` — teacher | 200 → final `/home` (Dashboard). Refusal by redirect; no form content leaked | MATCH | MATCH (dashboard) |
| R10 | `/students/create` — parent | 200 → final `/home`. Refused | MATCH | MATCH |
| R11 | `/students/create` — student | 200 → final `/home`. Refused | MATCH | MATCH |
| R12 | `/students/{sr_id=1}` show — admin (app href hash `GaboegA2ZBmvL2`) | 200 "Student Profile - Aarav Sharma". Fields render truthfully (Name, ADM_NO `HA/N/2026/99128`, Class "Nursery 1 · Gold", Parent …), "Incomplete profile" pill renders, ID Card link present, no lorem, no tabs. **Photo = stock silhouette:** `<img style="width:90%;height:90%" src="…/global_assets/images/user.png" class="rounded-circle">` inside a legacy Limitless card (`row > col-md-3 > card > card-body`). DB: **all 55/55 users have `photo = …/global_assets/images/user.png`** — there is no initials fallback on this page at all | **DEFECT** (D1) | DEVIATION — legacy Bootstrap grid stacks (`col-md-3` → full width) so nothing clips, but the 90%-width silhouette photo becomes a huge blank avatar block; no Loop/`pat-` phone treatment on this page |
| R13 | `/students/{sr_id=1}/edit` — admin | 200 "Edit Student". Selects all correct collections: my_class_id = 10 class names only; section_id pre-scoped to the student's own class (1 opt "Gold"); my_parent_id = 4 parent names only; dorm_id = 5 hostels; gender/blood-group/nationality(194)/state(38) sane. **One flaw:** `lga_id` `<select>` renders with **0 options** — the student's current LGA is not displayed until state is re-picked (AJAX-only population) | DEVIATION | DEVIATION — full desktop form at phone width, no form-specific `@media` in qs.css beyond global actionbar rules |
| R14 | `/students/graduated` — admin | 200. Class filter = "All Classes" + 10 class names only ✓. Honest empty state: "No graduated students yet. Students appear here after promotion with the Graduated option." | MATCH | MATCH — no table; single select + empty-state text, full-width controls |
| R15 | `/students/promotion` — admin | 200. `fc`/`tc` selects = "Select Class" + 10 class names only ✓; `fs`/`ts` = 0 options (AJAX cascade, same pattern as create forms) | MATCH | MATCH — stacked selects, full-width; no table, no fixed widths |
| R16 | `/students/promotion/manage` — admin | 200. Table 7 cols: S/N, Photo, Name, From Class, To Class, Status, Action — header only (no promotions in seed) + copy "Students who were promoted from 2018-2019 to 2019-2020 session" + "Reset All Promotions for the Session" action | MATCH | DEVIATION — 7-col `.table` in a single `.table-responsive` scroll wrapper; no phone treatment in qs.css for this page's classes |
| R17 | `/students/import` — admin | 200. Upload form POST `/students/import` + template link `/students/import/template`. Column table (Column/Required/Notes) has 17 rows describing **19 columns** (`phone / phone2` and `dorm / dorm_room_no` each share one row) — the 19-col claim checks out against the actual template (R18). Page also lists valid class/section names per class | MATCH | DEVIATION — 3-col reference table in a scroll wrapper with long Notes text; readable only via horizontal scroll at 390px, no phone-specific rule |
| R18 | `/students/import/template` — admin | **200, `Content-Type: text/csv; charset=UTF-8`, `Content-Disposition: attachment; filename=students-import-template.csv`**, 297 bytes. First line = exactly **19 columns**: `name,email,gender,phone,phone2,dob,address,nationality,state,lga,blood_group,class,section,parent_email,year_admitted,dorm,dorm_room_no,house,adm_no` + 1 example row (Adaeze Chiamaka Obi) | MATCH | MATCH (download; n/a) |
| R19 | `/students/export/6` — admin | **200, `text/csv`, `filename=students-jss-2.csv`**, 248 bytes, 8 cols (`Name,"Admission No",Class,Section,Gender,"Parent Name","Parent Phone","Parent Email"`) and exactly the 3 class-6 students with correct adm nos (`HA/J/2026/5711`, `…/35397`, `…/24223`); Gender/Parent cells honestly empty | MATCH | MATCH (download; n/a) |
| R20 | `/students/id_card/{sr_id=1}` — admin | 200 "ID Card - Aarav Sharma". **KNOWN** (old-design queue) — current state is concrete: it is NOT the old Limitless page; it uses a bespoke `idc-*` design (`.idc-card{width:430px;max-width:100%;aspect-ratio:85.6/54}`, `.idc-head` navy band "Hackstra Academy / Student Identity Card", `.idc-info` rows Class "Nursery 1 · Gold", Adm No, "Valid for session 2018-2019", Print ID Card + Back to Profile actions, print rules at 85.6mm×54mm). **Photo defect inside it:** `.idc-photo` contains `<span class="idc-initial">A</span>` **under** `<img src="…/user.png" onerror="this.remove()">`, and qs.css `.idc-photo img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover}` — user.png exists (it is every user's DB photo), so `onerror` never fires and the **stock silhouette covers the initials tile** | **DEFECT** (D1) + KNOWN | DEVIATION — `.idc-card` is `width:430px` with only `max-width:100%` as protection: at 390px (minus `.idc-stage` 18px side padding) the card shrinks but all inner type stays fixed (`.idc-name` 1.06rem, `.idc-head-sub` already .56rem letterspaced) — no `@media` block in qs.css touches `.idc-*` for screen sizes; legible-but-shrunk desktop card, not a phone design |
| R21 | `/my_id_card` — student | 200 "My ID Card". Identical `idc-*` markup and identical photo mechanism (adm no `HA/N/2026/99128` present, user.png img over `idc-initial`) | **DEFECT** (D1) + KNOWN | DEVIATION (as R20) |
| R22 | `/students/list/6` — student | 200 → final `/home`. Refused (bounce, no data) | MATCH | MATCH |
| R23 | `/students/list/6` — parent | 200 → final `/home`. Refused | MATCH | MATCH |
| R24 | `/students/list/6`, `/subjects` — super admin | Both 200 → final `/dashboard` "Platform Overview". SA is bounced out of school pages entirely (consistent with SA view-only/platform boundary; SA gets no read view of the student list from this URL) | MATCH | MATCH (dashboard) |

**Menu check (Loop 83/132):** rendered sidebar HTML for admin AND teacher each contain exactly **one** `<a href="…/students/list">Student Information</a>`. MATCH.
**Dead buttons:** none in this cluster. The 9 baseline `href="#"` on admin pages are JS toggles (user-menu dropdown, sidebar mobile toggles, nav group headers Academics/Administrative/Payments/Students/Student Promotion/Exams); list/6's 6 extra are row-action dropdown toggles and Delete items with `onclick="confirmDelete(this.id)"` wired to real POST forms. MATCH.
**Title vs in-card heading:** every page above has exactly one in-card heading equal to its `<title>` base (e.g. "Manage Subjects", "Student Information — JSS 2") — the duplicate-title defect class is **not** reproduced in C2. (Create/show URLs for CRUD resources intentionally share the "Manage X" title — see §3 note.)

---

## 2. ADMIN USER FORMS

| # | Page × Role | Status / Evidence | Desktop | Phone |
|---|---|---|---|---|
| R25 | `/users/create` — admin | 200 "Create New User", POST `/users`, submit "Create User", 15 labelled fields (Full Name*, Email, Address*, Gender*, Nationality*, State*, LGA*, photo upload, Date of Employment, Username, Password…). **`user_type` select has exactly 4 options: Accountant, Parent, Teacher, Admin.** There is no **Librarian** option (a live role — `librarian@hackstra.app` exists) and no Student option (students are created via Admit Student by design). An admin cannot create a librarian account from this form | **DEFECT** (D3) | DEVIATION — long desktop form at phone width; selects full-width (nothing escapes), but no form-specific `@media` treatment |
| R26 | `/users/{user 18}/edit` — admin (app href hash `j2G09gXAZqeOdM`) | 200 "Edit User" for student user Aarav Sharma. 12 labelled fields; gender/blood-group/nationality/state selects carry the correct collections. The role is **not editable**: the form shows a "Select User: *" label but contains **no `user_type` `<select>`** (only gender, bg_id, nal_id, state_id, lga_id selects render) — role can be set at creation, never changed here. `lga_id` again renders 1 empty option | DEVIATION | DEVIATION (as R13) |
| R27 | `/users/18/edit` (raw id) — admin | **500** — page title is the exception "Attempt to read property "id" on null" (1.17 MB error page). The `{id}` route requires `Qs::hash` (per AGENTS.md lesson); the app's own hrefs use the hash so in-app navigation works, but a raw-id URL produces a server error instead of a 404 | BROKEN | BROKEN (same 500) |

---

## 3. ACADEMICS CRUD (admin unless noted)

**Structural note for all of §3:** `/classes/create`, `/sections/create`, `/subjects/create`, `/grades/create`, `/dorms/create` do **not** render separate create pages — each returns the index page (byte size within ~40 bytes of index, same `<title>` "Manage X") with the create form embedded below/beside the table on the same page. Likewise `/{resource}/{id}` **show** routes for classes/grades/dorms/events return the index listing (final URL = index). Row Edit/Delete on index pages use **raw** ids (`/classes/6`, `/subjects/11`, `/sections/8`) and work; the same resources' **edit** pages have proper titles ("Edit Class - JSS 2", "Edit Subject - English Language (JSS 2)").

| # | Page × Role | Status / Evidence | Desktop | Phone |
|---|---|---|---|---|
| R28 | `/classes` — admin | 200 "Manage Classes". Table 4 cols (S/N, Name, Class Type, Action), all 10 classes with correct types (JSS 2 = Junior Secondary … SSS 3 = Senior Secondary), Edit/Delete per row. Embedded create form: Name* + Class Type select (6 opts: Creche, Junior Secondary, Nursery, Pre Nursery, Primary, Senior Secondary) + note "When a class is created, a Section will be automatically created for the class…" | MATCH | DEVIATION (P2) — 4-col table is narrow enough, but it is the desktop table in a **double-nested** `.table-responsive` (see P2) with no phone layout |
| R29 | `/classes/create` — admin | 200, renders R28 page (embedded form), title stays "Manage Classes" | MATCH | DEVIATION (as R28) |
| R30 | `/classes/6` (show) — admin | 200 → final `/classes`. No class detail page exists; show silently returns the listing | DEVIATION | DEVIATION (as R28) |
| R31 | `/classes/6/edit` (raw id) — admin | 200 "Edit Class - JSS 2", POST `/classes/6`, Name* editable. **Class Type is a `disabled` text input** (`<input id="class_type_id" disabled value="Junior Secondary">`) — the class type offered as a select at creation **cannot be changed on edit** | DEVIATION | MATCH — `pat-form` single column, fields full-width, sticky `.pat-actionbar` has `@media (max-width:767px)` padding + `btn-primary{min-width:0}` treatment (qs.css L3723 area) |
| R32 | `/sections` — admin | 200 "Manage Class Sections". Defaults to JSS 2: table 5 cols (S/N, Name, Class, Teacher, Action), 1 row "Blue / JSS 2 / Arun Prakash" = DB section 8 ✓. Embedded create form: Name*, Class select = 10 class names only ✓, Teacher select = 4 teacher names + empty ✓ (filter select `class_id` also classes-only) | MATCH | DEVIATION — 5-col desktop table in double-nested `.table-responsive` (P2), no phone rules |
| R33 | `/sections/create` — admin | 200, renders R32 page (embedded form) | MATCH | DEVIATION (as R32) |
| R34 | `/sections/8/edit` — admin | 200 "Edit Section of JSS 2". Name*, Class shown, Teacher select = 4 teachers only ✓, POST `/sections/8`, "Submit form" | MATCH | MATCH (`pat-form` + sticky actionbar pattern, as R31) |
| R35 | `/subjects` — admin | 200 "Manage Subjects". **KNOWN** (old-style queue; owner screenshot). Current render: `pat-card` chrome like the other CRUD pages. Table 6 cols: **S/N, Name, Short Name, Class, Teacher, Action** (Action column added since the owner's screenshot), defaults to JSS 2 showing its exact 2 DB subjects (English Language/Eng, Mathematics/Math, both Teacher = Arun Prakash ✓). Create form **on the same page below the table**: Name*, Short Name*, Class select = **10 class names ONLY** ✓, Teacher select = **4 teacher users ONLY** (Ananya Sharma, Arun Prakash, Priya Nair, Rahul Verma) + empty ✓ — no person-in-Class or non-teacher-in-Teacher defect. Filter select `class_id` = classes only. Submit "Submit form" + filter submit "View Subjects" both real | MATCH + KNOWN | **DEFECT** (P1) — the owner's screenshot reproduces structurally: 6-col `.table` inside **double-nested** `<div class="table-responsive"><div class="table-responsive">`; qs.css gives `.content .table thead th` **no `white-space:nowrap` and no min-width** (contrast `.t9-table th{white-space:nowrap}` elsewhere in the same file), and **no `@media` block in qs.css mentions this table, `.pat-card` tables, or subjects at all** (the only `pat-` media rule is for `.pat-form .pat-actionbar`). Columns therefore compress until "Short Name" wraps mid-word ("SHOR T NAME") and Teacher/Action sit beyond the 390px fold inside a scroll region with no affordance — exactly the clipped TEACHER column the owner screenshot |
| R36 | `/subjects/create` — admin | 200, renders R35 page (form below table on same page) | MATCH + KNOWN | **DEFECT** (same page/mechanism as R35) |
| R37 | `/subjects/11/edit` — admin | 200 "Edit Subject - English Language (JSS 2)". Class select = 10 class names only ✓; Teacher select = 4 teachers + empty ✓; POST `/subjects/11` | MATCH | MATCH (`pat-form` pattern, as R31) |
| R38 | `/grades` — admin | 200 "Manage Grades". Table 6 cols (S/N, Name, Grade Type, Range, Remark, Action), 6 grade rows (A–F bands from DB: A 70–100 Excellent, B 60–69 Very Good…). Embedded create form: Name, Grade Type (`class_type_id` = "Not Applicable" + 6 types), Mark From/To, Remark select (11 remarks) | MATCH | DEVIATION — 6-col desktop table in double-nested `.table-responsive` (P2), no phone rules |
| R39 | `/grades/create` — admin | 200, renders R38 page (embedded form) | MATCH | DEVIATION (as R38) |
| R40 | `/grades/1/edit` (raw id) — admin | 200 "Edit Grade". Labels Name*, Grade Type, Mark From*, Mark To*, Remark — all present, POST `/grades/1`, "Submit form" | MATCH | MATCH (`pat-form` pattern) |
| R41 | `/grades/{hash of 1}/edit` — admin | **500** "Attempt to read property "id" on null". The hash is **not** decoded here (route param is `{grade}`, not `{id}`), the lookup misses and the edit view dereferences null. In-app hrefs use raw ids so the menu path works, but the URL class that is mandatory for students/users is a server error here | BROKEN | BROKEN (same 500) |
| R42 | `/dorms` — admin | 200 "Manage Dorms". Table 4 cols (S/N, Name, Description, Action), 5 rows = DB hostels (Faith, Peace, Grace, Success, Trust). Embedded create form: Name* + Description | MATCH | DEVIATION — 4-col desktop table in double-nested `.table-responsive` (P2), no phone rules |
| R43 | `/dorms/create` — admin | 200, renders R42 page (embedded form) | MATCH | DEVIATION (as R42) |
| R44 | `/dorms/1` (show) — admin | 200 → final `/dorms`. No dorm detail page; show returns the listing | DEVIATION | DEVIATION (as R42) |
| R45 | `/dorms/1/edit` — admin | 200 "Edit Dorm - Faith Hostel". Name* + Description, POST `/dorms/1`, "Submit form" | MATCH | MATCH (`pat-form` pattern) |
| R46 | `/events` — admin | 200 "School Events". Table 5 cols (Date, Title, Type, All Day, Action) inside `.de-events`; 3 rows = DB `school_events` (Parent-Teacher Meeting 2026-10-26, Annual Sports Day 2026-10-29, Term 1 Orientation Day 2026-10-11). "New Event" button → `/events/create` ✓; per-row Edit links real; Delete = POST forms | MATCH | DEVIATION — 5-col table in a single `.table-responsive`; **qs.css contains zero `.de-*` rules at all** (grep: no `.de-events` base or media rule) — the events table has no phone treatment of any kind |
| R47 | `/events/create` — admin | 200 "New Event". Labels: Title, Type (5 opts: Examination, Meeting, Fee, Holiday, Event), Starts, Ends (optional), Description (optional), All day event (checkbox). POST `/events`, submit "Create Event" | MATCH | MATCH — single-column form, full-width controls, no fixed widths, no table |
| R48 | `/events/1` (show) — admin | 200 → final `/events`. No event detail page; show returns the listing | DEVIATION | DEVIATION (as R46) |
| R49 | `/events/1/edit` — admin | 200 "Edit Event". Same 6 labelled fields as create, POST `/events/1`, submit "Save Changes" | MATCH | MATCH (as R47) |
| R50 | `/events/{hash of 1}/edit` — admin | **404** "Page not found". Third behaviour for the same URL class: students/users **require** the hash, grades **500** on it, events **404** on it (param `{event}` not decoded, lookup fails gracefully). In-app hrefs use raw ids | DEVIATION | DEVIATION (404 page renders fine) |
| R51 | `/subjects`, `/classes`, `/sections`, `/grades`, `/dorms` — teacher | All 200 → final `/home` (Dashboard). Teacher is bounced from every Academics CRUD page; teacher's rendered menu offers none of them (menu hrefs contain no /subjects, /classes, /sections, /grades, /dorms links) | MATCH | MATCH (dashboard) |

*(51 page×role rows, R1–R51. R35/R36 also carry the KNOWN tag; R20/R21 carry KNOWN alongside DEFECT.)*

---

## 4. NAMED DEFECTS — DETAIL

### D1 — Stock silhouette shown as the student photo (Profile + ID Cards) — DEFECT
- **Where:** `/students/{sr_id}` show (admin), `/students/id_card/{sr_id}` (admin), `/my_id_card` (student).
- **Mechanism:** DB `users.photo` = `…/global_assets/images/user.png` for **55/55 users**. Profile page renders it directly: `<img style="width:90%;height:90%" src="…/user.png" class="rounded-circle">` with **no initials fallback**. ID Cards render an initials span (`.idc-initial`) but layer the same img over it — qs.css `.idc-photo img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover}` — and the img's `onerror="this.remove()"` never fires because user.png exists. The list page (R5) proves the correct pattern already exists in-app: `l7-avatar` initials tiles, no img.
- **Spec broken:** "photo = real photo else INITIALS tile; stock silhouette image = DEFECT".

### D2 — Row-action dropdown opens the wrong way, rightmost column (named control-clipping class) — DEFECT
- **Where:** `/students/list/6` — admin, every row (3/3 rows in seed).
- **Markup:** last `<td class="text-center print-hide">` → `<a class="list-icons-item" data-toggle="dropdown">` → **`<div class="dropdown-menu dropdown-menu-left">`** containing View Profile / Edit / Reset Password / Delete.
- **Mechanism:** a left-aligned menu anchored to a button in the **rightmost** column extends rightward from the button; inside a horizontally-scrolled `.table-responsive` at phone width (and at narrow desktop widths) the menu is clipped by the wrapper's overflow / escapes the viewport edge. The correct class exists **on the same page**: the Export button's menu and both topbar menus use `dropdown-menu dropdown-menu-right`. This is the same defect class as the owner's Manage Exams "Actions button looks totally useless" complaint (that page is C3's; the class is confirmed present here).

### D3 — `/users/create` cannot create a Librarian — DEFECT
- `user_type` select options, verbatim: **Accountant, Parent, Teacher, Admin**. Librarian is a live role in the system with no creation path on this form (Student absence is by design — Admit Student flow).

### D4 — Inconsistent `{param}` ID handling across resources — DEFECT (with BROKEN instances R27, R41)
- Students + Users: hash **required** (raw id → 500 on `/users/18/edit`).
- Grades: hash → **500** (`/grades/GaboegA2ZBmvL2/edit`), raw id works.
- Events: hash → **404**, raw id works. Classes/Sections/Subjects/Dorms: raw id works, hash → silently returns the index listing (200 → final `/classes` etc. — probed `/classes/{hash}`, `/classes/{hash}/edit`, `/sections/{hash}/edit`, `/subjects/{hash}`, `/subjects/{hash}/edit`, `/dorms/{hash}`, `/dorms/{hash}/edit`).
- Four different behaviours for the same URL shape; two of them are server errors.

### P1 — Manage Subjects phone clipping (owner screenshot) — PHONE DEFECT
See R35. Mechanism, in one line: 6-column desktop table + double-nested `.table-responsive` + **no `nowrap`/min-width on `.content .table thead th` in qs.css** + **zero `@media` rules for the page** = mid-word header wrap ("SHOR T NAME") and Teacher/Action beyond the fold. KNOWN queue item, still present structurally.

### P2 — Desktop-table-dumped-on-phone pattern (all Academics index tables + promotion/manage + import) — PHONE DEVIATION
Subjects/Classes/Sections/Grades/Dorms index tables all sit in **double-nested** `<div class="table-responsive"><div class="table-responsive"><table class="table">` (redundant double wrapper, verified in subjects + classes HTML; single wrapper on students list, promotion/manage, events, import). qs.css's 174 `@media` blocks richly cover dashboards/stat grids (`.stu-stats-row` collapses 3→2→1 at 1199/767/479px, `.teacher-stat-grid`, `.acc-stat-grid`, etc.) but **no `@media` block targets any C2 CRUD table or `.pat-card`** — the only `pat-` media rule in the file is `.pat-form .pat-actionbar` at ≤767px. Every C2 list table is therefore the desktop table behind horizontal scroll at 390px: no column dropping, no card reflow, no sticky first/action column. Below the owner's premium reference standard even where nothing is literally clipped (Classes/Dorms 4-col tables).

### Controls swept and provably SAFE (clipped/escaping-controls class)
- **Export dropdown** on `/students/list/6` (admin + teacher): menu is `dropdown-menu dropdown-menu-right` ✓.
- **Topbar notification + user menus** (all C2 pages): `dropdown-menu-right lav-notif-menu` / `lav-user-menu` ✓.
- **Subjects/Classes/Sections/Grades/Dorms/Events row actions:** inline Edit links + POST Delete forms — no dropdown exists to escape ✓.
- **`.l83-toolbar`** (students list): `display:flex;flex-wrap:wrap;gap:10px` ✓. **`.l83-pagination`**: `flex;flex-wrap:wrap;justify-content:space-between` and renders info-only ("Showing 1 to 3 of 3 students") at one page ✓.
- **`.pat-actionbar`** (all `pat-form` edit pages): `position:sticky;bottom:0` by design with `@media (max-width:767px)` padding reduction and `.btn-primary{min-width:0}` — Cancel/Submit stay reachable and unclipped at 390px ✓.
- **`.qa102-tabs`** (Admit Student): `flex;flex-wrap:wrap` ✓. **`.stu-stats-row`** (students pages): grid collapses at 1199/767/479px ✓.
- **No floating buttons, no fixed-width >390px screen elements** found in C2 pages: qs.css fixed widths >390px are breakpoint values inside `@media` queries themselves; the one fixed-width component, `.idc-card{width:430px}`, is guarded by `max-width:100%` (see R20 for its residual DEVIATION).

---

## 5. FIT STRUCTURAL PASS (task §4) — /subjects, /students/list/6, /classes

| Page | Wrapper | Table | qs.css suspects |
|---|---|---|---|
| `/subjects` | **Double** `.table-responsive` around the table | `.table`, 6 cols, no col widths | `.content .table thead th` — no nowrap/min-width; **no `@media` for the page** → P1 DEFECT |
| `/students/list/6` | Single `.table-responsive` around bulk form + table | `.table`, 9 cols (incl. checkbox + Action) | No `.l83-*` table media rules; `.l83-email` cell carries a full email that forces column width → R5 phone DEFECT (with D2) |
| `/classes` | **Double** `.table-responsive` | `.table`, 4 cols | Same absent phone treatment → P2 DEVIATION (fits, but unreformed desktop table) |

**Refusal-mechanism inconsistency (cross-cutting, minor):** out-of-scope student access is refused three ways in this cluster — hard **403** page (teacher → `/students/list/6`), **302-style bounce to `/home`** (teacher/parent/student → create, graduated, promotion, all Academics CRUD), and **bounce to `/dashboard`** (SA → school pages). All refuse correctly; the UX is not uniform.


---

# APPENDIX C — Cluster C3: Exams, marks, exam date-sheet, timetables

# C3 — EXAMS + MARKS + EXAM DATE-SHEET + TIMETABLES — PURE AUDIT (no fixes made)

App: http://127.0.0.1:8937 · worktree `hackastra-batch146` · fresh seed · probed 2026-10-23 ~01:27–01:35 IST
Method: probe harness (status = final after redirects; `final` URL quoted where a redirect is the verdict), rendered-HTML greps, sqlite read-only, binary PDF fetch + `pdftotext` where the probe harness's UTF-8 save corrupts PDFs. Phone verdicts are STRUCTURAL only (rendered HTML + `public/assets/css/qs.css`) — no browser/CDP.

**Summary counts (primary verdict per row, 65 page×role rows): MATCH 47 · DEVIATION 7 (+1 partial — row 4 is MATCH on figures / DEVIATION on its dead action) · DEFECT 4 · BROKEN 1 · KNOWN 4 · KNOWN-suspicion CLEARED 1. Plus 3 clipped-control class rows (C-1…C-3; C-1 is also row 24's phone verdict) and 10 phone findings (P-1…P-10).**

## DB anchors (sqlite, read-only)
- `exams`: only id 1, "Term 1 Examination", term 1, year `2018-2019`, **`results_published = 0` (NOT published)**.
- `settings`: `principal_name` = empty, `school_stamp` = empty, **no `principal_signature` row at all** → signature/stamp unset.
- `grades`: A 70–100, B 60–69, C 50–59, D 45–49, E 40–44, F 0–39.
- `exam_dates`: **0 rows** (no dates announced for any exam/class).
- `time_table_records`: 8 records (classes 1,2,3,5,6,7,8,9 — none for Primary 1 (4) or SSS 3 (10)); **`exam_id` is NULL on all 8** → no exam timetable exists for exam 1.
- `sections` for class 6 (JSS 2): only id 8 "Blue".
- Marks, Aarav Sharma (user 18, sr 1, class 1 section 1): English tex1 76, Maths tex1 51 → **total 127**. Class-6 marks DB: u40 72+63=135, u41 71+88=159, u42 57+74=131.
- `exam_subject_components` (exam 1): per class only the English subject has a custom scheme (Theory 60 / Internal Assessment 30 / Viva 10); Maths is legacy everywhere. JSS 2 English component ids 16/17/18 = Theory/TH/60, Internal Assessment/INT/30, Viva/VIVA/10.
- Parent Kabir Anand (parent@hackstra.app, user 4) has exactly 2 children: user 18 (Aarav) and user 19 (Layla) — `student_records.my_parent_id = 4` for sr 1 and sr 2 only. User 20 is NOT his child (true foreign test).
- Aarav fees: paid ₹18,000+₹6,000+₹1,500 = **₹25,500, "Paid in full / nothing outstanding"** (his dashboard). The ₹2,79,700 outstanding anchor is school-wide (admin dashboard: Total ₹13,11,000 / Collected ₹10,31,300 / Pending ₹2,79,700) — other students, not Aarav.

## Verdict table (Phone = separate structural phone verdict)

| # | Page × Role | Verdict | Phone |
|---|---|---|---|
| 1 | GET /marks — admin | MATCH | DEVIATION (P-1) |
| 2 | GET /marks — teacher | MATCH | DEVIATION (P-1) |
| 3 | Marks entry grid /marks/manage/1/1/1/1 (via POST /marks/selector) — admin | MATCH | DEVIATION (P-2) + DEFECT C-2 |
| 4 | Marks entry grid — teacher | MATCH (figures) / DEVIATION (dead "View Marksheet" action, F-4) | DEVIATION (P-2) + DEFECT C-2 |
| 5 | Marks grid published-state banner — admin+teacher | DEVIATION (F-5) | — |
| 6 | GET /marks/batch_fix "Fix Mark Errors" — admin | **KNOWN** (still live) | DEVIATION (P-9) |
| 7 | GET /marks/bulk (Select Student Marksheet) — admin | MATCH | MATCH |
| 8 | GET /marks/annual/{hash18}/2018-2019 — admin | MATCH (anchors exact) | DEVIATION (P-3) |
| 9 | GET /marks/annual/{hash18}/2018 (literal) — admin/student | DEVIATION (F-9) | — |
| 10 | GET /marks/print_annual/{hash18}/2018-2019 — admin | MATCH | n/a (print doc) |
| 11 | GET /marks/pdf_annual/{hash18}/2018-2019 — admin | MATCH | n/a (PDF) |
| 12 | GET /marks/show/{hash18}/2018-2019 — admin | MATCH | **DEFECT (P-3)** |
| 13 | GET /marks/print/{hash18}/1/2018-2019 — admin | MATCH | n/a (print doc) |
| 14 | /marks/show + /annual + /print_annual (own) — student | MATCH (honest "Results Not Published" gate) | DEVIATION (P-3) |
| 15 | /marks/show + /annual + /print (user 19) — student (foreign) | MATCH (refused → /dashboard) | — |
| 16 | /marks/show (own child 18) — parent | MATCH (publish gate reached = he IS the parent) | — |
| 17 | /marks/show + /annual (user 20, not his child) — parent | MATCH (refused → /dashboard) | — |
| 18 | /marks/print + /show + /annual (Aarav) — teacher | MATCH (refused → /dashboard; settled law) | — |
| 19 | Teacher /home menu | MATCH (no Marksheet / Admit Cards items) | MATCH |
| 20 | GET /marks/tabulation/1/6/8 — admin | MATCH (reconciles with DB) | DEVIATION (P-4) |
| 21 | GET /marks/tabulation/print/1/6/8 — admin | MATCH | n/a (print doc) |
| 22 | GET /exams/1/scheme — admin | MATCH | MATCH (P-5) |
| 23 | GET /exams/1/scheme/6 — admin | MATCH | MATCH (P-5) |
| 24 | GET /exams — admin | **KNOWN** (old-style Manage Exams) | **DEFECT (C-1 / P-6)** |
| 25 | GET /exams/create — admin | **KNOWN** (old-style; same index + "Add Exam" tab) | DEVIATION (P-6) |
| 26 | GET /exams/1/edit — admin | **KNOWN** (old-style) | DEVIATION (P-6) |
| 27 | GET /exams/GaboegA2ZBmvL2/edit (Qs-hashed id) — admin | **BROKEN** (500, F-27) | — |
| 28 | GET /exams — teacher | MATCH (refused → /home) | — |
| 29 | GET /exams/admit-cards — admin | MATCH | MATCH |
| 30 | GET /exams/admit-cards/print/1/6 — admin | MATCH | DEVIATION |
| 31 | GET /my-admit-cards — student | MATCH (own card listed) | MATCH |
| 32 | GET /my-admit-cards/1/18 (own, raw id as rendered) — student | MATCH (renders; NOT fee-blocked — correct, F-32) | MATCH |
| 33 | GET /my-admit-cards/1/19 and /1/20 — student (foreign) | MATCH (403) | — |
| 34 | /my-admit-cards/1/18 — parent (own) / /1/20 — parent (foreign) | MATCH (200 / 403) | — |
| 35 | /exams/admit-cards + print/1/6 — teacher | MATCH (refused → /home; settled law) | — |
| 36 | GET /exam-dates — admin (sheet view) | MATCH | MATCH (P-8) |
| 37 | GET /exams/date-sheet/manage — admin | DEVIATION (duplication, F-37; function itself works) | MATCH (P-8) |
| 38 | GET /exam-dates/show (and /exam-dates) — teacher | MATCH | MATCH (P-8) |
| 39 | GET /exam-dates/show — student | MATCH | MATCH (P-8) |
| 40 | GET /exam-dates/show — parent | MATCH (child switcher) | MATCH (P-8) |
| 41 | GET /exam-dates/print — admin | MATCH | n/a (print doc) |
| 42 | GET /exam-dates/pdf — admin | MATCH (real PDF, same content) | n/a (PDF) |
| 43 | GET /exam-dates — SA | MATCH (redirected → /dashboard; expected 302 behaviour, probe follows redirects) | — |
| 44 | GET /exam-dates + /exam-dates/show — accountant | MATCH (403 "Access not allowed") | — |
| 45 | GET /exam-dates + /exam-dates/show — librarian | MATCH (403) | — |
| 46 | "Exam Date Sheet" menu item count — admin/teacher/student/parent | MATCH (exactly 1 each) | MATCH |
| 47 | GET /timetables/mine — accountant | DEVIATION (refused by redirect → /dashboard, not 403) | — |
| 48 | GET /timetables/mine — librarian | DEVIATION (refused by redirect → /dashboard, not 403) | — |
| 49 | GET /timetables — admin | MATCH | DEVIATION (P-7) + DEFECT C-3 |
| 50 | GET /timetables/mine — teacher | MATCH (own teaching only) | DEVIATION (P-7) |
| 51 | GET /timetables/mine — student | MATCH (own class only) | DEVIATION (P-7) |
| 52 | GET /timetables/mine — parent | MATCH (child switcher, 2 children) | DEVIATION (P-7) |
| 53 | GET /timetables/mine/print — teacher | MATCH (real document) | n/a |
| 54 | GET /timetables/mine/pdf — teacher | MATCH (real PDF, same content) | n/a |
| 55 | GET /timetables/mine/print — student | **DEFECT** (F-55: falls through to list page) | DEFECT |
| 56 | GET /timetables/mine/pdf — student | **DEFECT** (F-55: returns text/html list, not a PDF) | DEFECT |
| 57 | GET /timetables/mine/print — parent | **DEFECT** (F-55) | DEFECT |
| 58 | GET /timetables/mine/pdf — parent | **DEFECT** (F-55) | DEFECT |
| 59 | GET /timetables/mine (+print/pdf) — admin | MATCH (redirects to full /timetables list; admin has no personal timetable — by design) | — |
| 60 | GET /timetables/records/show/1 — admin | MATCH (figures) / DEVIATION (day order, F-60) | DEVIATION (P-7) |
| 61 | GET /timetables/records/manage/1 — admin | MATCH | DEVIATION (wide entry table, global cell rules only) |
| 62 | GET /timetables/records/print/1 — admin / student (own class) | MATCH (student foreign record id 7 → 403) | n/a (print doc) |
| 63 | GET /timetables/records/edit/1 — admin | **KNOWN suspicion CLEARED** (F-63, quoted options) | MATCH (P-10) |
| 64 | GET /timetables/time_slots/edit/1 — admin | MATCH (correct collections) | MATCH (pat-form, stacked small selects) |
| 65 | GET /marks, /exams, /exam-dates, /timetables — anon | MATCH (all → /login) | — |

## Detailed findings

### Marks selector & entry grid (Loops 84 / 76)
- **Selector (rows 1–2) MATCH.** 4 steps rendered ("1 Select Exam / 2 Select Class / 3 Select Subject / 4 Manage Marks"). Banner is SLIM: `.l84-banner{padding:14px 20px}` single flex row, 44px icon, one-line text + inline points ("Supports all exam types · Class & section wise · Easy mark entry and updates") — not a tall banner. "Supported Exam Types" info card lists **"Term 1 Examination — every exam created for the current session"** = the only exam in DB. Form is `pat-form`, action `/marks/selector`, selects contain the right collections (exam: only Term 1; class: all 10 classes; section/subject load per class).
- **Entry grid (row 3) MATCH.** Reached via the real selector flow: POST /marks/selector (exam 1, class 1, section 1, subject 1) → `/marks/manage/1/1/1/1`. Component columns come from the configured scheme, exactly as DB: **THEORY (60) / INTERNAL ASSESSMENT (30) / VIVA (10) / TOTAL (100)**; header states "Maximum Marks: 100 (Theory: 60, Internal Assessment: 30, Viva: 10)" and the scheme alert "Theory (60) + Internal Assessment (30) + Viva (10) = 100". Input values match `mark_components`: Aarav `comp_1_1=46, comp_1_2=23, comp_1_3=7` → total cell **76**, 76.0%, grade A — identical to his report card. Footer stats reconcile: 4 students, Class Average **74.5** (= (76+77+80+65)/4), Highest 80, Lowest 65.
- **F-4 (row 4) DEVIATION:** the teacher's grid is identical (correct — her class/section), but every row's kebab menu offers **"View Marksheet"** (`href=/marks/show/j2G09gXAZqeOdM/2018-2019`) — a document settled law denies teachers; following it redirects the teacher to /dashboard. A dead-end action is offered in the teacher UI (admin's identical link works).
- **F-5 (row 5) DEVIATION:** no published-state banner is rendered on the entry grid in either role. qs.css defines `.l86-pub-banner` (line 5892) but the rendered grid HTML contains **0 occurrences** of `l86-pub-banner` and no "published / not published" text, while DB `exams.results_published = 0`. (The /exams list does show a correct "Not published" badge.) The grid gives the marks-enterer no on-page signal that exam 1 is unpublished.

### Fix Mark Errors — KNOWN (row 6)
Still live in this tree, exactly as the known-queued note said. GET /marks/batch_fix as admin → **200, title "Fix Mark Errors"**, working selector form (Exam: Term 1 Examination; Class: all 10; Section) with a "Fix Errors" submit. Menu item present in the admin nav, quoted label: **"Marks Correction"** (`<a href="http://127.0.0.1:8937/marks/batch_fix" class="nav-link">Marks Correction</a>`). Owner ordered removal; it has not happened in this tree. No fix attempted (audit only).

### Annual report / marksheet (Loop 85)
- **Row 8 MATCH — anchors exact on screen** (admin, year `2018-2019`): "ANNUAL TOTAL: 127 · OVERALL ANNUAL AVERAGE: 63.5 · CLASS ANNUAL AVERAGE: 69.5 · ANNUAL POSITION: 4th". Subject rows English 76/A/5th, Mathematics 51/C/6th — consistent with DB tex1 76/51.
- **Row 10 print_annual MATCH:** identical figures (127 / 63.5 / 69.5 / 4th), identical grading strip.
- **Row 11 pdf_annual MATCH:** binary fetch returns `%PDF-1.7`, content-type application/pdf, 1,274,421 bytes; `pdftotext` extracts **ANNUAL TOTAL: 127, OVERALL ANNUAL AVERAGE: 63.5, CLASS ANNUAL AVERAGE: 69.5, ANNUAL POSITION: 4th** — identical to screen and print. (The probe harness's own save of this URL is a UTF-8-corrupted copy; the binary fetch is the evidence.)
- **Grading strip MATCH against DB** on screen, print and PDF: "A => 70-100, B => 60-69, C => 50-59, D => 45-49, E => 40-44, F => 0-39" = `grades` table verbatim.
- **Signature/stamp MATCH (no defect):** settings have `principal_name` empty, `school_stamp` empty, and no `principal_signature` row. The printed/PDF annual card's signature block renders for PRINCIPAL a blank line only — `<div class="rc-sign-line"></div><div class="rc-sign-role">PRINCIPAL</div><div class="rc-sign-name"></div>` — no signature image, no printed principal name, no stamp `<img>` anywhere in the print HTML. Class Teacher correctly shows the real teacher name (Ananya Sharma) over her own blank line.
- **Rows 12–13 MATCH:** /marks/show screen and /marks/print carry identical figures to the annual card (TOTAL SCORES OBTAINED: 127, FINAL AVERAGE: 63.50, CLASS AVERAGE: 69.50, POSITION: 4th) and identical component splits (English Theory 46 / Internal 23 / Viva 7; Maths legacy CA 10+10 / CA total 20 / Exam 31) — screen vs print consistent. /marks/select_year/{hash18} renders a single-option year picker (2018-2019) whose POST flow targets marks.show.
- **F-9 (row 9) DEVIATION:** the literal year segment `2018` (as written in the task spec) does **not** work: /marks/annual/{hash}/2018, /marks/show/{hash}/2018 and both annual print/PDF variants silently redirect to /dashboard for admin and student. Only the full session string `2018-2019` renders. No error message explains why.
- **Publish gate (rows 14, 16) MATCH:** student (own) and parent (own child) get the honest **"Results Not Published"** page for show/annual/print/print_annual — correct, because exam 1 is unpublished in DB and both viewers passed the identity gate first (i.e. the gate chain is identity → fee → publish, and Aarav is not fee-blocked, see F-32).

### Access control — settled law (rows 15, 17–19, 28, 33–35)
- Teacher → /marks/print, /marks/show, /marks/annual for Aarav: all redirect to /dashboard (**refused**). Teacher → /exams/admit-cards and /exams/admit-cards/print/1/6: redirect to /home (**refused**). Teacher /home nav "Examinations" section contains only **Exams · Marks · Exam Date Sheet** — no "Marksheet", no "Admit Cards" item (grep-proven).
- Student → another student's (user 19) show/annual/print: redirect to /dashboard (**refused**). Student → /my-admit-cards/1/19 and /1/20: **403**.
- Parent → user 20 (not his child) show/annual: redirect to /dashboard (**refused**); /my-admit-cards/1/20: **403**. Parent → user 19 is **not** a foreign case: DB shows user 19 is also his child, and the publish gate (not a refusal) is what renders — correct.
- Teacher → /exams (Manage Exams): redirect to /home (admin-only, refused).

### Tabulation (rows 20–21)
**MATCH, reconciled with DB.** /marks/tabulation/1/6/8 (class 6, its only section Blue id 8): Adolphus Miller ENG 72 MATH 63 **Total 135** Avg 67.50 2nd; Christopher Runte 71/88 **159** 79.50 1st; Elenor Bartoletti 57/74 **131** 65.50 3rd — every cell equals the `marks` rows for class 6 (subjects 11/12). Print version is identical and adds correct summary stats (Students 3, Class Average 70.83 = 425/3/2, Highest 159, Lowest 131) and blank EXAMINATION OFFICER / PRINCIPAL lines.

### Marking scheme editor (rows 22–23)
- **/exams/1/scheme MATCH:** per-class table "Subjects 2 · Custom Schemes Set 1 of 2 · Configure" for all 10 classes — exactly DB state (English custom, Maths legacy in every class).
- **/exams/1/scheme/6 MATCH:** English Language block renders component rows with input values **Theory/TH/60, Internal Assessment/INT/30, Viva/VIVA/10** (component ids 16/17/18 = DB), "Total: 100 / 100", each row locked with the honest note "Has marks — max locked". Preset list includes the honest Legacy option ("CA 20 + CA 20 + Exam 60 (no custom components)"). Mathematics block shows the structure-guard message verbatim: *"Legacy scheme (CA 20 + CA 20 + Exam 60) — Marks have already been entered for Mathematics in this exam under the legacy scheme, so a custom scheme can no longer be set for this exam. Configure the scheme on the exam before marks entry begins."* — honest guard, no fake editability.

### Exams CRUD (rows 24–28)
- **KNOWN old-style (rows 24–26), concrete markers:** legacy Limitless chrome — `page-header-light` + `<span class="font-weight-semibold">Manage Exams</span>`, `nav-tabs nav-tabs-highlight` ("Manage Exam / Add Exam"), plain `<table class="table">`, generic button text "Submit form", tab-based create instead of the pat-form redesign used elsewhere. Row data itself is correct: "1 · Term 1 Examination · Term 1 · 2018-2019 · **Not published** (badge) · Actions" — the publish badge matches DB `results_published = 0`. /exams/create renders this same index with the Add Exam tab; /exams/1/edit renders the same-generation plain form (Name *, Term select First/Second/Third Term, Cancel / "Submit form").
- **F-27 (row 27) BROKEN:** GET /exams/GaboegA2ZBmvL2/edit (the Qs hash of 1, the form this app's `{id}` convention produces elsewhere) → **500, title "Attempt to read property "name" on null"**. The route/controller evidently expects the raw id (the in-page Edit link is raw `/exams/1/edit`, which works) and crashes on the decoded-null lookup instead of 404ing. Any hashed link to exam edit 500s.

### Admit cards (rows 29–35)
- **Selector (row 29) MATCH:** Exam select (only Term 1), Class select (all 10), Section select lists every section **labelled with its class** ("JSS 2 — Blue", "Nursery 1 — Gold", …) plus "All Sections" — the collection is complete and disambiguated, not wrong.
- **Admin print (row 30) MATCH — real document, honest schedule derivation:** 3 cards for JSS 2 (the 3 DB students), each listing English Language / Mathematics with Date "—" and Time "—", headed by the explicit note: *"No exam timetable has been built for JSS 2 in this exam yet, so the cards list the class subjects without dates. Build the exam timetable (Timetables → Records) to print dated cards."* This agrees with DB: all `time_table_records.exam_id` are NULL and `exam_dates` is empty — no dates are invented.
- **F-32 (row 32) — defaulter-block state verified CORRECT:** Aarav's own card (/my-admit-cards/1/18, the exact raw-id URL the rendered page links) renders the full card with the same honest no-timetable note. He is **not** fee-blocked, and should not be: his fees are paid in full (₹25,500 paid, ₹0 outstanding on his dashboard). The designed defaulter notice therefore correctly does **not** replace his card; the ₹2,79,700 school-wide outstanding figure is other students' dues (admin dashboard anchor), not his balance. No balance mismatch found.
- Hashed student_id variants (e.g. /my-admit-cards/1/{hash}) return 403 even for the owner — the route takes raw ids (as its own rendered hrefs use), so this is a non-flow path, noted for completeness, not counted as a defect.

### Exam Date-Sheet — Loop 103 (rows 36–46)
- **Empty state honest everywhere:** `exam_dates` is empty, and admin view, teacher show, student show and parent show all render **"Dates not announced yet — The school has not announced any dates for Term 1 Examination (…) yet."** No date is fabricated anywhere.
- **F-37 (row 37) DEVIATION — route duplication:** two admin routes render two different pages under the identical title "Exam Date Sheet": `/exam-dates` = the sheet **view/print** page (defaults to class JSS 2, Print + Download PDF actions), `/exams/date-sheet/manage` = the **editor** ("Set the date of every subject's paper for an exam, class by class… dates are never guessed", exam+class pickers, no print actions). The admin menu's single "Exam Date Sheet" item points to the **manage** route; other roles' single item points to /exam-dates (which for a teacher renders the show view in place). Functionally complementary, but the duplicate naming/route split is exactly the kind of duplication the owner has flagged elsewhere.
- **Scoping on show pages MATCH:** teacher's class picker contains only her classes (Nursery 1, Nursery 2, Primary 1); student's sheet is locked to his own class (Nursery 1, "Student: Aarav Sharma"); parent's has a **Child switcher** (Aarav Sharma — Nursery 1 / Layla Anderson V — Nursery 1) covering exactly his 2 DB children.
- **Print/PDF MATCH:** /exam-dates/print and /exam-dates/pdf carry the same content; binary fetch of the PDF gives `%PDF-1.7`, and `pdftotext` shows the same sheet (Term 1 Examination · JSS 2, "Dates for this exam have not been announced yet", both subjects "Not announced / —"). Default class on print is JSS 2, same as screen.
- **SA /exam-dates MATCH:** redirected away (final URL /dashboard after the redirect chain — SA platform overview), i.e. the expected 302 behaviour (probe follows redirects; no sheet rendered for SA). **Accountant and librarian: hard 403** on both /exam-dates and /exam-dates/show.
- **Menu count MATCH:** exactly **one** "Exam Date Sheet" item in each of admin, teacher, student and parent /home (grep count = 1 per role).

### Timetables (rows 47–64)
- **Admin grid (row 49) MATCH:** /timetables lists the 8 DB records grouped by class (JSS 2, JSS 3, Nursery 1/2/3, Primary 2, SSS 1, SSS 2), each "Class TimeTable · 2018-2019 · Active" with View/Print/Manage/Edit/Delete — classes without records (Primary 1, SSS 3) simply have no group, matching DB.
- **/timetables/mine scoping MATCH:** teacher sees only her own teaching (English Language, Nursery 1 + Nursery 2, 30 period entries Mon–Fri + the same as a day-grid); student sees only Nursery 1 · Gold (his class/section); parent sees a **Child switcher (Aarav Sharma / Layla Anderson V)** over his child's Nursery 1 · Gold grid. Student opening a foreign class's record print (/timetables/records/print/7, JSS 2) → **403**; his own (/print/1) → 200.
- **Rows 47–48 DEVIATION:** accountant and librarian /timetables/mine are **refused by redirect** (final /dashboard), not by the **403** the settled scoping specifies (contrast: the same two roles get a proper 403 on /exam-dates, and a student gets 403 on a foreign timetable record). The data is protected; the refusal semantics differ from spec.
- **F-55 (rows 55–58) DEFECT — student/parent "My Timetable" print & PDF do not exist as documents:** GET /timetables/mine/print and /timetables/mine/pdf as student and as parent both fall through (final URL `/timetables`) to the **Manage Timetables list page** (scoped to their 1 class record); the pdf URL returns `text/html`, not a PDF. The teacher equivalents work properly: /timetables/mine/print renders a real "Teacher Weekly Timetable — Ananya Sharma" document and /timetables/mine/pdf returns a real `%PDF-1.7` whose text matches ("TEACHER WEEKLY TIMETABLE · 30 periods per week"). Mitigating fact: the Print button actually rendered on the student/parent My Timetable page does **not** use these routes — it links `/timetables/records/print/1` (own record), which works — so the broken routes are unlinked-but-routable, and the spec'd mine/print + mine/pdf pair is satisfied for teacher only.
- **F-60 (row 60) DEVIATION (minor):** /timetables/records/show/1 and /records/print/1 render the day rows in the order **Friday, Monday, Tuesday, Wednesday, Thursday** — not chronological. The /timetables/mine grids render Mon–Fri correctly. Content otherwise identical and correct (Nursery 1, 3 slots, English in every cell, matching DB).
- **Records manage (row 61) MATCH:** time-slot table (3 slots, correct times) and entry table use the right collections (Day Mon–Sun; Subject English/Mathematics for the class; Time Slot the record's 3 slots; Teacher Ananya Sharma).
- **F-63 (row 63) — KNOWN SUSPICION CLEARED with rendered evidence.** /timetables/records/edit/1 rendered selects, dumped verbatim:
  - Label "Class *" → `<select name="my_class_id">` options: `6 JSS 2 · 7 JSS 3 · 1 Nursery 1 · 2 Nursery 2 · 3 Nursery 3 · 4 Primary 1 · 5 Primary 2 · 8 SSS 1 · 9 SSS 2 · 10 SSS 3`, **selected: "Nursery 1"** — class names only, correct record value. **No "Ankit kumar" (or any person) appears.**
  - Label "Type (Class or Exam)" → `<select name="exam_id">` options: `(value "") "Class Timetable" · 1 "Term 1 Examination"` — i.e. the Type control's options are the record types: "Class Timetable" vs the exam name (selecting an exam is what makes a record an exam timetable). Record 1's DB `exam_id` is NULL, consistent with "Class Timetable".
  - The owner's screenshot therefore does not reproduce on this tree/record: either it showed a stale build or a different state. On the current rendered HTML the suspicion is **cleared**, with the caveat that the Type select being backed by `exam_id` (empty = Class) is a labelling subtlety, not wrong data.
- **Time-slot edit (row 64) MATCH:** /timetables/time_slots/edit/1 (slot id 1 from DB) selects are hour 1–12 (selected 8), minutes 00–55 in 5s (selected 40 for "to"), meridian AM/PM (selected AM) — the right collections, matching DB slot 1 (8:00 AM – 8:40 AM).

## CLIPPED / ESCAPING CONTROLS — owner defect class (2026-10-23 01:31 order)

### C-1 — DEFECT — /exams (admin): the "Actions" button (the owner's trigger instance)
Markup, verbatim from the rendered row:
```html
<td class="text-center">
  <div class="dropdown">
    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-toggle="dropdown">Actions</button>
    <div class="dropdown-menu dropdown-menu-right">
      … Admit Cards · Marking Scheme · Publish Results · Edit · Delete …
    </div>
  </div>
</td>
```
Mechanism, step by step:
1. **Position:** "Actions" is the **right-most (6th) column** ("Action") of a plain `<table class="table">` with columns S/N · Name · Term · Session · Results · Action.
2. **Wrapper:** the table sits in a bare Bootstrap `<div class="table-responsive">` — horizontal-scroll container. qs.css gives this legacy page **no** cluster-specific treatment: no min-width, no sticky action column, no `@media` rule for this table beyond the global `@media (max-width:767px){.content .table td,th{padding:9px 10px}}` (qs.css:155).
3. **On a ~390px phone** the six columns' content (exam name, "2018-2019", a "Not published" badge, the button) exceeds the ~358px content width, so the table overflows its scroll wrapper and the **last column is the first thing pushed to/past the screen edge** — exactly the owner's screenshot: the Actions button renders at the edge, half-usable.
4. **The menu compounds it:** the dropdown's parent `.dropdown` lives **inside** the scroll container. `dropdown-menu-right` IS present (alignment class is not the problem), but a Bootstrap dropdown inside `.table-responsive` cannot overlay the page — it is trapped in the container's scroll box, so even after scrolling the button into view, its menu (5 items incl. Publish Results / Delete — the only path to those actions) opens inside the clipped table area.
Verdict: **DEFECT** — page /exams, role admin, element = row "Actions" button + its dropdown menu, mechanism = last column of a horizontally overflowing legacy table inside `.table-responsive` with the dropdown parented inside that scroll container and no phone treatment in qs.css.

### C-2 — DEFECT — Marks entry grid (admin + teacher): per-row kebab "View Marksheet" menu
Same class, same mechanism: each row's actions are `<div class="dropdown"><button class="l86-kebab-btn" data-toggle="dropdown">…</button><div class="dropdown-menu dropdown-menu-right"><a href="/marks/show/…">View Marksheet</a></div></div>` in the **last column (ACTIONS)** of `<table class="table table-striped l76-marks-table">` inside `<div class="table-responsive">`, with `thead th{white-space:nowrap}` (qs.css:4001) guaranteeing the 10-column grid overflows a phone viewport. The menu is right-aligned but parented inside the scroll container → trapped/clipped the same way as C-1. (For the teacher this is doubly moot — the single menu item is the dead link of F-4.)

### C-3 — DEFECT — /timetables (admin): per-record "More actions" dropdown
Each timetable row carries inline View/Print anchors **plus** `<div class="btn-group"><button class="btn btn-light btn-sm dropdown-toggle" data-toggle="dropdown" aria-label="More actions for …">…</button><div class="dropdown-menu dropdown-menu-right">Manage · Edit · Delete …</div></div>` — the only path to Manage/Edit/Delete. The row's table is `<table class="table ttx-table l81-table">` inside `<div class="l7-table-scroll">`, and qs.css:223–224 sets `.l7-table-scroll{overflow-x:auto}` with `.l7-table-scroll>table{min-width:600px}` — on a 390px phone the table is 600px wide inside a ~358px box, the actions cell sits at the far right of the scrollable area, and the dropdown is parented inside that scroll container (same trap as C-1/C-2).

### Controls checked and provably SAFE (one line)
Topbar bell/user menus on every cluster page (`dropdown-menu dropdown-menu-right lav-notif-menu / lav-user-menu`, parented in the navbar, outside any scroll container); the /timetables group-head "New Timetable" menu (`.l81-group-menu`, in the group header, outside `.l7-table-scroll`); tabulation Print button (plain anchor in the card header); date-sheet Print/PDF actions (`.l103-actions{display:flex;flex-wrap:wrap}`, `@media 575px` → head stacks, actions width:100%); scheme editor Configure/Save buttons (plain anchors/buttons in page flow); marks-grid sticky action bar (`.l76-actionbar{position:sticky;bottom:0}`, page-level, not container-clipped); admit-cards "Print / Save as PDF" (page-level button on a target=_blank print view); all native `<select>`s in pat-forms (full-width fields — a native select panel cannot escape its field).

## PHONE findings (structural; mechanism named per verdict)

- **P-1 — /marks selector (admin, teacher): DEVIATION.** Forms stack properly (`.pat-fields` 2-col grid → `@media (max-width:767px)` 1 column, qs.css:1343) and the banner wraps (`@991` flex-wrap; `@767` padding 13px 15px). BUT the 4-step indicator self-destructs on phone: `@media (max-width:767px){.l84-step{display:none}.l84-step.is-done,.l84-step.is-current{display:flex}}` (qs.css:4884ff) while the **server-rendered HTML carries neither `is-done` nor `is-current` on any of the 4 steps** (all plain `class="l84-step"`) — unless/until JS adds a state class, the progress indicator renders nothing on a phone, and the "steps driven by actual selections" state is not present in the rendered markup at any width.
- **P-2 — Marks entry grid: DEVIATION (scroll-by-design) + DEFECT C-2.** The grid itself is **horizontal scroll by design, not uncontrolled clipping**: `.table-responsive` wrapper, `thead th{white-space:nowrap}`, inputs capped (`.l76-mark-input{max-width:120px;height:34px}`). Ten nowrap columns on a phone still make entry a pan-heavy chore (short of premium), and the summary chips collapse properly (`.l86-chips` → 1 column @767, qs.css:5889). The kebab-menu trap is scored separately as C-2.
- **P-3 — Report cards: /marks/show DEFECT; /marks/annual DEVIATION.** The `.report-card` tables (`.rc-marks{width:100%}`, 12px font, 4px/3px cell padding) have **no scroll wrapper at all** in the screen views (grep: `table-responsive` = 0, `l7-table-scroll` = 0 in both pages) and the card's own stylesheet contains **only `@media print`** — no screen media query. The marksheet's 11-column table (S/N, Subject, Theory, Internal, Viva, CA1, CA2, CA Total, Exam, Total, Grade, Position, Remarks) is therefore **squeezed into the viewport width uncontrolled** on a phone — not scroll-by-design. The annual card's 7-column table squeezes less severely → DEVIATION.
- **P-4 — Tabulation (screen): DEVIATION.** Wrapped in `.table-responsive` (scroll-by-design exists), but `.qs-tab-table` (qs.css:3415) is `width:100%` with **no min-width floor** — with the demo's 2 subjects it fits, but the wide-table risk the owner named (a full class tabulation = a column per subject) has no structural protection: columns will squeeze rather than scroll at subject counts where other tables (`.l7-table-scroll>table{min-width:600px}`) are protected.
- **P-5 — Scheme index + scheme/6: MATCH.** Tables sit in `.table-responsive` (scroll-by-design); the editor's preset grid collapses (`@media 767px: .l76-scheme-preset{grid-template-columns:1fr}`, `.l76-scheme-block{padding:14px}`, qs.css:4021).
- **P-6 — /exams, /exams/create, /exams/1/edit: DEFECT (index, = C-1) / DEVIATION (create, edit).** Legacy pages get only the global @767 cell-padding rule; create/edit are plain legacy forms (full-width controls, functionally reachable, visibly short of the pat-form premium standard).
- **P-7 — Timetable grids (admin /timetables, /timetables/mine all roles, records/show): DEVIATION — scroll-by-design, with a forced-scroll flaw.** Mechanism is present and deliberate: `.l7-table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}` + `>table{min-width:600px}` + a **sticky day column** (`.ttx-grid .ttx-day-col{position:sticky;left:0;min-width:104px}`). Day columns are NOT squeezed. However, `min-width:600px` applies even to the demo's 3-slot grids, whose natural width (~104px day + 3 narrow slots) could fit a 390px phone — every phone user is forced to pan a mostly-fitting grid. Premium would size the floor to the slot count. Admin index adds `@media 575px` care (year badge hidden, toggle padding) — noted as a plus.
- **P-8 — Exam Date-Sheet (manage + show, all roles): MATCH.** `.pat-fields` collapses to 1 column @767; the sheet page has its own phone rule (`@media (max-width:575px){.l103-head{flex-direction:column}.l103-actions{width:100%}}`, qs.css:6086); in the current (empty) state there is no table at all — a padded empty-state card (`.l103-empty{padding:34px 22px}`) that cannot overflow. When dates exist, the sheet table is a narrow 4-column `.l103-table` (Date/Time/Subject/Note) with no fixed widths — no squeeze mechanism identified.
- **P-9 — /marks/batch_fix: DEVIATION.** Legacy form page; selects are full-width form-controls (reachable), but no pat-form/@media treatment — old-style on phone, consistent with its KNOWN status.
- **P-10 — /timetables/records/edit/1 (+ time_slots/edit/1): MATCH.** Both are `pat-form` (fields → 1 column @767); the Class/Type selects are standard full-width `.form-control select` inside stacked fields — **no fixed widths >390px, no custom dropdown panel that can escape the viewport** (the owner's dropdown-escape concern does not reproduce on the edit forms; it reproduces on the list-page dropdowns, C-1…C-3).

## Notes / limits
- All verdicts are from rendered HTML + DB + qs.css; no live browser was used (per subagent constraints), so phone verdicts name the governing markup/CSS mechanism rather than pixel measurements.
- PDF evidence was taken from binary fetches (the probe harness saves PDFs as corrupted UTF-8 text; its HTML copies of PDF URLs were not used as evidence).
- Nothing in the app was edited, submitted, or deleted; the only write is this file. Marks-grid evidence was obtained by POSTing the real selector form (read-only navigation; no marks were saved).


---

# APPENDIX D — Cluster C4: Attendance, fees/payments, payroll, parent fees

# Cluster C4 Audit — Attendance + Fees/Payments + Payroll + Parent Fees

- **Mode:** PURE AUDIT, read-only. GET probes + read-only SQLite only. No forms submitted, no app files edited.
- **App:** http://127.0.0.1:8937 — worktree `~/workspace/projects/hackastra-batch146`, fresh seed (seeded 2026-10-23 01:22 IST).
- **Method note:** the stock `probe.py` decodes bodies as UTF-8 text, which **corrupts binary PDFs** (its saved "PDFs" show flate errors / blank renders — an artefact of the harness, not the app). All PDF/CSV verdicts below come from a binary-safe fetcher (`/tmp/audit/bprobe.py`, saves raw bytes + Content-Type) and `pdftotext` on those bytes. PDFs are healthy.
- **Phone verdicts** are structural only (no browser): derived from rendered HTML + `public/assets/css/qs.css` (174 `@media` blocks; brace balance 0 — the "Loop 91 repair" comments are historical, blocks are closed now). MATCH (phone) requires a named mechanism: `.table-responsive` scroll wrapper, `flex-wrap` filter forms, card-list registers, or page-specific `@media (max-width:767px/575px/480px)` rules.

## Anchor reconciliation (SQLite, read-only)

| Anchor | DB result | Verdict |
|---|---|---|
| Fee defaulters 11 students / ₹2,79,700 | 11 students with `payment_records.balance > 0`, sum ₹2,79,700 | ✅ MATCH |
| Partially Paid 7 / ₹1,62,700 | 7 students with partial payments, balances sum ₹1,62,700 | ✅ MATCH |
| Fully Unpaid 4 / ₹1,17,000 | 4 students, nothing paid, balances sum ₹1,17,000 | ✅ MATCH |
| Collections 99 receipts / ₹10,31,300 / avg ₹10,417 / 33 students | `receipts`: 99 rows, SUM ₹10,31,300, AVG ₹10,417.17, 33 distinct students via `payment_records` | ✅ MATCH |
| Receipt dates "all 22 Oct 2026" | All 99 receipts are dated **23 Oct 2026** in this fresh seed (seed run 01:22 IST, 23 Oct). Pages and DB agree with each other; the anchor's date is off by one vs this seed. | ⚠️ Anchor superseded by seed date — pages MATCH DB |
| Payslip anchor: Ananya Sharma Oct 2026, basic ₹45,000 / allow ₹6,500 / ded ₹2,500 / net ₹49,000 / paid 05 Oct 2026 | `payrolls` id 1, user_id 3: exactly those figures, status paid, `paid_at` 2026-10-05 | ✅ MATCH |
| Attendance sessions | `attendance_sessions`: 36 rows, all `submitted`; 139 entries (126 present / 6 late / 7 absent) | ✅ |
| Attendance % spot-check | Per-student DB calc `(Present+Late)/Marked`: lowest is **75.0%** (student_records 3 Mikel Hane 3/4, 28 Eugenia Pacocha 3/4); 30 of 37 students have any marks, 7 zero-marked | Baseline for defaulters verdicts |

---

## 1. ATTENDANCE

| Page × Role | Result | Verdict | Phone | Evidence |
|---|---|---|---|---|
| `/attendance` — admin | 200 → final `/attendance/report` | MATCH | MATCH (redirect; target assessed below) | Index routes by role to the period-model landing; no day-wise page served |
| `/attendance` — teacher | 200 → final `/attendance/periods` | MATCH | MATCH (redirect) | Same |
| `/attendance` — student | 200 → final `/home` (dashboard) | MATCH | MATCH | Role refused, lands on own dashboard |
| `/attendance` — sa | 200 → final `/dashboard` (Platform Overview) | MATCH | MATCH | SA kept out of school-operational pages (view-only boundary) |
| `/attendance/periods` — admin | 200 "Mark Attendance", oversight banner "viewing in oversight mode (read-only)" | MATCH | MATCH — session table in `.table-responsive` (designed horizontal scroll) | Read-only oversight is explicit on the page |
| `/attendance/periods` — teacher | 200, own sessions + "register not submitted" reminders | MATCH | MATCH — table in `.table-responsive` | Session rows link `/attendance/periods/session/{id}` and `/take` per period |
| `/attendance/periods` — teacher1 | 200 | MATCH | MATCH — same wrapper | Own sessions listed |
| `/attendance/periods` — student | 200 → final `/home` | MATCH (refusal) | MATCH | No register access |
| `/attendance/periods` — accountant | 200 → final `/home` | MATCH (refusal) | MATCH | No register access |
| `/attendance/periods/13/take` — teacher | 200 → final `/attendance/periods/session/1`, title "Mark Attendance — Submitted" | MATCH (Loop 77) | MATCH — register table in `.table-responsive`; status pills per student | Session 1 already submitted → submitted register view, "Submitted · Locked" banner, topic "Revision — extracts and short answers" shown, 7 students all Present, counts 7/7/0/0 reconcile to DB (session 1: 7 present entries) |
| `/attendance/periods/43/take` — teacher1 | 200 → final `/attendance/periods/session/2` | MATCH (Loop 77) | MATCH — same | Own submitted session (DB: session 2, time_table 43, teacher 7) |
| `/attendance/periods/15/take` — teacher (unsubmitted) | 200 "Take Attendance" form | MATCH | MATCH — **card-list register** (one card per student, Present/Absent/Late segmented radio controls), topic-taught required field, sticky live summary bar (Loop 61: JS mirrors counts into the sticky bar; in-flow sticky, not a fixed overlay) | "Submission is final… locked… cannot be edited" warning shown before submit; counts bar Present/Absent/Late/Unmarked |
| `/attendance/periods/13/take` — accountant | 200 → final `/home` | MATCH (refusal) | MATCH | Cannot take a register |
| `/attendance/periods/session/1` — teacher | 200 submitted register detail | MATCH | MATCH — `.table-responsive` | Same content as take-redirect; Print / Export CSV / Download PDF are plain links |
| `/attendance/periods/session/1/print` — teacher | 200, standalone document | MATCH | MATCH (print doc) | **No app chrome** (no sidebar/topbar in markup). Letterhead from SETTINGS only: "18B North Central Park… Tel: 0123456789 · Email: info@hackstra.app". No Bangalore / 5600 / @gmail anywhere. Figures = screen = DB |
| `/attendance/periods/session/1/pdf` — teacher | 200, `application/pdf`, attachment, 884,157 bytes, `%PDF-1.7`, A4, 2 pages | MATCH | n/a (document) | pdftotext: same letterhead, topic, 7 students, 7/0/0 counts as screen/print |
| `/attendance/periods/session/1/export` — teacher | 200, `text/csv`, attachment | MATCH | n/a | 7 data rows = the 7 session entries in DB, statuses match |
| `/attendance/periods/report` — admin | 200 "Subject-wise Period Attendance Report" | MATCH (Loop 80 family) | MATCH — 2 tables, 3 `.table-responsive` wraps; filter form wraps | Default scope JSS 2 / English: "Sessions held: 2", per-student rows (Adolphus 2 sess, 1P/0A/1L, 100%), session log with date/time/teacher/topic and "3 of 3", "2 of 3" marked counts |
| `/attendance/periods/report` — teacher | 200, same figures | MATCH | MATCH — same | — |
| `/attendance/periods/export` — teacher | 200, `text/csv`, filename `period-attendance-jss-2-english-language-2026-10-01-to-2026-10-23.csv`, **3 data rows** | **DEVIATION** | n/a | Export is silently scoped to the report's *default filter* (JSS 2 / English Language) — 3 student-summary rows, **not** a session-log export: nothing in the file represents the 36 `attendance_sessions`. A user expecting the period/session export gets one class+subject with no on-page signal of the scope except the filename |
| `/attendance/defaulters` — admin | 200 "Low Attendance (Below 75%)" | MATCH (Loop 78) | MATCH — no table (empty state); filters use the wrapping filter-row pattern; summary cards stack | "0 student(s) below the line · 0 critical (below 50%)" — **correct vs DB**: lowest student is exactly 75.0% (not <75), spot-checked Mikel Hane 3/4 = 75% correctly excluded; 37 enrolled shown as scope; zero-marked students (7) not listed as 0%. Filters present: Class / Section / Month / Below-% (50–90) / Search. Tier language on this page = Critical <50% ✔ |
| `/attendance/defaulters` — teacher | 200, same | MATCH | MATCH — same | — |
| `/attendance/defaulters/export` — admin | 200, `text/csv` | MATCH | n/a | Header row only, 0 data rows — consistent with the (correct) empty defaulter set |
| `/attendance/report` — admin | 200 "Attendance Report" (Monthly, Loop 80) | MATCH | MATCH — table in `.table-responsive`; the one fixed size (`min-width:170px` on the "Attendance %" `<th>`) sits inside the scroll region | Default JSS 2 / Oct 2026: 8 Present, 0 Absent, 1 Late, **9 Total Periods Marked labelled "Student-period entries"** and **3 Registers Submitted labelled "Period registers"** — labelled separately ✔ and both reconcile to DB. Per-student % uses the rule (Adolphus (2+1)/3 = 100% ✔). Letterhead data from SETTINGS (info@hackstra.app); grep: **no "Bangalore", no "5600", no "@gmail"** in page, print or PDF |
| `/attendance/report` status legend — admin/teacher | Page footer policy line | **DEVIATION** | — | Report's status policy reads "Excellent ≥90% · Good 80–89% · **Needs Attention 60–79% · Critical below 60%**" — a *different* tier scale from the cluster rule used on the Low Attendance page (At Risk <75%, Critical <50%). Same student can be "Needs Attention" here and not a defaulter there; tier definitions should be one scale app-wide |
| `/attendance/report/print` — admin | 200 "Monthly Attendance Report — Print Preview" | **DEVIATION** (minor) | MATCH (preview is responsive; toolbar is `print-hide`) | Unlike the session print (standalone doc), this route renders the report **inside full app chrome** (sidebar/topbar in the DOM) with only `.mar-sheet`/`.mar-doc` print CSS + `print-hide` classes to suppress chrome at print time. The document itself is correct (settings letterhead, same figures), but the print path is a preview page, not a standalone print document |
| `/attendance/report/pdf` — admin | 200, `application/pdf`, attachment, 470,808 bytes, A4, 1 page | MATCH | n/a | pdftotext figures = screen: 8 / 0 / 1 / 9 entries / 3 registers, per-student 100% rows; letterhead settings-only, no Bangalore/gmail |
| `/attendance/report/export` — admin | 200, `text/csv` | MATCH (minor note) | n/a | 3 student rows + TOTAL row (8/0/1/9) match screen+DB. Minor: TOTAL row leaves "Attendance %" blank |
| `/attendance/my` — student | 200 "My Attendance" | MATCH | MATCH — 2 tables in `.table-responsive`; summary chips stack | Aarav only: 4 present / 0 / 0, 4 periods, 100%; register list = his 4 DB entries with topics; % rule printed on page |
| `/attendance/my` — parent (Kabir) | 200 | MATCH | MATCH — same | Child switcher lists **only his 2 children** (Aarav Sharma, Layla Anderson V — DB `my_parent_id=4` ✔); default view = Aarav's own figures |
| `/attendance/my` — parent1 (Sunita) | 200 | MATCH | MATCH | Honest empty state: "No student record is linked to your account yet…" (DB: no children for parent 9 ✔) — no other child's data leaks |
| `/attendance/my` — admin | 200 → final `/attendance/report` | MATCH | MATCH | Admin has no "my" attendance; redirected to the report |

**Attendance section totals: 31 rows — MATCH 28, DEVIATION 3, DEFECT 0, BROKEN 0.**


---

## 2. PAYMENTS (admin + accountant)

| Page × Role | Result | Verdict | Phone | Evidence |
|---|---|---|---|---|
| `/payments` — admin | 200 "Manage Payments" (Loop 94 picker + ledger) | MATCH | **DEVIATION** — 9-column records table (Receipt No/Student/Class/Amount/Method/Date/Status/Actions) is scroll-only inside `.table-responsive`; no card/stacked treatment at phone width, so the ledger is a sideways-scrolling spreadsheet on a 390px screen. Stat cards stack fine; row "Actions" menus are `dropdown-menu-right` (safe, §5) | Header cards reconcile exactly: **99 receipts · ₹10,31,300 collected · ₹2,79,700 pending dues · 11 defaulters** = anchors/DB. Rows show receipts dated 23 Oct 2026 (= DB seed date). "Find Student" collect- fee entry + session selector present |
| `/payments` — accountant | 200, same figures | MATCH | DEVIATION — same as admin | Accountant retains full payments access ✔ |
| `/payments` — parent | 200 → final `/home` | MATCH (refusal) | MATCH | Parent cannot enter the payments back-office |
| `/payments` — librarian | 200 → final `/home` | MATCH (refusal) | MATCH | — |
| `/payments` — sa | 200 → final `/dashboard` | MATCH | MATCH | SA boundary |
| `/payments/manage` (no class) — admin | 200 "Student Payments — No class selected" | MATCH | MATCH | Class picker (POST `select_class`, field `my_class_id`) with honest "Pick a class above…" state |
| `/payments/manage/GDBVJ8rGpQjAXm` (Qs::hash of 6) — admin | 200 → final `/payments/manage`, "No class selected" | **DEVIATION** | — | This route's parameter is **not** `{id}`-bound: the app itself addresses classes by **raw id** (`/payments/manage/6`, `/payments/export/6`). A hashed id silently degrades to the picker with no error or hint — inconsistent addressing vs the `{id}` routes elsewhere, and a silent failure mode if any view ever links it hashed |
| `/payments/manage/6` — admin | 200, JSS 2 list: S/N, Photo, Name, ADM_No, Payments — 3 students | MATCH + **KNOWN** | **DEFECT — see §5/D1** (per-student "Manage Payments" dropdown escapes) | Rows = exactly the 3 JSS 2 students in DB ✔. **Print + Export buttons present** ✔ (`/payments/export/6`). Print path (KNOWN — A4): Print is `window.print()` over the **screen table** with a `print-doc-head`/`print-doc-foot` wrapped around it; there is **no dedicated print table** (1 table total) — unlike Fee Defaulters, which has a separate print-only table. The table sits in `.table-responsive{overflow-x:auto}`, the classic structural cause of right-hand columns cutting on A4. Current state: KNOWN print weakness **still structurally present**; A4 rendering itself is not visually verifiable without a browser |
| `/payments/create` — admin | 200 "Create Payment" | MATCH (note) | MATCH — single-column form, fields full-width | This page creates a **fee definition** (Title / Amount ₹ / Class / Payment Method / Description; POST `/payments`). Method is a plain select with exactly **Cash / Online** — no gateway keys, no conditional fields, nothing breaks when toggled in markup. Note: despite the Loop 93 "Collect Fee" framing, there is **no student search on this page** — collecting from a student lives in the Find-Student search on `/payments` → invoice flow (next rows) |
| `/payments/create` — accountant | 200 | MATCH | MATCH — same | — |
| `/payments/student_search?q=aarav` — admin (AJAX, param found in `/payments` page JS: `$.getJSON(..., {q: q})`) | 200 `application/json` | MATCH | n/a (API) | Returns Aarav Sharma with adm_no, class/section, "₹ 0" outstanding, `has_outstanding:false`, and collect URL `/payments/invoice/j2G09gXAZqeOdM` (= Qs::hash of student user id 18 ✔). Wrong param (`?student=aarav`) returns `{"results":[]}` — degrades silently rather than erroring (acceptable for typeahead) |
| `/payments/1` — admin (raw and hashed both tried) | 200 → final `/payments` | **DEVIATION** (minor) | — | There is **no payment show page**: both raw and hashed ids redirect to the index. Harmless because the UI never links a show view (rows use Edit / Receipt / PDF), but the resource route is a dead end |
| `/payments/1/edit` — admin | 200 "Edit Payment" | MATCH | MATCH — form page | App renders **raw-id** edit links (`/payments/{id}/edit`) and they work; the Qs::hash form of the same URL redirects to the index (see manage-hash row — addressing inconsistency, app-internal links are consistent) |
| `/payments/receipts/GaboegA2ZBmvL2` (pr_id 1) — admin | 200, standalone receipt document | MATCH | MATCH (document layout, full-width tables) | School header from SETTINGS (address/phone/info@hackstra.app). Receipt DEMO-RCPT-01-018, Aarav Sharma, Term 1 Tuition Fee: **Amount Paid ₹18,000 / Balance After ₹0 / Total Fee ₹18,000 / CLEARED** = DB receipt 1 + payment_record 1 exactly; amount-in-words correct ("Rupees Eighteen Thousand Only") |
| `/payments/invoice/5kJG9pQngNqj2v` — admin (Collect Fee, from manage/6 rendered href) | 200 "Collect Fee / Create Payment" | MATCH (Loop 93) | **DEVIATION** — 8-column dues table is scroll-only (`.table-responsive`); student summary cards stack, but dues + completed tables demand sideways scroll on phone. Row "Actions" menus are `dropdown-menu-right` (safe, §5) | Adolphus Miller: **Total Fees ₹42,500 / Paid ₹18,300 / Due ₹24,200 / 43%** = DB exactly (12,800+4,000+1,500 paid; balances 19,200+4,000+1,000). Per-fee rows show fee ref, receipt no, amount, paid, balance, Partially Paid status ✔ |
| `/payments/invoice/5kJG9pQngNqj2v/2018-2019` — admin | 200, same | MATCH | DEVIATION — same | Session-scoped form of the same page |
| `/payments/pdf_receipts/DRXoJgaY2gM7PL` — admin (rendered href from invoice page) | 200, `application/pdf`, attachment, 880,985 bytes, `%PDF-1.7` | MATCH | n/a | Resolves to receipt id 57 / DEMO-RCPT-18-040 (Adolphus, Exam Fee): PDF text shows **₹1,500 paid / ₹1,000 balance** = DB receipt row (amt_paid 1500, balance 1000) ✔; settings letterhead |
| `/payments/export/6` — admin | 200, `text/csv`, `payments-jss-2-2018-2019.csv` | MATCH | n/a | 9 data rows = 3 class-6 students × 3 fees, amounts/balances = DB (Adolphus 12,800/19,200 etc.) |
| `/payments/export_student/5kJG9pQngNqj2v` — admin (rendered href) | 200, `text/csv`, `payments-adolphus-miller.csv` | MATCH | n/a | 3 rows = Adolphus's 3 receipts, figures = DB. Takes the same student hash as the invoice URL (no raw id needed) |
| `/payments/reports/defaulters` — admin | 200 "Fee Defaulters" (Loop 95) | **MATCH — anchors exact** | MATCH — screen table in `.table-responsive`; `.def95-*` rules include `@media` phone adjustments (pill size, student-cell gap shrink); money cells `white-space:nowrap` inside the scroll region, so no squeezed amounts | Header: **11 defaulter(s) owing ₹2,79,700 · Partially Paid 7 / ₹1,62,700 · Fully Unpaid 4 / ₹1,17,000** — all four figures exact vs DB. Filters present: Class / Section (dependent) / Status (All, Unpaid, Partially Paid) / Search. All 11 rows spot-checked vs DB (Albertha ₹33,000 unpaid; Adolphus 42,500/18,300/24,200; Winona 25,500/11,100/14,400 ✔). Second summary table (unpaid items / oldest fee) below |
| `/payments/reports/defaulters` — accountant | 200 | MATCH | MATCH — same | — |
| Fee Defaulters print — admin | Print button on the page | MATCH | — | Print is a **designed one-page document**: dedicated `.def95-print-only` table (separate from the screen table), `print-doc-head`/`print-doc-foot`, screen elements `print-hide`, `window.print()` trigger. Structurally the professional one-pager the Loop 95 spec asks for (contrast `/payments/manage/6`, which has no print-only table). A4 fit not visually verifiable without a browser |
| `/payments/reports/defaulters/export` — admin | 200, `text/csv`, `fee-defaulters-2018-2019.csv` | MATCH | n/a | 11 data rows; Total Due column sums to ₹2,79,700 ✔; oldest-fee column populated |
| `/payments/reports/collection` — admin | 200 "Fee Collection Report" (Loop 97) | **MATCH — anchors exact** | MATCH — table in `.table-responsive`; From/To date + class/section filters use `.pat-filter-form{display:flex;flex-wrap:wrap}` with fields `flex:1 1 180px` and a phone media rule stacking fields to 100% — Filter/Reset stay reachable | **₹10,31,300 collected · 99 receipts · avg ₹10,417 · 33 students paid** — all exact. Honest labelling: "Daily Average ₹10,31,300 — Per day with collections (1)" and a footnote that days without receipts are not counted as zero days. Range defaults 01–23 Oct 2026; the single collection day is 23 Oct (DB seed date) |
| `/payments/reports/collection` — accountant | 200 | MATCH | MATCH — same | — |
| `/payments/reports/collection?from=2026-10-23&to=2026-10-01` (from > to) — admin | 200 → redirect to default range **with explicit flash** | MATCH | — | Flash (danger) rendered by the page's flash handler: **"Invalid date range: the To date must be on or after the From date. Showing the default range instead."** — explicit rejection + stated fallback, *not* a silent swap. (Garbage date `from=2026-13-40` likewise redirects to the default range; flash text not separately captured for that variant) |
| `/payments/reports/collection/day` — admin | 200 → final `/payments/reports/collection?from=2026-10-01&to=2026-10-23` | MATCH | MATCH | "Day" view = redirect into the ranged report for the current period; figures identical |
| `/payments/reports/collection/export` — admin | 200, `text/csv` | MATCH | n/a | 1 day row (23 Oct 2026, 99, 1031300, 10417) + Total row — exact |
| `/payments/reports/collection/pdf` — admin | 200, `application/pdf`, 879,436 bytes | MATCH | n/a | pdftotext contains ₹10,31,300 / 99 / ₹10,417 ✔ |
| `/payments/gateway-transactions` — admin | 200 "Online Payments" | MATCH | MATCH — empty-state layout, no table to overflow | **Honest mock state**: "0 gateway transaction(s)" + "No online payments yet. When a parent pays a fee online, the transaction appears here." DB `gateway_transactions` = 0 rows ✔ — no fabricated captures; page also states captured money is recorded as normal fee receipts |

**Payments section totals: 27 rows — MATCH 23, DEVIATION 4 (manage hash fallback, payments show dead-end, phone: /payments index, phone: invoice), DEFECT 0 in this table (the manage/6 phone DEFECT is counted in §5), BROKEN 0.**


---

## 3. PAYROLL

| Page × Role | Result | Verdict | Phone | Evidence |
|---|---|---|---|---|
| `/payroll` — admin | 200 "Payroll", October 2026 | MATCH | **DEVIATION** — 9-column staff table (Member/Code/Role/Basic/Allowances/Deductions/Net/Status/Actions) is scroll-only in `.table-responsive`, and each row carries an inline expanding Edit/Generate form whose 4 fields inherit the table width — cramped, sideways-scroll work on a 390px phone; no card treatment | 13 staff listed, **4 generated** for October; **Total Net Payout ₹1,83,500** (= DB sum 49,000+64,000+40,700+29,800 ✔), **Paid Out ₹49,000**, **Pending ₹1,34,500** ✔. Honest scope note on page: "Marking a salary Paid records that it was disbursed — no bank transfer is made from this page" |
| `/payroll` — accountant | 200 → final `/home` (Dashboard) | MATCH (settled gate holds) | MATCH | Accountant is redirected out of payroll (302-style gate to dashboard, not a 403 page) — and `/payments/*` still works for the same account (rows in §2), so the gate is payroll-specific ✔ |
| `/payroll` — sa | 200 → final `/dashboard` | MATCH | MATCH | SA boundary |
| `/payroll/1/payslip` — admin | 200 "Payslip — October 2026" | **MATCH — anchor exact** | MATCH (see payslip-doc phone note below) | **Ananya Sharma (teacher@)**: Basic **₹45,000**, Allowances **₹6,500**, Total Earnings ₹51,500, Deductions **₹2,500**, **NET PAY ₹49,000**, "Rupees Forty Nine Thousand Only", Payment Date **05 Oct 2026**, Status PAID, Payslip No #1 — every anchor figure ✔ |
| `/payroll/2/payslip` — admin | 200 | MATCH | MATCH | Kavita Rao (user 2): 60,000 / 8,000 / 4,000 / **net ₹64,000**, PENDING, Payment Date "—" = DB payroll 2 ✔ (correctly *not* Ananya's slip — ids resolve to their own owners) |
| `/my-payslips` — teacher | 200 "My Payslips" (Loop 89) | **MATCH — anchor exact** | **DEVIATION** — history table (9 columns incl. Paid On + Actions) is scroll-only in `.table-responsive`; summary cards and the Download All button stack fine, but the table itself has no phone treatment beyond sideways scroll | Oct 2026 row: **45,000 / 6,500 / 2,500 / net ₹49,000 / Paid / 05 Oct 2026** ✔. Header stats honest: "Total Received · 2026 ₹49,000 · 1 paid payslip" |
| `/my-payslips/1` — teacher | 200, own payslip document | MATCH | MATCH | Same document as admin view of payroll 1 |
| `/my-payslips/2` — teacher | **403** "Access not allowed" | MATCH (refusal) | — | Payroll 2 belongs to user 2 (Kavita Rao); teacher (user 3) is refused — no foreign payslip leak |
| `/my-payslips/1/pdf` — teacher | 200, `application/pdf`, attachment `Payslip_October_2026.pdf`, 1,276,105 bytes, `%PDF-1.7` | MATCH | n/a | pdftotext contains "Ananya Sharma", 45,000, ₹49,000 ✔ |
| `/my-payslips/download-all?year=2026` — teacher | 200, `application/pdf`, attachment `Payslips_2026.pdf`, 7,408 bytes, 1 page A4 | MATCH (note) | n/a | A **real single-document PDF** (not a zip/placeholder): settings letterhead + the Oct 2026 payslip with ₹49,000. Note: at 7.4 KB it is plainly produced by a different/lighter render path than the 1.27 MB single-payslip PDF (no embedded logo/font payload) — figures are identical, so this is a consistency note, not a defect |
| `/my-payslips` — librarian | 200 | MATCH | DEVIATION — same table treatment as teacher | Own row: Revathi Subramanian, Oct 2026, 28,000 / 3,000 / 1,200 / **net ₹29,800 / Pending** = DB payroll 4 (user 55) ✔ |
| `/my-payslips/4` — librarian | 200, own payslip | MATCH | MATCH | — |
| `/my-payslips/1` — librarian | **403** | MATCH (refusal) | — | Foreign payslip refused for staff role too ✔ |

**Payroll section totals: 13 rows — MATCH 11, DEVIATION 2 (both phone table treatment), DEFECT 0, BROKEN 0.**

---

## 4. PARENT FEES

Parent under test: Kabir Anand (`parent@hackstra.app`, user 4) — DB children (`student_records.my_parent_id=4`): Aarav Sharma (user 18) + Layla Anderson V (user 19), both fully paid (balances ₹0).

| Page × Role | Result | Verdict | Phone | Evidence |
|---|---|---|---|---|
| `/my_children` — parent | 200 "My Children" | MATCH | MATCH — table in `.table-responsive`; row actions are plain links (View Profile / Marksheet) | Exactly his 2 children with adm numbers HA/N/2026/99128 + HA/N/2026/75099 = DB ✔ |
| `/my_children` — parent1 | 200 | **DEVIATION** (minor) | MATCH | Table renders header skeleton (S/N/Photo/Name/ADM_No/Section/Email/Action) with **zero rows and no empty-state message** — unlike `/my_fees` for the same account, which explains the situation in words. A parent with no linked child sees a blank grid |
| `/my_fees` — parent | 200 "Fees" | **DEFECT** | MATCH structurally — both child tables in `.table-responsive`; the defect below is a data defect, visible at every width | **Per-row Balance column is wrong for fully-paid fees.** For both children every row reads Fee Amount = Paid = **Balance** (Tuition ₹18,000 / ₹18,000 / **₹18,000**; Transport ₹6,000 / ₹6,000 / **₹6,000**; Exam ₹1,500 / ₹1,500 / **₹1,500**) while the same card's Total row correctly says **Total ₹25,500 / Paid ₹25,500 / Balance ₹0 — "Fees clear"**, and DB `payment_records.balance` = **0** for all six records. The Balance column duplicates the fee amount instead of the remaining balance. A parent reading row-by-row would believe ₹25,500 is still owed per child while the page simultaneously says "Fees clear" — contradictory money figures on one screen |
| `/my_fees/18` — parent (Aarav, rendered raw user-id href) | 200 | MATCH | MATCH | Detail page is correct: Completed Payments table (Amount/Paid, no broken Balance column), Total ₹25,500 / ₹25,500, "Fees clear for 2018-2019", "No incomplete payments" ✔ = DB. Confirms the DEFECT above is localised to the `/my_fees` index cards |
| `/my_fees/19` — parent (Layla) | 200 | MATCH | MATCH | Same correct structure for the second child |
| `/my_fees/20` — parent (another family's student) | **403** "Access not allowed" | MATCH (refusal) | — | User 20 is not his child; refused ✔ |
| `/my_fees/receipt/1` — parent (own child's pr_id 1) | 200, standalone receipt | MATCH | MATCH (document) | Identical document to the admin receipt: DEMO-RCPT-01-018, Aarav, ₹18,000 paid, **Balance After ₹0**, CLEARED = DB ✔ |
| `/my_fees/receipt/10` — parent (pr_id of student 21, Dr. Winona Cruickshank) | **403** "Access not allowed" | MATCH (refusal) | — | Another student's receipt is refused — the exact cross-family probe; no receipt data leaks ✔ |
| `/my_fees` — parent1 | 200 | MATCH | MATCH | Honest empty state in words: "No children are linked to your account yet. Once the school links your child's record…" (DB ✔) |
| `/payments` — parent | (counted in §2) → `/home` | MATCH (refusal) | MATCH | Parent cannot reach the back-office payments module |

**Parent-fees section totals: 9 rows — MATCH 7, DEVIATION 1, DEFECT 1 (the `/my_fees` Balance column), BROKEN 0.**

---

## 5. DEFECT CLASS SWEEP — clipped / escaping buttons & controls (owner order 2026-10-23 01:31 IST)

| # | Page × Role | Element | Verdict | Structural mechanism |
|---|---|---|---|---|
| D1 | `/payments/manage/6` (and every class list) — admin + accountant | Per-student **"Manage Payments"** dropdown button, right-most "Payments" column (×3 on this page, one per student) | **DEFECT** | The toggle sits in the **last column** of the table, but its menu is rendered `class="dropdown-menu dropdown-menu-left"` — the menu's left edge aligns to the button's left edge and it **opens rightward**, straight past the table/card edge. `qs.css` contains **zero** rules for `dropdown-menu-left` (grep count 0) — no phone media query re-aligns it, no `dropdown-menu-right` flip. On a 390px phone the menu (≈200px wide, items like "All Payments 2018-2019") necessarily runs off the right edge of the screen and is clipped — the exact owner complaint class ("button opens a menu escaping the screen"). On desktop it likewise spills outside the card. Every other row-action menu in this cluster uses `dropdown-menu-right`; this one is the outlier |
| D2 | `/payments/manage/{class}` print + phone table (KNOWN) | Class table + Print button | **DEVIATION** (KNOWN, still present) | See §2 manage/6 row: print = screen table via `window.print()` inside `.table-responsive` with only a print head/foot added — no print-only table. Phone: the table itself scrolls by design (not squeezed), but combined with D1 the page's primary per-student control is the broken one |

**Action controls checked and provably safe (one-line summary):** invoice "Actions" menus (`dropdown-menu-right` ×3) · `/payments` index row "Actions" + "Class Payments" menus (`dropdown-menu-right`) · topbar notification/user menus on every page (`dropdown-menu dropdown-menu-right lav-*`) · attendance session Print/Export CSV/Download PDF (plain links, in-flow) · attendance take-page sticky summary bar (designed sticky, Loop 61, in-flow — counts + "Mark all present" stay inside the form column) · Fee Defaulters / Collection Print + Export buttons (plain buttons/links in `.pat-toolbar-actions`, filter rows `flex-wrap:wrap`) · payroll Payslip/Mark Paid/Edit toggles and My-Payslips View/PDF/Download All (plain links/buttons) · parent My Children / My Fees Receipt links (plain links) · payslip document fixed widths are safe (the 380px element is the decorative watermark only; all data tables are `width:100%`).

**Phone-rendering mechanisms relied on above (qs.css evidence):** `.pat-filter-form{display:flex;flex-wrap:wrap}`, `.pat-filter-field{flex:1 1 180px}` + phone media stacking fields to `flex-basis:100%`; `.def95-*` phone media adjustments (pill/gap shrink); Bootstrap `.table-responsive` wrappers on every wide table in the cluster (counts per page: payments index 13, invoice 1, defaulters 1, collection 1, payroll 1, my-payslips 1, registers 1, reports 1–3, parent pages 1–2); register *entry* is a card list, not a table. Recurring phone shortfall is **scroll-only wide tables** (payments index, invoice, payroll, my-payslips) — usable, not clipped, but short of the premium standard: marked DEVIATION in-line above.

---

## Summary counts

| Section | Rows | MATCH | DEVIATION | DEFECT | BROKEN |
|---|---|---|---|---|---|
| 1. Attendance | 31 | 28 | 3 | 0 | 0 |
| 2. Payments | 27 | 23 | 4 | 0 (D1 counted in §5) | 0 |
| 3. Payroll | 13 | 11 | 2 | 0 | 0 |
| 4. Parent fees | 9 | 7 | 1 | 1 | 0 |
| 5. Control-clip sweep | 2 | — | 1 | **1** | 0 |
| **Total** | **82** | **69** | **11** | **2** | **0** |

### Headline findings
1. **DEFECT — `/my_fees` Balance column (parent):** every paid fee row shows Balance = full fee amount while the same card's total says ₹0 / "Fees clear" and DB balance is 0. Contradictory money figures for parents. (§4)
2. **DEFECT — `/payments/manage/{class}` "Manage Payments" dropdown (KNOWN class, owner's complaint pattern):** `dropdown-menu-left` in the right-most column, no CSS rescue — menu escapes the card and the phone viewport. (§5/D1)
3. **Money anchors are otherwise perfect:** Fee Defaulters page, Collection page, both CSVs, the collection PDF, the payments index cards and the DB all agree to the rupee (11 / ₹2,79,700; 7 / ₹1,62,700; 4 / ₹1,17,000; 99 / ₹10,31,300 / ₹10,417 / 33 students). Payslip anchor exact everywhere it appears (admin payslip, teacher list, teacher PDF, download-all PDF).
4. **Security scoping all held:** student/accountant refused registers; accountant gated out of payroll (redirect) while keeping payments; teacher + librarian refused foreign payslips (403); parent refused foreign fee detail and foreign receipt (403); SA redirected out of all school-operational C4 pages.
5. **Deviations worth a fix queue:** attendance status-tier scale inconsistency (report legend Critical <60% vs defaulters Critical <50%); `/attendance/periods/export` silently scoped to the default class+subject instead of the 36 sessions; `/payments/manage/{hash}` silent fallback + no `/payments/{id}` show page; report print is an in-chrome preview while session print is a standalone doc; `/my_children` blank-grid empty state for unlinked parents; scroll-only wide tables on phone (payments index, invoice, payroll, my-payslips).
6. **Anchor variance (not an app defect):** the brief's "all receipts dated 22 Oct 2026" does not hold in this fresh seed — receipts are dated 23 Oct 2026 in DB and on every page, consistently.

*Evidence: probe JSONs `/tmp/audit/c4_*.json`, saved HTML `/tmp/audit/html/`, binary-safe downloads `/tmp/audit/bin/` (PDFs/CSVs/JSON), DB read-only queries against `database.sqlite`.*


---

# APPENDIX E — Cluster C5: Circulars, feedback, Ask, assignments, materials, library, settings, public

# Audit C5 — Circulars + Feedback + Ask Hackstra + Assignments + Study Materials + Library + School Settings + Misc Public

PURE AUDIT — no app files edited. App: http://127.0.0.1:8937, worktree `hackastra-batch146`, fresh seed.
Method: probe harness per role (status/final URL/HTML saved to /tmp/audit/html/), sqlite read-only, route list, blade/CSS source read-only.
Phone verdicts: structural derivation from rendered HTML + `public/assets/css/qs.css` only (no browser/CDP). Every Phone MATCH cites its mechanism.

## Ground truth (sqlite, read-only)

- **circulars: 4 rows exist (seed brief said 3)** — id 4 = "Term 1 fee reminder" (parents). Columns are `starts_at`/`expires_at`/`is_published` (no publish_from/publish_until/status columns).
  id1 all/normal, id2 teachers/important, id3 parents/normal, id4 parents/important; all starts 2026-10-21, expires 2026-11-02, is_published=1, published_by=1 (Ankit Kumar). **On 2026-10-23 all 4 are Live/Active; there is NO scheduled, expired or draft circular in seed** → the "show() is Live-only?" question and non-live list behaviour could not be data-verified (limitation, see below).
- feedback_items: **0 rows** (feedback detail pages unverifiable with data — stated explicitly where relevant). Attachments are real: separate FeedbackAttachment model/table, controller stores files (`storeAs …/feedback`).
- assignments: id1 class 1 (Nursery 1), subj 1, teacher 3, due 2026-10-28 → Open; id2 class 4 (Primary 1), subj 7, teacher 3, due 2026-10-20 → Closed. Both `attachment_path` empty. Submissions: asg1 = 1 row / 1 distinct student / 0 graded; asg2 = 3 rows / 3 distinct / 2 graded. Eligible: class 1 has 7 student_records, class 4 has 3.
- study_materials: id1 class 4 link-only, id2 class 1 link-only (both `file_path` NULL, only `link_url`, teacher 3, created today).
- books: 7 titles, copies sum 23, issued sum 1. book_requests: 1 issued loan (book 1, Aarav Sharma, 20 Oct → 03 Nov 2026).
- settings: 27 rows. Values: system_name "Hackstra Academy", system_title "HA", phone 0123456789, system_email info@hackstra.app, address as printed on circular print, current_session 2018-2019, ai_enabled 0, ai_api_key empty, logo/stamp/principal_name empty. **No razorpay_* rows exist in the settings table** (keys are handled as write-only secrets by the controller; nothing stored in seed to leak).
- Teacher scope (subjects.teacher_id=3): classes 1, 2, 4 × English Language + Mathematics — this is the exact collection the assignment/material create selects show.
- student@hackstra.app = user 18, class 1. Routes use raw-id model binding for these resources (`{circular}`, `{assignment}`, `{material}` params): the Qs::hash `{id}` rule does **not** apply — in-app links are raw ids and work; hashed URLs (/circulars/GaboegA2ZBmvL2 etc.) 404 for every role, consistently. Not a defect in this tree.

## Summary counts

| Scope | MATCH | DEVIATION | DEFECT | BROKEN | Unverifiable (seed) |
|---|---|---|---|---|---|
| Desktop rows (59) | 50 | 7 | 0 | 0 | 2 |
| Phone rows (18) | 11 | 5 | 2 | 0 | (enabled-Ask noted inside its row) |

Headline defects (both phone, clipped-controls class): circular create/edit sticky action bar at 390px; assignment grading table's fixed 290px column.

---

## 1. Circulars

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| List — admin | MATCH | 4 chips Total 4 / Active 4 / Upcoming 0 / Expired 0 — exact vs DB (all 4 live). Search (`q`), audience, priority, status, from/to filters all in one GET form and **server-side verified**: `?audience=parents` → only ids 3,4; `?status=expired` → honest empty state; `?priority=urgent` → empty; `?q=Diwali` → only id 1; `?q=zzzznone` → empty state. Priority accent on cards (circ-pri-*), "Read Full Circular" present, Create button present (admin can create). No hashtags/tags, no banner images (no attachment/banner columns exist; none rendered). Card actions sit in a kebab menu (Edit / Unpublish / Delete + toggle-publish & delete forms). | MATCH | `.pat-filter-form{display:flex;flex-wrap:wrap}`, fields `flex:1 1 180px;min-width:0`; @575 `.pat-filter-field{flex-basis:100%}`, `.pat-filter-action .btn{width:100%}` → filters stack full-width, none unreachable. Stats are an inline grid `repeat(auto-fit,minmax(180px,1fr))` → collapses to 1 column at 390px, never 4-across. `.l75-circ-card .circ-item-top{flex-wrap:wrap}`; kebab menu is `dropdown-menu-right` (right-aligned, cannot escape right edge). |
| List — teacher | MATCH | Scoped correctly: Total 2 / Active 2 (ids 1+2 only); audience filter options scoped (Everyone/All Staff/Teachers); **no Create button**, no Edit/Delete kebabs. | MATCH | Same shared mechanisms as admin row. |
| List — student | MATCH | Scoped: Total 1 / Active 1 (id 1 only); no Create. | MATCH | Same shared mechanisms. |
| List — parent | MATCH | Scoped: Total 3 / Active 3 (ids 1,3,4); no Create. | MATCH | Same shared mechanisms. |
| List — sa | MATCH | Redirected to `/dashboard` (Platform Overview) — SA has no school-circulars view here, consistent with the SA view-only/platform boundary. | MATCH | Redirect target is the (separately audited) dashboard; no C5 surface renders. |
| Detail /circulars/1 — admin | MATCH | Hero card: title + Normal/Published/Active pills; meta grid (Audience Everyone, Published by Ankit Kumar, Published 23 Oct 2026, Available from 21 Oct, Valid until 02 Nov); Quick Information rail shows **Circular ID plainly as `#1`** — no fabricated reference number anywhere (KNOWN: batch142 ref/attachment/signatory feature is NOT in this tree and is correctly absent). Message rendering is escaped with paragraph breaks: blade `{!! nl2br(e($circular->body)) !!}` — no raw-HTML/XSS path. Actions role-true: Back / Print / Download PDF / Edit / Delete. | MATCH | `.l75-cd-toolbar{display:flex;flex-wrap:wrap;gap:8px}` → all 5 admin buttons wrap instead of clipping; meta grid `repeat(auto-fit,minmax(150px,1fr))` → 2 narrow columns at 390px; main/rail are Bootstrap col-lg-* → stack below 992px; pills row `flex-wrap:wrap`. |
| Detail — teacher (ids 1,2) | MATCH | 200 for audience-matching circulars; actions limited to Back / Print / Download PDF (no Edit/Delete forms in HTML). **Audience enforcement verified: GET /circulars/3 (parents) → 404.** | MATCH | Same detail mechanisms. |
| Detail — student (id 1) | MATCH | 200 for id 1; read actions only. **Enforcement verified: /circulars/2 (teachers) → 404, /circulars/3 (parents) → 404.** | MATCH | Same detail mechanisms. |
| Detail — parent (ids 1,3) | MATCH | 200 for ids 1,3; read actions only. **Enforcement verified: /circulars/2 (teachers) → 404.** | MATCH | Same detail mechanisms. |
| Create /circulars/create — admin | MATCH | Redesigned form present: numbered sections (1 Circular Details, 2 Audience & Visibility), **live preview panel present and genuinely driven by the form** (`syncPreview()` JS keyed on title/body/audience/priority element values, preview card id `pv_item`). Fields match the model exactly (title, body, audience, priority, starts/expires, published/draft). **No attachment input of any kind** (`type="file"` count 0) — correct for this tree, no fake upload. Sticky action bar present (see Phone). | **DEFECT** | Preview stacking itself is correct: `.l75-circ-layout` base is 1 column; the 380px sticky side preview only applies `@media(min-width:1200px)` → preview stacks below the form on phone. **But the sticky action bar clips:** `.pat-actionbar{display:flex;justify-content:flex-end;gap:10px}` has **no flex-wrap in the base rule or in its @767 block** (that block only reduces padding and sets `.btn-primary{min-width:0}`), and Bootstrap `.btn{white-space:nowrap}` makes each button unshrinkable below its text width. The bar holds 3 buttons (Save as Draft + Cancel + Publish Circular ≈ 340–360px combined min-content) against ≈330px available inside the bar at a 390px viewport (390 − 2×14 content padding − 2×16 bar padding) → the first button (Save as Draft, the overflow side under flex-end) is pushed/clipped off the bar's left edge. Same 3-button bar on /circulars/1/edit → same defect there. |
| Create — teacher / student | MATCH | GET redirects to `/home` (dashboard); no create access, consistent with no Create button in their lists. | MATCH | No C5 surface renders. |
| Print /circulars/1/print — admin | MATCH | Real print view: school header, title, **"Circular No. #1"** (plain id, not a dressed-up reference), audience/priority/published-by/dates, body, "Printed 23 Oct 2026" footer. No signatory, no attachment section — batch142 items correctly absent (KNOWN). | n/a | Print stylesheet surface; not a phone screen. |
| PDF /circulars/1/pdf — admin | MATCH | Returns a real generated PDF (`%PDF-1.7`, ~4.4KB binary stream), not a redirect or placeholder. | n/a | File download. |
| Edit /circulars/1/edit — admin | MATCH | 200, same redesigned form family as create. | **DEFECT** | Same 3-button `.pat-actionbar` mechanism as Create (see above). |

**Data limitation (circulars):** seed contains no Draft/Scheduled/Expired circular, so whether `show()` refuses non-live circulars while the list displays them could not be probed. List status filter does include Draft for admin and correctly returns the honest empty state for `status=expired`.

## 2. Feedback

**Data limitation (applies to all feedback rows): feedback_items is EMPTY in seed.** List/empty states and the create form are fully audited below; **feedback detail pages (`/feedback/mine/{id}`, `/feedback/{id}`, `/feedback/school/{id}`) could NOT be data-verified** — routes exist in the route list, but there is no row to open. Verdict for detail pages: UNVERIFIABLE (seed), not MATCH.

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| Create /feedback/create — teacher (also admin, student: 200, same form) | MATCH | Loop 75 pat-form, numbered steps (1 Report Details, 2 Describe & Attach, 3 Review, 4 Submit). Fields = model exactly: type, category (labelled "Area"), priority, title, context ("Where did this happen?"), description, `page_url` hidden. **No recipient select — by design**: destination is fixed to the school Admin ("Send to Admin", copy states only you + Admin can see it, Admin may forward to SA). Attachment upload is **real**: `attachments[]` file input + controller stores files and creates FeedbackAttachment records — not a discarded fake. | MATCH | `.pat-fields` 2-column grid collapses to 1 column @767 (qs.css ~1343); action bar holds only 2 buttons (Cancel + Send to Admin ≈ 205px) → fits the ~330px bar width at 390px. |
| Mine /feedback/mine — teacher | MATCH (empty state) | Honest empty state: cards Total 0 / Pending 0 / Resolved 0 / Closed 0, "No feedback reports yet", Create Your First Report CTA. Nothing fabricated. | MATCH | Stat cards are `dashboard-stat-card` in Bootstrap `col-sm-6 col-xl-3` → 2-across ≥576px, 1-column below; no table to squeeze. |
| Mine — status filtering | DEVIATION | Loop 87 question: **there are no status filter chips and no filter form/select on My Feedback at all** — the four stat cards are display-only. With data, a user could not filter their reports by status. | DEVIATION | Same absence on phone (nothing rendered to wrap or clip). |
| Inbox /feedback/inbox — admin | MATCH | "School feedback inbox": stats New 0 / In Progress 0 / Resolved 0 / Duplicates Merged 0 (all honest for empty table); server-side filter selects Status / Type / Area / Priority; table with honest empty row "No feedback matches. New reports from anyone in the school land here."; forward-selected bulk bar exists, so the row checkboxes have a real bulk action (not orphan checkboxes). | DEVIATION | The results table (checkbox + Report + Reporter + Status + Reported + …) sits in `.table-responsive` → no clipping/escape, but on a 390px phone it requires horizontal scrolling; short of the premium standard the assignments/materials pages meet with card layouts. Stat cards collapse correctly (col-sm-6 grid). |
| Inbox — teacher / student | MATCH | 403 "Access not allowed" for both — role-true. | MATCH | Error page only. |
| Detail pages (mine.show / show) | UNVERIFIABLE | Routes resolve (`feedback.mine.show`, `feedback.show`, `feedback.sa_show`) but seed has zero feedback rows — no detail page could be opened with data. | UNVERIFIABLE | Same limitation. |

## 3. Ask Hackstra (/ask)

Seed state: `ai_enabled=0`, no API key → the rendered page for every school role is the **disabled state**. The enabled chat UI therefore could not be live-probed; enabled-state claims below are structural (blade + CSS) and marked as such.

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| /ask — teacher | MATCH | Honesty copy is exemplary: "answers questions from your school's own circulars and policies… names the document every answer came from", "**It cannot see marks, fees or personal records**", "currently switched off… nothing to ask right now". No chat form, no suggestion chips and **no claim to live attendance/marks/fees data** while off. Help tiles route to Give Feedback / Contact Admin (info@hackstra.app, matches settings). Endpoint exists: route list has `POST ask → AskController@ask` and the (unrendered) blade form posts to `route('ask.ask')` — resolves. | MATCH | @767 `.l88-hero-inner{flex-direction:column;gap:6px}` and `.l88-hero-visual{flex-basis:140px;height:140px;width:190px}` → hero stacks, visual cannot squeeze text; `.l88-head{flex-wrap:wrap}`, @767 `.l88-head-actions{padding-top:0}`; help grid `repeat(auto-fit,minmax(250px,1fr))` → 1 column at 390px. |
| /ask — student | MATCH | Same disabled state and honesty copy, student-appropriate help options. | MATCH | Same mechanisms. |
| /ask — admin | MATCH | Same, plus role-true "Open AI Settings" action linking to the settings AI section. | MATCH | Same mechanisms. |
| /ask — sa | MATCH | Redirected to `/dashboard` (Platform Overview) — SA gets no school Ask surface. | MATCH | No C5 surface renders. |
| Enabled chat + suggestion chips | UNVERIFIABLE (live) | Not rendered in seed (AI off). Structural check of the blade: chips are `<button type="button" class="l88-chip" data-question="…">` with a JS handler that fills the composer (blade comment + script) — **not dead buttons by construction**; composer posts to the existing `ask.ask` route. This could not be exercised end-to-end. | DEVIATION (structural, enabled state only) | Bubbles are handled (`.ask-bubble{max-width:82%}` → 94% @575) and chips wrap (`.l88-suggest{display:flex;flex-wrap:wrap}`), **but `.ask-composer-bar{display:flex;justify-content:space-between}` has no flex-wrap and no @575/@767 treatment** — the hint text ("Answers cite the school's own circulars and policies only.") and the nowrap Ask submit button share one row and will squeeze/compete at 390px. |

## 4. Assignments

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| List — teacher | MATCH | **Cards verified vs DB: Total 2 / Open 1 / Closed 1 / Classes With Assignments 2.** Submissions shown as submitted/eligible with distinct students: `1 / 7 submitted · 0 graded` (asg1) and `3 / 3 submitted · 2 graded` (asg2) — all exact vs DB. Server-side GET filters: search, class, subject, status, sort (due-date/title). **List/grid toggle present and real** (`.lms82-toggle` buttons switch table vs `.lms82-grid`). **No checkbox column anywhere** (checkbox count 0). Actions per row: View / Edit / Delete (teacher owns both assignments). | DEVIATION | Stats collapse correctly (`.lms82-stats` 4-across → 2×2 @991 → 1 column @575, and `.lms82-grid` `minmax(320px,1fr)` → `minmax(0,1fr)` @575), and the table sits in `.table-responsive` (no clipping). But the **default view is the 7-column table**, which needs horizontal scrolling at 390px — the grid alternative exists but is not the default. Short of premium, not clipped. |
| List — admin | MATCH | Same verified counts (2/1/1/2, same submission fractions); actions View / Delete only (admin did not create them). | DEVIATION | Same table-scroll mechanism as teacher row; stats collapse correctly. |
| List — student ("My Assignments") | MATCH | Shows **only the class-1 assignment** (class-4 asg2 absent, as required); own state shown as "Submitted" with the real due date/teacher. No create/edit/delete controls. | MATCH | Student list is a card/row layout (not the 7-column table); rows stack full-width. |
| List — parent | MATCH | Per-child sections (both children in class 1): only asg1, with Submitted / Pending state per child and an honest Marks column (— when ungraded). Asg2 (class 4) absent. | MATCH | Per-child stacked blocks, no wide table. |
| Show /assignments/1 — teacher | MATCH | Submissions list with grading state chips (1 received / 0 graded / 1 waiting — exact), the one real submission (Aarav Sharma, text answer, submitted 22 Oct), per-submission grading form (marks_awarded + feedback + Save), "Use for Internal Marks" action. | **DEFECT** | Submissions table header cell carries an **inline `style="min-width:290px"`** on the "Marks & Feedback" column — the column containing the marks input, feedback input and Save button. Inside `.table-responsive` the table's total min-width (Student + Submitted + Answer + fixed 290px grading column) far exceeds 390px, so the page's primary teacher task (grading) sits in a fixed 290px column reachable only by horizontal scrolling, squeezed against the viewport edge. Exactly the owner's squeezed-column class. |
| Show /assignments/1 — student | MATCH | Own submission rendered with text, submitted timestamp, and honest ungraded state ("Your teacher has not graded this yet"). No other students' submissions visible. | MATCH | Single-column stacked layout, no table. |
| Direct GET /assignments/2 — student & parent | MATCH | Both are redirected to `/dashboard` — the class-4 assignment is **not leaked** by direct URL (and not in their lists). | MATCH | No C5 surface renders. |
| Create /assignments/create — teacher | MATCH | Class select = Nursery 1 / Nursery 2 / Primary 1 and Subject select = exactly teacher 3's six scoped subjects (class-labelled) — correct collections only, no unscoped classes/subjects. Attachment field is real (`attachment_path` column + download path in controller). Student GET /assignments/create → redirect to dashboard. | MATCH | `.pat-fields` → 1 column @767; action bar has 2 buttons (Cancel + Publish Assignment ≈ 225px) → fits at 390px. |
| Edit /assignments/1/edit — teacher | MATCH | 200, same form family with the assignment's values. | MATCH | Same pat-form mechanisms as create. |
| Download /assignments/1/download — teacher | DEVIATION | Route exists and is safe (no UI button exposes it for attachment-less assignments — list/show render no download link), but a direct hit on an assignment with **no attachment returns 403 "Access not allowed"**, although the teacher owns the assignment and the real reason is "no file attached" (controller: `if (!$assignment->attachment_path …) abort(403)`). Misleading semantics on direct access; no dead UI control. Asg2 download behaves the same (403). | DEVIATION | Error page only; same semantics. |
| Internal preview /assignments/1/internal/preview — teacher | MATCH | 200, real flow: exam select (Term 1 Examination), average vs best-N choice, plain warning "pushing writes real marks", preview-before-push. No fake data shown. | MATCH | Single-column form layout (pat-form family); no wide tables or multi-button bars. |

## 5. Study Materials

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| List — teacher | MATCH | **Cards verified vs DB: Total 2 / Subjects With Materials 2 / Classes With Materials 2 / Recent 2 — and "Recent" is labelled on the card** ("Added in the last 7 days"; both materials were created today, so 2 is correct). Filters: search + subject + class + **type limited to Document / Link** (the only two kinds the model stores: file vs link_url). Groups are genuinely collapsible (`button.lms82-group-head[data-lms82-group][aria-expanded]` + JS toggle) with per-group empty states. Actions role-true: **Open Link + Delete only — no Edit button** (correct: routes confirm materials has NO edit route), no Download offered for link-only items. | MATCH | `.lms82-stats` collapse as assignments (2×2 @991 → 1 col @575); @575 `.lms82-mat-row{flex-wrap:wrap}` and `.lms82-mat-row .lms-mat-actions{padding-left:50px}` → action buttons drop to their own line under the title instead of being clipped; filter form is the shared wrapping `.pat-filter-form`. |
| List — student | MATCH | Scoped to own class: Total 1 / Subjects 1 / Classes 1 / Recent 1 (only the class-1 phonics material). **Read-only: no Create link, no Delete forms.** | MATCH | Same lms82 mechanisms; only action is Open Link. |
| List — parent | MATCH | Scoped via children (class 1): Total 1, same single material, read-only. | MATCH | Same mechanisms. |
| List — admin | MATCH | Full-scope list; Delete present, no Create (materials are teacher-owned) — role-true. | MATCH | Same mechanisms. |
| Grouping noise — teacher list | DEVIATION | Groups are per class×subject, not per subject: "English Language" appears as two separate groups (one per class) and the identical empty "Mathematics — No study materials yet" group repeats **3 times**. Empty states are honest, but the repeated empty groups are noise short of the Loop 82 "collapsible subject groups" intent. | DEVIATION | Same repetition on phone; collapsible heads do work, so it is noise, not breakage. |
| Create /materials/create — teacher | MATCH | File-upload **or** link (one-of-two rule stated on the form, matches `file_path`/`link_url` model); class/subject selects = teacher 3's scoped collections exactly (same as assignments create). Student GET → redirect to dashboard. | MATCH | `.pat-fields` → 1 column @767; 2-button action bar (Cancel + Share Material) fits at 390px. |
| Download /materials/download/1 (and /2) — teacher | DEVIATION (minor) | Both seed materials are link-only (`file_path` NULL), so no download should occur — and none does. But the controller answers with `back()` + error flash: the probe landed back on the previous page (`/materials/create`, the prior URL in session) and **no visible error text was present in the rendered body** of the redirected page. Correct outcome, poor signalling; note no Download button is exposed in the UI for link materials, so there is no dead UI control. Student direct hit → redirect to dashboard. | DEVIATION (minor) | Same silent bounce on phone. |

## 6. Library

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| Books /books — librarian | MATCH | **Stats verified vs DB: Total Titles 7 / Total Copies 23 / Issued Copies 1 / Available Copies 22** (sums recomputed from the books table). Filters: search, type (Book/Textbook/Journal/Magazine/E-Book/Other — matches stored `book_type` values), class, availability, sort — all server-side form fields. All 7 rows with correct per-title copies/status (book 1 "Partially Issued", 1 issued / 4 total). Edit + Delete per row; **/books/1/edit resolves 200** from the list's href. | DEVIATION | Table (Book / Type / Class / Location / Copies / Status / Actions) is inside `.table-responsive` → nothing clips or escapes, but 7 columns require horizontal scrolling at 390px and Edit/Delete are reachable only by scrolling; no card alternative (unlike assignments/materials). Stat cards collapse correctly (col-sm-6 → 2-across, 1-col <576px). |
| Books create /books/create — librarian | MATCH | Fields = model exactly: Title (`name`), Author, Type select, Total copies, Class (optional, "General / all classes"), Shelf/location, Online copy URL, Description. | MATCH | pat-form single-column collapse @767; 2-button bar (Cancel + Add Book) fits. |
| Books edit /books/1/edit — librarian | MATCH | 200 with the book's values in the same form. | MATCH | Same pat-form mechanisms. |
| Books — student / teacher (direct GET) | MATCH | Both redirected to `/home`. Consistent with the settled rule: **students have no library page** (not in their menu, and direct access is refused by redirect); teachers likewise get no library surface here. | MATCH | No C5 surface renders. |
| Book requests /book_requests — librarian | MATCH | Tabs All / Issued / Returned; the single real loan rendered correctly (English Grammar and Composition → Aarav Sharma, Student, Issued 20 Oct 2026, Due 03 Nov 2026, status Issued) with a working "Mark Returned" action (PATCH route exists). "Issue a Book" entry point present. | DEVIATION | 6-column table in `.table-responsive` → horizontal scroll on phone (no clipping/escape; tabs are wrapping nav pills). Short of premium only. |
| Book requests create /book_requests/create — librarian | MATCH | **Book select contains exactly the 7 books from the books table, nothing else.** Borrower select = all users with role labels (students/teachers/admins/parents/staff) — appropriate for an issue desk. Issue/Due date fields present. | MATCH | Single-column form; selects are full-width form-controls inside the form grid → cannot escape a 390px viewport. |
| Book requests — student (direct GET, list + create) | DEVIATION | Both redirect to `/home`: a student cannot see even their **own** current loans anywhere (their one issued book is invisible to them in-product). The settled rule covers students having no Books *page/menu*; loan self-visibility is a separate gap, recorded factually. | DEVIATION | No surface renders. |

## 7. School Settings (admin) — /super_admin/settings

URL found from the admin menu ("School Setup → Settings" → `/super_admin/settings`). The same URL redirects teacher → `/home` and sa → `/dashboard`: in this tree the route serves the **school Admin** only.

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| Settings — admin | MATCH | All expected sections present: **School Profile** (values match DB exactly: Hackstra Academy / HA / 0123456789 / info@hackstra.app / address; session select lists 2022-2027 plus "2018-2019 (current)" = DB value), **Academic Calendar** (term begin/end, next-term fees per class type), **Exam Security** (Lock Exam), **Online Fee Payments (Razorpay)**, **AI Assistant** (drafting), **Report Card** (principal name/signature/stamp), **AI Assistant / Ask Hackstra + Knowledge Base** (rebuild button, "index currently holds 0 documents" — honest, add-policy form, audience note). **Upload fields present for logo, principal signature and school stamp**, with honest empty states ("No stamp uploaded — report cards print without one… no placeholder is ever drawn"). **Secrets are write-only**: `razorpay_key_secret`, `razorpay_webhook_secret` and both `ai_api_key` inputs render `type="password"` with `value=""` and "Leave blank to keep the saved value" copy — **no stored secret is ever echoed** (seed stores none, and the markup pattern proves the page would not print one). Razorpay Key ID renders as a text input but with `value=""` in this seed — no key exists in DB to leak. Sticky Save Settings bar present. | MATCH | `.pat-fields` 2-column grid → 1 column @767; the action bar holds a single Save Settings button (no crowding); the current-logo preview uses inline `max-width:140px` (fits 390px); selects/inputs are full-width form-controls. |
| Settings — duplicate AI key fields | DEVIATION | `id="ai_api_key"` / `name="ai_api_key"` is rendered **twice** on one page (once in the main settings form → `settings.update`, once in the separate AI form → `settings.ai_update`). Duplicate element IDs are invalid HTML and make label/`for` targeting ambiguous; the two near-identical "AI Assistant" sections (drafting vs Ask/RAG) are also easy to confuse — the RAG section itself carries the clarifying note "AI configuration is managed by the platform (Super Admin)" while still presenting toggles/provider fields (2 of its inputs render disabled). | DEVIATION | Same duplicate-ID markup on phone; no layout breakage caused. |
| Settings — teacher / sa | MATCH | Teacher → redirect `/home`; SA → redirect `/dashboard`. Neither can open school settings by direct URL. | MATCH | No C5 surface renders. |

## 8. Misc public / anon

| Page × role | Desktop | Evidence | Phone | Phone mechanism |
|---|---|---|---|---|
| `/` — anon | MATCH | Redirects to `/login`; the login page is the branded Hackstra Academy page ("Welcome to Hackstra Academy"). No dead landing. | MATCH | Branded auth layout is a single narrow column; no fixed-width elements found in the rendered HTML. |
| `/register` — anon | DEVIATION | **Public registration is ENABLED** (recorded factually): the form renders (Name / E-Mail / Password / Confirm Password) and both `GET register` and `POST register` routes are live. The page is the **stock Laravel scaffold** (4,857 bytes, only `css/app.css`, default Bootstrap card) — visibly un-redesigned next to the branded login page, and it lets any anonymous visitor create an account (what role a self-registered account receives was not probed — no submission was made, per audit-only scope). | MATCH | Stock single-column form; no fixed widths, nothing escapes 390px. (Design shortfall counted in the Desktop verdict.) |
| `/password/reset` — anon | MATCH | Renders; form posts to the live `password.email` route; footer links checked — `/privacy-policy` and `/terms-of-use` both return 200, **no dead links** on the page. | MATCH | Single-column scaffold form, no fixed widths. |

## 9. Clipped / escaping controls sweep (owner defect class, 2026-10-23 01:31 IST)

**DEFECT hits (2):**
1. **Circular create + edit sticky action bar @390px** — `.pat-actionbar` flex row, `justify-content:flex-end`, `gap:10px`, **no flex-wrap in base or @767 rules**; Bootstrap `.btn{white-space:nowrap}`; 3 buttons (Save as Draft / Cancel / Publish Circular) ≈340–360px min-content vs ≈330px available → Save as Draft clips off the left edge of the bar. (Full mechanism in §1.)
2. **Assignment show grading column @390px** — inline `style="min-width:290px"` on the "Marks & Feedback" `<th>` of the submissions table; the grading controls (marks + feedback + Save) live in that fixed column inside a `.table-responsive` whose total width far exceeds the viewport → primary action squeezed behind horizontal scroll. (§4.)

**Action controls checked and provably safe (one line):** circulars-list kebab menus (`dropdown-menu dropdown-menu-right`, right-aligned) · circular-detail toolbar (`.l75-cd-toolbar{flex-wrap:wrap}`) · all list-page toolbars (`.pat-toolbar`/`.pat-toolbar-actions{flex-wrap:wrap}`) · assignments list/grid toggle (`.lms82-viewbar` + icon-only `.lms82-toggle` buttons) · materials row actions (@575 row wraps, actions move to their own indented line) · every 2-button `.pat-actionbar` (Feedback Send, Publish Assignment, Share Material, Add Book, Save Settings — each ≤ ~235px vs ~330px available) · all filter selects (inside `min-width:0` flex fields, `flex-basis:100%` @575, full-width form-controls — none can escape 390px) · Ask suggestion chips (`.l88-suggest{flex-wrap:wrap}`, enabled state, structural) · topbar notification/profile menus on every C5 page (`dropdown-menu-right`). The one remaining squeeze-risk found is the enabled-Ask composer bar (no wrap rule) — recorded as DEVIATION in §3, not a proven clip.

## 10. Limitations stated plainly

- Feedback detail pages: **could not be data-verified — feedback_items is empty in seed** (list/empty states + create form audited instead).
- Circulars: no draft/scheduled/expired circular exists in seed → non-live `show()` behaviour unverifiable; all 4 seeded circulars are Live.
- Ask Hackstra: AI is disabled in seed (`ai_enabled=0`) → enabled chat, answer quality and chip behaviour verified only structurally (blade/CSS/route list); no AI query was submitted.
- No form was submitted anywhere in this audit (no creates, grades, forwards, registrations or settings saves) — write paths were verified via routes, controllers and rendered forms only.
