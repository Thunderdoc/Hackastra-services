# Hackstra — Multi-School (Tenancy) Plan

**Date:** 2026-10-21 · **Status:** planning document. Nothing in this file is
built, and nothing in the product should be described to a buyer as
multi-school until Phase 3 below is complete. Effort figures are planning
estimates, not measurements.

---

## 1. Verdict on the current architecture

**Hackstra today is a single-school application** — the brief's option A,
with no hidden tenant scaffolding. This is verifiable, not a matter of
interpretation:

| Check | Command / source | Result |
|---|---|---|
| Tenant column anywhere | `grep -rn "school_id" database/migrations/` | **0 matches** |
| Tenant concept in code | `grep -rn "school_id" app/` | **0 matches** |
| Tables | 32 migrations | users, student_records, payments, marks, settings… — none carries a school reference |
| Settings | `settings` table = `type` + `description` rows; `Qs::getSetting($type)` returns *the* row | One global value per key for the whole install: one school name, one logo, one current session |
| Settings call sites assuming one school | `grep -rn "getSetting" app/ resources/views/` (at audit base ref) | **20 call sites in 14 files**, every one context-free |
| Identity uniqueness | users migration: `email`, `username`, `code` are **globally** `unique`; `student_records.adm_no` globally `unique` | Two schools cannot both have `admin@…` or admission № 001 |
| School context in requests | routes, middleware, session | No slug, subdomain, or switcher exists anywhere |
| Roles | `super_admin` is the top role | It is a *school's* super admin; there is no platform-level identity above it |

**Consequence for sales today:** one deployment = one school. That is a
legitimate way to sell the current product (per-school installs); it is not
SaaS multi-tenancy, and the platform/school separation in
`REQUIREMENTS_MATRIX.md` #15 is about roles inside one install, not tenants.

## 2. The seed that already exists

Per-school **branding and configuration are already settings-driven** — name,
acronym, logo, address, phone, email and current session all render from the
settings table across login, sidebar and page titles (shipped in the
customization workstream, live-verified). Tenancy turns that single settings
row-set into a per-school row-set; the *consumers* of branding barely change.
This is the one part of tenancy the codebase is accidentally ready for.

## 3. What true multi-tenancy would require

### 3.1 Schema
- New `schools` table (identity, status, branding defaults, plan fields as
  plain columns).
- `school_id` (indexed, FK) on **every domain table**: users, student_records,
  classes, sections, subjects, exams, marks, exam_records, attendances,
  payments, payment_records, receipts, pins, books, book_requests, dorms,
  timetables + records + slots, school_events, audit_logs, promotions,
  staff_records — effectively all 32 migration tables except pure reference
  data (states, LGAs, nationalities, blood groups, class types, grades scale
  can stay global — a decision to record, not assume).
- `settings` gains `school_id`; uniqueness moves from `type` to
  (`school_id`, `type`).
- Global uniques become composite: (`school_id`, `email`), (`school_id`,
  `username`), (`school_id`, `adm_no`).

### 3.2 School-context resolution
- Decide the context carrier **once**: subdomain per school (cleanest for
  branding/login) vs a school code on the login form (works on one domain).
  Subdomain has DNS/hosting consequences on the current platforms (Railway/
  Render custom domains per school).
- A `ResolveSchool` middleware runs before auth, sets the current school,
  and scopes the login itself (a user only exists inside a school).
- A platform context (no school) exists only for the future platform role.

### 3.3 Authorization & query scoping
- The query choke points are the **11 repositories** (`app/Repositories`) —
  most reads flow through them, which makes scoping feasible: add the school
  constraint inside the repos (or via Eloquent global scopes per model) and
  audit the exceptions (controllers and dashboard code that query models
  directly — e.g. the dashboard aggregates and `DashboardSearchController`).
- Policies gain a school check: a record from school A must fail `view` for
  every user of school B *before* the existing ownership logic runs.
- The role middleware sets stay the same *within* a school; a new
  `platform_admin` identity (above `super_admin`) owns school onboarding and
  must be technically unable to read school data by default — support access,
  if ever offered, is a separate audited mechanism, not a role flag.

### 3.4 The `{id}` hash binder — named risk
All `{id}` route parameters (17 on master) decode through `Qs::decodeHash()`,
whose salt is **date-based** (`date('dMY').'HACKSTRA'`, `Qs::hash()`). Two
tenancy consequences:
- Hashes are **not school-scoped**: a hash minted in school A decodes to a
  bare integer that school B's routes will happily resolve against B's data
  unless §3.3 scoping is airtight. The policy layer must therefore check
  school *and* ownership, in that order.
- Any move to per-school salts (tempting for isolation) breaks every
  existing link and printout; keep one app salt and get isolation from
  scoping, not from the hash.

### 3.5 Data migration
- Create school #1 from the current install's settings; backfill
  `school_id = 1` on all domain tables; convert the uniques to composites;
  re-point settings rows. Reversible if rehearsed on a copy; the demo's
  ephemeral database is **not** a rehearsal environment — this needs a
  staging copy of a realistic school database.
- Seeders must become school-aware (reference data global; demo/school data
  per school).

### 3.6 Everything else that quietly assumes one school
- File uploads: storage paths must gain a school segment (or per-school
  disks), or one school can guess another's file paths.
- Exports, print views and the audit log: add school to the audit trail and
  make every report/export inherit the context (they are query-built, so
  §3.3 covers them — but each must be regression-tested, per `UPGRADE_PLAN.md`
  style checklists).
- Backups: per-school export/restore becomes a platform feature, not a
  host snapshot.

## 4. Phased plan (estimates)

| Phase | Content | Estimate |
|---|---|---|
| 0 — Decisions | Context carrier (subdomain vs code), reference-data ruling, platform-role definition, go/no-go per phase | 2–4 days |
| 1 — Schema + context | `schools` table, `school_id` backfill migration, context middleware, login scoping, composite uniques | 1–2 weeks |
| 2 — Scoping | Repo/model scoping, policy school-checks, dashboard + search + reports audit, regression pass per module | 2–3 weeks |
| 3 — Platform layer | `platform_admin`, school onboarding flow, per-school settings/branding UI (builds on §2), school status controls | 1–2 weeks |
| 4 — Migration & hardening | Real-data migration rehearsal + execution, cross-school penetration tests (IDOR matrix re-run per role), backup story | ≈1 week |
| **Total** | | **≈5–9 engineer-weeks** |

Sequencing constraint: the Laravel 8→11 upgrade (`UPGRADE_PLAN.md`) should
land **first or last, never concurrently** — both projects touch nearly every
file, and tenancy on an EOL framework doubles the regression surface.

## 5. Risks, plainly

1. **Silent cross-school leakage** is the failure mode that matters: one
   missed query scope mixes two schools' children/fees. Mitigation is
   architectural (repo-level scoping + a cross-school IDOR test matrix),
   not vigilance.
2. **The demo lies by omission:** today's demo seeds exactly one school, so
   no demo screenshot can evidence tenancy. Do not demo Phase 1 as if it
   were Phase 3.
3. **Printed/emailed artefacts** (receipts, admit cards) carry hashed links
   that already expire daily (§3.4); tenancy must not promise parents
   permanent emailed links without solving that first.
4. **Interim path:** until this plan is executed, sell **one install per
   school** (the current Docker images already support it) and describe it
   exactly that way.
