# lav_sms Complete Project Update Audit

**Project:** `4jean/lav_sms`
**Local path:** `C:\Users\HP VICTUS\Desktop\SMS`
**Prepared:** 21 October 2026
**Purpose:** A complete, practical list of what must be fixed, upgraded, tested, and improved before this school-management system should be treated as a reliable production application.

---

## 1. Executive summary

This is a legacy Laravel school-management system. It can run locally and the main database workflows are present, but it is not yet a modern, production-ready application.

The project needs work in five connected areas:

1. **Runtime and package modernization** — PHP/Laravel/Composer and the Node frontend are from an old generation.
2. **Core workflow correctness** — marksheets, marks, payments, receipts, permissions, and reporting need end-to-end verification.
3. **Data and security hardening** — validation, authorization, database constraints, file uploads, secrets, sessions, and audit history need strengthening.
4. **User experience** — the dashboard and sidebar are being modernized, but most internal pages still use the old interface and inconsistent responsive behavior.
5. **Operations** — automated tests, backups, queues, mail, logging, deployment, monitoring, and recovery procedures are incomplete.

The safest approach is **not** to rewrite everything at once. First stabilize the current Laravel application and its data, then upgrade the runtime and frontend in controlled stages.

---

## 2. Current baseline

### 2.1 What already works or is partially complete

- Local Laravel application starts at `http://127.0.0.1:8000`.
- MySQL is configured locally on port `3307`.
- Migrations and original seeders have been run.
- Login and role-based navigation are available.
- Demo students, exams, marks, payment records, and receipts have been added through an idempotent seeder.
- The Super Admin dashboard has data-backed student, staff, parent, enrollment, and payment metrics.
- The left navigation has been redesigned while preserving existing route conditions.
- Existing feature tests currently pass after correcting the guest redirect expectation.
- The marksheet workflow reaches class, section, student, and academic-year selection.

### 2.2 Known incomplete or unreliable areas

- Final marksheet generation has not been proven end-to-end from a student with marks through printable output.
- Attendance is not represented by a dedicated attendance table/model in the current schema.
- Events/upcoming-events are not backed by a dedicated event source.
- Smart insights are currently informational dashboard states, not a complete analytics system.
- Search in the new top bar is visual only; it is not yet a permission-aware global search.
- Notification and theme controls are visual controls without complete backend behavior.
- Full role/record authorization tests are missing.
- Backup restore has not been verified in an isolated environment.
- Mobile browser and cross-browser regression coverage is incomplete.
- The current frontend build chain is fragile and was not successfully rebuilt with the modern installed Node environment.

---

## 3. Package and runtime audit

## 3.1 PHP runtime

### Current state

The project was written for PHP 7.2-era Laravel 8 and currently accepts:

```json
"php": "^7.2||^8.0"
```

The local setup uses PHP 8.2.33. PHP 8.2 compatibility warnings and old dependency behavior have already appeared.

### Required changes

- Decide and document one supported PHP version for development and production.
- Prefer PHP 8.2 or PHP 8.3 as the immediate stabilization target.
- Do not claim PHP 8.4/8.5 support until the full dependency tree and application test suite pass.
- Enable and verify at least:
  - `openssl`
  - `pdo_mysql`
  - `mbstring`
  - `fileinfo`
  - `tokenizer`
  - `xml`
  - `ctype`
  - `json`
  - `curl`
  - `zip`
  - `gd` or Imagick if image processing is required
- Make the production PHP configuration explicit instead of relying on a developer machine `PHPRC`.
- Add a documented PHP version file or container image so every environment uses the same runtime.

## 3.2 Laravel framework

### Current package

```json
"laravel/framework": "^8.40"
```

Laravel 8 is end-of-life. A major upgrade must be staged because this codebase uses older controller syntax, middleware, helpers, Blade patterns, and third-party packages.

### Recommended upgrade sequence

1. Stabilize on the latest compatible Laravel 8 patch level.
2. Upgrade to Laravel 9 and fix framework deprecations.
3. Upgrade to Laravel 10 and move to PHP 8.1+ conventions.
4. Upgrade to Laravel 11 or the current supported Laravel release after checking package compatibility and the project’s deployment target.
5. Upgrade only one major version at a time, running migrations, tests, route checks, and browser smoke tests after every step.

