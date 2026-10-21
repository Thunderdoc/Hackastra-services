# Prompt: Modernize the Hackstra School Management System

Copy everything below this line and give it to ChatGPT or another coding assistant.

---

I have a school management system called **Hackstra** that is being modernized from the original Laravel School Management System codebase.

Repository:

```text
https://github.com/4jean/lav_sms
```

Local project:

```text
C:\Users\HP VICTUS\Desktop\SMS
```

Local URL:

```text
http://127.0.0.1:8000
```

The application manages students, parents, teachers, administrators, classes, sections, subjects, examinations, marks, marksheets, payments, receipts, dormitories, library records, staff, promotions, reports, and school settings.

## Current technology

- Laravel 8
- PHP
- MySQL
- Composer
- Blade templates
- Vue 2
- Laravel Mix
- Webpack
- Bootstrap 4
- jQuery
- Axios 0.18
- `node-sass`
- DomPDF
- Laravel UI authentication

Important project locations:

- `composer.json`
- `composer.lock`
- `package.json`
- `webpack.mix.js`
- `.env.example`
- `routes/web.php`
- `routes/api.php`
- `app/Models/`
- `app/Http/Controllers/`
- `app/Repositories/`
- `database/migrations/`
- `database/seeders/`
- `resources/views/`
- `resources/js/`
- `resources/sass/`
- `public/`

## Local database

The local database is demo-only:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=lav_sms
DB_USERNAME=lav_sms
DB_PASSWORD=lav_sms_local
```

Existing demo accounts include:

```text
Super Admin
Username: cj
Email: cj@cj.com
Password: cj

Admin
Username: admin
Email: admin@admin.com
Password: cj

Teacher
Username: teacher
Email: teacher@teacher.com
Password: cj

Parent
Username: parent
Email: parent@parent.com
Password: cj

Accountant
Username: accountant
Email: accountant@accountant.com
Password: cj

