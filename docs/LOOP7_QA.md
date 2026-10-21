# Loop 7 — Workstream C: Live QA Crawl & Defect Log

Date: 2026-10-21 · Site: https://hackastra.onrender.com · Role: Super Admin
(cj@cj.com, scripted session; login form field `identity`)
Branch: `feature/loop7-qa`

## Crawl results (23 pages)

| Page | Route | HTTP | Size | http:// refs | Local imgs broken | Notes |
|---|---|---|---|---|---|---|
| Home (SA dashboard) | /home | 200 | 54,493 | 0 | 0 | Charts checked in detail below |
| Students list | /students/list/1 | 200 | 74,245 | 0 | 0 | 17 imgs, all default avatar, all 200 |
| Student create | /students/create | 200 | 72,987 | 0 | 0 | |
| Users | /users | 200 | 121,872 | 0 | 0 | 20 imgs, all 200 |
| Classes | /classes | 200 | 56,381 | 0 | 0 | No empty state (fixed) |
| Sections | /sections | 200 | 70,743 | 0 | 0 | No empty state (fixed) |
| Subjects | /subjects | 200 | 88,000 | 0 | 0 | No empty state (fixed) |
| Exams | /exams | 200 | 36,251 | 0 | 0 | **Bare table: header only, no rows, no message (fixed)** |
| Marks | /marks | 200 | 36,635 | 0 | 0 | Selector screen, no table |
| Payments index | /payments | 200 | 34,081 | 0 | 0 | Class-select screen |
| Payments manage (extra) | /payments/manage | 200 | — | 0 | 0 | **Zero payment records in demo data** |
| Payment create | /payments/create | 200 | 36,932 | 0 | 0 | |
| Attendance index | /attendance | 200 | 38,683 | 0 | 0 | Marking form present |
| **Attendance report** | **/attendance/report** | **500** | 6,615 | 0 | — | **CRITICAL — see D1** |
| Events | /events | 200 | 33,069 | 0 | 0 | Empty state present ("No events yet") |
| Books | /books | 200 | 33,636 | 0 | 0 | Empty state present (Loop 5) |
| Book requests | /book_requests | 200 | 33,742 | 0 | 0 | Empty state present (Loop 5) |
| Timetables | /timetables | 200 | 45,331 | 0 | 0 | No empty state (fixed) |
| Dorms | /dorms | 200 | 45,061 | 0 | 0 | No empty state (fixed) |
| Grades | /grades | 200 | 52,231 | 0 | 0 | No empty state (fixed) |
| Settings | /super_admin/settings | 200 | 42,153 | 0 | 0 | |
| Pins | /pins | 302 → /dashboard | 406 | 0 | — | See D4 (info) |
| Audit logs | /super_admin/audit-logs | 200 | 35,531 | 0 | 0 | |
| My account | /my_account | 200 | 39,784 | 0 | 0 | |

Site-wide: **0 mixed-content (`http://hackastra…`) references on every page** —
the Loop 6 https fix holds everywhere. **0 broken local images** — the only
chrome images are `global_assets/images/logo_icon_light.png` and
`global_assets/images/user.png` (default avatar); both exist under `public/`
and return 200. All student/user photos in the demo resolve to the default
avatar, which loads.

## Dashboard chart verification (from live /home HTML)

| Chart | Type | Verdict |
|---|---|---|
| Student enrollment trend | Inline SVG, viewBox 560×170 | **Renders WITH real data**: polyline with 6 points (May–Oct), axis labels 0 / 19 / 37, caption "37 Students". First five months sit at baseline, Oct rises to 37 (demo students were all created in Oct — data is real, shape is a seed artefact). |
| Fee collection ring | CSS ring (`--fee-progress`) + ₹ list | **Renders correctly but EMPTY data**: 0%, ₹0 total / ₹0 collected / ₹0 pending, "No fee collections yet this month". Cause is data, not markup: the seeders create **zero payment records** (`/payments/manage` is also empty). See D3. |
| Today's attendance donut | CSS ring (`--attendance-progress`) + legend | **Renders correctly but EMPTY data**: 0% present, counts 0/0/0/0, honest note "No attendance recorded today." No attendance rows exist for today (feature is new; seeder does not backfill). See D3. |

## Defects

### D1 — CRITICAL — /attendance/report returns 500 (REPORTED, fix is controller-side, outside Workstream C file scope)
- Route exists on master: `routes/web.php` → `Route::get('attendance/report', 'AttendanceController@report')->name('attendance.report')`.
- View exists on master: `resources/views/pages/support_team/attendance/report.blade.php`.
- **Root cause: `AttendanceController` has no `report()` method** — verified in
  the master copy of `app/Http/Controllers/SupportTeam/AttendanceController.php`
  (87 lines; only `__construct`, `index`, `store`). The route therefore throws
  `BadMethodCallException` → 500. The sidebar "Attendance Report" link is live,
  so every staff click on it hits this 500.
