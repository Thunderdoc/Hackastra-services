# Visual Standard Sweep — Workstream DE (2026-10-22)

**Purpose.** The owner kept finding individual pages that still read as plain
static templates next to the redesigned app. This sweep ends the whack-a-mole:
every reachable screen was crawled and classified, the named defects were
redesigned, and everything not fixed here is catalogued with a verdict and a
reason.

**Method.** Scripted logins for all 7 roles on a fresh demo seed; every
sidebar/in-page link followed to depth 3: **1,301 page fetches** (Super
Admin 465, Admin 618, Teacher 87, Student 30, Parent 38, Accountant 35,
Librarian 28), consolidating to **93 distinct page patterns** (a few are
hash-id variants of the same route). Each page was checked for the legacy tells:
raw inline colour styles, `<font>`/`<center>` markup, oversized/off-system
buttons, plain unstyled tables outside the shared pattern, missing page
header, native-chrome form controls, and empty/dead columns. Suspects were
then read blade-by-blade.

**Headline result.** The app is broadly at standard — the form sweep, the
list-page pattern (Loop 28) and the page redesigns (Loops 52–66) hold up:
**zero** pages carry raw inline colour styles, `<font>` tags or centred
legacy markup. The defects that remained were concentrated and systematic,
not scattered: (1) split select chrome, (2) two thin list pages, (3) the
last oversized buttons.

---

## Named fixes (owner-flagged, 2026-10-22)

### 1. Select dropdowns — app-wide (Loop 73A) — FIXED
The app renders selects in two flavours: Select2-enhanced (`.select` /
`.select-search`, initialised globally) and plain `select.form-control`
(29+ instances: filter bars, library, payroll, attendance, admit cards…).
The plain ones rendered with **native browser chrome** next to the styled
ones — the Class/Subject filter pair the owner screenshotted. Loop 73A
delivers one component for both flavours:

- Plain selects: `appearance: none` + a custom SVG chevron in the ink token
  colour, token border/radius/padding identical to the redesigned inputs,
  the same focus ring, a muted chevron + `not-allowed` cursor when disabled,
  34px right padding so long options never hit the chevron, and a full
  `hk-dark` variant. DataTables length selects included.
- Select2 widget: border/radius harmonised to the same tokens and its
  CSS-triangle arrow replaced with the same SVG chevron (light + dark).
- These remain real `<select>` elements — keyboard and screen-reader
  behaviour is native; no fake dropdowns, no plugin JS touched.

Pages whose plain selects are fixed by this alone include: Books,
Book Requests, Attendance Report, Attendance Periods + Period Report,
Low Attendance filters, Fee Defaulters filter, Students list filter,
Admit Cards, Payroll, Events create/edit, Timetables record manage.

### 2. Low Attendance (Below 75%) — `attendance/defaulters` — FIXED
Was: the shared list pattern, but the results were a bare table, a
one-line text count, and a thin 8px bar with the % as small text under it —
a page that ended abruptly with dead space. Now (Loop 73C):

- A designed summary strip with the same four facts as stats: **Below the
  line / Students scanned / Month / Threshold**.
- The attendance % is a designed meter: rounded track + severity fill
  (danger below 50%, warning 50–74%) + a **% chip** beside it.
- Count columns centre-aligned; rows vertically centred.
- Data, ordering (worst first) and the footnote formula are byte-identical:
  *Attendance % = (present + late) / periods marked…* — untouched.

### 3. School Events — `events` index — FIXED
Was: plain date text, one grey `badge-light` chip for every type, and an
**Action column that rendered empty** for every non-write role. Now
(Loop 73B):

- Date is a designed date block (day / month-year; ranges collapse to
  `12–15` in-month or a "to …" line across months).
- Type chips in token colours per type: Examination violet, Meeting blue,
  Fee green, Holiday amber, Event red (all with dark variants).
- "All Day" renders as a chip; the Action header/cells render **only**
  for write roles (`teamSAWrite`, the CM gate) — permissions unchanged,
  the dead column is gone for viewers.
- Title/description typography brought to the list standard.

### 4. Marksheet screens — `marks/show/{id}/{year}`, `marks/annual/{id}/{year}` — FIXED (minor)
The pages themselves are at standard (Batch M2/M4 cards); their Print
actions were the **last oversized `btn-lg` buttons** in the app (the same
defect class as the tabulation sheet's giant red button, which Workstream
DA owns). Loop 73 normalises them to the standard button size. The print
documents are untouched (CY territory).

---

## Catalogue — every distinct page, with verdicts

Verdicts: **AT STANDARD** · **FIXED (DE)** · **OWNED** (another workstream's
surface; catalogued, not edited) · **NOTE** (at standard with an observation).

