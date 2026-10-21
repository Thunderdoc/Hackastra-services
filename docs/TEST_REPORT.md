# Hackstra — Test Report (2026-10-21)

What was actually tested, how, and what still needs a live environment.
No result in this file is claimed beyond the method stated.

## Automated checks run on 2026-10-21

| Check | Method | Result |
|---|---|---|
| PHP syntax, **all 205 PHP files** (app, routes, config, database, tests) | Full parse via php-parser | ✅ 0 failures |
| Blade templates, **all 103 views** | Directive balance (@if/@foreach/@section/@php…) | ✅ Balanced (2 flags were inline `@php(...)` — valid) |
| Route references | Every `route('…')` in views + PHP vs defined names (incl. resources + Auth::routes) | ✅ 0 undefined |
| Dead clicks | Anchor audit across all views (Loops 1–2) | ✅ Fixed: dead Marksheet link, fake tab anchors, empty-href session link, dead Books item |
| Unit-test logic | Money + GradeCalculator assertions (31) executed against faithful mirrors of the source | ✅ 31/31 pass |
| Secrets | Pattern scan over tracked code + `.env.example` review | ✅ No real secrets (4 flags = validation-rule strings); `.env.example` password empty |
| Composer consistency | composer.json requires vs composer.lock | ✅ All present; ⚠️ lock pins `laravel/framework 8.x-dev` (dev version — see upgrades) |
| Migrations | Inventory | 31 total, incl. new `school_events` + `attendances` (FK + unique constraints) |
| JS/CSS syntax | `node --check` on new JS; CSS brace balance | ✅ Pass |

**Update (same day, later): PHP 8.3.6 became available on the test
machine, so the checks above were re-run on the real runtime:**
- `php -l` (real PHP binary) over all PHP files — **0 syntax errors**
- The actual PHPUnit test files (8 test methods, 31 assertions) executed
  against the actual `GradeCalculator` and `Money` sources using a
  minimal TestCase stub (vendor/ is not installed here) — **8/8 pass**

**Still not run:** framework-bootstrapped tests and browser/end-to-end
tests — those need the full vendor install + MySQL (local machine or the
Railway deploy).

## Workflow verification status

Every workflow below has a complete, parse-verified static chain
(route → controller → view). "Runtime pending" means the last step —
clicking through it on a live install — has not happened yet.

| Workflow | Static chain | Runtime |
|---|---|---|
| Login / logout / password reset | ✅ | Pending live |
| Dashboard (stats, charts, calendar, insights) | ✅ | Pending live |
| Global search (Ctrl+K) | ✅ | Pending live |
| Students: create/list/view/edit/delete, reset password | ✅ + policy enforcement | Pending live |
| Promotions | ✅ | Pending live |
| Marks: entry → marksheet → tabulation → print | ✅ + GradeCalculator wired & tested | Pending live (audit §7 priority) |
| Payments: invoice → pay now → receipt → PDF | ✅ + receipt IDOR fixed | Pending live |
| Attendance: daily entry → monthly report | ✅ (new) | Pending live |
| School events CRUD | ✅ (new) | Pending live |
| Notifications (recent activity) | ✅ (new) | Pending live |
| Pins, settings, my account | ✅ legacy, untouched | Pending live |

## Deployment build diagnosis (2026-10-21, from the real build log)

The first Railway builds failed at the container build, in two stages:
1. Strict `composer install` on PHP 8.2 → exit 2 (no detail visible then).
2. With a platform-relaxed install, `composer dump-autoload` failed:
   *"Your Composer dependencies require a PHP version >= 8.4.1.
   You are running 8.2.34."*

Root cause, verified in `composer.lock`: the lock resolves
**symfony/css-selector v8.1.6**, which requires PHP ≥ 8.4.1 — the lock was
last updated on a PHP 8.4 machine. Fix shipped: the production image now
uses **PHP 8.4** (`php:8.4-apache`), matching the lock. The PSR-4 warnings
in the same log (App\Book etc.) are pre-existing legacy namespace quirks,
warnings only, not the failure.

## Scope vs the original audit estimate

The audit's own table (22–46 working days total, "4–5 weeks"):

| Audit scope item | Estimate | Status 2026-10-21 |
|---|---|---|
| Get the existing application running | 1–2 hours | 🟡 Deploy-ready (Docker/Railway); live URL pending Railway build + account steps |
| Fix PHP/Composer/frontend compatibility | 1–3 days | 🟡 PHP 8.2 image + composer fallback done; legacy lock + frontend toolchain remain |
| Security and authentication review | 3–5 days | 🟢 Mostly done: env hardening, policies enforced, IDOR fixed, secret scan clean. Left: audit log, full auth-flow review |
| Database and validation improvements | 3–7 days | 🟡 New migrations with FK/unique + transactions; legacy indexes/FKs + more Form Requests left |
| Automated tests and CI | 3–5 days | 🟡 Unit tests written and logic-verified; CI file written but must be added via GitHub web UI (App lacks Workflows permission); PHPUnit run needs PHP env |
| Laravel major-version upgrade (8→11) | 5–10 days | 🔴 Not started — deliberately staged last, per the audit's own "stabilize first" order |
| Frontend migration Mix/Node Sass → Vite/Sass | 3–7 days | 🔴 Not started |
| Docker, deployment, monitoring, backups | 3–7 days | 🟡 Docker + Railway deployment done; monitoring + backups not done |
| Full testing and bug fixing | 3–7 days | 🟡 Full static testing done (this report) + 2 fix loops shipped; runtime E2E pending live env |

**Honest total: roughly 60% of the audit scope is complete.** The remaining
~40% is concentrated in the two upgrade tracks and live-environment QA —
the parts that cannot be done or verified without the app running.