- Exact contract the missing method must satisfy (from the view):
  `$classes` (all classes), `$selected_class`, `$month` (Carbon, from
  `?month=Y-m`), `$totals` = `['present'=>int,'absent'=>int,'late'=>int,'marked'=>int]`,
  and `$rows` — one entry per active student in the selected class:
  `['student' => StudentRecord (with user, section), 'present'=>int, 'absent'=>int,
  'late'=>int, 'marked'=>int, 'percent'=>float|null]` where
  percent = (present + late) / marked × 100, null when marked = 0.
  Suggested owner: parent agent (controller), ~30 lines using the existing
  `Attendance` model.

### D2 — MAJOR — Exams list renders a bare, unexplained table (FIXED in C scope)
Live `/exams` had exactly 1 `<tr>` (the header): zero exams in the demo data
and no empty-state message, so the page looks broken. Fixed by adding an
inline-SVG empty state (see Fixes). The underlying data gap is D3.

### D3 — INFO (data) — Demo seed contains no payments and no attendance
Fee ring, attendance donut, Recent Payments and the events strip all show
their (correctly built) empty states on the live demo because the seeders
create no `payment_records`, no `attendances`, and no upcoming `school_events`.
Not a code defect. Optional follow-up for the parent: extend the demo seeder
with a handful of payments/attendance rows so the dashboard charts demo with
non-zero data.

### D4 — INFO — /pins redirects to /dashboard
`GET /pins` → 302 → `/dashboard` (which loads 200). No error; the pins index
apparently defers to the dashboard when no pin context exists. Left as-is
(controller behaviour, outside C scope).

### D5 — MINOR — Main list pages had no empty states (FIXED in C scope)
Classes, Sections, Subjects, Grades, Dorms, Timetables, Users and Students
list (main + per-section tables) rendered header-only tables when their
collection was empty. All now carry an inline-SVG empty state (below).

## Fixes applied on this branch (view-level, Workstream C scope only)

Converted the main list loop in each view from `@foreach` to `@forelse` with
an `@empty` row containing a small inline-SVG inbox icon (app blue #3478f6)
and a muted message, mirroring the Loop 5 Books pattern:

| View | Loop converted | Message |
|---|---|---|
| pages/support_team/exams/index | `$exams` | "No exams found yet. Create the first exam using the form above." |
| pages/support_team/classes/index | `$my_classes` | "No classes found yet. Create the first class using the form above." |
| pages/support_team/grades/index | `$grades` | "No grade bands found yet. Create the first grade band using the form above." |
| pages/support_team/dorms/index | `$dorms` | "No dormitories found yet. Create the first dormitory using the form above." |
| pages/support_team/sections/index | per-class `$sections->where(...)` | "No sections in this class yet." |
| pages/support_team/subjects/index | per-class `$subjects->where(...)` | "No subjects in this class yet." |
| pages/support_team/timetables/index | per-class `$tt_records->where(...)` | "No timetables for this class yet." |
| pages/support_team/users/index | per-type `$users->where(...)` | "No users of this type yet." |
| pages/support_team/students/list | `$students` + per-section `$students->where(...)` | "No students found in this class yet." / "No students in this section yet." |

Note: `students/list.blade.php` was first synced from remote master (it
carried the Loop 2 Marksheet fix that the local clone lacked) — the branch
version is master + the two empty states, nothing reverted.

## New reusable partial

`resources/views/partials/charts/bar.blade.php` — pure-SVG bar chart, no JS
and no library. `@include('partials.charts.bar', ['labels' => [...], 'values' => [...]])`
with optional `chartTitle`, `chartColor` (default #3478f6), `chartHeight`
(default 180), `chartUnit`. Renders gridlines with a rounded axis ceiling,
per-bar value + category labels, `<title>` tooltips, and an inline-SVG
"No data yet." state for empty/zero data. Ready for Workstream A role homes.

## Verification

- Blade directive balance (foreach/forelse/if/section pairs + `{{ }}`
  balance) on all 9 edited views: **pass**, with loop-conversion deltas
  exactly matching the intended conversions vs HEAD.
- Bar partial: directive balance pass; all three `@php` blocks extracted
  and `php -l` clean.
- Every image path referenced: logo + default avatar confirmed present
  under `public/global_assets/images/` and HTTP 200 on the live site.
- Not touched (other workstreams' files): qs.css, HomeController, role
  home views, layout partials, Super Admin dashboard view.
