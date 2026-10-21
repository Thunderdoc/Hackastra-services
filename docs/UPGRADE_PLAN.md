# Laravel 8 → 11 Upgrade Plan (staged, prep only — nothing executed yet)

Date: 2026-10-21 · Branch: feature/loop7-upgrade-prep · Status: **PLAN ONLY**
Target end state: **Laravel 11.x on PHP 8.2+** (production images already run
PHP 8.4 — `php:8.4-apache` in both Dockerfiles — so the PHP runtime is *not*
the blocker; the dependency tree is). Path: three staged hops 8→9→10→11,
one branch + one regression pass per hop. Never skip a major: package
constraints and framework internals only compose hop-by-hop.

---

## 1. Current state — measured from composer.json / composer.lock

composer.json requires (quoted verbatim):

| Package | Constraint | Resolved in lock | Notes |
|---|---|---|---|
| php | `^7.2\|\|^8.0` | platform `^7.2\|\|^8.0` | Must become `^8.2` by the L11 hop (L10 already needs ^8.1) |
| laravel/framework | `^8.40` | **8.x-dev** | A dev pin, not a release — see §2 |
| barryvdh/laravel-dompdf | `^0.8.3` | v0.8.7 (dompdf/dompdf v0.8.3) | Oldest package in the tree; receipts depend on it |
| fideloper/proxy | `^4.4` | 4.4.1 | Dead at L9 — framework absorbs it (§3) |
| fruitcake/laravel-cors | `^2.0` | v2.0.4 (+ asm89/stack-cors v2.0.3) | Framework absorbs CORS at L9 |
| laravel/ui | `^3.0` | v3.2.1 | Auth scaffolding; needs ^4 from L9 on |
| laravel/tinker | `^2.5` | v2.6.1 | Minor bumps per hop |
| guzzlehttp/guzzle | `^7.0.1` | 7.3.0 | Fine all the way to L11 |
| hashids/hashids | `^4.1.0` | 4.1.0 | Used by `Qs::hash()` for every hashed record id |
| fakerphp/faker | `^1.14` | v1.14.1 | Sits in `require`, belongs in require-dev |
| symfony/* (transitive) | — | mostly **v5.4.x** | Correct pairing for Laravel 8 |
| swiftmailer/swiftmailer | (transitive) | v6.3.0 | Laravel 9 replaces SwiftMailer with Symfony Mailer |

Dev: barryvdh/laravel-ide-helper ^2.8 → v2.10.0, facade/ignition ^2.5 →
2.9.0, nunomaduro/collision ^5.0 → v5.4.0, phpunit/phpunit ^9.3.3 → 9.5.4,
mockery/mockery ^1.4.2 → 1.4.3.

composer.json also sets `"minimum-stability": "dev"` + `prefer-stable: true`.
That one line explains both oddities below and **must become `stable`**
(the Laravel 10 upgrade guide calls this out explicitly).

## 2. The symfony/css-selector v8 lock oddity — root cause + cure

The lock contains **symfony/css-selector v8.1.6 requiring PHP ≥ 8.4.1** inside
an otherwise Symfony 5.4 tree. Verified mechanism (from the lock itself):
`tijsverkoyen/css-to-inline-styles v2.4.0` (pulled in by Illuminate Mail)
allows `symfony/css-selector: ^5.4 || ^6.0 || ^7.0 || ^8.0`. The lock was last
regenerated on a **PHP 8.4 machine**, and with `minimum-stability: dev`
Composer happily resolved that one transitive package to the newest Symfony
8.1 line while everything else stayed on 5.4. The same dev-stability +
PHP 8.4 resolution explains `laravel/framework 8.x-dev` (a moving dev branch
instead of a tagged 8.x release) and a few other future-dated transitives in
the lock (brick/math 1.0.0, nette/utils v4.1.6, dragonmantank/cron-expression
v3.6.1 — all resolved for a newer PHP than the app declares).

Why it matters today: `composer install` from this lock fails on PHP < 8.4
(this is what killed the first Railway builds; both Dockerfiles currently
work around it with PHP 8.4 + a `--ignore-platform-reqs` retry fallback).

Cure (no hand-pinning): at the 8→9 hop, set `minimum-stability: stable`,
delete the lock, and re-resolve on the deployment PHP. css-selector then
lands on the line that matches the framework's Symfony major (6.4.x on
L9/L10, 7.x on L11) and the framework pin becomes a tagged release. Once the
lock is sane, the Dockerfiles' `--ignore-platform-reqs` fallback can be
deleted. The oddity therefore resolves itself as a side effect of Stage 1 —
do **not** spend a separate step fighting it beforehand.

## 3. Dependency-by-dependency: what each hop demands

| Package | 8→9 | 9→10 | 10→11 |
|---|---|---|---|
| laravel/framework | ^9.0 (PHP ^8.0.2) | ^10.0 (PHP ^8.1) | ^11.0 (PHP ^8.2) |
| fideloper/proxy | **REMOVE** — rewrite `app/Http/Middleware/TrustProxies.php` onto `Illuminate\Http\Middleware\TrustProxies`, `$headers` = FOR\|HOST\|PORT\|PROTO\|AWS_ELB bitmask (the `HEADER_X_FORWARDED_ALL` constant disappears with Symfony 6). Keep this app's `$proxies = '*'` (mixed-content fix depends on it) | — | — |
| fruitcake/laravel-cors | **REMOVE or bump ^3** — framework ships `Illuminate\Http\Middleware\HandleCors`; Kernel line 19 references `\Fruitcake\Cors\HandleCors::class` and must change either way | — | — |
| facade/ignition | **Replace** with spatie/laravel-ignition ^2.0 | keep ^2 | keep ^2 |
| laravel/ui | ^3.2 → **^4.0** (ui 4.x covers Laravel 9–12) | keep ^4 | keep ^4 |
| barryvdh/laravel-dompdf | ^0.8 → **^2.0** (dompdf 2.x) | keep ^2 | **^3.0** (dompdf 3.x; PHP 8.1+) — re-publish config/dompdf.php per hop and re-test receipts (see risks) |
| barryvdh/laravel-ide-helper | ^2.8 → ^2.14+ | ^2.15 | **^3.0** (dev-only; AppServiceProvider registers it only when `isLocal()`, so runtime risk is nil — worst case it is dropped for a hop) |
| nunomaduro/collision | ^6.0 | ^7.0 | ^8.0 |
| phpunit/phpunit | keep ^9.5 | **^10.0** + migrate phpunit.xml | ^10.5 or ^11 |
| mockery/mockery | ^1.4 → ^1.5 | ^1.6 | ^1.6 |
| laravel/tinker | ^2.7 | ^2.8 | ^2.9+ |
| fakerphp/faker | move to require-dev, ^1.20+ | keep | keep |
| hashids/hashids | keep 4.1 (its `^8.0` PHP constraint admits 8.2+) | keep | keep (optional ^5.0, PHP ^8.1 — `Qs::hash()` API unchanged) |
| guzzlehttp/guzzle | ^7.5+ | ^7.8 | ^7.8/^7.9 — no code impact |

No `spatie/*` or `maatwebsite/excel` packages exist in this tree (checked
composer.json + full lock) — nothing to do for them. `intervention/image` is
not present either; avatar/photo handling uses plain file storage.

## 4. Codebase-specific breakage — from greps actually run (2026-10-21)

Good news first (searched app/, config/, routes/, database/, resources/,
tests/ — **zero hits**): `dispatch_now`, `withSwiftMessage`, `Swift_*`,
any direct `Symfony\` usage in app code. Also already-modern:
factories are class-based (`Database\Factories\*`, `User::factory()` in
seeders — the legacy `$factory->define` style is absent), seeders use the
`Database\Seeders` namespace, and the test suite (Example/Money/
GradeCalculator tests) uses no PHPUnit-10-removed matchers
(`withConsecutive`, `->at()`, `assertRegExp` — zero hits).

The real work list, per hop:

**8 → 9 (the heavy hop)**
- TrustProxies rewrite + fideloper removal (§3). Small file, high blast
  radius: a mistake here resurrects the http:// mixed-content bug on every
  page. Regression script must include the asset-scheme check (§6).
- Kernel: `\Fruitcake\Cors\HandleCors` → `Illuminate\Http\Middleware\HandleCors`
  (Kernel.php line 19). There is no config/cors.php in the repo, so the
  framework defaults apply — same behaviour as today.
- Flysystem 1 → 3: file/photo uploads (student/teacher photos) ride on
  `Storage` facades; the v1→v3 API used here (`put`, `exists`, `url`,
  `delete`) is compatible, but every upload + photo-display page goes on the
  regression list. `league/flysystem 1.x-dev` in the lock disappears.
- Mail: SwiftMailer → Symfony Mailer is framework-internal; this app sends
  almost no mail (password resets). `config/mail.php` is the L8 shape and
  keeps working; smoke-test one password-reset email on staging.
- `assertDeleted` → `assertModelMissing` (L9 testing change): zero usages in
  tests today; noted so future tests don't reintroduce it.
- The `RouteServiceProvider::$namespace` classic string-controller style
  (~104 route statements, e.g. `'BookController@markReturned'`) keeps
  working on 9 — no route rewrite needed at this hop or later (see 10→11).

**9 → 10 (the tooling hop)**
- PHPUnit 9 → 10: `phpunit.xml` is the PHPUnit 8/9 schema
  (`<filter><whitelist>`, `convert*ToExceptions` attributes) — run
  `phpunit --migrate-configuration`, then hand-fix the stale
  `MAIL_DRIVER` env entry (renamed `MAIL_MAILER` back in Laravel 8; harmless
  but confusing) and re-check the Unit suite (Money/GradeCalculator must stay
  31/31 green — they are the canary for this whole upgrade).
- Collision ^7 pairs with PHPUnit 10 (above). Nothing else in app code is
  expected to change; PHP 8.1 floor is already exceeded by prod (8.4).

**10 → 11 (the runtime hop)**
- PHP floor becomes ^8.2: update composer.json `php` and the `.env.example`
  notes; Dockerfiles already on 8.4 need no change. Local dev machines on
  PHP 8.0/8.1 are the only casualties.
- dompdf 2 → 3 (§3): receipt PDFs (`PaymentController` lines 125/135 —
  `PDF::loadView(...)->download()`, `PDF::loadHTML(...)`) use an API that is
  stable across the jump, BUT dompdf ≥ 2 disables remote resources by
  default and changes font-cache/config defaults — the receipt download is
  a named regression item at this hop.
- Carbon 2 → 3: framework 11 accepts Carbon ^2.67 || ^3; the resolver will
  pick 3. App usage is plain date formatting/diffForHumans (dashboard,
  audit log) — low risk, covered by the dashboard regression item.
- Structure: Laravel 11's *skeleton* replaces kernels with
  `bootstrap/app.php`, but an upgraded app is **not** required to adopt it —
  the classic `app/Http/Kernel.php` + providers layout (this app's custom
  middleware aliases teamSA/teamSAT/teamLibrary/super_admin live there)
  continues to work on 11.x. Adopting the slim structure is an optional
  later cleanup, explicitly out of the critical path.
- `_ide_helper.php` at repo root is generated; delete + regenerate via the
  post-update scripts, never hand-edit across hops.

## 5. Frontend: Mix 2 → Vite — mostly dead weight, retire don't migrate

Measured reality (package.json, webpack.mix.js, blades):
- Stack: laravel-mix ^2.0, vue ^2.5.7, bootstrap ^4, jquery ^3.2, axios
  ^0.18, popper.js ^1.12; Mix compiles `resources/js/app.js` +
  `resources/sass/app.scss`. (node-sass is transitive inside Mix 2 and
  cannot install on a modern Node — but nothing in production runs npm:
  both Dockerfiles are PHP-only.)
- **`mix()` is called in ZERO Blade files**, and the compiled outputs
  `public/css/app.css` / `public/js/app.js` are **not committed** (absent
  from public/). The real UI loads committed static assets via `asset()`
  (Limitless theme under public/assets + public/global_assets, plus
  qs.css). The only consumer of the Mix outputs is the stock laravel/ui
  layout `layouts/app.blade.php`, extended by just 3 leftover scaffold
  views (auth/register, auth/verify, auth/passwords/Dmail) — the app's real
  login is the rebuilt custom page.
- `resources/js/app.js` registers Vue + an ExampleComponent that no live
  page mounts.

Plan: (a) delete webpack.mix.js, resources/js, resources/sass and the Mix
scripts/deps from package.json; (b) repoint those 3 scaffold views to the
app's real layout (or retire them — register/verify are disabled in
practice behind `Auth::routes()` defaults used by this app); (c) **only
if** a future feature needs a JS build, introduce Vite fresh
(`vite.config.js` + `@vite` in the master layout) at that time. Do not
carry Mix 2 forward into the L11 tree, and do not let the frontend block
the PHP hops — they are fully decoupled in this codebase. Effort: 0.5 day,
can be done before Stage 1 or between any two hops.

## 6. Go / No-Go checklist (every hop, in order)

**Before Stage 1 (once):**
- [ ] Git tag `pre-upgrade-l8` on master; full DB dump of any deployment
      holding real data (Render demo is ephemeral — nothing to back up;
      Railway, once live, is the one that matters).
- [ ] **Baseline reproducibility test:** in a throwaway Docker build from
      the *current* lock on PHP 8.4, `composer install` must succeed and
      the app must boot + pass the regression script. (Today this only
      passes via the Dockerfile's ignore-platform-reqs fallback — record
      that as the known-baseline state; it becomes a hard pass from
      Stage 1's fresh lock onward.)
- [ ] Regression environment: a staging Render service (or local Docker)
      running the same entrypoint as production, seeded with demo data.

**Per hop:**
- [ ] Branch `upgrade/l9`, `upgrade/l10`, `upgrade/l11` from the previous
      hop's green commit — never stack unmerged hops.
- [ ] composer.json edits per §3 table → delete lock → `composer update`
      on the target PHP → commit the fresh lock. No `--ignore-platform-reqs`
      anywhere from here on; if resolution needs it, STOP (that's the
      no-go signal).
- [ ] `php -l` sweep + Unit suite green (Money/GradeCalculator 31/31).
- [ ] Full regression script on staging (§7 list) — all items pass,
      including PDF download and the https-asset check.
- [ ] Deploy hop to staging only; production follows after a soak of at
      least a day (or one explicit owner go-ahead).

**No-go triggers (any one stops the line):** composer resolution requiring
platform overrides; Unit suite red; login or dashboard regression item
failing; Mark-Returned-style silent 404 appearing on any POST/PATCH flow
(see docs/LIBRARY_AUDIT.md — cache the lesson: after each deploy, exercise
at least one write flow per module, not just page loads).

## 7. Regression script (the live crawl list — run per hop, in order)

1. Login per role (Super Admin cj@cj.com, Admin, Teacher, Parent — all / cj)
   → each lands on a 200 dashboard with real stats.
2. **Asset scheme check:** dashboard HTML contains 0 `http://` asset refs
   (guards the TrustProxies rewrite).
3. Super Admin dashboard: stat cards, enrollment chart, fee donut,
   attendance donut all render with data.
4. Students: list, open one profile, admit form loads, class filter works.
5. Payments: list, record a payment, **download the PDF receipt** (dompdf
   canary), reset flow blocked/allowed per role.
6. Attendance: mark a class, open the monthly report.
7. Events: create + list + delete (Super Admin).
8. **Library round-trip:** add book → issue → **mark returned** → counts
   back to 0 issued; delete the book (exercises the only PATCH route).
9. Marks: year selector → entry grid loads → tabulation/print view renders.
10. Settings: save system settings; My Account: update profile (PUT flow).
11. Global search (Ctrl+K endpoint) returns a known student.
12. Audit log page loads (Super Admin).

## 8. Effort estimate (focused engineer-days, incl. regression per hop)

| Stage | Work | Estimate |
|---|---|---|
| 0 | Baseline, tag, staging, this plan's checklist setup | 0.5 d |
| 1 | 8→9 (TrustProxies, CORS, ignition, ui 4, dompdf 2, lock regen) | 1.5–2 d |
| 2 | 9→10 (PHPUnit 10 + phpunit.xml migration, Collision 7) | 1–1.5 d |
| 3 | 10→11 (dompdf 3, Carbon 3 check, PHP floor, ide-helper 3) | 1–1.5 d |
| 4 | Mix retirement (§5) — independent, any time | 0.5 d |
| **Total** | | **≈ 4.5–6 d** + soak time between hops |

## 9. First executable step (when the parent green-lights execution)

Stage 0 + Stage 1 as one sitting: tag `pre-upgrade-l8`, branch
`upgrade/l9` from master, apply ONLY the §3 "8→9" column to composer.json
(framework ^9.0, ui ^4.0, dompdf ^2.0, spatie/laravel-ignition ^2.0,
collision ^6.0, tinker ^2.7, faker → require-dev; **remove** fideloper/proxy
and fruitcake/laravel-cors; minimum-stability → stable), rewrite
TrustProxies onto the Illuminate base class, swap the Kernel CORS class,
delete composer.lock, `composer update` inside the PHP 8.4 Docker image,
then run the §7 regression script on staging. Production stays on Laravel 8
until Stage 1 soaks green.
