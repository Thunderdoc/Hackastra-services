# Hackstra — Phase-Zero Product Audit

**Date:** 2026-10-21 · **Scope:** the whole application as shipped on `master`
(including the Loop 1–9 work of 2026-10-21) · **Method:** repository inspection
plus scripted live checks against the public demo
(`hackastra.onrender.com`, 2026-10-21). Every number below comes from a
command run against the repo; the command or source file is named where it
matters. Companion docs: `REQUIREMENTS_MATRIX.md`, `ROLE_PERMISSIONS.md`,
`MULTI_SCHOOL_PLAN.md`, and the earlier research in `CASE_STUDY.md`.

---

## 1. Technical inventory

| Area | Verified state (source) |
|---|---|
| Framework | Laravel `^8.40` (`composer.json`); the lock file resolves `laravel/framework 8.x-dev` — `composer.json` sets `minimum-stability: dev`, so the framework is a moving dev build, not a tagged 8.x release (`composer.lock`). Upgrade path: `docs/UPGRADE_PLAN.md`. |
| PHP | `composer.json` allows `^7.2 \|\| ^8.0`, but the lock requires PHP ≥ 8.4.1 (`symfony/css-selector v8.1.6`); both production images are `php:8.4-apache` (`Dockerfile`, `Dockerfile.render`). The composer constraint understates the real floor. |
| Database | MySQL/MariaDB only (`.env.example`: `DB_CONNECTION=mysql`). 32 migrations, all table-creating (`ls database/migrations`). |
| Key packages | `laravel/ui v3.2.1` (auth scaffolding), `barryvdh/laravel-dompdf v0.8.7` (receipt/marksheet PDFs), `hashids/hashids 4.1.0` (URL id obfuscation), `fideloper/proxy 4.4.1`, `fruitcake/laravel-cors v2.0.4` (`composer.lock`). |
| Frontend | Blade — 109 `.blade.php` views at the audit base ref, more on master. Bootstrap 4 theme (Limitless) served as pre-built static files from `public/` (1.5 MB `public/assets` + `global_assets`). `package.json` declares Laravel Mix 2 / Vue 2.5.7 / jQuery 3.2 / Axios 0.18 as devDependencies, but `mix()` is called in **0** Blade files (`grep -rn "mix(" resources/views` = 0): the JS build pipeline is effectively unused; pages ship static assets. |
| Auth | Session auth via `laravel/ui`; login accepts **email or username** through a single `identity` field (`LoginController::username()`). Roles are a `user_type` string on `users` — 7 types: `super_admin`, `admin`, `teacher`, `accountant`, `librarian`, `student`, `parent`. Enforcement detail: `ROLE_PERMISSIONS.md`. |
| Code size | 31 models, 31 controllers, 11 repositories (`app/Repositories`), 18 seeders, 2 policies, 8 registered custom role middleware (`app/Http/Kernel.php`). |
| Routes | `routes/web.php` on master: 213 lines, 126 `Route::` statements incl. 12 `Route::resource` declarations; `routes/api.php` is a stub — only `/user` (`auth:api`) and `/health`. |
| Tests | PHPUnit 9.5.4 (dev). Test files: the two stock `ExampleTest`s plus `MoneyTest` and `GradeCalculatorTest` (8 methods / 31 assertions, `docs/TEST_REPORT.md`). The 31 assertions were executed against faithful source mirrors, **not** as a booted-application PHPUnit run — no test database run exists yet. Static verification is broader: 205 PHP files parse clean, all Blade views balanced, 0 undefined `route()` references (`TEST_REPORT.md`). |
| Deployment | Two Docker paths exist and are live-verified: `Dockerfile` (Railway target; `railway.json` healthcheck `/api/health`) and `Dockerfile.render` + `render.yaml` (Render free-tier demo; MariaDB bundled in-container, **database is ephemeral and re-seeds on every deploy**). Entrypoints migrate + seed on boot. |
| Background work | **None.** No `app/Jobs`, no `app/Mail`, no `app/Notifications`; the scheduler in `app/Console/Kernel.php` contains only the commented-out stock example. All email/SMS/reminder capability is therefore absent, not merely unconfigured. |
| CI | No `.github/workflows` directory exists at the audit base ref — **not verifiable from repository** whether CI runs anywhere. |
| Uploads | Local disk via `storage/` + `storage:link` in the entrypoints; upload paths centralised in `Qs` helpers. No cloud filesystem configured. |