### Platform & account
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Platform Overview | `/home` (SA) | SA | AT STANDARD | Loop 55; header hidden by design on homes |
| School Dashboard | `/dashboard/school` | SA | AT STANDARD | Loop-era dashboard |
| Role dashboards | `/home`, `/dashboard` | all | AT STANDARD | Per-role homes, Loops 12–17 |
| My Account | `/my_account` | all | AT STANDARD | Loops 52/53/66 one-screen rebuild; its two selects use the page's own `.acct-input` design |
| Settings | `/super_admin/settings` | SA, Admin | AT STANDARD | Select2 selects |
| Audit Log | `/super_admin/audit-logs` | SA, Admin | AT STANDARD | Pattern table |

### Students
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Students list | `/students/list/{class}` | SA, Admin, Teacher | AT STANDARD | Loop BD/3B; its 1 plain filter select fixed by Loop 73A |
| Student profile | `/students/{id}` | SA, Admin, Teacher, Parent | AT STANDARD | |
| Add Student | `/students/create` | Admin | AT STANDARD | Form-sweep sectioned form, Select2 |
| Edit Student | `/students/{id}/edit` | Admin | AT STANDARD | Same form family |
| Bulk import | `/students/import` | Admin | AT STANDARD | |
| Promotion hub | `/students/promotion` | SA, Admin | AT STANDARD | Select2 selects |
| Promotion manage | `/students/promotion/manage` | SA, Admin | AT STANDARD | |
| Graduated | `/students/graduated` | SA, Admin | AT STANDARD | |
| ID card (staff view) | `/students/id_card/{id}` | SA, Admin | OWNED | CY document system |

### Academics
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Classes / Sections / Subjects / Grades / Dorms | `/classes`, `/sections`, `/subjects`, `/grades`, `/dorms` + edits | SA, Admin | AT STANDARD | Pattern lists + item-delete forms; selects Select2 or fixed by 73A |
| Exams list | `/exams` | SA, Admin | AT STANDARD | CN added responsive wrapper |
| Exam create/edit | `/exams/create`, `/exams/{id}/edit` | Admin | AT STANDARD | |
| Marking scheme | `/exams/{id}/scheme` (+edit) | Admin | AT STANDARD | M1/M2 scheme UI |
| Admit Cards (screen) | `/exams/admit-cards` | SA, Admin, Teacher | FIXED (selects) | 3 plain selects → Loop 73A; the printed cards are CY's |

### Marks
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Marks selector | `/marks` | Admin, Teacher | AT STANDARD | Select2 filter form (Exam/Class/Section/Subject) |
| Manage marks grid | `/marks/manage/…` | Admin, Teacher | AT STANDARD | CN responsive wrappers; CJ register idiom |
| Bulk marks | `/marks/bulk` | Admin, Teacher | AT STANDARD | |
| Marks correction | `/marks/batch_fix` | Admin | AT STANDARD | |
| Year selector | `/marks/select_year/{id}` | SA, Admin, Teacher, Student, Parent | AT STANDARD | |
| Marksheet screen | `/marks/show/{id}/{year}` | SA, Admin, Teacher, Student, Parent | FIXED (minor) | Oversized print button normalised (fix 4) |
| Annual report screen | `/marks/annual/{id}/{year}` | same | FIXED (minor) | Same button fix |
| Tabulation | `/marks/tabulation` | SA, Admin | OWNED | DA (screen + print) |

### Attendance
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Low Attendance (Below 75%) | `/attendance/defaulters` | SA, Admin, Teacher | FIXED (DE) | Named fix 2 |
| Periods list | `/attendance/periods` | SA, Admin, Teacher | AT STANDARD | Pattern list; 1 plain select fixed by 73A |
| Period report | `/attendance/periods/report` | SA, Admin, Teacher | AT STANDARD | Pattern tables; Class/Subject plain selects fixed by 73A |
| Session log | `/attendance/periods/session/{id}` | SA, Admin, Teacher | AT STANDARD | |
| Take register | `/attendance/periods/{id}/take` | Teacher | AT STANDARD | Loop 61 segmented P/A/L |
| Monthly report | `/attendance/report` | SA, Admin, Teacher | AT STANDARD | Pattern; plain select fixed by 73A |
| Day-wise hub | `/attendance` | SA, Admin, Teacher | AT STANDARD | Badge-summary idiom (retired entry model; summary view only) |
| My Attendance | `/attendance/my` | Student, Parent | AT STANDARD | Badge summary + bar; same idiom as hub |

### Payments & payroll
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Payments hub | `/payments` | SA, Admin, Accountant | AT STANDARD | Loop 62 |
| Manage payments | `/payments/manage` | SA, Admin, Accountant | AT STANDARD | Loop 62 |
| Record payment | `/payments/create` | Admin, Accountant | AT STANDARD | Collect Fee workflow |
| Invoice | `/payments/invoice/{id}` | SA, Admin | AT STANDARD | Document screen; print is DB's |
| Fee defaulters (screen) | `/payments/reports/defaulters` | SA, Admin, Accountant | AT STANDARD | CK one-page print design; 1 plain select fixed by 73A; print owned by DB |
| Collection report | `/payments/reports/collection` | SA, Admin, Accountant | AT STANDARD | |
| Receipt (screen) | `/payments/receipts/{id}`, `/my_fees/receipt/{id}` | SA, Admin, Accountant, Parent | OWNED | DB print-v2 surface |
| Payroll | `/payroll` | SA, Admin, Accountant | AT STANDARD | 2 plain selects fixed by 73A |
| Payslip (screen) | `/payroll/{id}/payslip`, `/my-payslips`, `/my-payslips/{id}` | staff roles | AT STANDARD | Print owned by DB |

