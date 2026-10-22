# Pre-Ship Hardening Audit — Hackstra (Workstream DI, completed by DI-2)

Base: `origin/master` `ec0634b6` · Branch: `feature/pre-ship-hardening` · All findings proven
empirically against the seeded app on a local server (scripted logins, real HTTP requests,
DB state checked before/after every probe). Verdicts: **PASS** (tested, holds) /
**FIXED** (hole proven, fix committed, re-proven) / **GAP** (residual observation).

Commits: `865f27f` (item 1) · `7351738` (item 2) · `29f68e3` (item 3) · `de510d8` (item 4) ·
`ca3aa8e` (item 5).

---

## Item 1 — Debug / log hygiene sweep — **PASS (cleaned)**

- Removed the dead `TestController` scaffold and its commented-out `/test` route.
- Removed an **active** `console.log(resp)` from the sections/subjects AJAX handler in
  `partials/js/custom_js.blade.php` (leaked full AJAX responses to any browser console),
  plus its two commented-out twins.
- Verified clean afterwards: no `console.log`, `dd()`, `dump()`, `var_dump` or `print_r`
  anywhere in `app/`, `routes/` or views.

## Item 2 — Hostile uploads: client-controlled extension in `Qs::getFileMetaData` — **FIXED · HIGH**

**The DI suspicion was correct, and worse than "unproven".** The shared helper copied
`getClientOriginalExtension()` and `getClientMimeType()` — both attacker-controlled — into
the stored file's metadata, and every upload surface builds its stored name as
`'<prefix>.' . $f['ext']` on the **public** disk (`storage/app/public`, served at `/storage/…`).

Proven pre-fix (seeded server, real uploads):

| Surface | Call site | Attack file landed as |
|---|---|---|
| My Account profile photo (any role, incl. teacher) | `MyAccountController.php:70` | `uploads/teacher/<code>/photo.html` |
| School logo (Admin settings) | `SuperAdmin/SettingController.php:81` | `uploads/logo.html` |
| School stamp (Admin settings) | `SuperAdmin/SettingController.php:95` | same helper, same mechanism |
| Student photo — create & update | `StudentRecordController.php:106, 236` | `uploads/student/<code>/photo.html` |
| Staff/user photo — create & update | `UserController.php:151, 207` | same helper, same mechanism |

Attack file = valid GIF header + `<script>` payload, filename `evil.html`, part Content-Type
`image/gif`. It **passed** validation (the sniffed content really is an image), was stored as
`.html`, and was served **unauthenticated** as `Content-Type: text/html` with the script in the
body — a stored-XSS payload hosted on the app's own origin, reachable by anyone who opens the
"photo" URL. The DB even publishes that URL as the user's photo / school logo.

What was *not* exploitable, and why the severity is HIGH rather than critical: direct
`.php`/`.phtml` filenames are refused today — Laravel's `image`/`mimes` validators carry a
php-upload blocklist (`php, php3–php8, phtml, phar`) keyed on the client extension, and all
seven surfaces validate with `image|mimes:jpeg,gif,png,jpg,webp`. But that defence lived
**only in the form requests**; the shared helper would have turned any future validation
lapse (or any new caller) into a stored `photo.php` — remote code execution. Defence in the
wrong layer is not defence.

**Fix (shared helper only, `app/Helpers/Qs.php`):** the extension is now derived from the
file's sniffed real type (`guessExtension()`), clamped to the image extensions the app
accepts (`jpg, jpeg, png, gif, webp`, fallback `jpg`); the stored type comes from the sniffed
`getMimeType()`. One change protects all 7 call sites enumerated above (the only upload
surfaces in this tree — verified by grepping every `storeAs`/`move` in `app/`).

Re-proven post-fix: the `evil.html` attack now stores as `photo.gif` and is served as
`image/gif` on My Account and on admin user-create; legitimate uploads keep working with
their true extensions on every surface — JPG (My Account), WebP logo, PNG stamp, PNG student
photo, GIF-content user photo.

## Item 3 — Input-validation spot audit, 10 riskiest write endpoints — **FIXED (5 defects) / PASS (5)**

