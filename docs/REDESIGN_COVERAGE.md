# Redesign Coverage — Hackstra (batch145, Loop 108 sweep, 2026-10-23)

Proof artifact for the redesign completion sweep. Every GET route a user can
reach (source: `php artisan route:list --json` on this tree — 166 GET routes,
140 of them page routes after excluding ajax/api/ignition) is listed below
with its roles, its design state, and the evidence for that state.

**States**
- `REDESIGNED` — carries a shipped Loop design (loop number noted).
- `REDESIGNED-108` — was OLD-STYLE; brought to the standard in this sweep.
- `DOCUMENT/PRINT` — a print/PDF document surface with its own document treatment.
- `REDIRECT` — no page of its own (controller redirects to the module index; in-code note).
- `DOWNLOAD` — file response, not a page.
- `REMOVED-BY-OWNER` — deleted on the owner's explicit order (2026-10-23).
- `SA-SWEEP` — Super-Admin platform surface owned by the parallel SA-platform squad (batch143); inventoried here, not edited here (file-collision avoidance).

**Design standard applied** — the shipped Loop system: `partials/patterns/list_page`
+ `form_page` (Loop 28/75), the Loop card/table language, dark-first `qs.css`
(`html.hk-dark`), flash via the global banner — no page blade carries an inline
`session('flash_')` block (verified: the only occurrences in the tree are inside
`partials/js/custom_js.blade.php`, the global toast mechanism itself).
CSS for this sweep is append-only **Loop 108** (brace balance verified 0).

**Mobile standard (owner, 2026-10-23)** — every redesigned page must be premium
on a phone: no clipped columns, usable menus, clean stacking. The mechanism for
the simple CRUD/record tables is `.l108-stack` (Loop 108 CSS): at ≤768px the
table stacks into labelled row-cards (`data-label` per cell) so **every column
stays visible**; wider analytical grids keep their documented treatments
(students list, tabulation, attendance grid with sticky student column).

**Reference mapping (owner steer 2026-10-23 01:19 IST)** — reference set hunted
before redesigning: `~/workspace/goals/hackstra-school-platform-modernization/references/`
(empty except an empty `spaces/` dir), `.../briefs/` (empty), agent notes and
hidden files (empty); the owner's reference images in `~/workspace/user/media_library/`
are the page-redesign sprint set already consumed by Loops 74–102 for the pages
they depict (dashboards, attendance, circulars, marks, payments…). **No reference
image exists for any long-tail page in this sweep** — for those pages the bar is
the shipped Loop design system itself, matched exactly, at the premium level of
the reference-driven pages.

---

## 1. Dashboards & home

| Route | Page | Roles | State |
|---|---|---|---|
| `/` (home), `/dashboard`, `/home` | Role home dispatch | all | REDESIGNED (per-role homes below) |
| dashboard (admin) | School operations dashboard (`support_team/dashboard`) | admin | REDESIGNED (dashboard system, Loop 17 line, upgraded through the wave loops) |
| `dashboard/school` | School board (SA step-in) | super_admin | REDESIGNED (same board) |
| teacher home | `teacher/home` | teacher | REDESIGNED (Loop 79) |
| student home | `student/home` | student | REDESIGNED (Loop 99) |
| parent home | `parent/home` | parent | REDESIGNED (Loop 96) |
| accountant home | `accountant/home` | accountant | REDESIGNED (Loop 92) |
| librarian home | `librarian/home` | librarian | REDESIGNED (Loop 100) |
| SA platform home | `super_admin/platform_home` | super_admin | SA-SWEEP (batch143) |
| `dashboard/search` | Global search results | teamSA + scoped roles | REDESIGNED (dashboard hero/panel system) |

## 2. Academics CRUD (teamSA; writes teamSAWrite)