### Laravel-specific work

- Replace deprecated helper calls and old framework assumptions.
- Review `app/Http/Kernel.php`, exception handling, providers, middleware, and route middleware aliases.
- Review `app/Providers/*` for boot-time behavior and old package hooks.
- Replace direct query logic in controllers with service/query classes where it is complex.
- Add request classes for validation instead of validating large forms inside controllers.
- Add policies/gates for record-level access.
- Review route model binding and hashed identifiers.
- Review all `Auth::user()` usages for null safety and role boundaries.
- Confirm all forms use CSRF protection and correct HTTP methods.
- Remove development-only generators and debug helpers from production.

## 3.3 Composer dependencies

### Current dependency risks

| Package | Current concern | Required action |
|---|---|---|
| `barryvdh/laravel-dompdf` `^0.8.3` | Old PDF integration | Upgrade to a Laravel/PHP-compatible release and verify every receipt/marksheet PDF |
| `fideloper/proxy` `^4.4` | Legacy Laravel package | Remove when the target Laravel version includes trusted proxy support |
| `fruitcake/laravel-cors` `^2.0` | Legacy CORS package | Replace with the framework-supported CORS configuration for the target Laravel version |
| `guzzlehttp/guzzle` `^7.0.1` | Broad but old lower bound | Raise and test against the target PHP/Laravel version |
| `hashids/hashids` `^4.1.0` | Must remain compatible with identifier helpers | Keep only if still required; centralize usage |
| `laravel/framework` `^8.40` | End-of-life | Upgrade in stages |
| `laravel/tinker` `^2.5` | Old lower bound | Upgrade with Laravel |
| `laravel/ui` `^3.0` | Legacy scaffolding | Remove if the application no longer uses its auth scaffolding |
| `barryvdh/laravel-ide-helper` | Development-only generated helpers | Remove from production and avoid generating files during deployment |
| `facade/ignition` | Laravel 8-era error UI | Replace with the error package required by the target Laravel version |
| `nunomaduro/collision` | Version coupled to Laravel/PHP | Upgrade with the framework |
| `phpunit/phpunit` `^9.3.3` | Old test runner | Upgrade with the target PHP version and update tests |

### Composer configuration changes

- Remove `"minimum-stability": "dev"` unless a specific package genuinely requires it.
- Keep `"prefer-stable": true`.
- Keep the lockfile committed and update it intentionally.
- Avoid post-update scripts that modify generated project files unexpectedly:
  - `ide-helper:generate`
  - `ide-helper:meta`
- Separate production install from development install:

```powershell
composer install --no-dev --prefer-dist --optimize-autoloader
```

- Add a CI command that detects abandoned packages and security advisories.
- Run `composer validate`, `composer outdated`, and a dependency vulnerability scan before every release.
- Do not update every package blindly. Upgrade framework and tightly coupled packages in groups, then test.

## 3.4 Node and frontend packages

### Current package risks

The project uses:

```json
"laravel-mix": "^2.0",
"vue": "^2.5.7",
"bootstrap": "^4.0.0",
"axios": "^0.18",
"jquery": "^3.2",
"cross-env": "^5.1",
"popper.js": "^1.12"
```

The old build depends on a legacy Laravel Mix/Webpack chain and the repository contains `node-sass` dependencies. `node-sass` is deprecated and does not build reliably on current Node versions. This is why the project currently relies on existing compiled assets instead of a trustworthy modern build.

### Required frontend migration

1. Replace Laravel Mix/Webpack 2 with Vite.
2. Replace `node-sass` with Dart Sass (`sass` package).
3. Decide whether to:
   - keep Blade plus a small JavaScript layer for a lower-risk migration, or
   - migrate selected screens to Vue 3, or
   - migrate the entire UI to a new frontend architecture.
4. Do not upgrade Vue 2 and Vue 3 in the same uncontrolled change.
5. Upgrade Axios, Bootstrap or the chosen CSS system, Popper, and other direct dependencies together.
6. Rebuild every asset in CI; do not depend on checked-in compiled files as the only build path.
7. Remove unused `resources/js/components/ExampleComponent.vue` and placeholder scaffolding after confirming it is unused.
8. Audit old global assets and remove duplicate libraries, Flash exports, obsolete plugins, and unused demo scripts.