All probes sent invalid payloads (empty, wrong type, negative, over-range, nonexistent
references) and checked HTTP behaviour + DB state (no 500s, no partial writes allowed).

### Defects proven and fixed

1. **`POST /payments/pay_now/{id}` — money integrity, HIGH.** `PayNowRequest` required only
   `numeric`. `amt_paid=-5000` was accepted: it *reduced* a student's paid total
   (7200 → 2200) and issued a real receipt for −₹5,000. `amt_paid=999999` against a ₹10,800
   balance was accepted: balance went to **−₹9,84,199**, record marked paid, receipt issued.
   Fix: rule is now `required|numeric|min:1`, and the controller refuses amounts above the
   outstanding balance with a graceful validation error (422 JSON / redirect). Re-proven:
   both attacks refused with zero state change; a legitimate ₹800 partial payment records
   and receipts exactly as before.
2. **`POST /payments` — fee-head creation, MEDIUM.** `amount` was only `required`: the strings
   `abc` and `-100` were stored as fee amounts (text in the amount column — downstream PHP 8
   arithmetic on it throws). Fix: `PaymentCreate.amount` = `required|numeric|min:0`;
   `PaymentUpdate` gained `sometimes|numeric|min:0` (it validated amount not at all).
   Re-proven: `abc` now bounces, no row written.
3. **`POST /users` with an empty payload — 500, MEDIUM.**
   `UserRequest::getValidatorInstance()` read `$input['user_type']` unguarded
   ("Undefined array key"). Guarded, mirroring the six sibling requests. Re-proven: 302
   validation bounce, no row.
4. **`PUT /students/{id}` — two defects, MEDIUM.** (a) Omitting the optional `my_parent_id`
   500'd — `StudentRecordUpdate` lacked the `?? null` guard its Create twin has; mirrored.
   (b) `my_class_id=99999` was *stored*, orphaning the student into a nonexistent class
   (marks cleanup then ran against the bogus class). No foreign key was existence-checked.
   Fix: `exists:` rules added for `my_class_id, section_id, state_id, lga_id, nal_id,
   my_parent_id, dorm_id, bg_id` on **both** student create and update requests.
   Re-proven: bogus class bounces and the record is unchanged; valid updates still save.
5. **`POST /exams` — term validation, LOW.** A duplicate (term, session) is refused with the
   friendly "already exists in the current session" message (verified working), but
   `term=99` was stored. `ExamCreate`/`ExamUpdate` term is now `in:1,2,3`. Re-proven.

### Audited clean (graceful errors, no 500, no partial writes)

- `POST /students` (empty payload → validation bounce).
- `PUT /super_admin/settings` (too-short system name / missing session → bounce, stored
  values unchanged).
- `POST /students/import` — binary garbage and wrong-column CSVs import **nothing** and
  bounce gracefully (student/user counts invariant).
- `POST /payroll/generate` — garbage period input is clamped to the current period by
  `resolvePeriod()` (month 13 → current month, year `abc` → current year); no bogus-period
  rows, no 500. Noted as designed behaviour, not a defect.
- `PUT /marks/update/{exam}/{class}/{section}/{subject}` — over-max (99999 vs max 60),
  negative and non-numeric component cells are all rejected by `MarkComponentUpdate`;
  stored scores byte-identical afterwards.

## Item 4 — Page weight / slow-network measurement — **FIXED (one cheap win) / GAP (two observations)**

Measured on the seeded server, uncompressed bytes, HTML + every same-origin asset referenced:

| Page | Before | After |
|---|---|---|
| Login (guest) | 269 KB (3 assets) | unchanged |
| Each role dashboard (SA/Admin/Teacher/Student/Parent/Accountant/Librarian) | **≈ 2.71–2.74 MB** (27–28 assets) | **≈ 0.76–0.79 MB** |

Heaviest assets app-wide: `pdfmake.min.js` **948 KB**, `vfs_fonts.min.js` **850 KB**,
`qs.css` 210 KB, `fullcalendar.min.js` 209 KB, `fileinput.min.js` 91 KB,
`datatables.min.js` 81 KB, `jszip.min.js` 75 KB, `buttons.min.js` 72 KB, `moment.min.js` 50 KB.
Local HTML response times were 0.03–0.08 s — on slow school connections the bottleneck is
payload, not the server.

