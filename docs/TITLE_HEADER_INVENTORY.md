# Title / Common-Header Inventory — Workstream BS (Loop 46)

Owner directive (2026-10-21 23:39 IST): one best common header for the whole
app; a page's title renders exactly ONCE. Proof cases: My Payslips (header
"My Payslips" + card title "My Payslips") and Circulars feed (header
"Circulars" + card title "Circulars & Notices").

## Method
- Scripted logins for all 7 roles; BFS crawl of every reachable GET page
  (430 page records, 429 × HTTP 200; 121 distinct pages after id-normalising
  URLs). For each page: layout-header title (`#page-header`), `<title>`, and
  every in-content heading (`h1–h6`, `.card-title`) matching the title.
- Cross-checked against blade sources (every `@section('page_title')` vs
  literal headings in the same file).
- The crawler's heading list double-counts `.card-title` elements (two
  regexes); "×2" in raw crawl output for a card title = ONE element.

## Root causes found
1. **Legacy card titles** — old pages carry `<h6 class="card-title">` in the
   first card repeating the page title (the My Payslips disease).
2. **Pattern partials** — `partials/patterns/list_page` prints its `title`
   slot as an `<h4>` toolbar heading and `partials/patterns/form_page`
   prints its `title` slot as an `<h5>` — so every pattern page also
   rendered its title twice (layout header + pattern heading).

## CURED — pattern partials (one fix, every consumer)
- `partials/patterns/list_page.blade.php` — title slot no longer rendered as
  a heading; toolbar renders only when a subtitle or action exists.
  Consumers cured: classes, sections, subjects, users index; students list,
  graduated, promotion; marks bulk selector; payments invoice.
- `partials/patterns/form_page.blade.php` — title slot no longer rendered
  as a heading; form head renders only when a subtitle exists.
  Consumers cured: circulars create/edit, students add/edit, users
  create/edit, settings, classes/sections/subjects create forms.
- `payments/invoice.blade.php` — the one informative pattern title
  ("Manage Payment Records for {student}") was demoted into the subtitle
  slot (name preserved); the generic title slot removed.

