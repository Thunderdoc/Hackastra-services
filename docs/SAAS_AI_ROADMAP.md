# Hackstra — SaaS, Customisation & AI/RAG Roadmap

**Date:** 2026-10-22 · **Owner:** Ankit Kumar (Thunderdoc) · **Status:** decision-ready
design document. Nothing in this file is built yet; effort figures are planning
estimates, not measurements. Phase labels (T, C, R, AI, X) are used throughout
and in §6 sequencing.

**Locked scope — owner decision 2026-10-22:** the customisation matrix (§3),
the one-stop backlog (§4) and the AI/RAG scope (§5) in this document are
owner-approved commitments, not options. Build order: tenancy → customisation
→ payments + WhatsApp (revenue) → AI/RAG chat → teacher AI → analytics → rest.

**Relationship to existing docs:** `docs/MULTI_SCHOOL_PLAN.md` (2026-10-21) is the
deep tenancy analysis — schema deltas, scoping choke points, migration rehearsal,
5–9 engineer-week estimate. This document does not repeat it; it turns it into
product decisions and extends the same treatment to per-school customisation,
the one-stop backlog, and AI/RAG. Where the two disagree, this document wins on
sequencing and `MULTI_SCHOOL_PLAN.md` wins on schema detail.

**Owner directives this answers (2026-10-22):** (1) true multi-school SaaS
after the LMS lands; (2) the software must be *very* flexible — customised per
school without code; (3) one-stop: a school runs everything from Hackstra, zero
paper; (4) AI + RAG integrated.

---

## 1. Current-state facts (verified in this tree, @ 592c76d1)

### 1.1 One school per install — no tenant scaffolding anywhere

| Check | Source | Result |
|---|---|---|
| Tenant column | `grep -rn "school_id" app/ database/ routes/ config/` | **0 matches** |
| Data model | `database/migrations/` (2013→2026) | ~40 tables, none carries a school reference |
| Identity uniqueness | users migration; `student_records` migration | `users.email`, `users.username`, `users.code`, `student_records.adm_no` are **globally** unique — two schools cannot both have `admin@…` or admission № 001 |
| Login context | routes, middleware, session | No slug, subdomain, or school switcher exists |
| Files | `config/filesystems.php` | Default disk `public`, flat paths — no per-school segment |

**Consequence:** today, selling to N schools = N installs (the Docker images
support this). That is honest and shippable; it is not SaaS. Nothing in sales
material may call the current build multi-school.

### 1.2 What is already configurable — the settings system

Identity flows from **settings rows**, not code. Table `settings`
(`database/migrations/2018_09_22_080555_create_settings_table.php`) is a
key/value store: `type` + `description`, read through `Qs::getSetting($type)`
(`app/Helpers/Qs.php:291`), written by the settings UI
(`app/Http/Controllers/SuperAdmin/SettingController.php`,
`app/Http/Requests/SettingUpdate.php`). Seeded keys
(`database/seeders/SettingsTableSeeder.php`):

| Key | Drives |
|---|---|
| `system_name` | School name everywhere (login, sidebar, print headers) via `Qs::getSystemName()` |
| `system_title` | Acronym → `Qs::getAppCode()` (`Qs.php:28`): browser titles **and** admission-number / staff-ID prefixes |
| `logo`, `school_stamp`, `principal_name` | Print documents, ID cards, receipts |
| `phone`, `address`, `system_email`, `alt_email`, `email_host`, `email_pass` | Contacts + outbound mail config |
| `current_session` | The academic session every module filters on |
| `term_begins`, `term_ends` | Term dates |
| `lock_exam` | Exam/marks lock flag |
| `next_term_fees_{j,pn,p,n,s,c}` | Next-term fee hints per class band |

Because consumers read settings at render time, turning this row-set into a
**per-school row-set is the one part of tenancy the codebase is accidentally
ready for** (see `MULTI_SCHOOL_PLAN.md` §2).

