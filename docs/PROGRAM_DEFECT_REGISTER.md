# Hackstra — Program Defect Register (ChatGPT Master Prompt program)

Started 2026-10-23 under the owner's approval ("Run the whole program —
you set the order"). The ChatGPT master prompt is treated as a bounded
program backlog, not a governing document; the owner's standing rules
(decide + push, milestone reports, truthful content) govern execution.

## Baseline (Phase 1, verified 2026-10-23)

- Laravel 12.70.1, PHP 8.4 (prod Docker), Blade + Bootstrap 4/Limitless.
- 311 routes · 52 controllers · 53 models · 53 migrations · 231 Blade
  views · 5 phpunit test files (thin — expansion is Phase 11 work).
- Live demo: Render, ephemeral MariaDB, reseeds every deploy.
- Gate: fresh `migrate:fresh --seed` (55 users / 37 students) +
  `scripts/qa-smoke.sh` role checks = PASS at every push.

## Verified controls (evidence, not claims)

| # | Control | Evidence |
|---|---------|----------|
| V-01 | Role×URL tamper matrix (student/parent/teacher/SA) | Wave-10 sweep: foreign child 404, foreign marks 403, SA school POST 403 |
| V-02 | Accountant scope: marks/students/library all 302; own payments 200 | Live probe 2026-10-23 |
| V-03 | Librarian scope: payments/defaulters/marks all 302; own books 200; users 302 | Live probe 2026-10-23 |
| V-04 | ID handling: raw integer / garbage / nonexistent hash on `{id}` routes → 404; raw receipt id → safe 302 | Live probe 2026-10-23 |
| V-05 | Admission numbers: `student_records.adm_no` has a DB UNIQUE constraint | Migration `create_student_records_table` |
| V-06 | Marks revisions are audit-logged with per-cell old→new values | `MarkController` → `Audit::log('marks.updated', …)` |
| V-07 | Suspended account's live session dies on its next request | Live: session 200 → suspend → 302 /login (`EnsureActiveAccount`) |
| V-08 | Attendance double-submit / POST replay: exactly 1 session, replay redirected to it with the locked message | Live replay test 2026-10-23 (DB unique key + controller guard) |
| V-09 | Marks over component max rejected server-side (FormRequest) | Wave-10 sweep |
| V-10 | Payment above outstanding balance refused (controller) | Wave-10 + Loop 84 |
| V-11 | CSV exports escape spreadsheet formula injection (`CsvExport::cell`) | Wave-10 fix |
| V-12 | Timetable generate/replace cannot orphan submitted attendance | Wave-10 fix + TT-01 below |

## Findings

