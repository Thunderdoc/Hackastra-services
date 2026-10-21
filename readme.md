## **Hackstra School Management System**

**Hackstra** is a school-management platform for educational institutions. It is currently being modernized from its original Laravel 8 foundation.

**SCREENSHOTS** 

**Dashboard**
<img src="https://i.ibb.co/D4T0z6T/dashboard.png" alt="dashboard" border="0">

**Login**
<img src="https://i.ibb.co/Rh1Bfwk/login.png" alt="login" border="0">

**Student Marksheet**
<img src="https://i.ibb.co/GCgv5ZR/marksheet.png" alt="marksheet" border="0">

**System Settings**
<img src="https://i.ibb.co/Kmrhw69/system-settings.png" alt="system-settings" border="0">

**Print Marksheet**
<div style="clear: both"> </div>
<img src="https://i.ibb.co/5c1GHCj/capture-20210530-115521-crop.png" alt="print-marksheet">

**Print Tabulation Sheet & Marksheet**
<img src="https://i.ibb.co/QmscPfn/capture-20210530-115802.png" alt="tabulation-sheet" border="0">

<hr />  

There are 7 types of user accounts. They include:
 
Administrators (Super Admin & Admin)
- Librarian
- Accountant
- Teacher
- Student
- Parent

**Requirements**

- PHP 8.2 (development target while the staged Laravel upgrade runs — see `docs/MODERNIZATION.md`)
- Composer 2, MySQL 8 (or MariaDB 10.6+), Node 20 for the legacy frontend build
- PHP extensions: openssl, pdo_mysql, mbstring, fileinfo, tokenizer, xml, ctype, json, curl, zip

**Installation**
- Install dependencies (`composer install`)
- Copy `.env.example` to `.env`, set database credentials, and run `php artisan key:generate`
- Migrate the database (`php artisan migrate`)
- Seed the database (`php artisan db:seed`)
- Optional demo data for the dashboard (students' fees, marks, school events): `php artisan db:seed --class=DashboardDemoDataSeeder`

> ⚠️ The seeded credentials below are **demo-only**. Rotate every one of them before any public deployment. Production must run with `APP_ENV=production` and `APP_DEBUG=false`.

**What's new in the modernization (see `docs/MODERNIZATION.md` for the full plan)**
- Super Admin dashboard rebuilt to the current design: real fee totals in ₹, enrollment trend, school calendar, upcoming events, smart insights
- Daily **Attendance** (sidebar) — one record per student per day, powering the dashboard panel
- **School Events** management (sidebar) — powers the calendar and Upcoming Events
- **Global search** in the top bar (`Ctrl + K`) across students, staff, classes and payments
- Record-level policies for students and payments; CI on PHP 8.2

**Login Credentials**
After seeding. Login details as follows:

| Account Type  | Username | Email | Password |
| ------------- | -------- | ----- | -------- |
| Super Admin | cj | cj@cj.com | cj |
|  Admin | admin | admin@admin.com | cj |
|  Teacher | teacher | teacher@teacher.com | cj |
|  Parent | parent | parent@parent.com | cj |
|  Accountant | accountant | accountant@accountant.com | cj |
|  Student | student | student@student.com | cj |

#### **FUNCTIONS OF ACCOUNTS** 

**-- SUPER ADMIN**
- Only Super Admin can delete any record
- Create any user account
 
**-- Administrators (Super Admin & Admin)**

- Manage students class/sections
- View marksheet of students
- Create, Edit and manage all user accounts & profiles
- Create, Edit and manage Exams & Grades
- Create, Edit and manage Subjects
- Manage noticeboard of school
- Notices are visible in calendar in dashboard
- Edit system settings
- Manage Payments & fees

**-- ACCOUNTANT**
- Manage Payments & fees
- Print Payment Receipts

**-- LIBRARIAN**
- Manage Books in the Library

**-- TEACHER**
- Manage Own Class/Section
- Manage Exam Records for own Subjects
- Manage Timetable if Assigned as Class Teacher
- Manage own profile
- Upload Study Materials

**-- STUDENT**
- View teacher profile
- View own class subjects
- View own marks and class timetable
- View Payments
- View library and book status
- View noticeboard and school events in calendar
- Manage own profile

**-- PARENT**
- View teacher profile
- View own child's marksheet (Download/Print PDF)
- View own child's Timetable
- View own child's payments
- View noticeboard and school events in calendar
- Manage own profile

### **Contributing**

Your Contributions & suggestions are welcomed. Please use Pull Request

### **Security Vulnerabilities**

If you discover a security vulnerability within Hackstra, report it privately to the project owner before publicly disclosing it. Do not include passwords, tokens, or private student data in an issue.

***Please Note*** that some sections of this project are in the work-in-progress stage and would be updated soon. These include:

- The Noticeboard/Calendar in the Dashboard Area
- Librarian/Acountant user pages
- Library Resources/Study Materials Upload for Students

### **Project identity**
- Product name: Hackstra
- School name and contact details should be configured in the application Settings page.