**In flight (concurrent workstream, SA boundary):** settings are being split
into *school settings* (writable by the school Admin) and *platform settings*
(Super Admin only), and the Super Admin is becoming **view-only inside a
school** — he sees everything, writes nothing; the school Admin is the real
operator. Tenancy (§2) builds directly on that split: platform settings stay
global, school settings gain `school_id`.

### 1.3 What is already configurable — academics

- **Marking schemes are data, not columns.** `exam_subject_components` +
  `mark_components` (`database/migrations/2026_10_21_000008_create_marking_scheme_tables.php`):
  per exam × subject components (e.g. Theory 60 + Internal 30 + Viva 10)
  summing to 100, computed through one pipeline (`MarkComputation`). A subject
  with no component rows keeps the legacy CA1 20 + CA2 20 + Exam 60 scheme.
  Real 0 ≠ not-entered (NULL) is preserved.
- **Grade scales are rows.** `grades` table (name, `mark_from`, `mark_to`,
  `remark`, optional `class_type_id`) — editable bands per class type.
- **Result publish switch** per exam (`exams.results_published`): parents and
  students see a result only after the school publishes it.
- Sessions, terms, classes, sections, subjects, dorms are all CRUD data —
  no academic structure is hard-coded.

### 1.4 Role / gate model

Seven user types (`database/seeders/UserTypesTableSeeder.php`, level-ordered):
`super_admin` (1), `admin` (2), `teacher` (3), `parent` (4), `accountant` (5),
`librarian`, `student`. Enforcement lives in **controller constructors**
(teamSA / teamSAT / teamAccount / teamLibrary helpers in `app/Helpers/Qs.php`)
plus per-resource policies, and reads flow mostly through **11 repositories**
(`app/Repositories/`: User, Student, MyClass, Exam, Mark, Payment, Setting,
Dorm, Pin, Location, TimeTable) — the query choke points that make tenant
scoping feasible. Known direct-query exceptions to audit at scoping time:
dashboard aggregates and `app/Http/Controllers/DashboardSearchController.php`.

Data scoping inside one school is already server-side: a teacher sees only
classes tied to them via `subjects.teacher_id` ∪ `sections.teacher_id`
(TeacherScope); a parent sees only their own children (`Qs::userIsMyChild`);
a student only their own record.

### 1.5 Stack reality (matters for §5)

