<div align="center">

# 🏫 Hackstra — School Management System

**Admissions to alumni — one platform that takes a school fully digital.**
Attendance, exams, fees, library, documents and communication in one place, with nothing left on pen and paper.

[![Laravel 11](https://img.shields.io/badge/Laravel-11-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green?style=flat-square)](LICENSE)
[![Live Demo](https://img.shields.io/badge/Live%20Demo-hackastra.onrender.com-blue?style=flat-square)](https://hackastra.onrender.com)

Developed and maintained by **[Ankit Kumar](https://github.com/Thunderdoc)**.

![Hackstra login](docs/screenshots/login.png)
*The split-screen sign-in page — each school's name, logo and session are configured in Settings.*

</div>

---

## 📸 Screenshots

| | |
| --- | --- |
| ![Platform Overview](docs/screenshots/platform-overview.png) | ![School Dashboard](docs/screenshots/super-admin-dashboard.png) |
| **Platform Overview** — the Super Admin's cross-school command view: user accounts by role, audit activity and a configuration checklist. | **School Dashboard** — enrolment, fee collection and today's attendance at a glance, with the school calendar. |
| ![Admin Dashboard](docs/screenshots/admin-dashboard.png) | ![Teacher Dashboard](docs/screenshots/teacher-dashboard.png) |
| **Admin Dashboard** — the school operator's daily board: totals, quick actions, enrolment trend, fee collection and attendance donut. | **Teacher Dashboard** — my subjects, my classes, today's timetable and one-tap attendance. |
| ![Attendance Register](docs/screenshots/attendance-register.png) | ![Payments](docs/screenshots/payments.png) |
| **Attendance Register** — period attendance with tappable Present / Absent / Late controls, a topic-taught log and a locked, final submission. | **Collect Fee** — find any student by name, admission number or class and jump straight to their payment records. |
| ![Student Marksheet](docs/screenshots/marksheet.png) | ![My Account](docs/screenshots/my-account.png) |
| **Student Marksheet** — component-wise scores (Theory / Internal / Viva) under a configurable marking scheme, ready to print. | **My Account** — one-screen profile, password, notifications and preferences, the same for every role. |

| Mobile | |
| --- | --- |
| ![Admin dashboard on mobile](docs/screenshots/mobile-dashboard.png) | ![Attendance register on mobile](docs/screenshots/mobile-attendance.png) |
| *The Admin dashboard on a phone — fully responsive, no sideways scrolling.* | *Taking the register on a phone — big touch targets, same locked submission.* |

---

## ✨ Features

**Platform & roles**
- Seven role portals — Super Admin, Admin, Teacher, Student, Parent, Accountant, Librarian — each with its own home, menu and permissions
- Platform Overview for the Super Admin (platform owner) across schools, plus a per-school dashboard
- Role-based data scoping enforced on the server: a teacher sees only their own classes, a parent only their own children, an accountant only the fee office

**Students & admissions**
- Student records with photos, classes and sections
- **Quick Admit** — admit a student with just the essentials and complete the profile later
- Bulk CSV import with a downloadable template, bulk actions, promotions, graduations and printable student ID cards

**Attendance**
- Period-wise attendance register with Present / Absent / Late, topic-taught log and final locked submission
- Monthly attendance reports (screen, print and PDF from one set of figures) and a low-attendance (below 75%) list

**Exams, marks & documents**
- Exams with fully configurable marking schemes — components such as Theory, Internal Assessment and Viva, each with its own maximum, per school / exam / subject
- Marks entry with a live class summary; every mark change is written to the audit log with its old and new values
- A publish switch controls when results become visible to students and parents
- Printable term marksheets, an Annual / Cumulative Report Card, and admit cards
- **Digital documents** — the Principal's signature and the school seal are uploaded once in Settings and printed on documents automatically
- Fee defaulters' marksheets and admit cards are withheld (students & parents) until dues are cleared — and released automatically on payment

**Fees & payments**
- Collect Fee at the counter: itemised fee heads, full or part payment, instant receipt with amount in words
- **Online payment** — parents pay from their portal through the school's own Razorpay account; the money goes straight to the school, never through Hackstra
- Fee Defaulters report that prints professionally on exactly one page, a collection report with per-day breakdown, and CSV exports

**Library**
- Book catalogue, issue/return loans and student book requests, with a librarian dashboard built from the live ledger

**Communication & AI**
- Circulars with audience targeting and priority levels (print + PDF), a notification bell, and platform mail
- **Ask Hackstra** — an AI assistant that answers school questions from the school's own knowledge base and circulars, with citations, scoped to what the asker's role is allowed to see
- School feedback inbox for parents and students, forwarded to the platform owner

**Office & insight**
- Role-personal timetables, staff payroll with printable one-page payslips (single or combined PDF)
- A full audit log of security-relevant actions (logins, payments, deletions, mark changes with values)
- Global search (Ctrl + K), dark mode by default, and professional print layouts with the school header on every document

---

## 🛠 Tech stack

- Laravel 11 · PHP 8.4 · Blade · Bootstrap 4 (Limitless) + a custom design system
- MySQL / MariaDB in production; SQLite used for local development
- Docker images for demo (bundled database) and production (external MySQL)
- Razorpay for online fee payment (each school connects its own account)

## 🚀 Running it

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

## 🔑 Demo logins

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

## 🔒 Security

If you discover a security vulnerability in Hackstra, please report it privately to the project owner before publicly disclosing it. Do not include passwords, tokens, or private student data in a public issue. See [SECURITY.md](SECURITY.md).

## 📄 License & ownership

Hackstra is released under the MIT License — see [LICENSE](LICENSE).

© Ankit Kumar. Developed and maintained by Ankit Kumar; originally based on an open-source school-management codebase, extensively rebuilt.