### Suggested modern frontend direction

For the least risky path:

- Blade remains the server-rendered page system.
- Vite compiles Sass and small JavaScript modules.
- Bootstrap 5 or the existing CSS patterns are used consistently.
- Vue is introduced only for interactive screens that genuinely need it.
- DataTables, Select2, FullCalendar, and other plugins are upgraded or replaced one at a time.

### Required frontend quality checks

- `npm ci`
- `npm run build`
- No deprecated `node-sass` dependency
- No browser console errors on login, dashboard, students, marks, marksheet, payments, receipts, and settings
- No HTTP requests to invalid `localhost` asset URLs when the app is served on `127.0.0.1`
- Correct asset URLs under a subdirectory or reverse proxy
- Mobile layout tested at 375px, 768px, 1024px, and desktop widths

---

## 4. Environment and configuration changes

### `.env.example`

The current example contains development defaults and `APP_DEBUG=true`. It must be made safer and clearer.

Required changes:

- Use placeholders, not real passwords.
- Document that production must use `APP_ENV=production` and `APP_DEBUG=false`.
- Document the actual database port only in local setup documentation, not as a production assumption.
- Rename or migrate old mail variables to the configuration expected by the target Laravel version.
- Add:
  - `LOG_LEVEL`
  - `FILESYSTEM_DISK`
  - `QUEUE_CONNECTION`
  - cache/session settings appropriate for production
  - mail sender identity
  - application timezone
  - optional storage, Redis, and queue settings
- Never commit `.env`, database exports containing real users, uploaded private files, or credentials.
- Rotate all demo credentials before any public deployment.

### Configuration review

- Confirm `config/app.php` timezone, locale, URL, and encryption settings.
- Confirm `config/database.php` strict mode and charset/collation.
- Confirm `config/filesystems.php` private versus public files.
- Confirm `config/session.php` secure cookies, same-site policy, lifetime, and domain.
- Confirm `config/mail.php` and prevent mail credentials from appearing in logs.
- Confirm `config/cors.php` allows only required origins.
- Confirm queue, cache, and session drivers for production.

---

## 5. Database and data-integrity work

### Current schema coverage

The database includes users, user types, classes, sections, subjects, student records, exams, marks, grades, exam records, payments, payment records, receipts, timetables, books, dormitories, promotions, and settings.

### Important gaps

- There is no dedicated attendance model/table in the current schema.
- There is no clear event/calendar model powering upcoming events.
- The historical migration dates and naming need normalization.
- Foreign-key coverage and delete behavior need a complete review.
- Academic session is central to marks and payments but must be consistently scoped in every query.
- Money fields need consistent decimal types, currency rules, and transaction handling.
- Duplicate/demo-data prevention should be enforced by database keys where appropriate, not only by seeder logic.

### Required database improvements

- Add explicit foreign keys for every relationship that must be enforced.
- Add indexes for:
  - student admission number
  - user email/login ID
  - academic session
  - class and section
  - exam/class/subject combinations
  - payment/student/session combinations
  - receipt/payment record relationships
- Add unique constraints where duplicates are invalid.
- Define safe `onDelete` behavior for students, marks, payments, receipts, and exams.
- Use database transactions for:
  - recording a payment and receipt
  - publishing/updating marks
  - student admission and related records
  - promotion
- Standardize money columns as fixed-precision decimals. Do not use floating-point arithmetic for currency.
- Add created/updated timestamps consistently.
- Add audit fields for important decisions: actor, timestamp, old value, new value, reason.
- Add an explicit `academic_sessions` table if the current settings-based session cannot guarantee relational integrity.
- Decide whether attendance and events are required features. If yes, design proper tables and permissions rather than showing fabricated dashboard values.

### Data migration requirements

- Take a verified database backup before every schema migration.
- Test migration on a copy of the real database.
- Write rollback or recovery instructions for every destructive migration.
- Create a data dictionary documenting each table, key, relationship, and business meaning.

---

## 6. Authentication, authorization, and security

### Roles that must be tested

- Super Admin
- Admin
- Teacher
- Accountant
- Parent
- Student

### Required authorization work

