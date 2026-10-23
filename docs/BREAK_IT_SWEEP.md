# Break-It Sweep — 2026-10-23

Owner mandate (2026-10-23): try to break the system — every module,
every parameter, every combination — collect what surfaces and fix it.
This pass ran scripted role×URL tampering, write-block probes, and
abuse cases against the new generators and the money/marks paths.
(The owner is additionally running an independent ChatGPT audit from
`Hackstra-ChatGPT-Audit-Prompt.md`; its findings will be cross-checked
against the code in a follow-up pass.)

## Found and FIXED in this pass

1. **Timetable replace could orphan submitted attendance.**
   Attendance sessions reference timetable cells (`time_table_id`,
   FK `set null`). The new generator's replace mode deleted a class's
   cells wholesale — sessions survived but lost their period link,
   and the same lesson could then be marked twice under the new grid.
   **Fix:** replace mode now refuses any class whose record already
   carries submitted attendance ("timetable locked this session"),
   names it in the result message, and leaves its grid untouched.
   Verified live: class with 4 sessions + 15 cells unchanged after a
   replace attempt; a class without sessions replaces normally.
   *Residual note:* the manual Manage page can still delete a single
   cell that has sessions (same set-null orphaning, one cell at a
   time, deliberate admin action). Flagged for a follow-up guard.

2. **New streamed CSVs bypassed formula-injection escaping.**
   The shared `CsvExport` prefixes cells starting with `= + - @`
   so a typed name can never become a spreadsheet formula. The two
   streamed exports added on 2026-10-23 (attendance report, student
   attendance report) wrote raw `fputcsv`. **Fix:** both now map data
   rows through `CsvExport::cell()` (visibility widened to public).

3. **Timetable generator accepted a school day running past midnight.**
   12 periods × 90 minutes from 08:00 generated slots into the next
   day. **Fix:** the generator refuses a day ending after 9:30 PM
   with a plain-language message. Verified live.

## Attacked and HELD (no change needed)

- **Role × URL tamper matrix** (fresh seed, live requests):
  student → payments / students list / marks manage / other students'
  attendance reports / users: all redirected to own scope; settings 404.
  Parent → another child's student profile: **404** (no existence leak);
  admin surfaces redirected. Teacher → payments/users redirected;
  marks grid for a class they don't teach: **403**.
- **Super Admin write block**: POST events and POST circulars with a
  valid CSRF token → **403** (PlatformOnly); school GETs → 302 to the
  platform dashboard.
- **Student-report scoping**: teacher opening a non-taught student's
  report → 403; "all classes" CSV for a teacher contains only their
  classes.
- **Marks over-max**: the marks FormRequest proves every cell is a
  whole number within its configured component max; invalid saves are
  rejected server-side, never silently wiped.
- **Payment overpay**: `pay_now` refuses amounts above the outstanding
  balance with a validation error (negative-balance bug was fixed in
  an earlier loop; re-verified at the code level in this pass).
- **CSV formula injection** via the shared exporter: escaped by design.
- **Date-sheet generator extremes**: 30 days × gap-10-after-every-day
  + a garbage skip-date string → clean redirect, no crash; day list is
  built under a hard iteration guard and slot counts derive from the
  built list, so a shortened calendar degrades honestly (the result
  message always states the real first–last dates).
- **Invoice id-type confusion**: `/payments/invoice/{receipt-hash}`
  (wrong id kind) redirects safely to the dashboard with a danger
  flash instead of erroring or leaking.