| Route | Page | State |
|---|---|---|
| `classes` | Manage Classes | REDESIGNED (Batch 4 list_page) + **REDESIGNED-108 table stack** |
| `classes/create`, `classes/{class}` | — | REDIRECT to index (create form lives on index) |
| `classes/{class}/edit` | Edit Class | REDESIGNED (BW form_page) |
| `sections` | Manage Class Sections | REDESIGNED (Batch 4) + **REDESIGNED-108 table stack** |
| `sections/create`, `sections/{section}` | — | REDIRECT to index |
| `sections/{section}/edit` | Edit Section | REDESIGNED (BW form_page) |
| `subjects` | **Manage Subjects — OWNER-FLAGGED (01:11 IST): old-style + TEACHER column clipped on phones, SHORT NAME header wrapped broken** | REDESIGNED (Batch 4 list_page) + **REDESIGNED-108: `l108-stack` + data-labels — every column visible on phones; thead nowrap ends the broken header wrap** |
| `subjects/create`, `subjects/{subject}` | — | REDIRECT to index |
| `subjects/{subject}/edit` | Edit Subject | REDESIGNED (BW form_page) |
| `grades` | Manage Grades | REDESIGNED (BW) + **REDESIGNED-108 table stack** |
| `grades/create`, `grades/{grade}` | — | REDIRECT to index |
| `grades/{grade}/edit` | Edit Grade | REDESIGNED (BW form_page) |
| `dorms` | Manage Dorms | REDESIGNED (BW) + **REDESIGNED-108 table stack** |
| `dorms/create`, `dorms/{dorm}` | — | REDIRECT to index |
| `dorms/{dorm}/edit` | Edit Dorm | REDESIGNED (BW form_page) |

## 3. Exams & marks

| Route | Page | Roles | State |
|---|---|---|---|
| `exams` | **Manage Exams — OWNER-FLAGGED (old light tabbed shell; row Actions dropdown clipped off the phone viewport)** | teamSA | **REDESIGNED-108**: list_page + form_page create card (BW academics template); **the Actions button + dropdown is REMOVED (owner order 01:31 IST)** — the five per-exam tools (Admit Cards, Marking Scheme, Publish/Unpublish Results, Edit, Delete) are an inline `.l108-tools` cluster that wraps inside the card at every width; table `l108-stack` |
| `exams/create`, `exams/{exam}` | — | | REDIRECT to index |
| `exams/{exam}/edit` | Edit Exam | teamSAWrite | REDESIGNED (BW form_page) |
| `exams/{exam}/scheme` | Marking Scheme (per class) | teamSAWrite | **REDESIGNED-108**: pat shell + stack table (was legacy card + panel options) |
| `exams/{exam}/scheme/{class}` | Scheme editor | teamSAWrite | **REDESIGNED-108 shell** (Loop 76 editor interior unchanged) |
| `exams/admit-cards` | Admit Cards (per exam/class) | teamSA | REDESIGNED (document family chrome) |
| `exams/admit-cards/print/{exam}/{class}` | Admit cards print | teamSA | DOCUMENT/PRINT |
| `my-admit-cards` | My Admit Cards | student, parent | **REDESIGNED-108**: pat shell + stack tables (was plain card) |
| `my-admit-cards/{exam}/{student_id}` | Own admit card | student, parent | DOCUMENT/PRINT |
| `marks` | Marks entry selector | teamSAT | REDESIGNED (Loop 84) |
| `marks/manage/{exam}/{class}/{section}/{subject}` | Marks entry grid | teamSATWrite | REDESIGNED (Loop 86) |
| `marks/bulk` | Marksheet selector (bulk) | teamSA | REDESIGNED (Batch 1A list_page) |
| `marks/tabulation/{…}` | Tabulation sheet | teamSAT | REDESIGNED (qs-tab system; BW selector) |
| `marks/tabulation/print/{…}` | Tabulation print | teamSAT | DOCUMENT/PRINT |
| `marks/show/{id}/{year}` | Student marksheet (screen) | teamSAT + student/parent own | REDESIGNED (Loop 68 report-card partials; Batch 6 M2 section headers) |
| `marks/print/{id}/{exam_id}/{year}` | Marksheet print | | DOCUMENT/PRINT |
| `marks/annual/{id}/{year}` | Annual report card | | REDESIGNED (Loop 85) |
| `marks/print_annual/{id}/{year}`, `marks/pdf_annual/{id}/{year}` | Annual print/PDF | | DOCUMENT/PRINT |
| `marks/select_year/{id}` | Marksheet year selector | | REDESIGNED (BW form_page) |
| (no route) | Results-not-published notice (`marks/not_published`) | student, parent | REDESIGNED (Workstream BH designed empty state) |
| (no route) | Fee-blocked document notice (`fee_blocked`) | student, parent | REDESIGNED (owner ruling 2026-10-22 notice design) |
| ~~`marks/batch_fix`~~ (GET) + ~~`marks/batch_update`~~ (PUT) | ~~Fix Mark Errors / "Marks Correction"~~ | was teamSAWrite (Admin) | **REMOVED-BY-OWNER (order 2026-10-23 01:10 IST)**: routes deleted (GET + PUT both 404), controller actions deleted, blade deleted, and the Exams-submenu item labelled **"Marks Correction"** deleted (it was the only menu link to the page, shown to Admin under `userIsTeamSAWrite`), plus its entry in the submenu's expanded-routes list. Codebase grep for `batch_fix` / `batch_update` / `Marks Correction` returns only removal-note comments. The recompute capability stays in code: `App\Support\MarkComputation` is untouched and still used by marks entry (`MarkController@update`) and the assignment internal-marks push (`AssignmentController`) — verified by grep. |