- Define a permission matrix for every module and action.
- Add policies for student, marks, payment, receipt, exam, class, user, and settings records.
- Test both route access and record-level access.
- Ensure a user cannot access another class, student, payment, or marksheet merely by changing an ID in the URL.
- Ensure teachers can only manage the classes/subjects assigned to them.
- Ensure parents/students can only view their own permitted records.
- Keep Super Admin-only operations separate from ordinary Admin operations.
- Protect destructive actions with authorization, validation, confirmation, and audit logging.
- Add rate limiting to login, password reset, sensitive searches, and public endpoints.
- Require strong passwords and secure password reset behavior.
- Confirm uploaded images/documents have size, MIME, extension, and storage restrictions.
- Store private uploads outside a publicly guessable path where appropriate.
- Review XSS risks in names, comments, notices, receipts, and rich-text fields.
- Review mass assignment `$fillable`/`$guarded` on all models.
- Do not expose exception traces, SQL, credentials, or internal paths in production.

### Security verification

- Run Composer dependency vulnerability checks.
- Run a secret scan over the repository and recent changes.
- Check that `.env`, logs, database dumps, and uploaded private files are ignored.
- Verify HTTPS, secure cookies, CSRF, session invalidation on logout/password change, and trusted proxy settings.
- Add an audit log for login, password change, payment edits, mark changes, approvals, and role changes.

---

## 7. Marks and marksheet workflow

This is the highest-priority functional area because it currently reaches selection screens but the final output has not been proven.

### Required end-to-end path

1. Create/select academic session.
2. Select exam.
3. Select class.
4. Select section.
5. Select subject(s).
6. Load the correct enrolled students.
7. Enter or edit marks.
8. Validate mark ranges and required fields.
9. Save marks transactionally.
10. Calculate total, average, grade, position, pass/fail, and remarks.
11. Generate the student marksheet.
12. Print/download PDF.
13. Verify the same result through tabulation and student views.

### Specific checks

- Trace the route from `marks/select_year/{id}` to the final marksheet route.
- Confirm the year-selection form submits the expected field and route.
- Confirm the selected year is not silently ignored.
- Confirm marks are filtered by the active session, exam, class, section, subject, and student.
- Confirm missing marks are distinguished from zero marks.
- Confirm grade boundaries match the configured grade table.
- Confirm position ties have a defined rule.
- Confirm a marksheet cannot be generated for unauthorized students.
- Confirm PDF output has correct school branding, student identity, subjects, scores, grades, totals, and signatures.
- Add fixtures with complete marks, partial marks, zero marks, failed marks, and missing marks.

### Required tests

- Unit tests for grade and total calculations.
- Feature tests for each selection step.
- Authorization tests for marks and marksheets.
- PDF smoke test.
- Browser test for the complete path using seeded demo data.

---

## 8. Payments, receipts, and financial correctness

### Required behavior

- Create fee definitions and assign them to the correct class/session.
- Record full, partial, and outstanding payments.
- Prevent overpayment unless explicitly supported.
- Use decimal arithmetic and database transactions.
- Generate a unique receipt number.
- Generate a printable/downloadable receipt.
- Correctly calculate total assigned, collected, outstanding, and refunded amounts.
- Preserve an immutable payment history or audit trail.
- Restrict edits and deletions to authorized roles.
- Recalculate dashboard totals from verified payment records.

### Tests

- Full payment.
- Partial payment.
- Multiple installments.
- Outstanding balance.
- Duplicate submission.
- Invalid amount.
- Unauthorized payment update.
- Receipt generation and PDF download.
- Reconciliation between payment records, receipts, and dashboard totals.

---

## 9. Dashboard, search, attendance, events, and reports

### Dashboard

- Keep all metrics backed by database queries.
- Add loading, empty, and error states.
- Use a consistent academic-session filter.
- Avoid presenting unavailable data as if it were zero.
- Make quick actions point only to valid, authorized routes.
- Implement search rather than leaving the search box decorative.
- Implement notification behavior or remove the notification count.
- Implement the theme toggle or remove the control.

### Attendance

The current schema does not provide a dedicated attendance source. Choose one:

1. Implement attendance tables, models, permissions, entry screens, reports, and tests.
2. Remove the attendance dashboard card until the feature exists.
3. Keep a clearly labeled “not configured” state, never a fabricated percentage.

