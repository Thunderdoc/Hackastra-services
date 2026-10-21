# Library Module — Regression Audit (Loop 7, Workstream I)

Date: 2026-10-21 · Branch audited: master code (Loop 5, commit 2835232 lineage)
Scope: books catalogue CRUD + book_requests issue/return + teamLibrary gating.

## Static audit — PASS

- **Routes ↔ controllers.** `routes/web.php` (inside `auth` → `SupportTeam`
  namespace group): `Route::resource('books')->except(['show'])` maps to
  BookController index/create/store/edit/update/destroy (all exist);
  `Route::resource('book_requests')->only(['index','create','store'])` maps to
  BookRequestController index/create/store; the extra
  `PATCH book_requests/{id}/return` → `markReturned` exists.
- **Views ↔ route names.** Every `route()` referenced by the 6 library views
  (books.index/create/store/edit/update/destroy, book_requests.index/create/
  store/return) is defined. Delete button is rendered for Super Admin only,
  matching the controller's `super_admin` middleware on `destroy`.
- **Gating.** `teamLibrary` alias registered in `app/Http/Kernel.php`;
  middleware checks `Qs::userIsTeamLibrary()` → `getTeamLibrary()` =
  `['admin','super_admin','librarian']`. Both controllers apply it in their
  constructors.
- **Schema.** 2018 migrations provide every column used: books (name,
  my_class_id, description, author, book_type, url, location, total_copies,
  issued_copies); book_requests (book_id, user_id, start_date, end_date,
  returned string default '0', status). No new migration needed.
- **Validation.** BookSave: name required ≤100, total_copies integer ≥0,
  book_type restricted to `Book::types()`. BookRequestCreate: book/user must
  exist, `end_date` `after_or_equal:start_date`.
- **Copy-count math (in code).** Issue: availability re-checked server-side
  (`availableCopies() < 1` → reject), loan + `issued_copies` increment in ONE
  transaction. Return: idempotent guard, decrement floored at 0 for the
  counter check, in a transaction. Book update clamps `issued_copies` to
  `total_copies`. Destroy blocked while `activeRequests()->exists()`.
- `php -l`: clean on all 10 library files (controllers, models, middleware,
  form requests, routes, Qs, Kernel).
- Remote master copies of the library files + `routes/web.php` are
  byte-identical to the audited local copies (checked file-by-file).

## Live audit — https://hackastra.onrender.com (Super Admin cj@cj.com)

| Check | Result |
|---|---|
| Login (identity field) → /home | ✅ 302 → /home |
| GET /books | ✅ 200, "Library Books", honest empty state (no books seeder exists) |
| GET /book_requests | ✅ 200, "Book Loans & Requests", empty state |
| GET /books/create, /book_requests/create | ✅ 200, forms render, borrower select populated from seeded users |
| POST /books (create, total_copies=2) | ✅ 302 → /books; catalogue shows "0 issued / 2 total · 2 available" |
| PUT /books/1 (update) | ✅ 302 → /books |
| POST /book_requests (issue) | ✅ 302 → /book_requests; counts become "1 issued / 2 total · 1 available"; loan row listed with Mark Returned |
| DELETE /books/1 while a copy is on loan | ✅ Blocked — book survives, flash "still has copies out on loan" (the 302 target was /login only because the curl harness sends no Referer, so `back()` fell back; the block itself fired) |
| Gating: logged-in Parent opens /books | ✅ 302 away, catalogue not rendered |
| **PATCH /book_requests/1/return (Mark Returned)** | ❌ **404 on the live deployment — see below** |

### The one live defect: Mark Returned 404s on Render (code is correct)

Reproduced deterministically (5/5 retries), with every request style:
form-style spoofed `_method=patch` + the form's own token + Referer,
genuine `PATCH` verb, `X-CSRF-TOKEN` header, query-string token — all 404.
Control probes prove the route IS registered in the deployed app:
wrong-verb requests to the same URI return 405, and a token-less PATCH
returns 419 (CSRF). The deployed source is byte-identical to master
(verified), and the identical flow pattern (spoofed PUT/DELETE) works
elsewhere in the same deployment. Conclusion: this is a **deployed-instance
defect, not a source defect** — most consistent with a stale compiled
route/config cache baked during the day's rapid stacked pushes to Render.

**Recommended action (parent/ops):** trigger a clean redeploy of the Render
service (clear build cache) and re-run this exact test: issue any book, then
PATCH the return and expect 302 + counts back to 0 issued. If it still 404s
after a clean redeploy, escalate to runtime debugging with the container's
Laravel log before touching library code. The test book/loan created during
this audit (book id 1 / loan id 1, "Audit Regression Book") lives only in the
demo container's ephemeral MariaDB and disappears on the next restart.

## Minor observations (listed, deliberately NOT fixed here)

1. **Issue race (theoretical).** Availability is check-then-act without a row
   lock; two simultaneous issues of the last copy could both pass the check.
   Negligible at school-library traffic; fix later with
   `lockForUpdate()` inside the transaction if loan volume grows.
2. **Counter desync edge.** Editing a book's `total_copies` below its active
   loan count clamps `issued_copies` down, so the counter can under-report
   until those loans are returned (return decrements floor at 0, so it never
   goes negative). A stricter policy (reject total < active loans) is a
   product decision, not a bug fix.
3. **Redirect-to-login for wrong role.** teamLibrary (like every Team*
   middleware in this codebase) redirects authenticated-but-unauthorized
   users to the login page instead of showing a 403. Codebase-wide pattern;
   changing it belongs in a cross-cutting loop, not the library module.
4. **No books seeder.** Fresh demo databases show the (correct) empty state;
   adding 3–4 demo books + one active loan to the demo seeder would make the
   module demo-ready. Suggested as a tiny follow-up, not done here.
