# Hackstra — School Management System

**Hackstra** is a complete school management system built to take a school fully digital — admissions, attendance, exams, fees, library and communication in one place, with nothing left on pen and paper. Every document a school prints today — marksheets, receipts, admit cards, ID cards, payslips — is generated, printed and exported from inside the app.

Developed and maintained by **Ankit Kumar**.

**Login** (`docs/screenshots/login.png`)
*The split-screen sign-in page — each school's name, logo and session are configured in Settings.*

## Screenshots

*Screenshot files live in `docs/screenshots/` and are delivered with each release build. Add the PNG files to that folder and these entries display inline.*

**Platform Overview** (`docs/screenshots/platform-overview.png`)
*Platform Overview — the Super Admin's cross-school command view: user accounts by role, audit activity and a configuration checklist.*

**School Dashboard** (`docs/screenshots/super-admin-dashboard.png`)
*School Dashboard — enrolment, fee collection and today's attendance at a glance, with the school calendar.*

**Admin Dashboard** (`docs/screenshots/admin-dashboard.png`)
*Admin home — the school operator's daily board: totals, quick actions, enrolment trend, fee collection and attendance donut.*

**Teacher Dashboard** (`docs/screenshots/teacher-dashboard.png`)
*Teacher home — my subjects, my classes, today's timetable and one-tap attendance.*

**Attendance Register** (`docs/screenshots/attendance-register.png`)
*Period attendance register — tappable Present / Absent / Late controls, a topic-taught log and a locked, final submission.*

**Payments** (`docs/screenshots/payments.png`)
*Collect Fee — find any student by name, admission number or class and jump straight to their payment records.*

**Student Marksheet** (`docs/screenshots/marksheet.png`)
*Student marksheet — component-wise scores (Theory / Internal / Viva) under a configurable marking scheme, ready to print.*

**My Account** (`docs/screenshots/my-account.png`)
*My Account — one-screen profile, password, notifications and preferences, the same for every role.*

| Mobile | |
| --- | --- |
| **Admin dashboard on mobile** (`docs/screenshots/mobile-dashboard.png`) | **Attendance register on mobile** (`docs/screenshots/mobile-attendance.png`) |
| *The Admin dashboard on a phone — fully responsive, no sideways scrolling.* | *Taking the register on a phone — big touch targets, same locked submission.* |

## Features

**Platform & roles**
- Seven role portals — Super Admin, Admin, Teacher, Student, Parent, Accountant, Librarian — each with its own home, menu and permissions
- Platform Overview for the Super Admin (platform owner) across schools, plus a per-school dashboard
- Role-based data scoping enforced on the server: a teacher sees only their own classes, a parent only their own children

**Students**
- Student records with photos, classes and sections; bulk CSV import with a downloadable template
- Bulk actions, promotions, graduations and printable student ID cards

**Attendance**
- Period-wise attendance register with Present / Absent / Late, topic-taught log and final locked submission
- Attendance reports, CSV export and a low-attendance (below 75%) defaulter list

**Exams & marks**
- Exams with configurable marking schemes (components such as Theory, Internal Assessment and Viva that sum to 100)
- Marks entry per subject, class tabulation sheets, and a publish switch that controls when results become visible to students and parents
- Printable term marksheets and an Annual / Cumulative Report Card
- Admit cards that students and parents can download and print

**Fees & payments**
- Collect Fee: search by student name, admission number or anything, record full or part payment, print the receipt
- Invoices, payment receipts with amount in words, a collection report and a Fee Defaulters report that prints on one page
- CSV exports for payment records

**Library**
- Book catalogue with covers, issue/return loans and student book requests

**Communication**
- Circulars with audience targeting (staff / parents / students) and priority levels, a notification bell with upcoming events, and staff messages

**Office & insight**
- Timetables per class, staff payroll with status tracking and printable payslips
- Audit log of security-relevant actions (logins, payments, deletions, mark changes)
- Global search (Ctrl + K), dark mode, and professional print layouts with school header/footer on every document

## Tech stack

- Laravel 11 · PHP 8.4 · Blade · Bootstrap 4 (Limitless) + custom design system
- MySQL / MariaDB in production; SQLite used for local development
- Docker images for demo (bundled database) and production (external MySQL)

## Running it

### Demo (single container, Render-style)

```bash
docker build -f Dockerfile.render -t hackstra-demo .
docker run -p 8080:80 -e APP_KEY=<your-app-key> hackstra-demo
```

The demo image bundles its own MariaDB and re-seeds demo data on every start — data is intentionally ephemeral.

### Production (Docker + external MySQL)

```bash
docker build -t hackstra .
docker run -p 8080:80 \
  -e APP_KEY=<your-app-key> -e APP_ENV=production -e APP_DEBUG=false \
  -e DB_HOST=<mysql-host> -e DB_DATABASE=<db> -e DB_USERNAME=<user> -e DB_PASSWORD=<pass> \
  hackstra
```

Migrations run automatically on boot. Set `SEED_DEMO=true` **only** for demos — never on a real school deployment. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the full Railway/Render walkthrough.

### Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## Demo logins

Available after seeding (`php artisan db:seed`, or any `SEED_DEMO=true` deployment). **Demo-only — change or remove every one before real use.**

| Role | Email | Password |
| --- | --- | --- |
| Super Admin | superadmin@hackstra.app | hackstra |
| Admin | admin@hackstra.app | hackstra |
| Teacher | teacher@hackstra.app | hackstra |
| Parent | parent@hackstra.app | hackstra |
| Accountant | accountant@hackstra.app | hackstra |
| Librarian | librarian@hackstra.app | hackstra |
| Student | student@hackstra.app | hackstra |

The demo school is **Hackstra Academy**. Additional sample accounts are also seeded (extra teachers `teacher1–3@teacher.com`, password `teacher`; extra students, password `student`).

## Security

If you discover a security vulnerability in Hackstra, please report it privately to the project owner before publicly disclosing it. Do not include passwords, tokens, or private student data in a public issue. See [SECURITY.md](SECURITY.md).

## License & ownership

Hackstra is released under the MIT License — see [LICENSE](LICENSE).

© Ankit Kumar. Developed and maintained by Ankit Kumar; originally based on an open-source school-management codebase, extensively rebuilt.