## 4. Students

| Route | Page | Roles | State |
|---|---|---|---|
| `students` | Student Information (class picker) | teamSA | REDESIGNED (batch132 picker) |
| `students/list/{class_id?}` | Per-class student list | teamSA | REDESIGNED (Loop 83) |
| `students/create` | Add Student (Quick Admit family) | teamSAWrite | REDESIGNED (Loop 102) |
| `students/{student}` | Student profile | scoped | REDESIGNED |
| `students/{student}/edit` | Edit Student | teamSAWrite | REDESIGNED (form sweep) |
| `students/import` | Student CSV import | teamSAWrite | REDESIGNED (form_page, pat=9) |
| `students/import/template` | Template CSV | | DOWNLOAD |
| `students/graduated` | Graduated Students | teamSA | REDESIGNED (Batch 3 list_page) |
| `students/promotion/{…}` | Student Promotion | teamSAWrite | REDESIGNED (Batch 3 list_page) + **REDESIGNED-108: promote table stack** |
| `students/promotion/manage` | Manage Promotions (reset) | teamSAWrite | **REDESIGNED-108**: pat shell, status badges, stack table (was legacy cards + panel options) |
| `students/id_card/{sr_id}` | Student ID Card | teamSA | **REDESIGNED-108 chrome** (legacy card-header/panel options dropped; Loop 27 `.idc-` card document + print untouched) |
| `my_id_card` | **My ID Card — known leftover named in the sweep brief** | student, parent | **REDESIGNED-108 chrome** (same as above; child switcher kept) |
| `my_children` | My Children | parent | **REDESIGNED-108**: list_page; bare `icon-menu9` glyph dropdown replaced by the labelled Actions button (BW pattern); "ADM_No" header corrected to "Adm No"; stack table |

## 5. Attendance (period model)

| Route | Page | Roles | State |
|---|---|---|---|
| `attendance/periods` | Mark Attendance (period register) | teacher, teamSA | REDESIGNED (Loop 77) |
| `attendance/periods/{tt_id}/take` | Take period attendance | period's teacher | REDESIGNED (Loop 61 line) |
| `attendance/periods/session/{session_id}` | Submitted register view | scoped | REDESIGNED (Loop 77) |
| `attendance/periods/session/{session_id}/print` | Register print | | DOCUMENT/PRINT |
| `attendance/periods/report` | Subject-wise report + session log | teamSA, teacher | REDESIGNED (pat shell) |
| `attendance/periods/export` | CSV export | | DOWNLOAD |
| `attendance/defaulters` | Low Attendance (Below 75%) | teamSA, teacher | REDESIGNED (Loop 78) |
| `attendance/defaulters/export` | CSV export | | DOWNLOAD |
| `attendance/report` | Monthly attendance report | teamSA | REDESIGNED (Loop 80 document set) |
| `attendance/report/print`, `attendance/report/pdf` | Report print/PDF | | DOCUMENT/PRINT |
| `attendance/report/export` | CSV export | | DOWNLOAD |
| `attendance` | Day-wise attendance URL (retired surface) | teamSA, teacher | REDIRECT by design: `AttendanceController@index` redirects into the period landing (`attendance.periods.index`) with an explanatory flash — the period-only model working as ruled. Its old blade is dormant (see §13) |
| `attendance/my` | My Attendance / Child Attendance | student, parent | **REDESIGNED-108**: pat shell (legacy card-header + panel options dropped), both tables `l108-stack`, badge inline styles removed |

## 6. Fees, payments, payroll