### Library
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Books | `/books` | SA, Admin, Librarian | AT STANDARD | Loop 59 book cells; 4 plain selects fixed by 73A |
| Book create/edit | `/books/create`, `/books/{id}/edit` | Admin, Librarian | AT STANDARD | Plain selects fixed by 73A |
| Book requests | `/book_requests` | SA, Admin, Librarian | AT STANDARD | |
| New book request | `/book_requests/create` | Admin, Librarian | AT STANDARD | Plain selects fixed by 73A |

### Communication & events
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Circulars | `/circulars` | all | AT STANDARD | Loop 60 redesign |
| Circular view | `/circulars/{id}` | all | AT STANDARD | |
| Circular create/edit | `/circulars/create`, `/circulars/{id}/edit` | Admin | AT STANDARD | Loop 60 form |
| School Events | `/events` | SA, Admin (+ viewers) | FIXED (DE) | Named fix 3 |
| Event create/edit | `/events/create`, `/events/{id}/edit` | Admin | AT STANDARD | Plain select fixed by 73A |

### Timetables
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Timetables hub | `/timetables` | SA, Admin, Teacher, Student | OWNED | DB (timetable screen + print) |
| Record show | `/timetables/records/show/{id}` | SA, Admin, Teacher, Student | OWNED | DB |
| Record manage | `/timetables/records/manage/{id}` | Admin | OWNED | DB |
| Record edit / time slots | `/timetables/records/edit/{id}`, `/timetables/time_slots/edit/{id}` | Admin | OWNED | DB |
| My timetable | `/timetables/mine` | Teacher, Student, Parent | OWNED | DB |

### Users & own-portal pages
| Page | Route | Roles | Verdict | Notes |
|---|---|---|---|---|
| Users list | `/users` | SA, Admin | AT STANDARD | DataTables chrome; inline styles are avatar/status presentation, not legacy tells |
| User create/edit/profile | `/users/create`, `/users/{id}`, `/users/{id}/edit` | SA, Admin (+ own) | AT STANDARD | Form-sweep forms, Select2 |
| My children | `/my_children` | Parent | AT STANDARD | |
| My fees | `/my_fees`, `/my_fees/{id}` | Parent | AT STANDARD | |
| My admit cards | `/my-admit-cards` (+detail) | Student, Parent | AT STANDARD | Screen; printed cards CY |
| My ID card | `/my_id_card` | Student, Parent | OWNED | CY document system |
| Login | `/login` | public | AT STANDARD | Design A split screen |

### Sidebar (all roles)
Catalogued, **not edited** — owned by DC (styling) and DD (SA item gating).

---

## Deferred / not defects
- **Pins pages** (`pins/create`, `pins/enter` blades still exist): the Pins
  feature was retired in favour of the Result Publish switch (BH); the
  pages are unreachable from any menu and were not hit by the crawl. Dead
  code, not a visual defect — removal is a behaviour decision for the
  parent, noted here rather than actioned.
- No page required logic changes to reach standard; nothing was catalogued
  as "needs behaviour work" in this sweep.

## Evidence
Proof was gathered at HTTP level (see the workstream report for why
headless-Chromium screenshots were not producible in this environment —
even a one-line test page timed out): for each fixed page the rendered
HTML was captured before (master) and after (sweep tree) with the same
scripted logins.

- **Events (Admin + Teacher)** and **Low Attendance**: full-page rendered
  text compared as number multisets — identical except (a) the live
  topbar clock digits, which differ between any two captures minutes
  apart, and (b) one designed addition on Low Attendance: the new
  summary strip prints the threshold value ("Below 75%") that the old
  one-line count never displayed. Every student, count, percent and
  footnote character of data is unchanged.
- **Structural assertions on the after-render**: Teacher Events has no
  Action header and no edit/delete links; Admin Events renders the
  `ev-date` blocks, per-type `ev-chip-*` classes and its Action column;
  Low Attendance renders the four `ad-stat` blocks, the `ad-meter` +
  `ad-pct` component and the byte-identical footnote; the marksheet
  screen carries the `de-marks-show` wrapper.
- **Selects**: the plain-select pages (Period Report ×2, Books ×4,
  Payroll ×2, Admit Cards ×3) render unchanged HTML — the fix is the
  served stylesheet: the live `qs.css` bytes were fetched and verified
  to contain Loop 73 (custom-select rules, Select2 harmonisation and
  every `hk-dark` variant).
- `scripts/qa-smoke.sh` on the sweep tree: **PASS** (all 7 role homes).
- Before/after mirror HTML is preserved under `/tmp/de/mirror/` and
  `~/workspace/de-work/after_*.html` for visual rendering anywhere.