## CURED — legacy pages (in-page duplicate removed)
Exact repeats (heading deleted; card header kept only where it holds real
actions, otherwise the empty header block went too):
parent/children ("My Children"), payroll/my_index ("My Payslips" — proof
case; "Total received so far" + table untouched), attendance_periods/report,
attendance/report ("Monthly Attendance Report" under "Attendance Report"),
books/index, books/create, books/edit, book_requests/create,
circulars/index ("Circulars"), circulars/feed ("Circulars & Notices" —
owner-flagged case), dorms/index, dorms/edit ("Edit Dorm" under
"Edit Dorm - {name}"), classes/edit ("Edit Class" under "Edit Class -
{name}"), events/index, events/create ("New School Event" under "New
Event"), events/edit ("Edit School Event" under "Edit Event"),
exams/index, exams/admit_cards, grades/index, grades/edit,
marks/index, marks/select_year, marks/tabulation (selector card only; the
results card "Tabulation Sheet for {class} - {exam} ({year})" is a real
document heading and stays), payments/manage, payments/create,
payments/edit, payments/collection, timetables/index (the "Timetables"
card only; the separate "Create Timetable" form card is a genuinely
different label and stays), timetables/edit, timetables/mine.

Qualified repeats (title + qualifier) — heading demoted to a muted
qualifier line in the same card header, information preserved:
- attendance/defaulters → "Below {threshold}%"
- attendance_periods/index → "{date}"
- payments/defaulters → "Session {year}"
- payroll/index → "{Month} {year}"
- super_admin/audit_logs → "Who did what, and when"
- students/promotion/reset → "Students who were promoted from {old} to
  {new} session" (was a full sentence repeating "Manage Promotions")
- exams/scheme/index → "Term {term} · {year}" (card repeated
  "Marking Scheme — {exam} (Term, year)" under header "Marking Scheme —
  {exam}")
- exams/scheme/edit → "{exam} · Term {term} · {year}" (card repeated the
  whole "Marking Scheme — … · Class: …" line under the header)

Dynamic-title repeats (found via the owner's 23:53 IST flags + a
dynamic-expression sweep; the literal sweep cannot see these):
- sections/edit — header "Edit Section of {class}" + card title repeated
  it verbatim (owner-flagged screenshot). Cured (block removed).
- exams/index — header "Manage Exams" + card title "Manage Exams"
  (owner-flagged screenshot; the cure was already in the legacy sweep
  above — his screenshot shows the live, pre-fix build).
- exams/edit — card "Edit Exam" under "Edit Exam - {name} ({year})".
  Cured.
- subjects/edit — card "Edit Subject - {class}" under "Edit Subject -
  {subject} ({class})". Cured.
Sequencing note for the parent: Workstream BW will migrate the sections
edit FORM onto form_page and polish the exams index table/actions. This
workstream changed ONLY the heading layer of sections/edit (duplicate
card-header block removed; the form markup is untouched) and of
exams/index (duplicate card title removed; table/actions untouched), so
BW can build on either merge order — if BW's tree predates this one,
its versions of these two blades must re-apply the heading cure.

## Round 2 — owner screenshot round 2 (2026-10-22 00:01 IST)

Owner: fix this class EVERYWHERE, not only on flagged pages. A second
token-overlap audit (titles vs every h1–h6, card titles, tab labels,
subheadings, breadcrumb pairs) ran over all blades; results:

1. **Attendance Report** (attendance/report) — header "Attendance
   Report" vs card title "Monthly Attendance Report": already cured in
   Round 1 (the owner's screenshot shows the live, pre-fix build).
   Exact diff line: removed
   `<h6 class="card-title">Monthly Attendance Report</h6>`; the card
   header keeps its Print / Export actions untouched.
2. **Attendance Defaulters** (attendance/defaulters) — SEPARATE CALLOUT
   for the parent (BV rename interaction). On THIS tree:
   - Before: `<h6 class="card-title">Attendance Defaulters — below {{ $threshold }}%</h6>`
   - After:  `<span class="text-muted">Below {{ $threshold }}%</span>`
   BV (master commit 041cdf1a, post-dates this tree) renamed the pair
   to header "Low Attendance (Below 75%)" / card "Low Attendance
   (Below {{threshold}}%)". Re-applying this cure onto BV's text: the
   card heading must GO (delete it, or demote to a genuinely different
   line) — do NOT demote it to "Below {{threshold}}%" there, because
   BV's header already carries "(Below 75%)", so the qualifier alone
   would still repeat the header. Net effect wanted on master: header
   "Low Attendance (Below 75%)" + no card heading.
3. **Manage TimeTables** (timetables/index) — header "Manage
   TimeTables" vs card title "Create Timetable": cured (card-header
   block removed; the create form is untouched). Tabbed-card class
   ruling applied app-wide: a STATIC card heading that restates the
   page title or the active tab's name is removed/demoted. The tab
   strips themselves (dorms "Manage Dorms / Create New Dorm", grades
   "Manage Grades / Add Grade", exams "Manage Exam / Add Exam",
   payments "All Classes", invoice "Incomplete/Completed Payments",
   timetable-manage pane tabs) are functional pane switchers, not
   headings — after Round 1 those pages carry NO static card heading,
   so no title is rendered twice there; tab labels were left
   byte-identical (navigation, not chrome).
4. Additional Round-2 cures from the token-overlap audit:
   - payments/index — card title "Manage Payments for {{ $year }}
     Session" demoted to muted "{{ $year }} Session" (Collect Fee and
     Select year cards keep their genuinely different labels).
   - parent/fees — "Fees — {{ $year }}" demoted to muted
     "Session {{ $year }}".
   - parent/fees_show — "Fees for {{ $sr->user->name }} — {{ $year }}"
     demoted to muted "{{ $sr->user->name }} — {{ $year }}" (the child
     identifier is preserved; the repeated title word is gone).
   - exams/admit_my — card title "Admit Cards" under header "My Admit
     Cards": card-header block removed.
   - timetables/time_slots/edit — card title "Edit Time Slots" under
     header "Edit Time Slot": card-header block removed.
5. Reviewed and KEPT after Round-2 audit (not duplicates): marksheet
   heading "Student Marksheet — {student} ({class})" and tabulation
   heading "Tabulation Sheet for {class} {section} - {exam} ({year})"
   (entity identifiers — the heading names WHICH document, like
   profile-name headings); payslip "PAYSLIP" document heading;
   My Account's single h1 under the hide_page_header contract;
   pins/* blades (unreachable fossils — routes removed with BH).
   Breadcrumb-vs-H1 pairs: none exist app-wide (no page supplies a
   breadcrumb; My Account's was removed by BN).
6. Caught only by the full-coverage re-crawl (all roles authenticated;
   earlier crawls silently under-covered non-SA roles — see method
   note in the workstream report):
   - book_requests/index — card title "Book Loans & Requests" repeated
     the header verbatim (source audit missed it: `&amp;` entity).
     Heading line removed; the header's Catalogue / Issue a Book
     actions are untouched.
   - attendance/my — ternary card title rendered "My Attendance" for
     students (== header) and "Child Attendance" for parents. Now the
     heading renders only in parent mode, where it genuinely differs.
   - students/id_card_mine — ternary card title rendered "My ID Card"
     for students (== header) and "Student ID Cards" for parents.
     Same cure: heading renders only in parent mode.

## REVIEWED — deliberately NOT duplicates (unchanged)
- Role home pages: designed heroes/greetings; no layout page-header title
  is rendered there (title source is the document title only).
- Student/User profile pages: the in-page heading is the person's NAME
  (content identity block), the header carries "Student Profile - {name}".
- Payslip page: "PAYSLIP" is the printed document's own title (the layout
  header is suppressed in the page's print CSS).
- Student ID card page: the card is a document; its heading is the
  document label.
- circulars/show: header says "Circular"; the card title is the circular's
  own subject line (content).
- students/import: the upload card's heading ("Admit Many Students At
  Once — Upload a CSV File") is a descriptive section label for that card,
  distinct in wording and content from the page title.
- my_account: suppresses the layout header by contract
  (`hide_page_header`) and renders its own single heading — the model case.
- exams/admit_cards per-exam sub-headings ("{exam} — N student(s)"): real
  section labels.

## Other same-class defects logged for the parent (NOT fixed here)
1. My Account (redesigned page): tab labels and the side info cards repeat
   each other — "Preferences" appears as a tab and as a card title;
   "Account Security" / "Account Actions" likewise pair a tab-adjacent
   label with a card title. Same words, different UI roles — owner's call.
2. Privacy Policy page: the document title "PRIVACY POLICY" appears in the
   header and again as the document's own heading.
3. Timetable manage pages repeat their panel labels ("Add Time Slots",
   "Use Existing Time Slots", "Time Slots - {name}") across tab panes —
   plausibly one per pane by design; needs a visual check.
4. Period-attendance session page repeats its session heading
   ("{Subject} — {Class} Submitted ✓ Locked") — appears twice in the
   rendered headings (banner + card).
5. Pins blades (pins/create, pins/enter, pins/index) still carry the
   legacy duplicate card titles, but their routes were removed with the
   PIN system — unreachable fossils; cure only if the pages ever return.
6. The teacher home's special subheader (partials/header teacher branch)
   prints "Current Session: {session}" as small text under the date —
   informational, inside the teacher dashboard header only; noted because
   the session also lives in the topbar chip.

## Verification
Re-crawl after the fix: every cured page must show its title exactly once
(layout header only, or the page's own single heading where the header is
suppressed by contract). See the workstream report for the before/after
table (My Payslips and Circulars feed included as proof cases).