### Events and calendar

- Decide whether events come from timetables, notices, exams, holidays, or a new events table.
- Add an explicit source and permission model.
- Display only real records.
- Add create/edit/delete screens if Super Admins are expected to manage events.

### Reports

At minimum, plan:

- student enrollment report
- attendance report, once implemented
- marks/grade report
- class tabulation
- payment collection report
- outstanding fee report
- receipts report
- teacher/class allocation report
- audit activity report

All reports need filters, authorization, pagination, export limits, and verified totals.

---

## 10. UI/UX and accessibility

- Apply the new visual system consistently to students, users, classes, exams, marks, marksheets, payments, receipts, settings, and reports.
- Remove duplicated legacy layouts and conflicting CSS rules.
- Make tables usable on mobile through responsive scrolling or card layouts.
- Add visible validation errors and success messages.
- Use labels and accessible names for icons and controls.
- Ensure keyboard navigation works for menus, Select2 controls, dialogs, and forms.
- Maintain sufficient color contrast.
- Do not rely on color alone for payment or marks status.
- Add confirmation dialogs for destructive actions.
- Standardize date, currency, number, and academic-session formatting.
- Verify print styles for receipts, marksheets, reports, and tabulation sheets.
- Fix failed asset requests such as absolute `http://localhost/...` URLs when the application is served elsewhere.

---

## 11. Testing and quality gates

### Current gap

The current test suite is very small. Passing two tests does not prove that this application works.

### Required test layers

1. **Unit tests**
   - grade calculations
   - fee totals
   - balance calculations
   - receipt number generation
   - session filtering
2. **Feature tests**
   - login/logout/password reset
   - role-protected routes
   - student CRUD
   - marks entry and update
   - marksheet generation
   - payment and receipt workflows
   - settings and academic session
3. **Authorization tests**
   - every role against every sensitive route
   - record-level ID tampering
4. **Browser tests**
   - login
   - dashboard
   - marksheet
   - payment
   - receipt
   - mobile navigation
5. **Build checks**
   - Composer install
   - migrations from an empty database
   - seeders
   - frontend production build
   - PHP syntax and static analysis
   - dependency vulnerability scan

### Release gate

A release should not be marked ready until:

- tests pass on a clean database;
- frontend assets build from source;
- migrations run from empty and backup copies;
- every role authorization test passes;
- marksheet and payment workflows pass end-to-end;
- backup restoration is demonstrated;
- production configuration has debug disabled;
- browser console has no blocking errors;
- actual test results are recorded.

---

## 12. Backups, deployment, and operations

### Backups

- Define backup frequency and retention.
- Back up database and uploaded files separately.
- Encrypt backups.
- Store a copy outside the application machine.
- Test restoration in an isolated environment.
- Document recovery time and recovery point objectives.
- Record the last successful restore test.

### Deployment

- Use a repeatable deployment method: Docker, managed PHP hosting, or a documented server build.
- Pin PHP, Composer, Node, database, and web-server versions.
- Run migrations with an approval and backup step.
- Build frontend assets in CI.
- Cache config/routes/views only after configuration is valid.
- Run health checks after deployment.
- Never use `APP_DEBUG=true` in production.
- Configure a real queue worker if emails, PDFs, imports, or notifications become asynchronous.

### Monitoring

- Centralize application logs.
- Track failed jobs, login failures, 5xx errors, slow requests, database errors, and storage failures.
- Add uptime and health checks.
- Alert on backup failures and disk/database capacity.
- Avoid logging passwords, tokens, payment secrets, or full personal records.

---

## 13. Recommended implementation order

### Phase 0 — Baseline and safety

- Create a clean database backup.
- Record current PHP, Composer, Node, MySQL, and package versions.
- Run current tests and browser smoke tests.
- Create a staging environment.
- Freeze destructive schema changes until backup/restore is verified.

### Phase 1 — Core correctness

- Finish and test the marksheet workflow.
- Finish and test marks calculations and tabulation.
- Finish and test payments, receipts, balances, and PDFs.
- Add authorization tests.
- Fix invalid asset URLs and blocking browser errors.

### Phase 2 — Data integrity and security