### Structural facts that shape everything else

- **Single-school architecture.** `grep -rn "school_id" database/migrations app/` = **0**. Settings are global key-value rows (`settings.type`/`description`); `Qs::getSetting()` returns the one row for a key. See `MULTI_SCHOOL_PLAN.md`.
- **Every `{id}` route parameter is hash-bound.** `RouteServiceProvider` binds
  `id` through `Qs::decodeHash()`; 17 routes on master use `{id}`. Links must
  be generated with `Qs::hash($id)`. The hash salt is **date-based**
  (`date('dMY').'HACKSTRA'` in `Qs::hash`), so a hashed URL only decodes on
  the day it was generated — emailed/printed deep links with `{id}` expire at
  midnight. This caused a real production bug (library, §2) and constrains
  any future emailed-link feature.

---

## 2. Module classification

Status key: **Working** = verified end-to-end (live or full static chain) ·
**Partially working** = core works, a named layer is missing ·
**UI-only** = screens without a working backend · **Broken** = fails at
runtime · **Missing** = no code · **Requires security review** = works, but
an authorization defect is known (detail in `ROLE_PERMISSIONS.md`).

| # | Module | Status | Evidence (one line) |
|---|---|---|---|
| 1 | Super Admin / Admin dashboard | **Working** | Rebuilt to the target design; real fee/attendance/enrolment aggregates; scripted login → HTTP 200 with live data (2026-10-21). |
| 2 | Role home pages (Teacher, Student, Parent, Accountant) | **Working** | Per-role homes shipped (Loop 7-A); teacher + parent homes live-sampled; parent home re-verified today. |
| 3 | Students | **Working** | Full CRUD + `StudentRecordPolicy` + CSV export; was **Broken** on 2026-10-21 (`GET /students` 500 — resource route with no `index()`; store crash on a null class-type) → hotfixed and live-verified (302 → class list 200). |
| 4 | Teachers | **Partially working** | No dedicated teacher module: teachers are `users` rows + `StaffRecord`, managed through Users (teamSA). No staff attendance, leave, or document workflow (`CASE_STUDY.md` #23). |
| 5 | Parents (portal) | **Working** | Children, marksheets, own-children attendance (`attendance/my`), fees portal (`my_fees`, receipts), admit cards — all live-verified as a parent today (200s; staff-only page correctly bounced, 302). |
| 6 | Users & roles | **Working** | CRUD gated teamSA, destroy/reset-password super-admin-only, in-method guard on profile view. Was **Broken** (`GET /users/create` 500 — missing `create()`) → hotfixed 2026-10-21. Roles are fixed types, not assignable permissions. |
| 7 | Classes & sections | **Working** | teamSA CRUD, destroy super-admin-only; `create`/`show` 500s (missing methods) hotfixed 2026-10-21. |
| 8 | Subjects | **Working** | Same CRUD pattern and hotfix history as classes. |
| 9 | Attendance | **Working** | Marking grid, monthly report, defaulter list (<75%), student/parent self-view, CSV export — report + defaulters live-verified 200 today. Report action was **Broken** (dropped in a refactor → 500) → restored and verified 2026-10-21. |
| 10 | Examinations & marks | **Working** | teamSA tabulation/batch-fix, teamSAT mark entry, PIN-gated student/parent marksheet with print; verified statically end-to-end and by live page checks; demo seed now contains marks so a marksheet renders. Enforcement note: `MarkController`'s own teamSAT middleware line is commented out — route groups + in-action ownership/PIN checks carry it. |
| 11 | Fees, payments & receipts | **Working** | teamAccount end-to-end: fee definitions, per-student ledger, receipts + PDF (Dompdf), amount-in-words, CSV exports, fee-defaulter and collection reports (live 200 today). Known partial layer: no online gateway, no instalment/due-date/discount fields (`CASE_STUDY.md` #8–#9). |
| 12 | Academic calendar / school events | **Working** | `SchoolEvent` resource (teamSA; destroy super-admin-only); events render on dashboards. |
| 13 | Notices / circulars | **Missing** | `grep -rni "circular\|announcement" app/ routes/` = 0; no model, route, or view. Being built under the current product brief. |
| 14 | Dormitories | **Partially working** | Dorm CRUD works (teamSA; destroy super-admin-only); no bed allocation view, warden or dorm-attendance workflow (`CASE_STUDY.md` #21). |
| 15 | Library | **Working** | Catalogue CRUD + issue/return with copy-count integrity (`docs/LIBRARY_AUDIT.md` static PASS). Was **Broken** live on 2026-10-21 — "Mark Returned" returned 404 because the loans view passed a raw id into a `{id}` route (hash binder, §1) → fixed by linking with `Qs::hash(...)`; full create → issue → return round-trip re-verified live. No fines/reservations. |
| 16 | Reports & analytics | **Partially working** | Named reports work: attendance report, tabulation + print, fee defaulters/collection, CSV exports, dashboard charts. No general report builder, no class/subject performance analytics (`CASE_STUDY.md` #29). |
| 17 | Settings | **Working** | Super-admin-only; school name/acronym/logo/session are settings-driven and render across login, sidebar and titles (live-verified). Two legacy keys (`email_host`, `email_pass`) have no settings-UI fields and no consumer — dead config. |
| 18 | User profiles (`my_account`) | **Working** | Self-service profile edit + password change for every role. |
| 19 | Audit log | **Working** | Login + sensitive actions (payments, marks, student delete) recorded; super-admin viewer at `/super_admin/audit-logs` live-verified 200 today. |
| 20 | Admit cards | **Working** | Staff roster + print sheet (2 per A4) and student/parent self-service with ownership enforcement; live 200 today. Dates come from the exam timetable or are shown as "—" — never invented. |
| 21 | PINs (result scratch-cards) | **Requires security review** | The enter/verify flow works and gates marksheet access, but PIN **generation and deletion have no role check** (GAP-2, `ROLE_PERMISSIONS.md`). Also a product risk: result access depends on physical scratch cards. |
| 22 | Timetables | **Requires security review** | Builder, records and print work and writes are teamSA-gated — **except deleting a timetable record**, which any authenticated account can invoke (GAP-1, `ROLE_PERMISSIONS.md`). |
| 23 | Transport | **Missing** | No model, route, or view (`CASE_STUDY.md` #22). |
| 24 | HR / payroll / leave | **Missing** | Only staff *records* exist (see #4); no payroll, leave, or staff-attendance code. |
| 25 | Homework / LMS | **Missing** | No code (verified by search, `CASE_STUDY.md` #17–#18). |
| 26 | API & mobile | **Missing** | `routes/api.php` is a 2-route stub; no mobile client exists. |
| 27 | Backups | **Missing** | No in-app or scheduled backup mechanism; only host-level snapshots on the deployment platforms. |

**UI-only modules: none found.** Every menu entry resolves to a working route
(the one historical exception — a Librarian "Books" item pointing at an empty
scaffold — was removed in Loop 2 and the module was later built for real).

---

## 3. Audit conclusions

1. The **school-operations core is genuinely working** — students, attendance,
   exams/marks, fees/receipts, library, events — and was hardened on
   2026-10-21 by a series of runtime fixes (five missing controller actions
   that produced 500s, one hash-binder 404, one dropped report action), each
   re-verified live after the fix.
2. The **two open security defects** are both authorization gaps on write
   actions (timetable-record delete, PIN mint/delete) — small fixes, listed
   as GAP-1/GAP-2 in `ROLE_PERMISSIONS.md`.
3. The **platform layer is thin by design**: "platform" today means one
   super-admin account type with settings + audit. There is no tenant
   concept (see `MULTI_SCHOOL_PLAN.md`) and no communication layer at all
   (module #13) — those are the two structural gaps the current brief
   addresses.
4. The **framework itself is the largest deferred risk**: Laravel 8 on a dev
   build with a PHP-8.4-only lock. The staged upgrade plan exists
   (`UPGRADE_PLAN.md`, ≈4.5–6 engineer-days); production stays on 8 until
   stage 1 soaks.
