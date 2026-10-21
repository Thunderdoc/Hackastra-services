# Hackstra — Product Case Study, Competitive Research & Gap Analysis

**Workstream:** Loop 7D (research only — no code changed)
**Date:** 2026-10-21
**Branch:** `feature/loop7-research`
**Purpose:** Hackstra is a commercial product the owner intends to *sell* to schools.
This study answers three questions with evidence: (1) what do the top school
management products actually ship, role by role? (2) what does our codebase
verifiably have today? (3) what should the next loops build, in what order?

**Method.** Competitor claims below come from web research run on 2026-10-21
(vendor pages and independent listing/review sites; sources in §10). Every
claim about *our* codebase was verified by reading the code in this repo
(routes, controllers, models, menus, seeders, `composer.json`) — file paths are
cited so any agent can re-check in seconds. Where a Loop 7 workstream was
mid-flight while this study was written, that is stated explicitly.

---

## 1. The market: what the top products ship

### 1.1 Fedena (Foradian, India — closest comparable: school ERP, India-first)
Fedena markets "50+ modules" and role dashboards for teachers, non-teaching
staff, students, parents and management. Verified module families from its
feature tour and listings: admissions & student information, attendance,
timetable, examinations + gradebook + report cards, finance (fee structures
allotted per student, expenses, financial reports), HR with **payroll and
payslips**, library (cataloguing, circulation, reservations/renewals, fines,
barcode/RFID), hostel, transport (routes, vehicle tracking, fuel/maintenance),
event calendar, internal messaging + discussion/task/poll plugins, custom
report builder, custom form builder, API access, payment-gateway integration,
online exams, applicant registration, alumni, audit, automatic timetable
generator, gate management. Sources: fedena.com/feature-tour,
saasworthy.com/product/fedena, apibit.com/product/fedena.

### 1.2 Teachmint (India — mobile-first school platform)
Ships an integrated SIS + LMS: admissions, student/staff attendance, fee
management with **online payment**, custom fee reports, homework, study
materials, teacher–student chat, two-way live classes with recordings,
tests/assessments, report cards, automated timetable, transport, hostel,
library, expense management, ID-card generation, website builder, parent and
student apps. Its pricing tiers gate exactly the features buyers ask about
first: fees, report cards, ID cards, transport. Sources:
goodfirms.co/software/teachmint, softwarefinder.com/lms/teachmint.

### 1.3 Classe365 (global — modular SIS + LMS + CRM)
Core: CRM & admissions pipeline (inquiry → application → enrolment), SIS
(demographics, academics, attendance, behaviour, health records), LMS (course
creation, multimedia content, assessments, discussion forums), fees/billing/
invoicing, financial accounting with **double-entry general ledger, chart of
accounts, P&L/balance sheet**, scheduling, student/parent/faculty portals,
 alumni & fundraising, e-commerce, degree audit, reporting/analytics, plus a
large integration marketplace (Moodle, QuickBooks, Google/Microsoft, payment
gateways, open API). Sources: classe365.com, capterra.com compare pages,
saashub Munis-vs-Classe365 feature list.

