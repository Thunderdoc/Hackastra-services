# Hackstra — School Management System

Hackstra is a school management system for running a school's day-to-day operations in one place: students, attendance, exams and marks, fees and payments, library, timetables, staff and payroll, and school settings.

Built by Thunderdoc.

## What it covers

Everything below is implemented in the codebase — no feature is listed here on the basis of a plan alone.

### Students

- Quick Admit for essentials-only admission, plus full student records
- Student lists and exports by class and section
- Bulk operations: CSV import of students and bulk delete
- Promotion between classes and sections, and graduated-student records
- Student ID cards, including a student's own ID card view

### Attendance

- Attendance entry, reports, low-attendance (defaulter) lists, and per-student reports, with export, print, and PDF options
- Period attendance (subject-wise): session registers with report, print, PDF, and export views

### Exams and marks

- Exams with marks entry per exam, class, section, and subject
- Tabulation sheets and bulk marksheets, with print views
- Admit cards, including per-student admit cards
- Publish and unpublish exam results
- A configurable marking scheme per exam, class, and subject

### Fees and payments

- Fee payment management, invoices, receipts (including PDF receipts), and exports
- Online payment checkout and callback handling, with a Razorpay webhook endpoint
- A student self-service view of their own fees and receipts

### Library

- Book catalogue and book requests, including marking a book returned
- Library fines, including waiving a fine
- A student self-service view of their own library records

### Timetables

- Timetable management and a timetable generator
- Role-personal timetables — for example, a teacher's own weekly timetable, with print and PDF options

### Staff and payroll

- User account management (including suspend, activate, and terminate)
- Payroll generation, payment, and payslips
- Staff self-service payslips (own records only)
- Staffing planning views

### School operations and self-service portals

- Classes, sections, and subjects
- School events, including a student-facing events view
- Feedback and issue reporting, with an inbox view
- School settings (system name, logo, and current session)
- Authenticated, role-gated dashboards and self-service pages for students and staff — own fees, own library, own ID card, own admit cards, own timetable, and own payslips

## Tech stack

From `composer.json` and `.env.example`:

- PHP `^8.2` (platform `8.3.6` in `composer.json`)
- Laravel `^12.0`
- `laravel/ui` `^4.0`, `laravel/tinker` `^2.9`
- `barryvdh/laravel-dompdf` `^3.0` (PDF output)
- `razorpay/razorpay` `^2.9` (online payments)
- `hashids/hashids`, `guzzlehttp/guzzle`, `fakerphp/faker`
- MySQL, as configured in `.env.example` (`DB_CONNECTION=mysql`, default database `hackstra`)
- PHPUnit `^11.5.3` (dev)

## Run it locally

These steps follow `.env.example` and the standard Laravel workflow:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Then edit `.env` for your local setup — database connection, host, port, database name, username, and password, plus `APP_URL` (the example uses `http://localhost:8000` and timezone `Asia/Kolkata`) — and run:

```bash
php artisan migrate
php artisan serve
```

Then open the `APP_URL` from your `.env` (by default `http://localhost:8000`).

> Security note, from `.env.example` itself: never commit a real `.env`, never use its placeholders in production as-is, and rotate every demo or seeded password before any public deployment.

## Status

Hackstra is under active development. This README describes only what is in the code today. A live demo link is intentionally not included here: the demo URL could not be verified as returning a real app page during this README pass, so no demo link is published until that check passes.

## License

MIT, as declared in `composer.json`.

---

Built by Thunderdoc.
