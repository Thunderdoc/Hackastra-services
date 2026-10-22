# Bulk Import & Bulk Edit System — Phase 1 Audit & Build Plan

**Status:** READ-ONLY audit + design. No behaviour changed; this document is the
deliverable the owner approves before any code is written.
**Tree audited:** `audit/bulk-system` @ `integrate/wave9-myaccount` tip
(910c500), fresh seed, Laravel 11 / PHP 8.3 CLI (Docker image PHP 8.4).
**Date:** 2026-10-22.

---

## 1. What exists today (current-state findings)

### 1.1 Student CSV import — `StudentBulkController` (Workstream AA)

File: `app/Http/Controllers/SupportTeam/StudentBulkController.php` (532 lines).
Routes (`routes/web.php`, students group): `GET students/import`
(`students.import`), `GET students/import/template`
(`students.import.template`), `POST students/import`
(`students.import.store`), `POST students/bulk_delete`
(`students.bulk_delete`). Entry points in the UI: an **Import CSV** button on
the student list page (`students/list.blade.php`) and the import page itself.
There is **no menu entry** for it.

**Columns (template order, `StudentBulkController::COLUMNS`):**
`name, email, gender, phone, phone2, dob, address, nationality, state, lga,
blood_group, class, section, parent_email, year_admitted, dorm,
dorm_room_no, house, adm_no` (19).
**REQUIRED (9):** `name, gender, address, nationality, state, lga, class,
section, year_admitted` — deliberately the same set the single Admit Student
form requires (`StudentRecordCreate`), minus the id-based fields, which the
importer takes **by name** and resolves to ids (class, section within class,
state, LGA within state, nationality, blood group, dorm).

**How `importStore` works today — one fused pass, no staging:**

1. Validates only the upload itself (`required|file|max:10240`, then a
   client-supplied extension check `=== 'csv'`).
2. Streams the file with `fgetcsv` (BOM-safe header normalisation in
   `readHeaderRow`; unknown columns ignored by `mapRow`).
3. For each row: `validateRow()` → on success **immediately**
   `createStudent()`; errors are collected in memory as
   `['row' => line, 'name' => …, 'messages' => […]]`.