### 1.4 PowerSchool SIS (US — enterprise K-12 SIS benchmark)
The SIS reference product: configurable student records, PowerTeacher Pro
gradebook (standards-based + traditional), attendance incl. behaviour/health/
graduation tracking, master scheduler, enrollment management with online
payments, parent/student portal with real-time grades/attendance, advanced
ad-hoc reporting, state/compliance reporting, transcripts, discipline,
special education, fee management, and an API/integration ecosystem.
Schoology (PowerSchool's LMS) adds the learning layer: courses with folders
and completion rules, assignments with due dates and reminders, 18 question
types, rubrics, discussions, Google/Microsoft integrations, and parent views
of course content and progress. Sources: g2.com PowerSchool SIS features,
softwaresuggest.com/powerschool-sis, getapp.com PowerSchool features,
teachers-blog.com Schoology guide, soprime.com Schoology LMS.

### 1.5 Blackbaud Education Management (US — private-school premium)
A "total school solution": enrolment management (custom applications,
checklists, self-scheduled tours/interviews, funnel reporting), tuition &
billing (instalment plans, multiple payment methods, mobile payment portal,
**true accounts-receivable subledger posting into the GL**), SIS with a 360°
student view, LMS (assignments, discussions, 24/7 access), school website
CMS, fundraising/alumni, fund accounting, financial-aid management, merchant
services. Starting price point (~US$21,000/yr on GetApp) shows how much of
the value sits in admissions + tuition, not in record-keeping. Sources:
blackbaud.ca K-12 solutions, appvizer.com Blackbaud review, getapp.ca.

### 1.6 MyClassCampus (India — 40+ module ERP, direct price competitor)
Markets: fees management that is "completely dynamic" — fee categories,
structures, **discounts**, auto fee reminders on due dates, fee reports
(payment/due/discount), cheque-status tracking, designed receipts, online fee
payment with student passbook and settlement reports; exam scheduler with
auto-generated result cards and subject/class/teacher-wise result analysis;
homework module, material upload, certificates generator (ID cards, bonafide,
leaving/migration certificates with dynamic designs), HRMS (staff attendance,
leave management, holidays), transport with GPS buses, biometric attendance,
grievance cell, gate-pass management, digital library, live quiz, birthday
SMS. Sources: saasworthy.com/product/myclasscampus,
softwaresuggest.com/blog/myclasscampus-review.

### 1.7 Edsby (K-12 engagement specialist)
Edsby's whole pitch is the *unified* record: a parent logs in and sees
current grades, recent attendance, upcoming assignments, teacher messages
and announcements in one place, **live** — attendance taken in period 1 fires
an automatic parent notification; a posted grade appears in the parent view
with no export step. Teachers get gradebook + course plans + a "Panorama"
360° student view (grades, attendance, discipline, IEPs, report cards,
observations, parent contact rights). Admins get district analytics and
at-risk student identification. Sources: edsby.com/features,
edsby.com/how-edsby-helps/teacher, edsby.com family-engagement guide.

### 1.8 The pattern across all seven
1. **Every** top product has a real parent/student portal — not a login that
   shows a menu, but live attendance, marks, fees and messages.
2. **Every** top product collects fees *online* with instalments, due dates,
   discounts and defaulter follow-up. Fees are the ROI story used to sell.
3. **Every** top product has a communication layer (in-app messaging and/or
   SMS/email/push) — Edsby and Teachmint lead with it.
4. Most have an LMS layer (homework/assignments/materials at minimum;
   Teachmint, Schoology, Classe365, Fedena-Learn go much further).
5. Admissions is a *pipeline* (inquiry → application → enrolment), not a
   data-entry form.
6. HR (staff records, leave, payroll) and transport appear in almost every
   Indian competitor's module list — buyers' checklists include them.
7. Mobile apps for parents/teachers are table stakes in India (Teachmint,
   MyClassCampus, Fedena all ship them).

---

## 2. Per-role use-case matrix

Roles in our system (verified in `database/seeders/UserTypesTableSeeder.php`
and `app/Helpers/Qs.php`): `super_admin`, `admin`, `teacher`, `parent`,
`accountant`, `student`, plus `librarian` in code (middleware `teamLibrary`,
`Qs::getTeamLibrary()`, librarian menu) though its user-type row is commented
out of the seeder. "Educator" in buyer language = our **Teacher** role (in
Indian products "faculty/educator" screens are the teacher screens); we do not
need a separate role, we need the teacher surface to be complete.

### 2.1 Super Admin (school owner / principal / trust office)
- **Daily jobs:** see the whole school at a glance; approve/setup academic
  structure (sessions, classes, sections, subjects, grading); manage all
  user accounts and roles; control result publication (exam lock); review
  money (collections vs dues); review security/audit trail; configure the
  school (name, logo, session, term dates).
- **Expected screens:** KPI dashboard (students, staff, fee collection %,
  today's attendance %, events), global search, settings, audit log,
  every module the Admin has, plus system-level controls.
- **End-to-end workflows:** set up a new session → create classes/subjects →
  admit students → define fees → exams run → results locked/published →
  promotions to next session; investigate "who changed this payment/marks"
  via audit log.

### 2.2 Admin (school office / academic coordinator)
- **Daily jobs:** admissions and student records, staff accounts, classes/
  sections/subjects, timetables, exams, marks oversight, fee setup, notices
  and events, library oversight, dorm allocation, promotions.
- **Expected screens:** everything in the support-team menu except system
  settings/audit; student list by class; tabulation sheet; fee management.
- **Workflows:** admit a student (record + login + parent link) → assign
  class/section/dorm → student appears in teacher's attendance & marks
  grids automatically; run exam cycle: create exam → teachers enter marks
  → tabulation → print marksheets → promote/graduate at year end.

### 2.3 Teacher / Educator
- **Daily jobs:** take attendance for their class(es) in under two minutes;
  see today's timetable (which periods, which class); enter marks per
  subject after each test/exam; add class-teacher/principal comments;
  rate skills (affective/psychomotor); know which of their students are
  struggling or absent repeatedly.
- **Expected screens:** my classes & subjects, take-attendance grid,
  marks-entry grids per exam/class/section/subject, bulk marks entry,
  timetable (own + class), student profiles of own students, events.
- **End-to-end workflow (the core loop):** mark attendance → teach →
  enter CA/test + exam marks → system computes totals/grades/positions →
  add comments & skills → marksheet ready for admin to print/publish →
  parents/students see it. Plus: check own timetable each morning;
  flag a student's repeated absence.

### 2.4 Student
- **Daily jobs:** see own timetable, own attendance record and %, own
  results per exam (after publication rules), own fee status (what's
  paid/pending), upcoming events; download/print own marksheet.
- **Expected screens:** personal dashboard (attendance %, latest results,
  fee status, today's classes), marksheet viewer, timetable viewer.
- **Workflows:** log in → pick exam year → (enter result PIN if the school
  locks results) → view/print marksheet; check "have my fees been fully
  paid?" before exam season.

### 2.5 Parent
- **Daily jobs:** know the child was present today; see marks/report cards
  as soon as published; see fee dues and due dates; get receipts for what
  they paid; receive school announcements; see events/exam dates.
- **Expected screens:** per-child cards (attendance %, latest result, fee
  balance), child marksheet, fee statement + receipts, announcements,
  events calendar.
- **End-to-end workflow (the money loop):** view child's fee statement →
  pay (online in top products; at the office in ours) → download the
  receipt → get reminded before the next due date. And the trust loop:
  absence notification the same morning → check attendance history →
  view published marksheet without visiting the school.

### 2.6 Accountant (bursar / fee clerk)
- **Daily jobs:** record cash/bank payments against the right student and
  fee item; issue receipts; correct mistakes safely (reset with audit);
  chase dues; report collections (today/this month/by class) to the
  principal; define the session's fee structure with Admin.
- **Expected screens:** fee-structure setup per class, class-wise student
  fee ledger (paid/balance), record-payment action, receipts list + PDF,
  collection summaries, defaulter list.
- **Workflows:** create fee items for the session → open a class ledger →
  record a parent's payment (full or part) → print/e-mail the receipt →
  balance updates everywhere; month-end: collection report + defaulter
  list for follow-up; year-end: reset/roll records into the new session.

### 2.7 Librarian
- **Daily jobs:** catalogue new books, issue books to students/staff,
  take returns, see what is overdue and who has it, keep copy counts true.
- **Expected screens:** book catalogue with search, issue screen (book +
  borrower), loans list with issued/returned/overdue states.
- **Workflow:** add copies → issue (copies-out increments) → due date
  passes → loan shows overdue → return (copies restore) — with deletion
  of a title blocked while copies are out.

---

## 3. Hackstra today — verified inventory

Architecture: Laravel 8 (`composer.json`: `laravel/framework ^8.40`,
`barryvdh/laravel-dompdf`, Hashids-obfuscated IDs), Blade views (~110),
role middleware in `app/Http/Middleware/Custom/`
(`TeamSA`, `TeamSAT`, `TeamAccount`, `TeamLibrary`, `SuperAdmin`,
`MyParent`, `ExamIsLocked`), team helpers in `app/Helpers/Qs.php`,
`App\Helpers\Money` (Indian digit grouping) and
`App\Support\GradeCalculator` with unit tests
(`tests/Unit/MoneyTest.php`, `tests/Unit/GradeCalculatorTest.php`).

| Area | What exists (verified) | Key files |
|---|---|---|
| Auth & roles | Laravel auth, 7 role types, per-role menus, password reset by admin, own profile/password (`my_account`) | `routes/web.php`, `app/Helpers/Qs.php`, `resources/views/pages/*/menu.blade.php` |
| Dashboards | Super Admin dashboard rebuilt to owner's target (stat deltas, quick actions, calendar, enrollment SVG chart, fee donut, attendance donut, recent students/payments, events, Smart Insights); Loop 7 added data-driven homes for Teacher/Student/Parent/Accountant | `app/Http/Controllers/HomeController.php`, `resources/views/pages/support_team/dashboard.blade.php`, `pages/{teacher,student,parent,accountant}/home.blade.php` |
| Students | Full CRUD, class lists, profile, admission number, parent link (`my_parent_id`), dorm fields, graduated list, withdraw/return, **promotions workflow** (promote/reset/manage) | `SupportTeam/StudentRecordController.php`, `SupportTeam/PromotionController.php`, `app/Models/StudentRecord.php` |
| Academics | Classes, class types, sections, subjects **with teacher assignment** (`subjects.teacher_id`), grades (bands per class type with remarks) | `SupportTeam/{MyClass,Section,Subject,Grade}Controller.php` |
| Exams & marks | Exams (name/term/year); marks grid per exam/class/section/subject with CA columns `t1–t4`, `tca`, exam `exm`, subject position, cumulative averages; bulk entry + batch fix; comments (`t_comment`, `p_comment`) and affective/psychomotor skills on `ExamRecord`; tabulation sheet + print; **PIN-locked result access** when `lock_exam` setting is on; policies on sensitive actions | `SupportTeam/{Exam,Mark}Controller.php`, `app/Models/{Mark,ExamRecord,Grade}.php`, `SupportTeam/PinController.php`, views under `pages/support_team/marks/` incl. `print/{sheet,comments,skills,grading}` |
| Fees & payments | Fee items per class per year (`payments`: title, amount, ref_no); per-student ledger (`payment_records`: amt_paid, balance, paid flag); record payment (part payments supported), receipts per instalment + **PDF receipts** (Dompdf), student fee **invoice/statement** page per year, reset-record with audit; role team = SA + Admin + Accountant; `PaymentRecordPolicy` incl. receipt-IDOR fix | `SupportTeam/PaymentController.php`, `app/Models/{Payment,PaymentRecord,Receipt}.php`, `pages/support_team/payments/{invoice,receipt,manage}.blade.php` |
| Attendance | Daily register per class/section/date with present/absent/late + remarks (`attendances`), bulk grid entry, monthly per-student report (present/absent/late/marked + %) | `SupportTeam/AttendanceController.php`, `app/Models/Attendance.php`, `pages/support_team/attendance/` |
| Timetable | Timetables per class with records + reusable time slots; view + print | `SupportTeam/TimeTableController.php` |
| Events | School events CRUD with types, dashboard calendar + upcoming lists | `SupportTeam/SchoolEventController.php` (destroy is Super-Admin-only) |
| Library | Catalogue CRUD + search; issue/return loans in transactions with copy-count integrity, overdue flagging; Librarian menu live | `SupportTeam/{Book,BookRequest}Controller.php` (Loop 5) |
| Dorms | Dorm CRUD; room number stored on the student record | `SupportTeam/DormController.php` |
| Search | Real global search (students/teachers/etc.), Ctrl+K | `DashboardSearchController.php` |
| Settings & audit | Super-Admin-only school settings (identity, session, term dates, exam lock, next-term fees); **audit log** of logins, payment record/reset/delete, student delete, marks update, with viewer page | `SuperAdmin/{Setting,AuditLog}Controller.php`, `app/Support/Audit.php` |
| Validation | Form Requests across students, users, payments (incl. `PayNowRequest`, `SelectClassRequest`), attendance, books, loans, events, timetable (Loop 6) | `app/Http/Requests/` |
| Parent surface | Children list (`/my_children`) → child profile → child marksheet via the guarded `marks.show`/`marks.print` (self/parent/staff check + optional PIN) | `MyParent/MyController.php`, `MarkController@show` |
| Platform | Live demo deployment, production Docker images (Railway + Render), `/api/health`, print stylesheet | `Dockerfile*`, `docs/DEPLOYMENT.md`, `public/assets/css/qs.css` |

**Verified absences** (searched `app/`, `routes/`, `database/migrations/`,
`composer.json` — no code exists): no `app/Notifications`, no mailables or
SMS usage anywhere (the only listener is the login→audit one in
`EventServiceProvider`), no payment-gateway SDK or checkout (the `stripe`
block in `config/services.php` is the untouched Laravel default), no
homework/assignment/transport/payroll/leave/inventory code at all, no Excel/
CSV import or export in any controller, no admissions/enquiry pipeline, no
certificate/ID-card generator, no staff attendance or leave, no expense
tracking, no backup package (`spatie/laravel-backup` absent), and
`routes/api.php` contains only the stock `/user` stub plus `/health` — there
is no API for a mobile app to consume.

---

## 4. Gap analysis — HAVE / PARTIAL / MISSING

Status key: **HAVE** = works end-to-end today · **PARTIAL** = core exists,
buyer-expected layer missing · **MISSING** = no code.

| # | Capability (buyer checklist wording) | Status | Evidence / what's missing |
|---|---|---|---|
| 1 | Student information & records | **HAVE** | StudentRecord CRUD, profiles, class lists |
| 2 | Admissions pipeline (enquiry → application → enrolment) | **MISSING** | Staff create students directly; no enquiry/application states, no public form |
| 3 | Attendance — staff taking register | **HAVE** | Bulk grid, 3 statuses, remarks (Loop: attendance feature) |
| 4 | Attendance — student/parent self-view & absence alerts | **MISSING** | No route serves attendance to its owners; no notifications exist |
| 5 | Exams & marks entry | **HAVE** | CA+exam structure, bulk entry, batch fix, tabulation |
| 6 | Marksheet / report card | **PARTIAL** | Rich print marksheet (see §5) but: no one-click whole-class PDF, no per-student performance graph, no QR/verifiable copy, logo limited to settings field |
| 7 | Result publication control | **HAVE** | Exam lock + PIN scratch-card flow — a differentiator, but see friction note §5.4 |
| 8 | Fees — structure & ledger | **PARTIAL** | Per-class fee items + per-student ledger work; no instalment plans, due dates, discounts/concessions, late fees (fields don't exist on `payments`/`payment_records`) |
| 9 | Fees — online payment | **MISSING** | No gateway; staff record cash manually via `pay_now` |
| 10 | Fees — receipts | **HAVE** | Per-instalment receipts + PDF (Dompdf), policy-guarded |
| 11 | Fees — defaulter & collection reporting | **MISSING** | No defaulter list, no daily/monthly collection report screens (`manage` is a per-class ledger only) |
| 12 | Fees — reminders | **MISSING** | No SMS/email/in-app reminder mechanism at all |
| 13 | Parent portal | **PARTIAL** | Children list + child marksheet only; no parent fee statement page (the invoice route is staff-side), no attendance, no announcements; parent menu has exactly one link (`my_children`) |
| 14 | Student portal | **PARTIAL** | Own marksheet + Loop 7 home summary; no own attendance page, no own fee statement page, student menu links only the marksheet flow |
| 15 | Communication (messaging, notices, announcements) | **MISSING** | Zero messaging/notification code; staff topbar shows a "recent activity" feed, which is not communication |
| 16 | SMS / email alerts | **MISSING** | No channels configured or sent; settings even store legacy `email_host`/`email_pass` keys nothing uses |
| 17 | Homework / assignments / study material | **MISSING** | No code (verified by search) — headline feature in Teachmint & MyClassCampus |
| 18 | LMS (courses, quizzes, online exams, live class) | **MISSING** | No code; every competitor except the smallest has at least homework+materials |
| 19 | Timetable | **PARTIAL** | Manual builder + print works; no auto-generation, no clash detection, no teacher-workload view (Fedena sells an auto-generator) |
| 20 | Library | **HAVE** | Loop 5: catalogue + issue/return + overdue; missing only fines & reservations (Fedena has both) |
| 21 | Hostel / dorms | **PARTIAL** | Dorm CRUD + room no. on student record; no room/bed allocation view, no dorm attendance or warden workflow |
| 22 | Transport | **MISSING** | No routes/vehicles/allocation; GPS is competitor table-stakes in India |
| 23 | HR — staff records | **PARTIAL** | `StaffRecord` + user accounts exist; no staff attendance, leave, or documents workflow |
| 24 | Payroll | **MISSING** | No code — Fedena & PowerSchool both ship it; Indian buyers ask |
| 25 | Leave management | **MISSING** | No code for staff or student leave applications |
| 26 | Inventory / assets / expenses | **MISSING** | No expense tracking even on the finance side; fees are the only money-in, nothing tracks money-out |
| 27 | Certificates & ID cards | **MISSING** | No TC/bonafide/ID-card generation — MyClassCampus sells a certificate generator explicitly |
| 28 | Bulk import / export | **MISSING** | No CSV/Excel import for students or marks; no exports anywhere — the #1 onboarding friction for a new school |
| 29 | Reports & analytics | **PARTIAL** | Dashboard charts + attendance report + tabulation; no report builder, no class/subject performance analysis, no exports |
| 30 | Audit & security | **HAVE** | Audit log (Loop 4), policies, Form Requests, hardened env — genuinely ahead of the fork's origin |
| 31 | API & mobile app | **MISSING** | `api.php` is a stub; competitors ship parent/teacher apps |
| 32 | Multi-school / multi-branch | **MISSING** | Single-tenant settings; Fedena/Classe365 sell group-of-schools management |
| 33 | Backups | **MISSING** | No in-app or scheduled backup story beyond host snapshots |
| 34 | Librarian role completeness | **PARTIAL** | Module + middleware + menu exist, but the `librarian` user type is commented out in `UserTypesTableSeeder` — the role cannot be seeded/assigned cleanly today |

**The five biggest gaps (buyer's-eye view):**
1. **The parent — the person who pays — has almost no product.** One menu
   link; no fees, no attendance, no messages. Every competitor leads with
   the parent experience (Edsby's entire pitch; Teachmint's parent app).
2. **Fees stop at the office door.** No online payment, no due dates or
   instalments, no discounts, no defaulters, no reminders. Fee collection
   is the #1 reason Indian schools buy this software category.
3. **There is no communication layer at all.** No notices, no messaging,
   no SMS/email — in a category where "parent communication" is a
   headline module for Fedena, Teachmint, Classe365, Edsby and Blackbaud.
4. **The teaching–learning layer is empty.** No homework, assignments,
   study material or quizzes; the product is administration-only while
   competitors bundle at least a light LMS.
5. **No platform runway:** no API/mobile, no bulk import, no HR/payroll/
   transport — the modules that decide deals with larger schools, and the
   import tooling that decides whether a new customer ever finishes
   onboarding.

---

## 5. Reference workflow A — the marksheet (report card)

### 5.1 What a great marksheet contains (synthesised from Fedena report
cards, MyClassCampus auto-generated result cards + result analysis,
PowerSchool report cards/transcripts, and the CCE-style templates Indian
schools expect)
1. **School identity block:** logo, school name, address, contact — the
   document is the school's stationery.
2. **Student block:** photo, full name, admission number, class + section,
   term and session, gender/age, house, days present vs days school opened
   (attendance summary *on* the report card).
3. **Subject table:** per subject — each CA component (Test 1, Test 2, …),
   total CA, exam score, **grand total**, grade, grade remark, subject
   position, and class highest/average for context.
4. **Summary block:** overall total, overall average, position in class,
   number of students in class, and **cumulative** term averages (Term 1/2/3
   running average) for end-of-year progression.
5. **Grading scale legend** printed on the sheet (bands → grade → remark).
6. **Affective & psychomotor skills** rated (e.g. punctuality, neatness,
   honesty; handwriting, sports, craft) — a hard expectation in Nigerian
   and Indian primary/secondary report cards.
7. **Comments:** class teacher's remark and principal/head's remark, with
   signature lines.
8. **Next-term block:** resumption date and **next term's fees** (schools
   use the report card as the fee-demand channel).
9. **Trust marks (modern additions):** generated date, "verify this
   result" reference/QR, and digital availability in the parent/student
   portal the day results are published.

### 5.2 Where Hackstra stands
Our print marksheet (`pages/support_team/marks/print/{sheet,comments,skills,
grading}.blade.php`, `MarkController@print_view`) already covers items 2
(partially — no photo/attendance on the sheet), 3 (CA columns `t1–t4`,
`tca`, `exm`, `sub_pos`, grade + remark via `GradeCalculator`), 4 (`total`,
`ave`, `pos`, `class_ave`, `cum`, `cum_ave` are all stored on marks/exam
records), 5 (grading partial), 6 (`ExamRecord.af/ps` skill ratings), 7
(`t_comment`/`p_comment`) and 8 (settings carry `next_term_fees_*` and
`term_begins`). This is a *strong* marksheet — stronger than several paid
products' defaults.

### 5.3 Marksheet gaps worth one loop
- One-click **whole-class marksheet PDF** (today: per-student print +
  tabulation print; an office printing 400 reports clicks 400 times).
- Student **photo + attendance summary** on the sheet.
- A small **performance trend** (term averages as a bar/sparkline — the
  Loop 7C reusable SVG bar partial `partials/charts/bar.blade.php` exists).
- **Verification reference** (result code/short URL) instead of relying
  only on the PIN scratch-card.
- School **logo rendering** on the print sheet from settings (field
  exists; ensure the print views actually use it).

### 5.4 A product decision to surface to the owner
The PIN scratch-card model (results unlock with a purchased PIN when
`lock_exam` is on) is inherited from the Nigerian market this codebase
originated in. Every researched competitor instead *publishes* results to
the portal (optionally after fee-clearance rules). PIN-gating is a real
revenue mechanic for some schools, so keep it — but as a **per-exam
publish toggle with a fee-clearance option**, not the only path: parents
who must buy a card to see a report card is the single most likely
demo-killer with Indian buyers.

---

## 6. Reference workflow B — fees, invoices & receipts

### 6.1 How the top products run fees (Fedena finance, MyClassCampus fees,
Blackbaud tuition, Classe365 billing, PowerSchool fee management)
1. **Structure first:** fee *heads/categories* (tuition, transport, exam…)
   composed into a class-wise **fee structure**, assignable per student
   with **discounts/concessions** (sibling, merit, staff ward) and
   **instalment plans** — each instalment has an amount and a **due date**.
2. **Invoice/demand:** each student gets an invoice (bill) per period with
   a unique invoice number, itemised heads, discount lines, previous
   balance carried forward, and total payable — visible in the parent
   portal and printable/emailable.
3. **Collection:** payment accepted by cash, cheque (with cheque-status
   tracking), bank transfer and **online gateway**; part-payments allowed;
   every payment issues a **receipt** (unique receipt no., date, student,
   heads paid against, amount in words, mode, collected-by) as PDF and
   in the portal; gateway payments reconcile via settlement reports and a
   student fee "passbook".
4. **Follow-up:** overdue lists by class/age of dues; **automatic
   reminders** before/after due dates (SMS/email/push); late-fee rules;
   defaulter reports for the principal; fee-clearance gating (e.g. admit
   card/result access when dues are cleared — the polite version of our
   PIN lock).
5. **Books:** daily collection register, class-wise and head-wise
   collection reports, discount reports, refund workflow, and (Classe365/
   Blackbaud) posting into a **general ledger** with P&L — the point
   where a fee module becomes *accounting*.

### 6.2 Where Hackstra stands
Solid bones: fee items per class/year (`Payment`), per-student ledger
with running `balance` and paid flag (`PaymentRecord`), part-payments,
per-instalment `Receipt` rows, PDF receipts, a per-student/per-year
invoice statement page, audited resets, and correct role gating
(team = SA/Admin/Accountant, policies enforced — the Loop 1 IDOR fix
means parents/staff can't open other students' receipts by guessing IDs).

### 6.3 Fee gaps (in workflow order)
- No instalments/due dates → no "overdue" concept → no defaulter list.
- No discount/concession field anywhere in the chain.
- No **mode of payment** captured (cash/cheque/UPI/bank) — `PaymentRecord`
  has `ref_no` only; accountants can't produce a cash-vs-bank day book.
- No online payment (see backlog #3).
- Receipt has no amount-in-words and no school-logo header treatment;
  invoice is a screen, not an emailable/printable bill document.
- No collection *reports* (daily/monthly/head-wise) — the Accountant's
  month-end is currently manual addition from the class ledger.
- No refund path (a reset deletes the record; auditors prefer a
  reversing entry — our audit log softens this but doesn't replace it).

---

## 7. Ranked implementation backlog — next loops

Scoring: **Impact** = how much it moves a buying decision in a demo with a
school owner/principal/parent (1–5). **Effort** = agent-days at the loop
pace this project has sustained (1–5, higher = more work). Ranked by
impact-per-effort with sales sequencing (portal & fees before platform).

| Rank | Item | Impact | Effort |
|---|---|---|---|
| 1 | Parent portal completion (fees + attendance + notices view) | 5 | 2 |
| 2 | Student/parent attendance self-view | 4 | 1 |
| 3 | Online fee payment (Razorpay) with auto-receipt | 5 | 3 |
| 4 | Accountant reports: defaulters + collection summaries | 5 | 2 |
| 5 | Instalments, due dates & discounts on fee structure | 4 | 3 |
| 6 | Notice board + real in-app notifications | 4 | 2 |
| 7 | SMS/email alerts (absence, fee due, result published) | 4 | 3 |
| 8 | Homework & assignments (light LMS) | 4 | 3 |
| 9 | CSV bulk import (students, then marks) + export basics | 4 | 2 |
| 10 | Marksheet pack: whole-class PDF, photo, trend, verify code | 3 | 2 |
| 11 | Publish-toggle & fee-clearance result release (PIN optional) | 3 | 1 |
| 12 | Staff leave + staff attendance (HR-lite) | 3 | 3 |
| 13 | Admissions enquiry → enrolment pipeline | 4 | 4 |
| 14 | Certificates & student ID card generator | 3 | 2 |
| 15 | API foundation (Sanctum) for a future mobile/PWA app | 3 | 3 |

### Build specs (each is scoped for one agent/loop)

**1. Parent portal completion.** Parent gets three new screens under the
existing `my_parent` group: *Fees* (per child: fee items, paid, balance —
read-only reuse of `Payment`/`PaymentRecord` queries behind
`PaymentRecordPolicy`), *Attendance* (child's register + %), *Notices*
(item 6's feed, read-only here). Touch: `routes/web.php` (MyParent group),
`app/Http/Controllers/MyParent/MyController.php` (+`fees()`, `attendance()`),
new `resources/views/pages/parent/{fees,attendance}.blade.php`, extend
`resources/views/pages/parent/home.blade.php` cards and
`resources/views/pages/parent/menu.blade.php`. Guard every child lookup
with `Qs::userIsMyChild()`.

**2. Attendance self-view.** One controller method serving both a student
(own `StudentRecord`) and a parent (per child): month grid + present/
absent/late counts + % from `attendances`. Touch:
`app/Http/Controllers/SupportTeam/AttendanceController.php` (new
`mine()`/`child()` read methods) or fold into item 1 + a student route in
`routes/web.php`; views `pages/{student,parent}/attendance.blade.php`;
reuse the monthly aggregation already proven in `AttendanceController@report`.

**3. Online fee payment (Razorpay).** Parent clicks *Pay* on the fee
statement → Razorpay checkout for the outstanding balance (full or chosen
instalment) → signed webhook creates the `PaymentRecord` + `Receipt`
(server-side, idempotent on gateway payment id) → parent sees receipt PDF.
Touch: `composer.json` (`razorpay/razorpay`), `config/services.php`,
new `app/Http/Controllers/PaymentsGatewayController.php` (checkout +
webhook, CSRF-exempt route in `routes/web.php`), migration adding
`gateway_ref` to `payment_records`, parent fees view (item 1), docs in
`docs/DEPLOYMENT.md` for keys. Keep manual `pay_now` untouched for office
collections.

**4. Accountant reports.** Two screens for teamAccount: *Defaulters*
(class filter; students with `balance > 0`, ageing by fee-item year;
totals via `App\Helpers\Money`) and *Collections* (date-range totals:
collected, by class, by day; CSV download optional stretch). Touch:
`app/Http/Controllers/SupportTeam/PaymentController.php` (`defaulters()`,
`collections()`), routes in the `payments` group of `routes/web.php`,
views `pages/support_team/payments/{defaulters,collections}.blade.php`,
`resources/views/pages/accountant/menu.blade.php` links, accountant home
(`HomeController@accountantHomeData`) tiles link here.

**5. Instalments, due dates, discounts.** Migration: `due_date`,
`discount` on `payment_records` seeding logic + `installment_no`; fee
creation optionally splits a class fee into N instalments with dates;
invoice view shows schedule; balance math must stay consistent with
`PaymentRepo` (read it first). Touch: new migration in
`database/migrations/`, `app/Models/{Payment,PaymentRecord}.php`,
`app/Http/Requests/Payment/{PaymentCreate,PaymentUpdate}.php`,
`PaymentController@store/invoice`, `pages/support_team/payments/{create,invoice}.blade.php`, extend `tests/Unit` coverage for the balance math.

**6. Notice board + notifications.** `notices` table (title, body,
audience: all/staff/students/parents or a class, author, publish_at);
SA/Admin compose; Laravel database notifications for the bell (replace
the recent-activity feed for parent/student roles; keep activity feed for
staff). Touch: migration, `app/Models/Notice.php`,
`app/Http/Controllers/SupportTeam/NoticeController.php`, resource route
in `routes/web.php`, views `pages/support_team/notices/*`, bell partial
`resources/views/partials/top_menu.blade.php`, role home views show
latest notices.

**7. SMS/email alerts.** Queue-backed notifications on three triggers:
absence marked (`AttendanceController@store`), fee due in 3 days / 
overdue (scheduled command using item 5's due dates), result published
(item 11). Channels: mail (SMTP from settings) + one SMS provider
(MSG91 or Twilio — decide by Indian pricing at build time); provider keys
in Super Admin settings. Touch: `app/Notifications/*`,
`app/Console/Commands/FeeDueReminders.php`, `app/Console/Kernel.php`,
`SuperAdmin/SettingController.php` + settings view.

**8. Homework & assignments (light LMS).** `assignments` (teacher,
class/section/subject, title, description, due date, optional file) +
`assignment_submissions` (student, file/text, submitted_at, teacher
remark/score). Teacher creates from their subjects; student/parent see
due + submitted state; no auto-grading in v1. Touch: migrations,
`app/Models/{Assignment,AssignmentSubmission}.php`,
`app/Http/Controllers/SupportTeam/AssignmentController.php`, routes,
views for teacher manage + student/parent lists, menus for
teacher/student/parent, storage via the existing uploads disk.

**9. CSV bulk import + export basics.** Student import: downloadable
template (columns matching `StudentRecordCreate` rules), row-by-row
validation with an error report, creates user + record + parent link;
marks import per exam/class/subject (columns = student adm_no + CA/exam
fields). Exports: student list and class fee ledger as CSV (plain
`streamDownload`, no new package needed). Touch:
`StudentRecordController` (`importForm()`, `import()`),
`MarkController` (`import()`), routes, views
`pages/support_team/students/import.blade.php`, reuse Loop 6 Form
Requests for validation rules.

**10. Marksheet pack.** (a) `MarkController@print_class` — loops a
section's students into one print view reusing
`marks/print/sheet.blade.php` partials; (b) add photo + attendance % to
the sheet header (data already in `users`/`attendances`); (c) term-trend
bars via `partials/charts/bar.blade.php`; (d) a short verification code
on the sheet (hash of student+exam+year via existing Hashids) that a
public `/verify` page can confirm. Touch: `MarkController.php`, routes
(`marks` group), `pages/support_team/marks/print/*`, new
`HomeController@verify_result` + tiny public view.

**11. Publish toggle & fee-clearance release.** Add `published` +
`require_fee_clearance` flags to `exams`; `MarkController@show/print_view`
checks: staff always; student/parent only when published (PIN flow still
applies if `lock_exam` is on *and* the school keeps it). Admin UI toggle
on the exams index. Touch: migration, `app/Models/Exam.php`,
`ExamController`, `MarkController` (the two guard blocks at `show`/
`print_view`), `pages/support_team/exams/index.blade.php`.

**12. HR-lite: staff leave + staff attendance.** `staff_attendances`
(date, user, status) marked by Admin like the student register, and
`leave_requests` (staff applies, Admin/SA approves, states
pending/approved/rejected). Touch: migrations, models, new
`app/Http/Controllers/SupportTeam/{StaffAttendance,Leave}Controller.php`,
routes, views, staff menus; teacher home shows own leave balance/status.

**13. Admissions pipeline.** Public enquiry form (name, phone, class
sought, message) → `admissions` table with states
new/contacted/test_scheduled/approved/converted/rejected → Admin pipeline
board → *Convert* pre-fills `StudentRecordController@create`. Touch:
migration + `app/Models/Admission.php`, public route + view
(`HomeController@admission_form/store`), `SupportTeam/AdmissionController.php`,
conversion handoff to student create, dashboard enquiry count tile in
`HomeController`.

**14. Certificates & ID cards.** Print views generated from existing
records: student ID card (photo, adm_no, class, session, school header),
bonafide/transfer certificate (student data + settings identity +
serial number stored on a `certificates` table for TC register). Touch:
`app/Http/Controllers/SupportTeam/CertificateController.php`, routes,
`pages/support_team/certificates/*` print views (reuse `@media print`
layer in `public/assets/css/qs.css`), student show page buttons.

**15. API foundation.** Install Sanctum (Laravel 8-compatible), token
auth for the same 7 roles, read-only v1 endpoints mirroring the portals:
`/api/v1/me`, `/children`, `/attendance`, `/marks`, `/fees`, `/notices`
— exactly the surface a future parent app/PWA needs; document in
`docs/API.md`. Touch: `composer.json` (`laravel/sanctum`), `routes/api.php`,
`app/Http/Controllers/Api/*`, policies reused from web side, feature
tests in `tests/Feature`.

**Deliberately not in the top 15** (note for the owner, with reasons):
transport GPS (hardware/vendor dependency — do routes/vehicles CRUD only
after a buyer commits), full double-entry accounting (Classe365-level;
overkill until an enterprise buyer appears), payroll (statutory
complexity per country/state — HR-lite first), online classes/LMS video
(partner/integration decision, not a build), multi-school tenancy
(architecture change — revisit when a school *group* is a live prospect),
native mobile apps (item 15 is the runway; PWA first).

---

## 8. Positioning — what we can honestly say in a sales demo today

**Lead with:** a complete academic core (students, classes, exams, a
genuinely rich marksheet with skills & comments, tabulation), a working
fee ledger with PDF receipts and audited corrections, daily attendance
with monthly reporting, timetable, events, a real library module, dorms,
year-end promotions, PIN-locked results, an audit log most competitors
bury in enterprise tiers, Indian-format money throughout, and role-based
dashboards per user type — deployable on cheap hosting.

**Never claim (yet):** online fee payment, parent app/SMS alerts,
homework/LMS, admissions CRM, payroll, transport. A buyer who discovers
one inflated claim discounts the ten true ones; the backlog above is
ordered so the most-demoed gaps close first — items 1–6 alone move
Hackstra from "admin records tool" to "school platform" in a buyer's
words.

---

## 9. Section list of this study
1. The market: what the top products ship (Fedena, Teachmint,
   Classe365, PowerSchool + Schoology, Blackbaud, MyClassCampus, Edsby)
2. Per-role use-case matrix (Super Admin, Admin, Teacher/Educator,
   Student, Parent, Accountant, Librarian)
3. Hackstra today — verified inventory
4. Gap analysis — HAVE / PARTIAL / MISSING (34 items, five biggest gaps)
5. Reference workflow A — the marksheet
6. Reference workflow B — fees, invoices & receipts
7. Ranked implementation backlog — top 15 with build specs
8. Positioning — honest demo claims
9. Section list
10. Sources

## 10. Sources (accessed 2026-10-21)
- Fedena — https://fedena.com/feature-tour · https://www.saasworthy.com/product/fedena · https://apibit.com/product/fedena/
- Teachmint — https://www.goodfirms.co/software/teachmint · https://softwarefinder.com/lms/teachmint
- Classe365 — https://www.classe365.com/customers/universitity-management-software/ · https://www.capterra.com/compare/135075-185315/Classe365-vs-DUAL-Credit-Management-System · https://cdn-b.saashub.com/compare-munis-vs-classe365
- PowerSchool SIS — https://www.g2.com/products/powerschool-sis/features · https://www.softwaresuggest.com/powerschool-sis · https://www.getapp.com/hr-employee-management-software/a/powerschool/features/
- Schoology — https://teachers-blog.com/schoology-learning-management-system/ · https://soprime.com/software/learning-software/schoology-lms · https://b2bvista.com/articles/understanding-schoology-comprehensive-guide/
- Blackbaud — https://www.blackbaud.ca/solutions/organizational-and-program-management/education-management/k-12 · https://www.appvizer.com/education/school-administration/blackbaud?nocache=true · https://www.getapp.ca/reviews/2057541/k-12-schools
- MyClassCampus — https://www.saasworthy.com/product/myclasscampus · https://www.softwaresuggest.com/blog/myclasscampus-review/
- Edsby — https://www.edsby.com/features/ · https://www.edsby.com/how-edsby-helps/teacher/ · https://www.edsby.com/edsby-for-districts/ · https://www.edsby.com/the-complete-guide-to-k12-family-engagement-systems/commentary/
- Codebase evidence — this repository, branch `feature/form-requests`
  tip (Loop 6, commit `502b20b`) with Loop 7 workstreams landing during
  writing; all file paths cited inline.
