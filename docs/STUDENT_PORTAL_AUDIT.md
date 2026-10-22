# Student Portal Audit — 2026-10-22

Scope: every page a signed-in **student** can reach (student menu +
shared sidebar + student-accessible routes), checked against the
2026-10-22 evening redesign pass (qs.css Loops 75–99). Method: route
table + `pages/student/menu.blade.php` + shared `partials/menu.blade.php`,
verified live as `student@hackstra.app` on a fresh seed.

## Page-by-page coverage

| # | Page | Route(s) | In tonight's pass? | Notes |
|---|------|----------|--------------------|-------|
| 1 | Dashboard (student home) | `dashboard` → `pages/student/home` | **Yes — Loop 99** | KPI strip (attendance %, subjects, open assignments, own fee status), today's classes with teacher names, assignments-due panel, subjects & teachers, attendance summary, quick actions, recent circulars, reminders. Earlier design: Loop 12. |
| 2 | Marksheet — year selector + term sheet | `marks.year_selector`, `marks.show`, `marks.print` | Yes — Wave 3 documents pass (print), Loop 84 selector chrome (shared partial) | Publish gate untouched; dashboard links go through the year selector, never around the gate. Teacher access to student marksheets was closed separately (fix113). |
| 3 | Annual / cumulative report | `marks.annual`, `marks.print_annual`, `marks.pdf_annual` | **Yes — Loop 85** | Screen ≡ print ≡ real PDF parity verified in that loop. |
| 4 | Admit Card | `admit_cards.my`, `admit_cards.my_card` | Yes — Wave 3 documents pass | Student prints only their own exam cards; the staff-override hole was closed by fix113. |
| 5 | My Attendance | `attendance.my` | **Yes — Loop 80** | Monthly self view on the same `monthlyReportData()` assembly as the staff Monthly Attendance Report (whose print + PDF shipped in that loop). Dashboard % verified equal to the report figure for the same student. |
| 6 | My Timetable | `tt.mine`, `tt.mine_print`, `tt.mine_pdf` | **Yes — Loop 81** | Includes real PDF download. |
| 7 | My ID Card | `id_card.mine` | Not in tonight's pass | Last designed in Loops 27/42 (previous rounds). Functional; no Loop 75–99 changes. |
| 8 | My Assignments (list + detail + submit) | `assignments.index`, `assignments.show`, `assignments.submit`, `assignments.download` | **Yes — Loop 82** | Student list/detail redesigned with the LMS pass; dashboard panel (Loop 99) reuses its scoping + the CS1 open rule. |
| 9 | Study Materials | `materials.index`, `materials.download` | **Yes — Loop 82** | Same LMS pass. |
| 10 | Circulars (list + detail + print/PDF) | `circulars.index`, `circulars.show` | **Yes — Loop 75** | Shared all-role surface; student sees only student/all-audience, in-window, published circulars. |
| 11 | Ask Hackstra | `ask.index` | **Yes — Loops 75 + 88** | Role-scoped RAG chat; suggested chips render only when the student's corpus is non-empty. |
| 12 | My Account | `my_account` | **Yes — Wave 9 / Loop 74** | All-role page; one-screen rebuild. |
| 13 | Give Feedback / My Feedback | `feedback.create`, `feedback.mine`, `feedback.mine.show` | **Yes — Loop 87** | Real status model (new/acknowledged/in_progress/resolved/wont_fix/duplicate). |
| 14 | Notifications (bell) | shared topbar | Yes — global chrome (sidebar/topbar loops) | Student/parent bell lists are written by the app; no separate page exists. Super Admin has no bell by design. |

## Gaps found

1. **No student fees page.** The fees portal (`my_fees`, `my_fees.show`,
   `my_fees.receipt`, online pay) sits behind the `my_parent`
   middleware — verified live: a student requesting `/my_fees` is
   bounced to login. A student can see their fee *status* only on the
   dashboard (Loop 99 KPI card, display-only, from the same
   `PaymentRepo::getAllMyPR` session assembly parents see). If students
   should see their full invoice/receipt history, that is a small new
   page reusing the same assembly with ownership = own user id.
2. **No student library page.** Book loans appear only as dashboard
   reminder chips (due/overdue). The books module (`books.*`) is
   staff/librarian-facing; students have no catalogue browse or
   book-request page.
3. **No student events page.** `events.index` is teamSA-only in the
   shared menu. `HomeController` already computes `upcoming_events`
   for the student home, but neither the Loop 12 nor the Loop 99 home
   renders it — students cannot browse the school calendar.
4. **ID Card not revisited tonight** (see #7) — the only
   student-owned page still on a previous-round design.
5. **Defaulter document block is intentionally not on the dashboard.**
   Per the owner's ruling it lives at the document endpoints (admit
   card / marksheet generation, sibling squad); the dashboard fee card
   states the due amount honestly and the documents themselves refuse
   generation until dues clear.

## Data-integrity notes (Loop 99)

- Attendance % = `HomeController::attendanceSummary` (present + late
  over marked entries) — the same computation the Monthly Attendance
  Report prints; cross-checked live (Aarav Sharma: 100%, 4/4, matches
  the report row exactly).
- Fee figures = `PaymentRepo::getAllMyPR(own id, current session)` —
  the parent home / fees portal assembly; cross-checked against the
  invoice sums in the database (paid-in-full and ₹14,400-due cases).
- Assignments = student scoping from `AssignmentController@index`
  (own class + section) with the CS1 `submit()` open rule; own
  submission state from the student's own submissions only.
- Isolation proven with a second student in a different class: no
  other student's name, class, figures, or assignments appear in any
  widget.