| Route | Page | Roles | State |
|---|---|---|---|
| `payments` | Manage Payments | teamSA, accountant | REDESIGNED (Loops 93/94) |
| `payments/create` | Collect Fee | teamSAWrite, accountant | REDESIGNED |
| `payments/manage/{class_id?}` | Class payment records | teamSA, accountant | REDESIGNED (Loop 28) |
| `payments/{payment}` | Payment record | scoped | REDESIGNED |
| `payments/{payment}/edit` | Edit payment record | teamSAWrite | REDESIGNED |
| `payments/student_search` | Student fee search | teamSA, accountant | REDESIGNED |
| `payments/invoice/{id}/{year?}` | Invoice | scoped | DOCUMENT (Loop 93) |
| `payments/receipts/{id}`, `payments/pdf_receipts/{id}` | Receipt screen/PDF | scoped | DOCUMENT/PRINT (Loop 68) |
| `payments/reports/collection` | Fee Collection report | teamSA, accountant | REDESIGNED (Loop 97) |
| `payments/reports/collection/day` | Day collection document | | DOCUMENT/PRINT |
| `payments/reports/collection/pdf` | Collection PDF | | DOCUMENT/PRINT |
| `payments/reports/defaulters` | Fee Defaulters | teamSA, accountant | REDESIGNED (Loop 95 line; one-page print) |
| `payments/gateway-transactions` | Gateway transactions | teamSA | REDESIGNED (payments family) |
| `payments/export/{class_id}` | CSV export | | DOWNLOAD |
| `my_fees` | My Fees (children picker) | parent | REDESIGNED |
| `my_fees/{student_id}` | Child fee statement + Pay | parent | REDESIGNED (Loop 75 checkout family) |
| `my_fees/receipt/{pr_id}` | Receipt | parent | DOCUMENT/PRINT |
| `payroll` | Payroll | teamSA (accountant gated out, Loop-era scope fix) | REDESIGNED (Batch 13 `pay-` module design; BW filter bar) |
| `payroll/{payroll}/payslip` | Payslip document | teamSA | DOCUMENT/PRINT |
| `my-payslips` | My Payslips | staff roles | REDESIGNED (Loop 89) |
| `my-payslips/{payroll}` | Payslip (screen) | own | REDESIGNED (Loop 90) |
| `my-payslips/{payroll}/pdf`, `my-payslips/download-all` | Payslip PDFs | | DOCUMENT/PRINT |

## 7. Timetables

| Route | Page | Roles | State |
|---|---|---|---|
| `timetables` | Timetables list + create form | scoped per role | REDESIGNED (Loop 81) |
| `timetables/records/edit/{ttr}` | Edit TimeTable Record — **OWNER-FLAGGED suspected bug (01:12 IST)** | teamSAWrite | REDESIGNED (BW form_page). **Bug investigation: NOT a wiring bug — see §11.** One real wart fixed in its sibling form (see §11) |
| `timetables/records/manage/{ttr}` | Manage record (slots/subjects hub) | teamSAWrite | **REDESIGNED-108 shell**: pat card + `.l108-tabs` tab standard (was legacy `nav-tabs-highlight`); tab panes (BW/ttx interiors) unchanged |
| `timetables/records/show/{ttr}` | View timetable | scoped | REDESIGNED (Loop 46 line) |
| `timetables/records/print/{ttr}` | Timetable print | | DOCUMENT/PRINT |
| `timetables/time_slots/edit/{ts}` | Edit time slot | teamSAWrite | REDESIGNED (form_page) |
| `timetables/mine` | My Timetable | teacher, student, parent | REDESIGNED (Loop 81) |
| `timetables/mine/print`, `timetables/mine/pdf` | Print/PDF | | DOCUMENT/PRINT |

## 8. Library, events, circulars, LMS