Laravel 11 / PHP 8.4 (`config/database.php` default connection `mysql`;
production containers bundle **MariaDB**; `sqlite` for local demo, `pgsql`
driver present but unused). Print is a first-class citizen (dedicated print
blades + print CSS). Audit log exists (`audit_logs`, `App\Support\Audit`).
No payment gateway, no SMS/WhatsApp integration exists anywhere in the tree
(only the framework's stock `stripe` block in `config/services.php`).

---

## 2. Tenancy design (Phase T)

### 2.1 Decision: shared database + `school_id` column

| Option | Verdict |
|---|---|
| **Shared DB, shared schema, `school_id` on every domain row** | **RECOMMENDED.** Matches the product's scale (Indian schools, tens → low hundreds of tenants), one deploy to patch/upgrade, one backup story, per-tenant cost near zero. The codebase's repo choke points (§1.4) make the scoping retrofit tractable. |
| Database per tenant | Rejected for now: per-school migrations/backups/upgrades multiply ops work linearly, and the current hosts (Railway/Render-class, single VPS-class) make N databases the expensive part. Revisit only if a large client contract demands physical isolation. |

This is the single most consequential decision in this document: **isolation
is enforced by scoped queries + policy checks, not by infrastructure** — which
is why the test matrix in §2.7 is a shipping gate, not a nice-to-have.

### 2.2 Tenant resolution

- **Login-bound tenancy (recommended for launch):** one domain; every user
  belongs to exactly one school (`users.school_id`); login resolves the tenant
  from the account itself. No DNS work, works on any host, parents never type
  a school code. A second factor for shared devices: the login page can
  optionally accept a school code to disambiguate identical emails across
  schools (emails become unique per *(school_id, email)*, §2.4).
- **Subdomain per school** (`greenwood.hackstra.app`) is the nicer long-term
  branding answer and can be layered later: a `ResolveSchool` middleware reads
  the host, sets the tenant, and asserts the logged-in user's `school_id`
  matches. Do not launch with it — wildcard DNS + per-tenant TLS on the
  current hosting class is avoidable friction.
- **Platform context:** the Super Admin operates *outside* any school
  (tenant = null) in a platform console: school list, onboarding, plans,
  usage. Entering a school from the console is **view-only** (the SA boundary
  already being built) and every such entry is audit-logged.

### 2.3 Scoping architecture

1. `schools` table: id, name, acronym (app code), status
   (trial/active/suspended), plan, owner contact, created_by, timestamps.
2. A `TenantScope` Eloquent **global scope** on every domain model: when a
   tenant context is set, all queries gain `where school_id = current`. A
   `BelongsToSchool` trait sets `school_id` automatically on create.
3. A `ResolveTenant` middleware (after auth) sets the context from
   `Auth::user()->school_id`; SA platform routes explicitly run tenant-less.
4. **Policy order is fixed: tenant check first, ownership second.** A record
   from school A must fail `view` for every school-B user *before* any
   ownership logic runs (`MULTI_SCHOOL_PLAN.md` §3.3).
5. The 11 repositories keep working unchanged from callers' perspective; the
   global scope does the work. The audit list of direct-query exceptions
   (dashboard aggregates, `DashboardSearchController`) gets tenant regression
   tests, because global scopes do not cover raw `DB::` queries or aggregates
   built by hand.
6. **Hashed `{id}` routes:** all `{id}` params decode through `Qs::decodeHash`
   to bare integers — a hash minted in school A decodes to an id that school
   B's route could resolve. Therefore scoping (§2.3.2/§2.3.4) is the *only*
   wall; keep ONE app-level hash salt for all schools (per-school salts would
   break every printed/emailed link and buy nothing once scoping is correct).

### 2.4 Schema changes

- `schools` table (new).
- `school_id` (unsigned int, indexed, FK → schools) on every domain table —
  enumerated from `database/migrations/`: `users`, `student_records`,
  `staff_records`, `my_classes`, `sections`, `subjects`, `exams`, `marks`,
  `mark_components`, `exam_subject_components`, `exam_records`, `grades`,
  `skills` (assessment skills), `payments`, `payment_records`, `receipts`,
  `attendances`, `attendance_sessions` + entries, `books`, `book_requests`,
  `dorms`, `time_tables`, `time_table_records`, `time_slots`,
  `school_events`, `circulars`, `payrolls`, `promotions`, `pins`,
  `audit_logs`, `user_preferences`, and the LMS tables
  (assignments / submissions / study materials) when they land.
- **Stays global (reference data):** `states`, `lgas`, `nationalities`,
  `blood_groups`, `class_types`, `user_types`. (`grades` deliberately gets
  `school_id` — grade scales differ per school, §3.)
- `settings` gains `school_id`; uniqueness moves from `type` to
  (`school_id`, `type`); platform settings live at a reserved `school_id = 0`
  (or a separate `platform_settings` table — decide in Phase T0; the reserved
  row is less code).
- Uniques become composite: (`school_id`, `email`), (`school_id`,
  `username`), (`school_id`, `adm_no`); `payments.ref_no` likewise.

### 2.5 Per-tenant settings & files

- Settings reads become `Qs::getSetting($type)` → tenant-aware under the hood;
  the ~20 existing call sites stay untouched because they are context-driven
  by the middleware (§2.3.3). This is the payoff of §1.2.
- **Storage partitioning:** uploads move under `schools/{school_id}/…` on the
  public disk (photos, logos, material files); LMS submission files stay on
  the private disk behind the permission-checked download route, partitioned
  the same way. No cross-tenant path guessing: downloads always resolve
  through a tenant-checked controller, never a raw URL.
- Exports (CSV/PDF/print) inherit tenant context automatically because they
  are query-built — each gets a regression test in §2.7.
- Backups: full-DB backup stays platform ops; add a **per-school data
  export** (students, marks, fees as CSV/Excel bundle) as a platform feature
  — schools leaving the platform take their data with them. This is a trust
  feature, priced into onboarding conversations.

### 2.6 Onboarding flow (new school, zero developer involvement)

1. SA (platform console) creates the school: name, acronym, city, owner
   name/email/phone, plan → `schools` row + tenant settings seeded from
   platform defaults (a copy of today's `SettingsTableSeeder` defaults,
   parameterised by school).
2. System creates the school's first **Admin** account and sends a
   set-password link (no shared demo passwords in production; `SEED_DEMO`
   stays demo-only).
3. Admin logs in and self-configures: logo, stamp, sessions, classes,
   sections, subjects, fee structure, marking scheme defaults — all existing
   CRUD, now tenant-scoped.
4. Admin bulk-imports students (existing CSV import), adds staff, and the
   school is live. SA sees the school in the platform overview (counts,
   status) but remains view-only inside it.

### 2.7 Cross-tenant test matrix — THE SHIPPING GATE

Phase T does not ship until every row passes, run as two real schools
(A and B) seeded side-by-side, as every role of B attacking A's data:

| # | Attack / check | Expected |
|---|---|---|
| 1 | B-teacher opens A-student profile via hashed `{id}` URL (hash minted in A) | Denied (403/redirect), no A data in HTML |
| 2 | ID-swapping sweep: for every `{id}` route, substitute A ids while logged in as B (all 7 roles) | Denied on every route |
| 3 | B-parent opens A's marksheet + annual report card, screen and print URLs | Denied; publish switch irrelevant cross-tenant |
| 4 | B-accountant opens A's receipt, invoice, fee defaulter list, collection report, CSV exports | Denied; exports contain B data only |
| 5 | Global/dashboard search as B with A-student name + admission № | Zero A results |
| 6 | B guesses A's uploaded file paths (photos, LMS submissions, materials) | No public path resolves; download routes deny |
| 7 | B-teacher crafts POSTs (marks update, payment record, assignment grade) with A record ids | Denied at policy layer; attempt audit-logged |
| 8 | Same email address registered in both schools; each logs in | Each sees only own school (composite unique + tenant-bound login) |
| 9 | Same admission № in both schools | Allowed; never cross-resolves |
| 10 | SA enters school A from platform console, crafts a write POST | Denied (view-only boundary); entry + attempt in audit log |
| 11 | School B suspended by SA | B logins blocked with a clear message; A unaffected; B data intact on reactivation |
| 12 | Per-school export (§2.5) run for A | Bundle contains A rows only, verified by row counts vs direct DB read |

### 2.8 Migration path from today's installs

Create school #1 from the existing install's settings → backfill
`school_id = 1` on all domain tables → convert uniques to composites →
re-point settings rows → regression pass. Rehearse on a **staging copy of a
realistic school database** (the demo's ephemeral DB is not a rehearsal
environment). Existing per-school installs keep running untouched until their
owner opts into the hosted SaaS.

### 2.9 Effort

Per `MULTI_SCHOOL_PLAN.md` §4: **5–9 engineer-weeks total** (schema + context
1–2, scoping 2–3, platform layer 1–2, migration + hardening ~1). Flagged
honestly: this is the largest single project on this roadmap, and it gates
everything AI-related (§5), because AI data access inherits tenant walls.

---

## 3. Per-school customisation matrix (Phase C)

Goal: a school admin configures everything below **without a developer**.
`EXISTS` = works today (single-school); tenancy makes it per-school.
`EXTEND` = mechanism exists, needs fields/UI. `NEW` = build it.

### 3.1 Branding & identity

| Item | Status | Notes / effort |
|---|---|---|
| School name, acronym (app code → adm № / staff-ID prefixes) | EXISTS | `settings.system_name` / `system_title` |
| Logo, school stamp, principal name & signature line | EXISTS | Print surfaces already render from settings |
| Address, phone, emails printed on documents | EXISTS | Settings rows |
| Accent colour / theme colour per school | NEW (S) | One `brand_colour` setting consumed by the CSS variable layer (qs.css already themes via variables + dark mode); logo on login already settings-driven |
| Login page welcome text | EXTEND (S) | One settings textarea, rendered on the login split-screen |

### 3.2 Academic structure

| Item | Status | Notes / effort |
|---|---|---|
| Sessions, term begin/end dates | EXISTS | Settings + session CRUD |
| Classes, sections, subjects, class types | EXISTS | Full CRUD today |
| Marking schemes per exam × subject (components & max marks) | EXISTS | §1.3 — strongest existing flexibility story |
| Grade scales (bands + remarks) per class type | EXISTS | `grades` rows; gains `school_id` in Phase T |
| Result publish / lock controls | EXISTS | `exams.results_published`, `settings.lock_exam` |
| Number of terms per session (2 vs 3) + term names | EXTEND (M) | Terms are currently implicit (exam/session strings); a `terms` setting + labels is contained, but report-card aggregation must read it |
| Class/section naming style (e.g. "JSS 1" vs "Grade 8") | EXISTS | Free-text CRUD names — no code involved |
| Label overrides ("Section" → "Division", "Dorm" → "House") | EXTEND (M) | Feasibility: honest assessment — labels live in dozens of blades + menu partials, not a lang file. Do it as a small `labels` settings map consumed by a `Qs::label('section')` helper, migrated surface-by-surface (menus + page titles first). Full coverage is a grind, not a project; ship the top 10 labels schools actually rename |

### 3.3 Fees & finance

| Item | Status | Notes / effort |
|---|---|---|
| Fee items per class per year (title + amount) | EXISTS | `payments` rows (`title`, `amount`, `my_class_id`, `year`) |
| Partial payments, balances, receipts | EXISTS | Core flow, money-parity audited |
| Named fee heads (Tuition / Transport / Exam / …) as a picklist | EXTEND (S) | Today the head is free text in `payments.title`; a per-school `fee_heads` list makes reports group cleanly. Data-compatible (title stays, head becomes a column) |
| Late-fee rules (flat / % after due date) | NEW (M) | Needs a fee due-date concept first (`payments.due_date`), then a rule row per school; defaulter report reads it |
| Discounts / sibling concessions | NEW (M) | Per-student adjustment rows on `payment_records`; accountant-applied, audit-logged |
| Online payment (gateway) | NEW | §4 — Phase R |

### 3.4 Documents & print

| Item | Status | Notes / effort |
|---|---|---|
| Marksheet / annual report card / admit card / ID card / receipt / payslip print | EXISTS | All print surfaces already render school header/footer from settings (§1.2) |
| Marksheet footer note ("This is a computer-generated…"), signatory titles | EXTEND (S) | Two settings textareas consumed by the existing print blades |
| Certificate templates (transfer / bonafide / character) | NEW | §4 — Phase X |

### 3.5 Modules & platform behaviour

| Item | Status | Notes / effort |
|---|---|---|
| **Module toggles per school** (library, dorms/hostel, LMS, payroll, transport-later, alumni-later) | NEW (M) | A `modules` settings map (JSON row) per school; menu fragments and dashboard widgets check it; routes for a disabled module return a friendly "not enabled" page (not 404). Enforcement = middleware check on the module's route group, so toggles are real, not cosmetic. Highest-leverage customisation item for sales: a day-school hides Hostel, a small school hides Payroll |
| LMS defaults (max upload size, late-submission default, allowed file types) | EXTEND (S) | Settings rows read by the LMS controllers when they land |
| Staff/teacher permissions fine-tuning (e.g. may a teacher print marksheets?) | EXTEND (M) | Today permissions are gate-fixed per role. A per-school permission matrix is powerful but risky; if built, build it as *grants on top of* the existing gates (never below the tenant/ownership floor) and keep money + settings out of it |
| Custom student fields | EXTEND (M) | **Recommendation: fixed optional fields, not EAV.** Schools ask for the same handful (transport route, previous school, religion/caste cert №, Aadhaar-last-4, medical notes). EAV/custom-field builders make lists, search, exports and print all slower to build and easier to break. Ship ~8 optional named fields (all nullable, hidden when unused) + one free "notes" field; revisit a true custom-field builder only when a paying school names a field outside the list |
| Language (Hindi / regional UI) | NEW (L) | Blades are not i18n-extracted; a full pass is multi-week. Defer; Indian school staff overwhelmingly operate in English UI today. Label overrides (§3.2) cover the common ask |

### 3.6 What deliberately stays platform-controlled

App version, security policy, hash/link mechanics, audit retention, and the
SA view-only boundary are **not** school-configurable. Flexibility stops
where another school's safety starts.

---

## 4. One-stop extras backlog (Phases R + X)

Everything a school still leaves Hackstra for today. Ordered by value to an
Indian school; R = revenue phase (§6), X = expansion backlog.

| Feature | One-line value | Effort | Dependencies |
|---|---|---|---|
| **Online fee payment — Razorpay** (UPI/cards/netbanking) | Parents pay from a link; receipt auto-generates; accountant reconciles instead of chasing cash | **M–L** | Fee invoices exist (payments/payment_records); webhook → `payment_records` + `receipts` write path must reuse the audited money pipeline; per-school merchant onboarding (Razorpay sub-merchant / own keys per school — money must land in the *school's* account, never the platform's) |
| **WhatsApp + SMS + email notifications** | Fee reminders, result-published alerts, circulars, absence alerts — Indian parents live on WhatsApp, not email | **M** | A notification service layer (queue + templates per school); WhatsApp Business API (template approval lead time — start early); per-school sender config in settings; cost per message metered like AI (§5.4) |
| **Online admissions + enquiry CRM** | Enquiry → follow-up → application → admit in one funnel; feeds student records directly | **M** | Public enquiry form (tenant-resolved by school code/subdomain), enquiry states, convert-to-student action reusing the student create path |
| **Certificates generator** | Transfer, bonafide, character, study certificates printed from live student data with school stamp | **S–M** | Print framework exists; needs certificate templates + an issue register (serial №, issued-by, audit) |
| **Student document vault** | Birth cert, TC, report cards stored per student; parent views own child's | **S** | Tenant-partitioned storage (§2.5); permission-checked downloads (same pattern as LMS submissions) |
| **PWA / mobile app shell** | Installable app icon, push notifications, offline-friendly shell for parents/teachers | **M** | The UI is already responsive + dark; PWA manifest/service worker + push provider. A native app is **not** recommended — PWA covers the ask at a fraction of the cost |
| **Online quiz / exam engine** | Question bank, timed MCQ quizzes, auto-marking into the Internal component | **L** | LMS (in flight) provides the class/subject plumbing; question bank + attempt engine are new; auto-marks must enter through the same marks pipeline as the assignment-average bridge |
| **Timetable auto-generator** | Constraint-based draft timetable instead of a weekend of manual grid work | **L** | Existing timetable grid is manual CRUD; generator is a solver (teacher availability, subject periods/week, room clashes) — ship "generate draft → teacher edits" |
| **Front desk: visitors + gate pass** | Visitor log, printed gate passes, student early-leave passes with parent confirmation | **S–M** | Standalone module; parent confirmation rides the notification layer |
| **Transport** (routes, vehicles, student-route mapping; GPS later) | Route-wise lists for dispersal, transport fee head linkage | **M** (GPS: **L**, phase separately) | Fee heads (§3.3) for transport fees; GPS needs a hardware/vendor integration — do not promise it in v1 |
| **Inventory / assets** | Lab, sports, furniture registers with issue/return | **S–M** | Mirrors the library module's proven pattern (items + loans) |
| **Helpdesk / grievances** | Parents/students raise tickets; staff resolve with an audit trail | **S** | Reuses circulars/messaging patterns; SLA states only, no workflow engine |
| **Alumni** | Passed-out students stay reachable for the school (events, testimonials, records requests) | **S** | Graduation flow already exists; alumni = a state + a light profile view |
| **HR depth: leave + payroll slips** | Staff leave applications/approvals; payslip PDFs already exist — add leave to complete HR | **M** | Payroll module exists (status + payslips); leave is a small workflow on staff records |

---

## 5. AI + RAG design (Phase AI)

### 5.1 Architecture — provider-agnostic, platform-keyed, metered

- **LLM abstraction:** one `AiClient` service behind a config block
  (`config/ai.php`, same style as `config/services.php`): `provider`,
  `chat_model`, `embedding_model`, `api_key` — **platform-level keys**, set
  by the Super Admin, never per school. Schools consume AI; they do not
  bring keys. Swapping providers (OpenAI / Anthropic / Gemini / an
  India-hosted open model) is a config change, not a rewrite.
- **Embeddings + vector store — the honest stack assessment.** Production
  runs MySQL/MariaDB (§1.5), which has **no production-grade vector index**
  on the versions this product deploys. Options:
  | Option | Verdict |
  |---|---|
  | Brute-force cosine in PHP over chunk embeddings stored as JSON in MySQL | **Phase AI-1 choice.** A school's corpus is small (handbook, circulars, policies, materials ≈ hundreds of documents, low thousands of chunks). At that scale in-memory scoring is milliseconds and adds **zero infrastructure**. |
  | Dedicated Postgres + pgvector sidecar (embeddings only; school data stays in MySQL) | The scale path when corpora or tenant count outgrow brute force; interface hides it behind the same repository |
  | Hosted vector DB (Pinecone etc.) | Rejected for v1: another vendor holding student-adjacent data, another bill, no benefit at this corpus size |
- **Ingestion pipeline:** document → extract text (PDF/Word/plain) → chunk →
  embed → store chunk rows: (`school_id`, source_type, source_id, chunk_text,
  embedding JSON, **audience metadata**: roles allowed, owner user id where
  personal). Circulars are already audience-scoped in the app
  (`Circular::forAudience`) — chunk metadata copies that exact model.
- **Retrieval is permission-checked twice:** chunks are filtered by
  `school_id` AND the asking user's role/ownership *before* ranking, and
  every source document is re-authorised at answer time. A parent can never
  retrieve a staff-only circular's chunks — they never enter the candidate
  set. This is the same tenant-first, ownership-second order as §2.3.4.

### 5.2 Knowledge sources (per school)

Circulars (already audience-scoped) · uploaded school handbook/policies ·
LMS study materials (teacher uploads become searchable for their classes) ·
fee structure rows · timetable · school FAQ entries the admin curates ·
the academic calendar/events. Nothing enters a school's knowledge base
without coming from that school's own data.

### 5.3 Features, in value order

**AI-1 — "Ask Hackstra" (RAG chat).** Parents, students, staff ask in plain
language: *"When does the school reopen?"*, *"Has the Term 2 fee been
announced?"* — answered from the school's documents with the source named.
Plus **my-data answers** through permission-checked queries, never free SQL:
a parent asking *"What is my child's attendance this month?"* triggers the
same repo calls the UI makes, scoped to their children; a teacher asking
about their class gets TeacherScope results. The LLM phrases the answer; it
never fetches data itself.

**AI-2 — Teacher tools.**
- Question-paper / quiz generator from syllabus + the teacher's own study
  materials (draft → teacher edits → becomes an assignment/quiz).
- **Grading assist:** suggests marks + feedback per submission; the teacher
  confirms or edits — never auto-final, and the confirm step is the audit
  point.
- Circular/notice drafting from 2–3 bullet points, in the school's tone.

**AI-3 — Admin analytics.**
- Natural-language reports: *"Fee defaulters in Class 10 with parent phone
  numbers"* → resolved to a permission-checked, pre-built report (a fixed
  catalogue of report intents — not text-to-SQL), result rendered as the
  existing report, request audit-logged.
