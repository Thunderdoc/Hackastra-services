# Hackstra Modernization — Working Plan

Source audit: `PROJECT_UPDATE_AUDIT.md` (21 Oct 2026). Target dashboard:
`docs/reference/dashboard-target-super-admin.png`.
This file tracks what has actually been done, in the audit's safe order.
Do not mark an item done without a test or a screenshot proving it.

## Baseline (verified 21 Oct 2026 from the repo itself)

- Branch `master` @ `7962ade` "Brand and modernize Hackstra school platform"
- Laravel `^8.40`, PHP constraint `^7.2||^8.0`, local PHP 8.2.33
- Frontend: Laravel Mix 2 / Webpack, Vue 2.5.7, Bootstrap 4, Axios 0.18,
  jQuery 3.2, Popper 1.12, node-sass chain (deprecated, fragile build)
- 97 Blade views, 172 web routes, 28 models
- Tests: only the two stock `ExampleTest` files — not evidence of correctness

## Phase 0 — Baseline & safety  ✅ started

- [x] `.env.example` hardened: no default DB password, production checklist,
      `LOG_LEVEL`, `FILESYSTEM_DISK`, `SESSION_SECURE_COOKIE`, mail sender,
      timezone, both `MAIL_DRIVER` and `MAIL_MAILER` for the staged upgrade
- [x] `.gitignore`: block `*.sql`/dumps/backups/private uploads, un-ignore
      `storage/` framework structure and `.editorconfig`
- [ ] Take a verified DB backup before any schema migration (deployment step)
- [ ] Record `composer validate`, `composer outdated`, `composer audit` output in CI

## Phase 1 — Core correctness

- [x] Dashboard fee totals come from `payment_records` joined to `payments`
      (`amt_paid` = collected, payment amount = owed, pending = owed − collected),
      formatted in Indian numbering (₹ 1,00,000) via `App\Helpers\Money`
- [x] Recent Students shows admission date; Recent Payments shows date
- [x] Enrollment trend rendered as the target's line/area chart (SVG, real counts)
- [ ] Marks/marksheet end-to-end: session → exam → class → section → subject →
      marks → grade/total/position → print/PDF → tabulation (audit §7 checklist)
- [ ] `App\Support\GradeCalculator` unit-tested; wire into marks flow after tests pass
- [ ] Payments: full / partial / outstanding / duplicate / invalid-amount tests (§8)

## Phase 2 — Security & data integrity

- [x] Policies added for StudentRecord / PaymentRecord / Mark and registered
      (enforcement in controllers is the next step — see policy classes)
- [x] Session `secure` cookie already env-driven; production must set it `true`
- [ ] Form Request classes for the large controller forms
- [ ] Indexes / foreign keys / transactions for payments, marks, promotion (§5)
- [ ] Audit log for login, payment edits, mark changes, role changes (§6)
- [ ] Dependency + secret scan in CI

## Phase 3 — UI/UX (target: the dashboard screenshot)

- [x] Super Admin dashboard rebuilt to the target layout: greeting + date/session,
      4 stat cards with real period deltas, Quick Actions (7), School Calendar,
      Enrollment Trend line chart, Fee Collection donut (₹), Today's Attendance,
      Recent Students, Recent Payments, Upcoming Events, Smart Insights
- [x] Login page rebuilt: branded split card (sidebar navy + dashboard blue),
      per-error messages (the old page escaped its `<br>` separators)
- [x] Same panel/table/form system rolled to every inner page via one global
      layer in `qs.css` (cards, tables, tabs, forms, buttons, dropdowns,
      modals, DataTables, pagination) — all 97 Blade screens inherit it
- [ ] Responsive pass at 375 / 768 / 1024 / desktop (CSS breakpoints added;
      needs a real browser pass); print styles added for the app shell,
      marksheet/receipt/tabulation sheets still to verify

## Phase 4 — Runtime & frontend build (staged, never blind)

1. Stabilize Composer on PHP 8.2 (remove `fideloper/proxy`, legacy CORS, etc.)
2. Laravel 8 → 9 → 10 → 11/12, one major at a time, tests after each step
3. Laravel Mix/Webpack 2 → Vite, `node-sass` → Dart Sass (`sass`)
4. Keep Blade server-rendered; Vue only where a screen genuinely needs it

Nothing in this phase is done yet — by design. The current `package.json`
and Laravel version are untouched in this change so the app keeps running.

## Phase 5 — Feature completion (real data or honest empty state)

- [x] `school_events` table + `SchoolEvent` model — Upcoming Events and the
      calendar now read real records (exams from the timetable are merged in)
- [x] `attendances` table + `Attendance` model — Today's Attendance reads real
      records for today; with no records it shows 0 / "No attendance recorded
      today", exactly like the target screenshot's Smart Insight
- [x] Global search is real: `GET /dashboard/search` (permission-aware,
      Ctrl+K focuses the top-bar search) — students, teachers, classes, payments
- [x] Attendance entry screen: sidebar → Attendance (class + date, bulk
      present/absent/late, transactional save, tampered class IDs ignored)
- [x] Events management screens: sidebar → School Events (CRUD for TeamSA,
      delete Super Admin-only, validated dates/types)
- [ ] Attendance reports / exports

## Phase 6 — Operations

- [x] CI (`.github/workflows/ci.yml`): PHP 8.2, composer validate/install,
      Unit testsuite, `composer audit`; frontend job honestly reports the
      legacy build state instead of faking green (gate after Vite migration)
- [ ] CI: secret scan
- [ ] Backups: schedule, encryption, off-machine copy, **tested restore**
- [ ] Deployment doc: pinned PHP/Composer/Node/MySQL versions, health check,
      `APP_DEBUG=false`, config/route/view cache after validation
- [ ] Rotate all demo credentials before any public deployment

## Definition of done

See audit §15. In short: clean install works, tests pass on a clean DB,
marksheet + payment flows pass end-to-end, every role is authorization-tested,
no secrets committed, no fake dashboard data, restore is demonstrated.