| Route | Page | Roles | State |
|---|---|---|---|
| `books` | Library Books | all scoped | REDESIGNED (Workstream BI list_page) |
| `books/create`, `books/{book}/edit` | Add/Edit Book | librarian, teamSAWrite | REDESIGNED (BW form_page + pat `_form`) |
| `book_requests` | Book Loans & Requests | librarian, teamSA | **REDESIGNED-108**: pat shell + stack table (was legacy card) |
| `book_requests/create` | Issue a Book | library write | REDESIGNED (BW form_page) |
| `events` | School Events | all | REDESIGNED (Workstream CD list_page) |
| `events/create`, `events/{event}/edit` | Add/Edit Event | teamSAWrite | REDESIGNED (form_page + pat `_form`) |
| `events/{event}` | Event detail | all | REDESIGNED (events family) |
| `circulars` | Circulars | all | REDESIGNED (Loop 75 set) |
| `circulars/create`, `circulars/{circular}/edit` | Create/Edit Circular | teamSAWrite | REDESIGNED (Loop 75) |
| `circulars/{circular}` | Circular detail | all | REDESIGNED (Loop 75) |
| `circulars/{circular}/print`, `circulars/{circular}/pdf` | Print/PDF | | DOCUMENT/PRINT |
| `assignments` | Assignments (per role lists) | teacher, student, parent | REDESIGNED (Loop 82; parent view CS2) |
| `assignments/create`, `assignments/{assignment}/edit` | Create/Edit Assignment | teacher, teamSAWrite | REDESIGNED (CS2 form_page) |
| `assignments/{assignment}` | Assignment detail + submissions | scoped | REDESIGNED (Loop 82) |
| `assignments/{assignment}/internal/preview` | Internal-marks preview | teacher | REDESIGNED (assignments family) |
| `assignments/{assignment}/download` | Attachment | | DOWNLOAD |
| `materials` | Study Materials | scoped | REDESIGNED (LMS family) |
| `materials/create` | Upload Material | teacher, teamSAWrite | REDESIGNED (LMS family) |
| `materials/download/{material}` | File | | DOWNLOAD |
| `ask` | Ask Hackstra (AI assistant) | all | REDESIGNED (Loop 88) |
| `feedback/create` | Give Feedback | all but SA | REDESIGNED (Loop 87/75) |
| `feedback/mine`, `feedback/mine/{feedback}` | My Feedback | all but SA | REDESIGNED (Loop 87) |
| `feedback/inbox`, `feedback/{feedback}` | Feedback inbox/detail (school) | teamSA | REDESIGNED (Loop 87) |
| `feedback/school`, `feedback/school/{feedback}` | School Feedback (SA inbox) | super_admin | REDESIGNED (Loop 87 family); SA-SWEEP is polishing further in batch143 |

## 9. Users, settings, account, platform

| Route | Page | Roles | State |
|---|---|---|---|
| `users` | Users / Platform Users | teamSA | SA-SWEEP (batch143: list scoping to super_admin+admin for SA + redesign) |
| `users/create`, `users/{user}/edit` | Create/Edit User | teamSAWrite | REDESIGNED (form sweep, pattern-heavy) |
| `users/{user}` | User Profile | teamSA | REDESIGNED (profile design system in qs.css) + **REDESIGNED-108**: hardcoded `bg-white` card header dropped (forced a white band in dark mode) |
| `my_account` | My Account | all | REDESIGNED (one-screen rebuild, 2026-10-22) |
| `my_account/download` | Account data export | own | DOWNLOAD |
| `super_admin/settings` | Settings (school + platform config) | teamSA | REDESIGNED (form sweep, pattern-heavy) |
| `super_admin/platform-mail` | Platform Mail (compose) | super_admin | REDESIGNED (Loop 75 form) |
| `super_admin/platform-mail/sent` | Platform Mail (sent) | super_admin | REDESIGNED (Loop 75 list) |
| `super_admin/audit-logs` | Audit Log | super_admin, admin (school trail) | SA-SWEEP (batch143) |

## 10. Guest & public pages

| Route | Page | State |
|---|---|---|
| `login` | Login | REDESIGNED (Loop 16 split-card design) |
| `register` | Register | **REDESIGNED-108**: was 100% stock Laravel scaffolding on `layouts.app`; now on `login_master` with the shared guest card (fields/routes identical) |
| `password/reset` | Forgot password | REDESIGNED (guest card family on login_master) |
| `password/reset/{token}` | Reset password | REDESIGNED (guest card family) |
| (email verification notice) | Verify email | **REDESIGNED-108**: was stock scaffolding; now on `login_master` guest card (content/resend identical) |
| `privacy-policy`, `terms-of-use` | Legal pages | **REDESIGNED-108 light**: pat-card on the guest layout (readable text pages) |

## 11. Timetable record form — bug investigation (owner flag, 01:12 IST)

Claim: Edit TimeTable Record shows a person's name ("Ankit kumar") in the Class
dropdown and an exam name in Type. Evidence gathered on this tree + fresh seed:

- `TimeTableController@edit_record` passes `$my_classes = MyClassRepo::all()`
  (MyClass models) and `$exams = ExamRepo::getExam(['year' => $ttr->year])`;
  the blade binds options by `id` with `selected` from `$ttr->my_class_id` /
  `$ttr->exam_id`. The create form on `timetables` (Loop 81) is wired identically.