- Early-warning list: attendance drop + marks decline → at-risk students for
  the class teacher, computed from data (statistics, not LLM guesses), with
  the LLM only summarising.

### 5.4 Guardrails (non-negotiable)

1. **No AI write without a human confirm.** Grading, marks, messages,
   reports: AI drafts, a named user confirms, the confirm is audit-logged.
2. **AI sees data only through the policy layer** — the same repos/scopes
   the UI uses. There is no AI-only data path to audit separately.
3. **PII discipline:** the only data sent to the LLM is the asking user's
   permitted slice plus retrieved chunks from their own school. No bulk
   student exports to any model, ever.
4. **Cost is metered per school** (`ai_usage`: school, user, feature,
   tokens, estimated cost). AI is a **paid add-on tier**: platform keys,
   platform bill, per-school quotas in the plan — a school that hasn't
   bought the tier doesn't see the features (module toggle, §3.5).
5. **Failure is quiet and safe:** AI down = feature hides, core software
   unaffected. Nothing in marks, fees or attendance may *depend* on the LLM.

### 5.5 Phasing inside AI

AI-1 RAG chat (highest visible value in a sales demo; also the riskiest —
hence last on the roadmap's sequence, §6, after tenant walls exist) →
AI-2 teacher tools → AI-3 analytics. Each is multi-week; AI-1 is **L**
(ingestion + retrieval + permission model + chat UI), AI-2 **M–L**, AI-3 **M**.

---

## 6. Sequencing

| Order | Phase | Contents | Why here |
|---|---|---|---|
| 0 | *(in flight)* | Final sprint commit → LMS milestone | LMS tables join the tenant schema list (§2.4) — tenancy must not start before LMS lands or it designs around a moving schema |
| 1 | **T — Tenancy** | §2 (5–9 engineer-weeks) | Everything after this assumes tenant walls: customisation is per-school, gateway keys are per-school, AI corpora are per-school |
| 2 | **C — Customisation** | §3 (module toggles + fee heads + document texts first; labels + custom fields next) | Cheap on top of tenant settings; directly answers "every school is different" in sales |
| 3 | **R — Revenue** | Razorpay online fees + WhatsApp/SMS/email notifications (§4) | The two features that justify a higher price and cut the school's fee-collection pain immediately |
| 4 | **AI-1** | Ask Hackstra RAG chat (§5) | Highest demo value; only safe once T's permission walls and §5.1's retrieval model exist |
| 5 | **AI-2 / AI-3** | Teacher tools, then analytics | Builds on AI-1's metering + guardrail plumbing |
| 6 | **X — Expansion** | Admissions CRM, certificates, vault, PWA, quiz engine, timetable generator, transport, front desk, inventory, helpdesk, alumni, HR leave (§4) | Value-ordered; pull forward individually when a paying school asks |

**The one-sentence version for the owner:** make it multi-school (T), make
each school feel like *their* software (C), make it collect money and send
messages (R), then make it intelligent (AI) — in that order, because each
step is the safety foundation of the next.