4. `createStudent()` builds the identity exactly as
   `StudentRecordController@store`: username = admission number =
   `APPCODE/<class-type code>/<year_admitted>/<serial>` upper-cased (serial =
   the row's `adm_no` when given, else `mt_rand(1000, 99999)`), `code` =
   random 10, default password `Hash::make('student')`, default photo; then
   **one `DB::transaction` per row** creating the `users` row
   (`UserRepo::create`) + `student_records` row
   (`StudentRepo::createRecord`). A failed row never poisons its neighbours.
5. Result: redirect back with the report in **session flash**
   (`import_report`) + one summary `Audit::log('student.imported', …)`.

**What this means for the new system:**

| Aspect | Today | Consequence for the design |
|---|---|---|
| Preview before commit | **None** — valid rows are created while the file is being read | The #1 gap the owner's brief targets. The fused pass must be split into *stage → validate → preview → apply*. |
| Update mode | **None** | New build (§4). |
| Report persistence | Session flash only — gone on refresh/navigation; **not downloadable** | Needs the jobs/rows tables in §5. |
| Import history | One summary audit-log line per import | No per-file record, no counts by file, no error archive. |
| Duplicate protection | Email uniqueness checked (in-file + DB) and adm_no uniqueness checked — **but only when the row carries an email / explicit adm_no**. A row with neither that is uploaded twice creates a second student with a fresh random serial | Re-uploading the same file is **not idempotent today**. The new engine must fingerprint rows (§6). |
| File types | CSV only; extension taken from the client filename | xlsx support needs a real reader (§3). Content sniffing should replace the extension check. |
| Size / scale | 10 MB cap; whole file processed inside one HTTP request; no chunking, no progress, no retry | Fine for hundreds of rows; risky for "thousands" (§6 processing model). |
| Error granularity | Row-level list of message strings; messages are **not keyed by field** and don't carry the supplied value | The brief's error table (row / field / value / explanation) needs `validateRow` refactored to emit structured errors (§4.2). |
| Audit | Summary line only | Keep, and extend with the job id. |

**What IS reusable, almost as-is:**

- `validateRow()` — already side-effect-free validation + name→id resolution
  (it only *reads*: uniqueness probes and lookups). This is the seed of the
  preview engine's validator. Caveat: it mutates the row by reference
  (writes resolved ids back) — exactly what a staging row wants to store.
- `buildLookups()` — name→model maps for classes, sections-by-class,
  states, LGAs-by-state, nationalities, blood groups, dorms.
- `createStudent()` — the per-row transactional create (user + record
  atomically). The apply step of the new engine can call the same code path,
  so a bulk-created student stays indistinguishable from a form/CSV one.
- `readHeaderRow()` / `mapRow()` / `rowIsBlank()` — header normalisation and
  row mapping for the CSV reader.
- The template download (`CsvExport::download`, header + one worked example
  row) and the whole of `App\Support\CsvExport` — streamed CSV writes with
  UTF-8 BOM and **formula-injection neutralisation already implemented**
  (cells starting with `= + - @` are quote-prefixed). All future exports —
  templates, existing-records downloads, error reports — should go through
  it for CSV.
- `bulkDelete()` — out of scope for this system, but proof of the pattern
  "ids in POST body, never in URL" that bulk confirm steps should follow.

### 1.2 Staff creation — how a teacher/staff account is made today

There is **no staff-specific controller**. All non-student accounts are
created through `UserController@store(UserRequest)` (`users` resource,
SupportTeam), gated `teamSA`, with two extra server-side rails from
Workstream CM:

- **Level check:** the actor cannot create an account type above their own
  level (`user_types.level`); SA writes only `super_admin`/`admin` targets,
  the school Admin writes every school account except `super_admin`
  (`mayWriteUserTarget`).
- Staff types are `Qs::getStaff()` = `super_admin, admin, teacher,
  accountant, librarian`.

**What a "staff record" actually is:** a `users` row plus, for staff types, a
`staff_records` row carrying exactly **one** school-supplied field —
`emp_date` (joining date; `Qs::getStaffRecord()` = `['emp_date']`) — plus the
generated `code`. Everything else a staff template could carry lives on
`users`: name, email, gender, phone, phone2, dob, address, bg_id, state_id,
lga_id, nal_id, photo. **There is no department, designation, or employee-no
column anywhere** — "designation" in this app *is* `user_type`.

**Staff ID: a concept exists, with a sharp edge.** In `UserController@store`,
a non-teamSA staff member's `username` is generated as
`APPCODE/STAFF/<YYYY>/<MM>/<mt_rand(1000,9999)>` from `emp_date`, and the
same value is stored as `staff_records.code` (`users.username` and
`staff_records.code` are both `unique`). Password defaults to the user-type
string (`Hash::make($req->password ?: $user_type)`), e.g. `teacher`.

> ⚠️ **Finding — the staff code is NOT stable.** `UserController@update`
> **regenerates** a staff member's username/code with a fresh random suffix
> on every single-record edit (staff, non-teamSA branch). So the code a
> school prints on an ID card today can change after an unrelated profile
> edit. For bulk-edit matching this makes `code` a *secondary* key at best,
> and the bulk system must **never** write `username`/`code` (see §2). The
> reliable staff matching key is **email** (`users.email`, unique) — when
> the school keeps emails on file. Owner decision D3 (§8) covers schools
> that don't.

**Staff store validation (`UserRequest`, POST branch):** required = `name`
(6–150), `user_type`, `gender`, `address` (6–120), `state_id`, `lga_id`,
`nal_id`; optional = `email` (unique), `phone` (6–20), `username` (only
meaningful for teamSA), `password` (nullable, 3–50), `photo`.

### 1.3 Student update path — what the single Edit form can change

`StudentRecordController@update(StudentRecordUpdate, $sr_id)` (policy-gated
`update` on the record) writes:

- user fields via `Qs::getUserRecord()` = `name, email, phone, phone2, dob,
  gender, address, bg_id, nal_id, state_id, lga_id`;
- student fields via `Qs::getStudentData()` = `my_class_id, section_id,
  my_parent_id, dorm_id, dorm_room_no, year_admitted, house, age`;
- a photo, if uploaded.

Validation highlights (`StudentRecordUpdate`): `name`, `gender`, `address`,
`my_class_id`, `section_id`, `state_id`, `lga_id`, `nal_id` required;
`email` unique **ignoring nobody** (`unique:users,id` — sic, the rule as
written ignores the *id column value* placeholder quirk of the original
codebase; the bulk validator must implement "unique except self" correctly);
class/section/state/LGA/nationality/parent/dorm must exist.

**Bulk-editable (safe) student fields** — everything the edit form writes,
resolved by name exactly as the importer does: name, gender, phone, phone2,
dob, email, address, blood group, nationality, state, LGA, class, section,
parent (by parent email), dorm, dorm room no, house, year admitted.

**Never bulk-editable:**

- `adm_no` — absent from `StudentRecordUpdate` entirely; it is also the
  `users.username` and the match key itself. Changing it is a
  re-identification, not an edit.
- `user_id`, `user_type`, `code`, `password`, `photo` — identity/security
  fields; role changes and password resets already have their own flows
  (Users module, `users.reset_pass`).
- `session` (written from settings at creation), `grad` / `grad_date`
  (graduation is its own workflow), marks/fees — class changes already carry
  a side effect the bulk engine must respect: `update()` calls
  `Mk::deleteOldRecord($user_id, $new_class_id)`, deleting the student's
  marks/exam records for the previous class. A bulk class move must run the
  same hook per student and the preview must **warn** that class changes
  carry this consequence, exactly as the single edit does silently today.

**Atomicity note:** the single `store()` does **not** wrap user + record in
a transaction (the CSV importer improved on it). The bulk engine follows the
importer's per-row transaction, not the form's.

### 1.4 Exports that exist today

| Export | Where | Columns | Round-trips into the importer? |
|---|---|---|---|
| Student roster CSV | `StudentRecordController@export` (`students.export`, `teamSAT`, teacher-scoped by class) | Name, Admission No, Class, Section, Gender, Parent Name, Parent Phone, Parent Email | **No** — display roster only; lacks address/nationality/state/LGA/year/dob that the importer requires |
| Student import template | `StudentBulkController@importTemplate` | The 19 importer columns + 1 example row | Yes (for add mode) |
| Payments/defaulter/collection CSVs | `PaymentController` / `FeeReportController` via `CsvExport` | Financial | n/a |

So "Download Existing Records" for edit mode is a **new export assembly**,
not a reuse of the roster: same `CsvExport` writer and same student query,
but emitting the full template column set (ids resolved **back to names** —
the reverse of `buildLookups`), keyed by `adm_no`, optionally scoped by
class/section like the roster export. For staff there is **no export at
all** today; the staff records download is greenfield (users of staff types
+ `staff_records.code`/`emp_date`).

### 1.5 Schema facts the design stands on

- `users`: `email` unique nullable, `username` unique nullable, `code`
  unique; `user_type` string; `dob` stored as **string**; profile FKs
  (`bg_id, state_id, lga_id, nal_id`) all nullable at the DB level (the
  *form* makes them required, the schema doesn't); `status` added
  2026-10-22 (`active/suspended/terminated` lifecycle, Workstream DF).
- `student_records`: `adm_no` unique nullable (30 chars), `user_id`,
  `my_class_id`, `section_id`, `my_parent_id`, `dorm_id`, `session`,
  `year_admitted`, `grad` flag.
- `staff_records`: `user_id`, `code` unique nullable, `emp_date` nullable.
  That is the whole table.
- **No `school_id` anywhere** — the app is single-school per install
  (multi-school tenancy is a separate, unbuilt phase). "School boundary" in
  this system therefore means the existing role gates, not tenant scoping.
- Fee side effects of admission: **none at creation time**, in either the
  form or the importer. `payment_records` are materialised later by
  `PaymentController@select_class` for the students then in a class — so
  bulk-created students need no fee handling to be indistinguishable, and
  bulk *class moves* interact with fees only through that same later flow.

---

## 2. Field policy — matching keys and what bulk may never touch

**Students**
- **Match key (update mode): `adm_no`** — unique, human-printed on every
  document, and identical to the student's `username`. A row without an
  `adm_no`, or one matching zero / (impossibly) more than one record, is
  *unmatched* → skipped by default, never auto-created (update mode never
  silently becomes add mode).
- Editable: the §1.3 list (user profile fields + class/section/parent/
  dorm/house/year_admitted), each cell validated by the same rules as
  `StudentRecordUpdate`.
- Never: `adm_no`, `user_id`, `user_type`, `code`, `password`, `photo`,
  `session`, `grad`, `grad_date` (§1.3).

**Staff**
- **Match key (update mode): `email`** (unique on `users`) — because the
  generated staff `code` is regenerated by the single-edit flow and cannot
  be trusted as stable (§1.2). The template may *carry* the code as a
  read-only reference column; if both are present they must agree, or the
  row is flagged ambiguous. Schools that don't keep staff emails need a
  decision (D3, §8) — the fallback is matching on code with the instability
  disclosed in the preview.
- Editable: name, gender, phone, phone2, dob, address, blood group,
  nationality, state, LGA, `emp_date`, and — in ADD mode only — `user_type`
  (the role being created).
- Never: `username`, `staff_records.code`, `user_type` in update mode (role
  changes stay in the Users module, where `mayWriteUserTarget` and the
  level check live), `password`, `status` (suspend/activate/terminate are
  lifecycle actions with their own audit trail — a spreadsheet must not
  terminate accounts), `email_verified_at`.

**Blank-cell rule (both modules, both modes):** a blank cell means
*no change* in update mode and *absent* in add mode. Bulk update never
clears a field; clearing stays a deliberate single-record edit. (The
owner's brief allows an explicit "clear field" operation — deferred to
Phase B as its own confirmed action, D5.)

**Preview diff rule:** for updates, a cell only counts as a change when its
normalised value differs from the stored value (names resolved to ids on
both sides before comparing), and the preview shows old → new per changed
field.

---

## 3. Spreadsheet libraries — neither exists today; recommendation

Verified: `composer.lock` and `vendor/` contain **no** spreadsheet package
(no PhpSpreadsheet, no OpenSpout, no Maatwebsite/Excel, no Box/Spout);
`App\Support\CsvExport`'s own docblock records this. CSV reading today is
hand-rolled `fgetcsv`.

| | PhpOffice/PhpSpreadsheet | OpenSpout (openspout/openspout) |
|---|---|---|
| Role | Full read/write/edit of xlsx/ods/csv | Streaming **read/write** of xlsx/ods/csv |
| Memory on large files | High — loads the workbook (mitigations exist but are fiddly); a 10k-row school file is fine, a 100k-row file hurts | Constant-memory row streaming by design — built for exactly this import shape |
| Writing styled templates | Excellent (fonts, fills, frozen panes, data-validation lists, multiple sheets incl. an instructions sheet) | Basic styling; multi-sheet supported; no cell data-validation lists |
| PHP / framework fit | PHP ^8.2 line (v3/v4) fits composer `php: ^8.2` + Laravel 11; large dependency tree | PHP ^8.1+ fits; small dependency tree |
| Maintenance | Very active, the de-facto standard | Active (successor of Box/Spout) |

**Recommendation:** adopt **both, each for what it's good at** — or, if the
owner wants exactly one new dependency, **PhpSpreadsheet alone**:

- **Reading uploads (the hot path): OpenSpout** — streaming reader for
  CSV + XLSX, so a 5,000-row file never sits in memory. Requirement:
  `composer require openspout/openspout:^4.0`.
- **Writing templates: PhpSpreadsheet** — the brief asks for marked
  required fields and an instructions sheet; styled headers + a second
  "Instructions" sheet + dropdown-friendly example rows are
  PhpSpreadsheet's home turf. Requirement:
  `composer require phpoffice/phpspreadsheet:^3.0` (the PHP ^8.2 line;
  v4 also satisfies `^8.2` — pin at install time after a dry-run).
  All *data* exports (existing records, error reports) keep using the
  existing `CsvExport` streamer — they don't need styling and must stay
  light; a template is the only styled artefact.

CSV uploads keep flowing through the existing `fgetcsv` reader (it's
proven, BOM-safe, and dependency-free); the engine's reader interface
(§4.1) hides which reader produced the rows.

---

## 4. The reusable engine — one pipeline for students + staff, add + update

### 4.1 Pipeline

```
Upload ──► Stage ──► Validate ──► Preview ──► Confirm ──► Apply (chunked) ──► Report + History
(file +     (rows      (per-row,    (counts:    (explicit    (per-row tx,        (job row +
 module +    persisted  structured  create /    POST with    resumable)          error report
 mode)       raw)       errors)     update /    job token)                       download)
                                    skip +
                                    diffs)
```

1. **Upload.** Admin picks module (students/staff), mode (add/update) and
   the file on the hub page. The file is stored under
   `storage/app/bulk-imports/<job-id>/` (private disk), a `bulk_import_jobs`
   row is created (`status=uploaded`), and the raw file is parsed row by
   row into `bulk_import_rows` (`status=staged`, raw cells as JSON). The
   upload request never validates-and-writes in one breath — that is the
   one structural change from today's importer.
2. **Validate.** A per-module validator (students: the extracted
   `validateRow` + an update-mode sibling; staff: new, mirroring
   `UserRequest`) runs over staged rows, writing back per-row verdicts:
   `valid_create`, `valid_update` (with a `changes` JSON of field →
   {old, new}), `duplicate`, `unmatched`, `invalid` (+ structured errors).
   Validation is pure reads — it can be re-run safely.
3. **Preview.** One screen over the job: totals (rows detected / will
   create / will update / duplicates / unmatched / invalid), the row-level
   error table (row no, field, supplied value, explanation — the brief's
   examples map 1:1: adm_no exists → duplicate; section not in class →
   relationship error from the existing lookups; bad date → format error;
   no match in update mode → unmatched), the old→new diff table for
   updates (first N rows inline, full set in the downloadable report), and
   an **error report download** (CSV via `CsvExport`, formula-safe) so the
   office can fix the sheet and re-upload. Nothing has been written to
   `users`/`student_records`/`staff_records` yet.
4. **Confirm.** A single POST carrying the job id + a per-job confirm token
   (idempotency, §6). Only rows still in a `valid_*` state are applied;
   the job flips to `applying`.
5. **Apply.** Rows are applied in bounded chunks (§6) — students through
   the same per-row transaction as today's `createStudent()` /
   the update path's field writes + `Mk::deleteOldRecord` hook on class
   change; staff through a `UserRepo`-level create/update mirroring
   `UserController@store/update` **without** its username regeneration.
   Each applied row records the created/updated record id; failures flip
   the row to `failed` with the exception message (truncated, no PII
   beyond the row's own cells) and never abort the chunk's other rows.
6. **Report + history.** Job completes with counts (created / updated /
   skipped / failed), an `Audit::log('bulk.applied', …)` summary carrying
   the job id, and the row data stays queryable on the History page;
   the completion/error report remains downloadable from the job.

### 4.2 Validators emit structured errors

Today's messages are plain strings. The refactor: each check appends
`['field' => 'section', 'value' => 'C', 'message' => "Section 'C' was not
found in class JSS 1."]`. The existing message *texts* are kept verbatim
(schools already know them); the preview and the error report consume the
structure, the legacy import page can keep flattening to strings.

Student add-mode validation = today's `validateRow` + one addition:
**adm_no collision is checked against the DB and the file** (today it's
only thrown inside `createStudent` at write time — for a preview it must
surface at validate time, classified `duplicate`).
Student update-mode validation = match on `adm_no` first (unmatched →
skip), then per-cell `StudentRecordUpdate` rules with "unique email except
this student" done correctly, then diff.
Staff add-mode = `UserRequest` store rules + the CM level check applied
per row (a row asking for a role above the actor's level is `invalid`,
never partially applied) + staff-code generation deferred to apply time.
Staff update-mode = match on email (§2), per-cell `UserUpdate`-shaped
rules, `user_type`/`username`/`code` cells rejected as not-editable
errors (not silently ignored — the office must see why).

### 4.3 Where the code lives (no duplicate modules)

- `app/Support/Bulk/` — `BulkImportService` (pipeline + chunking),
  `Readers/` (CsvReader wrapping today's helpers; SpoutReader),
  `Validators/StudentBulkValidator`, `Validators/StaffBulkValidator`,
  `Appliers/…` (thin adapters over the existing repos/creators).
- `StudentBulkController` keeps its existing routes/behaviour (the legacy
  one-pass import stays available during Phase A; the hub links the new
  flow). Whether the legacy importer is retired at Phase B is D6.
- One new controller `BulkManagementController` (hub, upload, preview,
  confirm, history, downloads) + two models (`BulkImportJob`,
  `BulkImportRow`).

---

## 5. Staging & history schema (additive migrations only)

Two new tables. Nothing existing is altered.

```php
// bulk_import_jobs — one row per uploaded file, forever (the history)
$table->increments('id');
$table->unsignedInteger('user_id');            // initiating admin
$table->string('module');                      // 'students' | 'staff'
$table->string('mode');                        // 'add' | 'update'
$table->string('original_filename');
$table->string('stored_path');                 // private disk path
$table->string('file_hash', 64);               // sha256 of the upload —
                                               // re-uploading the identical
                                               // file is detectable at once
$table->string('status');                      // uploaded → staged →
                                               // validated → applying →
                                               // completed | failed | discarded
$table->unsignedInteger('total_rows')->default(0);
$table->unsignedInteger('create_count')->default(0);   // preview + final
$table->unsignedInteger('update_count')->default(0);
$table->unsignedInteger('skip_count')->default(0);     // duplicate+unmatched
$table->unsignedInteger('error_count')->default(0);
$table->unsignedInteger('applied_create_count')->default(0);
$table->unsignedInteger('applied_update_count')->default(0);
$table->unsignedInteger('failed_count')->default(0);
$table->string('confirm_token', 64)->nullable();       // single-use (§6)
$table->timestamp('validated_at')->nullable();
$table->timestamp('applied_at')->nullable();
$table->timestamps();

// bulk_import_rows — one row per spreadsheet row (the staging + report)
$table->increments('id');
$table->unsignedInteger('job_id');             // FK → bulk_import_jobs
$table->unsignedInteger('row_number');          // spreadsheet line (header = 1)
$table->json('raw');                            // cells as uploaded (by column name)
$table->json('resolved')->nullable();           // names → ids, normalised values
$table->string('fingerprint', 64);              // §6 duplicate detection
$table->string('verdict');                      // staged | valid_create |
                                                // valid_update | duplicate |
                                                // unmatched | invalid | applied |
                                                // failed
$table->json('errors')->nullable();             // [{field, value, message}]
$table->json('changes')->nullable();            // update mode: field → {old, new}
$table->unsignedInteger('record_id')->nullable(); // created/updated users.id
$table->text('failure')->nullable();            // apply-time exception, truncated
$table->timestamps();
$table->index(['job_id', 'verdict']);
```

Why rows in a table and not a JSON blob on the job: the preview paginates
and filters rows (invalid-only view), the error report streams them, and
chunked apply needs per-row state transitions — all cheap with an index,
painful with a blob. A JSON-only design was considered and rejected for
exactly the "thousands of records" scale in the brief. Uploaded files and
row payloads contain PII — private disk only, retention per D4 (§8).

---

## 6. Processing model & risk list

### 6.1 v1 processing model — honest version

**There is no queue to build on.** `QUEUE_CONNECTION=sync` in `.env` and
`.env.example`; there are no `jobs`/`failed_jobs` migrations, no Horizon
config, and **zero** `ShouldQueue`/`dispatch` usage anywhere in `app/`.
The Render demo is a single `apache2-foreground` service with an ephemeral
database — a "queued" job there would be processed by nobody.

**v1 therefore applies synchronously in bounded chunks:** Confirm starts
the apply; each request applies at most **100 rows** (per-row transactions)
and returns the job's live counters; the page advances chunk to chunk
(auto-continue with a visible progress bar + a Stop control) until no
`valid_*` rows remain. Because every row's state is persisted, a closed
tab, a timeout, or a dead request leaves the job in `applying` — resumable
or discardable from History, never ambiguous and never half-written inside
a row.

This is deliberately the same shape a queue worker would drain: when a
real worker exists (production host, separate decision), a
`ProcessBulkImportJob` can consume the identical row states and the UI
flow doesn't change. Queueing is an upgrade path, not a v1 promise.

### 6.2 Duplicate-submit & idempotency design

Three independent rails (today's importer has only the third):

1. **File hash** — re-uploading the byte-identical file warns at upload
   ("this exact file was imported as Job #N") before anything is staged.
2. **Confirm token + status machine** — Confirm is a single-use token on a
   job in `validated` state; double-clicks, refreshes, and back-button
   resubmits hit an already-`applying/completed` job and apply nothing
   twice (apply only ever touches rows still in `valid_*`).
3. **Row fingerprint + DB uniques** — fingerprint = sha256 over the
   module + normalised match identity (students: adm_no when given, else
   name+class+section+year+dob; staff: email, else name+emp_date). At
   validate time a row whose fingerprint matches an `applied` row from an
   earlier job is classified `duplicate`. The existing unique constraints
   (`users.email`, `users.username`, `student_records.adm_no`,
   `staff_records.code`) remain the final backstop, and per-row
   transactions mean a backstop hit fails one row, not the file.
   (This closes the hole noted in §1.1: today's importer re-creates
   email-less, adm_no-less students on re-upload.)

### 6.3 Risks

| Risk | Mitigation in this design |
|---|---|
| Large files: memory blow-up, request timeouts | Streaming readers only (fgetcsv / OpenSpout); staged parsing in the upload request is row-at-a-time; hard caps — **10 MB** (existing precedent) and **10,000 rows** — with a clear "split the file" message (D7) |
| Linked-account atomicity (user row without record) | Per-row `DB::transaction` around user + record, the importer's proven pattern; a failed row leaves neither half |
| Formula injection in generated files | Every CSV the system emits goes through `CsvExport` (already neutralises `= + - @` cells); error reports included. Uploaded xlsx formulas are read as cached values by the reader — never evaluated server-side — and preview cells render through Blade escaping |
| Duplicate submissions | §6.2, three rails |
| Partial failure ambiguity | Counts reconcile by construction: created + updated + skipped + failed = total, on the job row and in the downloadable report |
| Malicious uploads | csv/xlsx only, content-sniffed (not client extension); .xlsm/.xls rejected; size cap; private-disk storage; no cell content is ever logged beyond the row's own staged data |
| PII retention | Uploaded files + staged rows hold personal data — retention + who can download them is D4; downloads are gated like the hub itself |
| Class-move side effect in bulk updates | Preview warns; apply runs the same `Mk::deleteOldRecord` hook as the single edit (§1.3) |
| Staff code instability contaminating matches | Email is the staff key; code is display-only (§2, D3) |

---

## 7. Template column lists (from the real schema)

### 7.1 Students — add mode (the existing, proven 19)

| Column | Required | Notes |
|---|---|---|
| name | ✔ | 6–150 chars |
| gender | ✔ | Male / Female |
| address | ✔ | 6–120 chars |
| nationality | ✔ | by name |
| state | ✔ | by name |
| lga | ✔ | by name, must belong to state |
| class | ✔ | by name; class must have a class type |
| section | ✔ | by name, must belong to class |
| year_admitted | ✔ | 4-digit year |
| email | – | unique; blank allowed |
| phone / phone2 | – | 6–20 chars |
| dob | – | YYYY-MM-DD |
| blood_group | – | by name |
| parent_email | – | must match an **existing** parent account (link only — bulk never creates parent accounts, §1.1) |
| dorm / dorm_room_no | – | dorm by name |
| house | – | free text |
| adm_no | – | the serial part only; blank = auto-generated. In **update mode this column becomes required** — it is the match key — and the remaining columns are the §2 editable set (a cell left blank = no change) |

The xlsx template ships with an **Instructions sheet**: accepted values
(gender spellings, date format, "names not ids" for class/section/state/
LGA, the adm_no rule, the blank-cell rule for update mode) — generated
from the same column metadata the validator uses, so docs and rules can't
drift apart.

### 7.2 Staff — add mode (new; mirrors `UserRequest` store)

| Column | Required | Notes |
|---|---|---|
| name | ✔ | 6–150 chars |
| user_type | ✔ | Role title (Teacher / Accountant / Librarian / Admin…); per-row level check (§4.2) |
| gender | ✔ | |
| address | ✔ | 6–120 chars |
| nationality | ✔ | by name |
| state | ✔ | by name |
| lga | ✔ | by name, must belong to state |
| email | – | unique; **required if the school wants update-mode matching later** (D3) |
| phone / phone2 | – | 6–20 chars |
| dob | – | YYYY-MM-DD (stored as string, as today) |
| blood_group | – | by name |
| emp_date | – | joining date, YYYY-MM-DD; drives the generated staff code |

Generated at apply time (never spreadsheet-supplied): username/staff code
(`APPCODE/STAFF/YYYY/MM/####`), account `code`, default password = the
role string (today's convention — the bulk flow inherits it; changing the
default-password policy is a product decision outside this build).

### 7.3 Staff — update mode

Match key column: **email ✔**. Editable columns: name, gender, phone,
phone2, dob, address, blood_group, nationality, state, lga, emp_date.
`staff_code` may appear as a **read-only reference** column (exported,
ignored on upload except for the agree-or-flag check in §2). `user_type`,
`username`, `password`, `status` are rejected if supplied (§2).

---

## 8. Permissions & hub placement; decisions for the owner

### 8.1 Gates (following the Workstream CM precedent exactly)

| Surface | Gate | Precedent |
|---|---|---|
| Hub page, templates download, existing-records download, history list/detail, error/completion reports | `teamSA` (SA read + Admin) | `students.import` page + template today |
| Upload, validate, **confirm/apply**, discard | `teamSAWrite` (Admin only) | `students.import.store`, `students.bulk_delete` today |
| Staff add rows | `teamSAWrite` **plus** the per-row CM level check (`mayWriteUserTarget` semantics: nobody bulk-creates a role above their own level; SA never reaches apply at all) | `UserController@store` |
| Student update apply | `teamSAWrite`; teacher scope does **not** extend to bulk (bulk is an office function; teachers keep their scoped single-record views) | — |

Super Admin visibility of the hub/history itself rides on the
`PlatformOnly` route allowlist: the new `bulk.*` routes are **not** added
to it by default, so SA is redirected from school surfaces as with every
other school module (the "numbers, not records" ruling). D2 lets the owner
change that deliberately.

### 8.2 Hub placement (named, not edited — per this audit's read-only scope)

- **Page:** "Bulk Management" hub at `bulk.index` (new
  `BulkManagementController`), with cards for: Bulk Add Students · Bulk
  Edit Students · Bulk Add Staff · Bulk Edit Staff · Templates ·
  Import History. Each card routes into the one pipeline (§4.1) with
  module+mode preset.
- **Menu file:** `resources/views/partials/menu.blade.php` — insert one
  item in the **Management** group (the `@if(Qs::userIsTeamSA())` block,
  header "Management"), **immediately after the Users item** (audited
  tree: Users ≈ lines 374–377, before Manage Classes), wrapped in
  `@if(Qs::userIsTeamSAWrite())` so it renders for the school Admin only,
  with active-state covering `bulk.*` route names. Rationale: the hub
  spans students **and** staff, so it belongs beside Users rather than
  inside the Students submenu; the existing in-page Import CSV buttons
  (student list, legacy import page) additionally gain a "Bulk Management"
  link so the office finds it from where it already works.

### 8.3 Open decisions for the owner

- **D1 — Libraries.** Both OpenSpout (reading) + PhpSpreadsheet
  (templates) as recommended in §3, or PhpSpreadsheet only (one
  dependency, heavier reads)?
- **D2 — SA visibility.** Keep bulk history Admin-only (recommended, per
  platform-only / numbers-not-records), or add `bulk.*` reads to the SA
  allowlist?
- **D3 — Staff matching.** Email required as the staff update key
  (recommended), or allow code-based matching knowing the single-edit
  flow can regenerate codes (§1.2)?
- **D4 — Retention.** Uploaded files + staged row data: auto-delete
  after 30 days, job counts kept permanently (recommended), or another
  period?
- **D5 — Clear-field operation.** Build the explicit, per-column-confirmed
  "clear these fields" update option in Phase B (recommended), or never
  allow bulk clearing?
- **D6 — Legacy importer.** Retire the one-pass CSV import once Phase A
  lands and route its buttons to the hub (recommended at Phase B), or
  keep both paths indefinitely?
- **D7 — Caps.** 10 MB / 10,000 rows per file for v1 (recommended)?
- **D8 — Parent accounts.** Bulk-create parents too, or out of scope for
  v1 (recommended: out — parents are linked, never bulk-created, as
  today)?

---

## 9. Phased build plan

### Phase A — the engine + students, end to end

1. Additive migrations: `bulk_import_jobs`, `bulk_import_rows` (§5).
2. `App\Support\Bulk` engine: readers (CSV via existing helpers; XLSX via
   OpenSpout per D1), `StudentBulkValidator` (structured errors; add +
   update modes), student applier over the existing create/update code,
   chunked apply + progress, confirm-token + fingerprint rails (§6.2).
3. `BulkManagementController` + hub/preview/history/report pages (Loop
   CSS, dark mode, FIT rule — same redesign standard as the rest of the
   app), templates (CSV now; styled XLSX with Instructions sheet),
   existing-records export for students (full column set, §1.4).
4. Menu item (§8.2) + gates (§8.1); legacy importer untouched and still
   linked.
5. Tests: per-stage feature tests (valid add, duplicates by adm_no /
   email / fingerprint, invalid class↔section, unmatched update rows,
   blank-cell no-op, old→new diff correctness, double-confirm applies
   nothing twice, chunk resume after simulated interruption, unauthorized
   roles, error-report contents, class-move marks hook), plus the
   existing suite + `scripts/qa-smoke.sh`.

**Why students first:** every hard part already has a proven counterpart
in the tree (validator, creator, template, lookups) — Phase A is mostly
*restructuring a working flow around staging*, the lowest-risk way to get
preview-before-commit, which is the owner's core demand, in front of the
school office fast. The engine is module-agnostic from the first commit,
so Phase B is additive, not a second system.

### Phase B — staff on the same engine + hardening

1. `StaffBulkValidator` + staff applier (mirrors `UserController@store/
   update` semantics **minus** username regeneration; per-row level
   check), staff templates + staff existing-records export.
2. Explicit clear-field operation (D5), legacy importer retirement (D6),
   retention job for uploaded files (D4).
3. Browser-side quick bulk actions on the student list (assign
   class/section to a checkbox selection) reusing the update applier —
   the brief's Phase 8 browser editor — scoped to class/section only.
4. Queue-worker enablement **only if** the production host gains a
   worker: swap chunk-driving from browser requests to a
   `ProcessBulkImportJob` draining the same row states (§6.1).

**Why staff is Phase B, not A:** there is no staff bulk code to
restructure — validator and applier are greenfield; the matching key
needs D3 answered first; and per-row role-authorization (CM level check)
is a security surface that deserves its own review rather than riding
along with the student launch.

### Explicitly out of scope for both phases

Bulk delete changes (exists, separate), bulk marks/fees/payments import,
multi-school tenancy behaviour (no `school_id` exists — §1.5), parent
bulk creation (D8), and any change to the single-record forms.

---

*End of audit. Tree verification: `scripts/qa-smoke.sh` PASS on this
branch (see the run log referenced in the handoff report); the only file
this audit adds is this document.*