- Stored data verified in the seeded DB: `time_table_records.my_class_id` ∈
  classes 1–9, `exam_id` NULL for all 8 records (all class timetables).
- Rendered proof: HTTP-fetched the edit page for **all 8 records** as Admin —
  every Class select lists class names with the record's own class selected;
  every Type select shows `Class Timetable` selected with `Term 1 Examination`
  present as an (unselected) option.
- **Conclusion: no wiring bug exists in these forms.** The Type list is *by
  design* "Class Timetable" + the session's exams (choosing one makes the record
  an exam timetable) — with one seeded exam it dominates the dropdown, which
  reads as "the value" in a screenshot. No select in the timetable module is
  populated with users.
- **Real wart found & fixed nearby**: `timetables/subjects/add.blade.php`
  re-selected the time slot after a validation bounce by comparing
  `old('ts_id')` to the slot's display text (`$tms->full`, e.g. "7:30 AM -
  8:15 AM") instead of its id — the choice was silently lost. Now compares
  `$tms->id`. (Old-input repopulation only; submit behaviour unchanged.)

## 12. Theme truth (Phase 3 — summary; full evidence in the sweep report)

- Logged-in pages render `hk-dark` **server-side** from the per-user DB
  preference (`UserPreference`), whose **default is `dark`** — a fresh account
  on a fresh seed has zero preference rows and renders dark (verified at the
  gate: fresh seed → scripted login as Admin and as Super Admin → `<html>`
  carries `hk-dark`).
- The topbar toggle flips the class instantly, mirrors it to
  `localStorage['hk-theme']`, and persists it fire-and-forget to the DB
  (`my_account.theme` → `UserPreference::set`). The guest/login layout reads
  `localStorage` pre-paint (dark unless explicitly set to light).
- So a phone showing light is a **persisted per-account preference behaving
  as designed** (a past toggle tap on that account; the DB preference follows
  the account across devices). It is not a broken default, and the preference
  system was **not** "fixed" — per the sweep brief.

## 13. Unreachable / dormant views (inventoried, not redesigned, not deleted)

| View | Why unreachable |
|---|---|
| `pages/support_team/pins/*` (index/show + partials) | PIN routes retired (Workstream BH); no route or menu reaches them |
| `pages/support_team/attendance/index.blade.php` (day-wise grid) | `AttendanceController@index`/`@store` redirect into the period flow (period-only model); the blade is never rendered. Loop 108 still standardised its shell + moved its inline `<style>` into qs.css so it is not a trap if ever revived — the `.attendance-grid` CSS in Loop 108 exists for that blade |
| `pages/admin/dashboard.blade.php` | No controller renders it; Admin home is `support_team/dashboard` |
| `pages/support_team/marks/random.blade.php` | No controller renders it |
| root `test.blade.php` | No route renders it |
| `layouts/app.blade.php` | Was only used by the stock auth pages; Loop 108 moved those to `login_master`. File kept (harmless) |
| `emails/*` | Mail templates, not pages |

## 14. Sweep change list (Loop 108)

- **Removed (owner order)**: Fix Mark Errors — routes, `MarkController@batch_fix/@batch_update`, `marks/batch_fix.blade.php`, "Marks Correction" menu item + expanded-list entry. `MarkComputation` kept intact.
- **Rebuilt**: `exams/index` (patterns + tools cluster, no dropdown), `exams/scheme/index`, `book_requests/index`, `parent/children`, `students/promotion/reset`, `exams/admit_my` shell, `auth/register`, `auth/verify`.
- **Shell-standardised**: `exams/scheme/edit`, `attendance/my`, `timetables/manage`, `students/id_card`, `students/id_card_mine`, `pages/other/*` (light). (The dormant day-wise `attendance/index` blade got the same treatment defensively — see §13.)
- **Table stack (`l108-stack` + data-labels)**: subjects (owner-flagged), classes, sections, grades, dorms, exams, scheme, book_requests, children, promotion promote + reset, admit_my, attendance/my (both tables).
- **Minute defects fixed**: users/show dark-mode white header band; attendance/my badge inline styles (`.l108-badges`); timetable `old('ts_id')` comparison wart; "ADM_No" header label.
- **CSS**: qs.css Loop 108 append (stack treatment, tabs standard, badges, attendance grid, tools cluster) — brace balance 0 after every append.