Student
Username: student
Email: student@student.com
Password: cj
```

All current records are demo data. There is no production data that must be preserved.

## Main objective

Modernize this old school management system into a secure, reliable, maintainable, responsive, and production-ready application while preserving existing school functionality.

Do not blindly rewrite the system. First inspect the codebase, understand the existing modules and relationships, then make incremental and testable changes.

## Required work

### 1. Framework and dependency compatibility

- Upgrade Laravel incrementally.
- Make the project compatible with supported PHP versions.
- Update Composer dependencies and regenerate `composer.lock`.
- Remove or replace abandoned packages.
- Resolve PHP deprecation warnings.
- Ensure Artisan, migrations, seeders, queues, cache, sessions, storage, and scheduled tasks work.
- Do not use unsafe version overrides unless absolutely necessary.

### 2. Frontend modernization

The old frontend uses Laravel Mix, Webpack, Vue 2, Bootstrap 4, jQuery, Axios 0.18, and `node-sass`.

- Replace `node-sass` with Dart Sass.
- Prefer Vite over Laravel Mix where practical.
- Upgrade Vue to a supported version or migrate to another maintainable frontend approach.
- Upgrade Axios and Bootstrap.
- Remove unused jQuery dependencies where possible.
- Make the build work on current Node.js versions.
- Preserve existing pages and workflows.
- Ensure all assets build correctly.

### 3. User interface

Create a clean, responsive school administration interface for:

- Super administrators
- Administrators
- Teachers
- Students
- Parents
- Accountants
- Librarians

Improve the dashboard with:

- Responsive sidebar and top navigation
- User profile menu
- Search and notifications
- Breadcrumbs
- Mobile layout
- Optional dark/light mode
- Clean cards and tables
- Charts for marks and payments
- Empty states
- Loading indicators
- Consistent forms, modals, alerts, and pagination

The design should look like a modern school-management SaaS platform, not an old 2018 admin template.

### 4. Authentication and authorization

Review and improve:

- Login and logout
- Password reset and password change
- Session regeneration
- Login throttling
- Strong password validation
- Optional two-factor authentication for administrators
- Email verification where appropriate
- Secure cookies and CSRF protection
- Authorization middleware
- Laravel Policies and Gates
- Centralized role and permission management

Roles should include:

- Super Admin
- Admin
- Teacher
- Student
- Parent
- Accountant
- Librarian

Enforce these rules:

- Students see only their own records.
- Parents see only their linked children.
- Teachers manage only assigned classes and subjects.
- Accountants manage payments but not system permissions.
- Only authorized administrators change school settings.
- Only authorized staff modify marks.

### 5. Demo data

Create a separate, idempotent demo seeder. It must be safe to run repeatedly and must not create duplicates.

Include:

- Several students
- Multiple classes and sections
- Multiple subjects
- Teachers assigned to subjects
- Parent accounts linked to students
- Administrators, accountants, and librarians
- At least two examinations
- Marks for multiple students and subjects
- Grades and remarks
- Payment types
- Full and partial payments
- Outstanding balances
- Receipts
- Timetable records
- Notices
- Dormitory data where supported
- Books and borrowing records where supported

The demo data must allow testing:

- Student dashboard
- Teacher dashboard
- Parent dashboard
- Admin dashboard
- Marks management
- Student marksheet
- Printable marksheet
- Payment management
- Payment receipt
- Outstanding balance
- Class and section management
- Subject management
- Examination management
- User management

Use clearly identifiable demo names and known demo passwords. Never use real credentials.

### 6. Marks and marksheets

Improve the marks module with:

- Examinations by term and academic session
- Subject/class assignments
- Teacher assignments
- Continuous assessment marks
- Examination marks
- Automatic totals
- Automatic averages
- Automatic grades and remarks
- Subject positions
- Overall student position
- Class averages
- Term and cumulative results
- Clear missing/absent marks
- Validation of mark ranges
- Duplicate prevention
- Authorized editing
- Optional audit history

Marksheets must include:

- School name and logo
- Student name and admission number
- Class and section
- Academic year and term
- Subjects
- Continuous assessment
- Examination score
- Total
- Grade and remark
- Position and average
- Attendance where available
- Teacher and principal comments
- Generation date
- Print and PDF download actions

Ensure A4 printing works correctly.

### 7. Payments and receipts

Improve payments with:

- Payment categories
- Fee definitions
- Academic sessions
- Class-specific fees
- Student-specific records
- Full and partial payment
- Outstanding balances
- Payment status
- Payment methods
- References and dates
- Receipt generation and PDF download
- Payment history
- Search and filters
- Payment reports
- Outstanding balance reports
- Class and student summaries

Prevent:

- Negative amounts
- Invalid references
- Duplicate payments
- Unauthorized edits
- Unauthorized receipt deletion
- Overpayment unless explicitly supported

### 8. Database quality

Review every migration and model for:

- Missing foreign keys
- Incorrect relationships
- Missing indexes
- Inconsistent types
- Duplicate records
- Missing unique constraints
- Unsafe nullable columns
- Incorrect cascade rules
- Poor naming
- Missing timestamps
- Missing audit fields

Add proper relationships between users, students, parents, classes, sections, subjects, teachers, exams, marks, grades, payments, receipts, timetables, books, and requests.

Use database transactions for payments, receipts, marks updates, promotions, user deletion, and bulk operations.

### 9. Validation and security

Review controllers, requests, models, routes, and views.

Add:

- Form Request validation
- Authorization checks
- Upload validation
- File-size and MIME-type limits
- Safe filenames
- Secure storage
- SQL injection protection
- XSS protection
- CSRF protection
- Rate limiting
- Output escaping
- Mass-assignment protection
- Safe error messages

Never expose `.env`, password hashes, debug traces, database credentials, internal paths, or unauthorized student data.

### 10. Reports and PDFs

Modernize PDF/report generation for:

- Student marksheets
- Class result sheets
- Tabulation sheets
- Payment statements
- Payment receipts
- Student profiles
- Attendance reports
- Staff reports
- Outstanding payment reports

PDFs must support the school logo, clean page breaks, A4 layout, headers, footers, print, and download.

### 11. Testing

Add automated tests for:

- Login and logout
- Password reset
- Role access
- Student creation
- Parent-child relationships
- Teacher permissions
- Marks creation and calculation
- Grade calculation
- Marksheet generation
- Payment creation
- Partial payments
- Outstanding balances
- Receipt generation
- PDF generation
- Unauthorized access
- Upload validation
- Database relationships

Use PHPUnit or Pest, factories, a test database, feature tests, unit tests, and browser tests where appropriate.

### 12. Code quality and CI

Add:

- Laravel Pint or PHP CS Fixer
- PHPStan or Larastan
- ESLint
- Prettier
- TypeScript where useful
- Composer audit
- npm audit
- GitHub Actions CI

CI should run dependency validation, migrations, tests, static analysis, security checks, and the frontend production build.

### 13. Configuration and deployment

Improve `.env.example` with safe placeholders and validate required environment variables.

Production must use:

```env
APP_ENV=production
APP_DEBUG=false
```

Add Docker support for PHP, Nginx, MySQL, Node.js, queue workers, scheduler, and optional Redis/mail testing.

Document deployment using Docker, Laravel Forge, Laravel Vapor, Azure App Service, AWS, DigitalOcean, or a VPS.

Include HTTPS, migrations, storage linking, queues, scheduler, backups, monitoring, log rotation, health checks, and rollback instructions.

Do not use `php artisan serve` in production.

### 14. Documentation

Update the README with:

- Project overview
- Requirements
- Installation
- Environment setup
- Database setup
- Demo seeding
- Demo login credentials
- Development server
- Frontend builds
- Tests
- PDFs
- Queues
- Scheduler
- Deployment
- Backup and restore
- Troubleshooting
- Security notes

## Implementation rules

1. Inspect the existing code before modifying it.
2. Preserve working functionality.
3. Make small, testable changes.
4. Do not rewrite the whole system without justification.
5. Do not remove modules without replacement.
6. Do not expose credentials or sensitive data.
7. Do not use fake success responses.
8. Show exact errors when something fails.
9. Use migrations for database changes.
10. Add tests for behavior changes.
11. Keep demo data separate from production data.
12. Make seeders idempotent.
13. Follow Laravel conventions.
14. Avoid deprecated packages.
15. Explain breaking changes.
16. Verify the application after every major phase.

## Required response format

Before changing code, provide:

1. Current architecture summary.
2. Major risks.
3. Dependency compatibility report.
4. Database and module inventory.
5. Modernization phases.
6. Estimated work per phase.
7. Files that will be changed.
8. Decisions requiring approval.

After each phase, report:

1. Files changed.
2. Features completed.
3. Tests executed.
4. Test results.
5. Remaining warnings.
6. Next recommended phase.

The final result must be a secure, modern, maintainable school management system with working students, parents, teachers, administrators, marksheets, payments, receipts, role-based access, demo data, tests, documentation, and reliable deployment.

---