- Add constraints, indexes, transactions, validation, policies, and audit records.
- Add session-aware query rules.
- Add secure upload handling.
- Run dependency and secret scans.

### Phase 3 — Runtime/package stabilization

- Stabilize Composer packages on the chosen PHP version.
- Remove obsolete packages.
- Upgrade Laravel one major version at a time.
- Add CI checks after each upgrade.

### Phase 4 — Frontend modernization

- Move from Mix/Webpack 2 to Vite.
- Replace node-sass with Dart Sass.
- Rebuild assets from source.
- Modernize screens incrementally while keeping workflows stable.

### Phase 5 — Feature completion

- Implement or remove attendance.
- Implement or remove events/calendar.
- Implement real global search and notifications.
- Add reports, exports, and audit history.

### Phase 6 — Operations and release

- Add deployment automation.
- Verify backups and restore.
- Configure monitoring and alerts.
- Run the full release gate.
- Rotate demo credentials and document production access.

---

## 14. Commands to run during the update

Run these from the project directory after the correct PHP/Node tools are installed:

```powershell
# PHP/package baseline
composer validate
composer show --outdated
composer audit

# Application baseline
php artisan about
php artisan route:list
php artisan migrate:status
php artisan test

# Clean database verification
php artisan migrate:fresh --seed

# Frontend baseline
npm ci
npm run build

# Useful production checks
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not run `migrate:fresh` against a real database. It deletes all tables.

---

## 15. Definition of done

The project is ready for a first controlled production release only when all of the following are true:

- [ ] Supported PHP, Laravel, Composer, Node, and database versions are documented.
- [ ] Composer and frontend dependencies install from clean environments.
- [ ] Frontend assets build successfully without node-sass.
- [ ] Login, logout, password reset, and session security are tested.
- [ ] Super Admin/Admin/Teacher/Accountant/Parent/Student permissions are tested.
- [ ] Student records are protected against URL-ID tampering.
- [ ] Marks entry, calculation, tabulation, marksheet, print, and PDF flows pass.
- [ ] Full, partial, outstanding, and receipt payment flows pass.
- [ ] Dashboard totals match manually verified database totals.
- [ ] Attendance and events are either implemented correctly or clearly removed/marked unavailable.
- [ ] Validation and error handling are visible and safe.
- [ ] Database constraints, indexes, and transactions are in place.
- [ ] Backups are created and restored in an isolated environment.
- [ ] Production debug mode is disabled.
- [ ] No secrets are committed.
- [ ] Browser desktop and mobile checks pass.
- [ ] CI runs tests, builds assets, scans dependencies, and reports failures.
- [ ] Deployment and rollback instructions are tested.
- [ ] Actual test results and known limitations are documented for the release.

---

## 16. Practical estimate

Approximate effort for one experienced developer familiar with Laravel and this codebase:

| Work | Estimate |
|---|---:|
| Baseline, backup, and dependency inventory | 0.5–1 day |
| Marks and marksheet correctness | 3–6 days |
| Payments, receipts, and PDF verification | 2–4 days |
| Authorization and security hardening | 3–6 days |
| Database integrity and migrations | 2–5 days |
| Dashboard/report completion | 3–7 days |
| Frontend build migration to Vite | 3–7 days |
| Laravel/PHP staged upgrades | 5–12 days |
| Tests, CI, browser checks, and bug fixing | 5–10 days |
| Deployment, backups, monitoring, and documentation | 3–6 days |

**Compatibility-focused stabilization:** approximately 2–3 weeks.
**Careful production modernization:** approximately 5–8 weeks.
**Large redesign with new attendance, events, reports, and broad UI replacement:** approximately 8–12+ weeks.

These estimates assume the existing business rules are retained. A complete rewrite or major redesign of the business model would take longer.

---

## 17. Final recommendation

Do not start by changing every package or rebuilding the entire interface. The correct order is:

1. Back up and baseline the application.
2. Fix the marksheet workflow.
3. Verify payments and receipts.
4. Add authorization and data-integrity tests.
5. Stabilize Composer/PHP.
6. Replace the broken frontend build chain with Vite and Dart Sass.
7. Complete only the dashboard features that have real data sources.
8. Add deployment, backup, monitoring, and release gates.

This order protects the existing student and financial data while steadily turning the legacy project into a maintainable system.