### REG-01 — Public registration is broken AND undecided (High, OWNER DECISION)
`POST /register` returns **500**: `NOT NULL constraint failed:
users.code` — the stock `RegisterController` never sets the app
`code` every provisioned user carries, so every self-registration
fails and no account is created. The page itself renders (200), so
the product publicly advertises a signup that always errors.
Policy question (owner's, parked since the full-site audit): a school
system's accounts are normally provisioned by the school.
Options: (a) close registration entirely — remove the routes/link;
(b) fix it into role-less pending accounts an Admin must activate.
Status: **awaiting owner decision** — not changed unilaterally.

### TT-01 — Manual timetable deletes could orphan submitted attendance (Medium, FIXED 2026-10-23)
`attendance_sessions.time_table_id` is `set null` on cell delete.
Wave 10 locked the auto-generate REPLACE path, but the manual paths
stayed open: `TimeTableController@delete` (single cell) and
`@delete_record` (whole record) would orphan registers and free the
same lesson to be marked twice. Both now refuse when any affected
cell carries a submitted session ("Submitted attendance is locked
history"). Verified live both ways: session-carrying cell survives
with the danger flash; a free cell deletes normally.
Residual (Phase 4): `use_time_slot` replaces a record's slot
definitions wholesale; its interaction with existing cells/sessions
needs the same examination.

### ADM-01 — Admission-number collision under concurrency 500s (Low, FIXED 2026-10-23)
`Qs::generateAdmNo()` sequences within one request; two concurrent
requests could mint the same next number — the UNIQUE index (V-05)
then turned the loser's insert into a 500, and the manual Admit
form (not transactional) orphaned the login account it had just
created. Fixed with `Qs::mintStudent()`: all three creation paths
(Admit form, Quick Admit, CSV import) now create user + record in
one transaction through the service, which re-seeds the sequence
from the database and retries with a fresh number ONLY on an
adm_no/username unique violation (any other failure rethrows
immediately). Admin-TYPED numbers are never renumbered — and the
typed path got its own fix: `StudentRecordCreate`'s
`unique:student_records` rule compared the typed SUFFIX against
stored COMPOSITE numbers so it could never fire; it now builds the
composite and refuses duplicates with a form error (QuickAdmit
parity). Verified: occupied next-number is skipped (1000→1001);
a simulated lost race retries and lands on the next number
(2 attempts → 1002); a real duplicate typed number returns to the
form with "Admission number … already exists" and creates nothing;
both forms still mint sequential numbers on the happy path.

## Features delivered under the program

### LIB-FINE — Library fines reflect in the main fee account (owner request, 2026-10-23)
The library had NO fine system (no fine field/action existed).
Built: `library_fines` table + model; `App\Support\LibraryFines`
service. A late return auto-imposes the fine at markReturned —
days overdue × the `library_fine_per_day` setting (₹10 default) —
no librarian arithmetic (Admin-Load-Zero). For a student borrower
the fine is mirrored into the fee ledger as a real "Library Fine"
Payment + PaymentRecord, so it appears in the student's fees, the
parent's My Fees, statements, balances and defaulters, and is
collected through the normal pay-now flow (receipt included).
Paying the record flips the fine to `paid` (sync hook in pay_now);
waiving a pending fine removes the unpaid mirror and keeps the
fine as history; a paid fine cannot be waived. Fines page:
`/library/fines` (teamLibrary; waive = teamLibraryWrite), linked
from Book Loans. Verified end-to-end live: impose ₹50 (5 days) →
parent fees show it → pay → status paid; impose ₹30 → waive →
mirror gone, paid fine's history preserved.
Note: books carry their title in `name`, not `title` (fixed in the
fine reason strings). The sidebar has no Library section at all —
the librarian works from the dashboard + loans page (pre-existing;
owner decision if a sidebar section is wanted).

### SCHED-A — Curriculum requirements + teacher eligibility + workload caps (Scheduling Engine Slice A, 2026-10-23)
The engine had nothing to plan against: subjects carried no weekly
requirement and "who may teach what" was not modeled. Added:
`subjects.periods_per_week` (NULL = not configured — an honest
state, never invented), `subject_type` (theory/practical),
`needs_double`; a `teacher_eligibilities` table + admin page
(`/planning/eligibility`, teamSA/teamSAWrite) recording the
subjects each teacher is qualified for; `App\Support\Staffing`
(caps + eligibility queries every later slice shares); Settings
fields for `teacher_max_periods_day` (default 8),
`teacher_max_periods_week` (default 40) and `library_fine_per_day`
(default ₹10 — the fines rate is now admin-editable too). Verified
end-to-end locally: eligibility save + service readback, subject
planning fields save and render in the list ("6 · Practical ·
Double"), settings values persist and drive the services. One real
bug caught during verification: an unimported `Str` in the new
blade 500'd the page when a teacher was selected — fixed (loop
index ids).

### SCHED-B..F — Staffing, allocation, generator targets, validation, invigilation (2026-10-23)
Slice B: Staffing Dashboard (/planning/staffing) — demand vs
capacity per subject and per teacher from configured data only;
Slice C: allocation workspace with reasoned recommendations,
eligibility as a server-side hard rule (also enforced on manual
subject assignment once a pool exists), cap overrides only with a
reason, all audit-logged; Slice D: the generator places exactly
the configured weekly targets, forms needs_double pairs, leaves
genuinely-free periods empty and reports per-class shortfalls;
Slice F: exam invigilation with balanced auto-assignment and a
hard same date+time clash rule.
GEN-01 (found BY the Slice E validator, fixed same day): a
single-class timetable regeneration ignored every other class's
existing record and double-booked their teachers (10 clashes).
The generator now preloads a by-time busy map from all kept
records; verified 10 → 0 clashes with shortfalls honestly
reported instead. Slice E validation: per-record reports
attribute only clashes involving that record (an early version
multiply-counted the year's clashes — 90 reported for 10 real);
publication states (draft/published) remain held for the owner's
explicit nod, as flagged when the sequence was approved.

### TT-02 — Slot replacement/deletion orphaned placed periods (Phase 5, FIXED 2026-10-23)
The TT-01 follow-up: `use_time_slot` replaced a record's slots
wholesale and `delete_time_slot` deleted single slots, while
cells reference slots by a plain id with NO foreign key — on a
populated record the cells' slot references dangled and submitted
attendance pointed at a grid that no longer existed. Guards:
slot-copy now refuses on any record that has periods placed
(copy belongs on empty timetables; populated ones regenerate),
and a slot with periods in it cannot be deleted. Verified both
directions (populated → refused + slots intact; empty → copy and
delete work). Phase 5 also re-probed: locked period sessions
expose NO edit/delete route at all (submit-only surface, replay
refused — Phase 2 proof stands); the retired day-wise store is
defused by design ("Nothing was saved"); an over-max marks
submission (99999 against the component scheme) left the stored
mark untouched.

### LED-01 / LED-02 — Fee-ledger integrity (Phase 6, FIXED 2026-10-23)
New `LedgerCheck` scanner recomputes the whole ledger (record
balance == fee − paid, paid-flag agreement, overpay, orphans,
receipt-ref uniqueness). On the demo data it reconciles exactly
(₹10,31,300 collected = the Collections anchor; ₹2,79,700
outstanding = the Fee Defaulters anchor), and it caught two real
defects: LED-01 — `reset_record` zeroed a record's balance along
with its payments, making a fully-owing student read as settled
and invisible to the balance-driven Fee Defaulters list; a reset
now restores the full fee as the balance (verified: partial
record reset → 0 paid / full balance / ledger still consistent).
LED-02 — a fee definition could be deleted while student records
existed under it, orphaning the ledger; deletion now refuses for
fees in use (verified both directions). The scanner also runs as
the Readiness page's "Fee ledger consistent" check.

### CONTENT-01 — Assignments + study materials scoping (Phase 7, VERIFIED CLEAN 2026-10-23)
Live-probed on the seeded data: a student opens their own
class's assignment (200) and is denied another class's (302);
the materials index shows a student only their own class's
materials; with a real file planted on a foreign class's
material, the student's direct download returns 403 while the
Admin's returns the file (200, exact bytes). Submission-file
downloads check student / owning teacher / parent-of-student /
teamSA in code. No defects found — this pair was hardened in the
redesign cycle and holds.

### PERF-01 — Page-load baseline (Phase 9, MEASURED 2026-10-23)
Local dev server (PHP built-in, SQLite, demo seed — relative
baseline only, not production numbers): admin dashboard 0.92s
cold / ~0.4s warm, students 0.58s, users 0.46s, staffing 0.45s,
readiness 0.35s, defaulters 0.24s, timetables 0.22s, marks
0.22s; teacher dashboard 0.39s, period attendance 0.38s,
my-timetable 0.24s. Nothing pathological (>2s) at demo scale.
Real performance work (query-count profiling, production
timings on Render) belongs to the post-launch cycle; no code
changed on the strength of dev-server numbers.

### TEST-01 — Engine regression tests (Phase 11, DONE 2026-10-23)
`tests/Feature/ProgramEnginesTest.php`: 7 new tests over the
program's engines — LedgerCheck accepts a consistent record,
flags a corrupted balance, flags overpayment; Qs::mintStudent
allocates unique admission numbers back-to-back; the eligibility
pool unions assigned + listed teachers and excludes others; the
timetable validator detects a planted cross-record teacher
clash; the library fine rate defaults to ₹10 and honours the
setting. All fixtures are self-created inside rolled-back
transactions. Full suite: 21 tests / 71 assertions, OK (baseline
was 14 tests).

## Queued by phase (from the master prompt backlog)

- Phase 3: enrollment/session integrity deep-check; ADM-01 fix.
- Phase 4: allocation/workload views; `use_time_slot` residual;
  engine hardening tests.
- Phase 5–7: attendance/marks/fees edge matrices; content modules.
- Phase 8: School Readiness checks page; Teacher Workload view.
- Phase 9: performance pass (N+1, indexes, unbounded reports).
- Phase 10: break-even model — BLOCKED on owner's real pricing/cost
  inputs (never invented).
- Phase 11: phpunit expansion (IDs/roles/scheduling/finance/import
  matrices), final regression + release-readiness report.