**Cheap win (committed):** the DataTables Buttons extension stack — JSZip + PDFMake + font
bundle + Buttons ≈ **1.95 MB** — was loaded by `partials/inc_bottom` on *every* page, but its
only live consumer is the students list's export buttons
(`table.datatable-button-html5-columns`: copy/excel/pdf/colvis). The four scripts now load
only on the `students.list` route. Verified: dashboards reference pdfmake 0 times and dropped
≈ 71%; the students list still loads all four scripts. (The retired pins page was the other
historical consumer; its routes no longer exist — `PinController` is orphaned code in this
base, observation only.)

**GAP — observations, deliberately not changed (not cheap wins):**
- `qs.css` (210 KB) ships on every page including login. Shrinking it needs a
  bundling/minification step — a build-pipeline decision, not a page edit.
- FullCalendar + moment (~260 KB) ride every dashboard; calendar widgets live there, so a
  per-widget lazy-load is the right shape but a larger change than this audit's remit.

*Integration note:* the gate keys off the route name `students.list`. The queued students-list
redesign (Workstream BD) restyles that page — if it drops the buttons table, simply delete the
gate (nothing else consumes the scripts); if it keeps the table, the gate is already correct.

## Item 5 — Break-it round 2: cross-scope IDOR + mass assignment — **PASS (IDOR) / FIXED (one low)**

### IDOR battery (hashed-id routes) — all denied, in-scope controls all 200

| Probe | Result |
|---|---|
| Teacher (classes 1/2/4) → show student record in class 3 | Denied (redirect to dashboard) |
| Teacher → edit student record in class 3 | Denied (redirect) |
| Teacher → marks bulk for class 3 | **403** |
| Teacher → show own-scope student (control) | 200 |
| Parent → `my_fees/{id}` of a non-child | **403** |
| Parent → own child's fees (control) | 200 |
| Parent → record of a non-child | Denied (redirect) |
| Student → another student's record | Denied (redirect) |
| Student → another student's marksheet | Denied (redirect) |

Followed denial redirects contain **zero** bytes of the target's data (name checks on the
rendered pages). The marksheet controller's ownership check (owner / parent-of-child /
staff) fires explicitly before the result-publish gate — which also explains why the owner's
*own* marksheet redirects in this seed: exam results are unpublished, and unpublished results
are withheld from students/parents by design (Workstream BH).

### Mass assignment

- `POST /users`: crafted `id` dropped (not fillable), crafted `code` dropped (server-generated
  after payload assembly), crafted `user_type` cannot escalate — an Admin creating a
  **super_admin** is refused server-side by the CM level check
  (`{"ok":false,"msg":"ACCESS DENIED…"}`, zero rows). **PASS.**
- `POST /students`: crafted `user_id` / `grad` / `id` all dropped (whitelist assembly +
  explicit overwrite). **PASS.**
- **FIXED · LOW:** `email_verified_at` is fillable on `User` and flowed straight from the
  request through `UserController` store *and* update (`$data = $req->except(['emp_date'])`,
  never overwritten) — a crafted create set it to an arbitrary past timestamp (proven:
  `2020-01-01 00:00:00` stored). Impact is low today (nothing in the app gates on the
  timestamp — accounts are admin-provisioned), but it was a genuine uncontrolled write to a
  trust field. Store now forces `null`; update preserves the stored value. Re-proven.

---

## Residual GAPs (observations carried forward, no fix attempted)

1. CSP remains unshipped (see DG) — unchanged by this audit.
2. `qs.css` size and FullCalendar-on-every-dashboard (item 4 observations).
3. `PinController` + pins views are orphaned (routes removed by BH); harmless, delete in a
   future cleanup if the feature is not returning.
4. The gateway (CU) online-payment path is not in this base; when it integrates, its
   server-side amount derivation should be re-checked against the item-3 pay_now standard
   (never trust a client-sent amount; cap at the outstanding balance).

## Final gate

Fresh `migrate:fresh --seed` on this branch, then `scripts/qa-smoke.sh`
with `BASE_URL=http://127.0.0.1:8983` — see the commit following this document for the result.
