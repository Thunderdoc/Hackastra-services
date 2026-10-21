# Hackstra — Role–Permission Matrix (as enforced in code)

**Date:** 2026-10-21 · **Method:** every cell below is traced to an enforcement
point — a route-group middleware (`routes/web.php`, master), a controller
constructor middleware, an in-action check, or a policy (`app/Policies/`).
**Menu visibility was never used as evidence.** Where enforcement is an
in-action ownership check rather than middleware, the cell is marked ◐.

**Legend:** ✓ allowed · — denied · ◐ own records / own children only ·
n/a = no such action or state exists in code.
**Denial behaviour:** all role middleware (`app/Http/Middleware/Custom/*`)
runs `Auth::check()` itself and **redirects to the login page** on failure —
there are no 403s from middleware (verified live 2026-10-21: a parent opening
a staff fee report received 302 → login). The single exception is the global
search, which aborts 403 in-action.

## The enforcement vocabulary

Roles are the `users.user_type` string. Middleware names map to role sets in
`app/Helpers/Qs.php`:

| Middleware | Roles admitted | Source |
|---|---|---|
| `teamSA` | super_admin, admin | `Qs::getTeamSA()` |
| `teamSAT` | super_admin, admin, teacher | `Qs::getTeamSAT()` |
| `teamAccount` | super_admin, admin, accountant | `Qs::getTeamAccount()` |
| `teamLibrary` | super_admin, admin, librarian | `Qs::getTeamLibrary()` |
| `super_admin` | super_admin | `Qs::userIsSuperAdmin()` |
| `my_parent` | parent | `Qs::userIsParent()` |
| `auth` | any signed-in role | Laravel |
| `examIsLocked` | *(not a role — a global on/off flag for result access, `Mk::examIsLocked()`)* | `PinController` constructor |

Two policies exist (`AuthServiceProvider` maps both):
`StudentRecordPolicy` — view: staff(SA/Admin/Teacher/Accountant) any record,
student own, parent own-child; create/update: SA/Admin; delete: super_admin.
`PaymentRecordPolicy` — view: SA/Admin/Accountant any, student own, parent
own-child (via a `StudentRecord` lookup); create/update: SA/Admin/Accountant;
delete: super_admin. **A policy only bites where `authorize()` is called** —
verified call sites: payment receipts (view ×2), payment record update,
payment reset, student update, student delete.

## Matrix

Roles: **SA** super_admin · **AD** admin · **TE** teacher · **AC** accountant ·
**LI** librarian · **ST** student · **PA** parent

| Module / action | SA | AD | TE | AC | LI | ST | PA | Enforced by |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|---|
| **Settings** — view / edit | ✓ | — | — | — | — | — | — | route group `super_admin` |
| **Audit log** — view | ✓ | — | — | — | — | — | — | route group `super_admin` |
| **Users** — list, create, edit | ✓ | ✓ | — | — | — | — | — | `UserController` ctor: teamSA (index/store/edit/update) |
| **Users** — delete, reset password | ✓ | — | — | — | — | — | — | ctor: `super_admin` only (destroy, reset_pass) |
| **Users** — view a profile | ✓ | ✓ | ✓ | — | — | ◐ | ◐ | in-action: self, teamSAT, or parent-of-that-child |
| **Students** — list by class, export CSV | ✓ | ✓ | ✓ | — | — | — | — | route `teamSAT` (list, export) |
| **Students** — view a record | ✓ | ✓ | ✓ | — | — | ◐ | ◐ | in-action guard in `show()` (self / teamSAT / my-child); policy mirrors it |
| **Students** — create, edit, reset password, graduated list | ✓ | ✓ | — | — | — | — | — | ctor: teamSA only-list |
| **Students** — delete | ✓ | — | — | — | — | — | — | ctor `super_admin` + `authorize('delete')` (policy agrees) |
| **Students** — promotions | ✓ | ✓ | — | — | — | — | — | `PromotionController` ctor: teamSA (all actions) |
| **Classes, Sections, Subjects, Grades, Exams, Dorms** — view/create/edit | ✓ | ✓ | — | — | — | — | — | each controller ctor: teamSA (except destroy) |
| **— same — delete** | ✓ | — | — | — | — | — | — | each controller ctor: `super_admin` only |
| **Timetables** — view / print | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | routes outside any team group (auth only) |
| **Timetables** — create/edit/delete timetables, slots, manage records | ✓ | ✓ | — | — | — | — | — | route groups `teamSA` |
| **Timetables** — **delete a timetable record** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **⚠ GAP-1 — no enforcement anywhere (see below)** |
| **Marks** — enter/edit, comments, skills | ✓ | ✓ | ✓ | — | — | — | — | route group `teamSAT` |
| **Marks** — tabulation, batch-fix, print tabulation | ✓ | ✓ | — | — | — | — | — | route group `teamSA` |
| **Marks** — view/print own marksheet | ✓ | ✓ | ✓ | — | — | ◐ | ◐ | in-action ownership + PIN verification (`marks.show/print` sit outside the staff groups by design) |
| **Marks** — publish control (exam lock on/off) | ✓ | — | — | — | — | — | — | lock is a global setting; settings screen is `super_admin`-only |
| **Payments** — definitions, ledger, record payment, reset record | ✓ | ✓ | — | ✓ | — | — | — | `PaymentController` ctor: teamAccount (all actions); update/reset also `authorize('update')` |
| **Payments** — view a receipt / invoice | ✓ | ✓ | — | ✓ | — | ◐ | ◐ | teamAccount routes + `authorize('view')`; ◐ via the parent/student portals below |
| **Fee reports** — defaulters, collection, export CSV | ✓ | ✓ | — | ✓ | — | — | — | `FeeReportController` ctor: teamAccount |
| **Attendance** — mark, report, defaulters, export | ✓ | ✓ | ✓ | — | — | — | — | `AttendanceController` ctor: teamSAT (except `my`) |
| **Attendance** — self view | — | — | — | — | — | ◐ | ◐ | `attendance.my`: student own; parent switches between own children |
| **Events** — create/edit (management screens) | ✓ | ✓ | — | — | — | — | — | `SchoolEventController` ctor: teamSA |
| **Events** — delete | ✓ | — | — | — | — | — | — | ctor: `super_admin` only |
| **Library** — catalogue & loans: view/create/edit, issue, return | ✓ | ✓ | — | — | ✓ | — | — | both controllers ctor: teamLibrary |
| **Library** — delete a book | ✓ | — | — | — | — | — | — | `BookController` ctor: `super_admin` only (destroy) |
| **Admit cards** — staff roster & print | ✓ | ✓ | ✓ | — | — | — | — | `AdmitCardController` ctor: teamSAT (index, printSheet) |
| **Admit cards** — own card | — | — | — | — | — | ◐ | ◐ | in-controller: self / `Qs::userIsMyChild` / teamSAT, else **403** |
| **PINs** — enter & verify (to unlock a marksheet) | ✓ | ✓ | — | — | — | ◐ | ◐ | flow-level: teamSA can view entry; verify checks the PIN against the student |
| **PINs** — **generate PINs, delete used PINs, view PIN list** | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | **⚠ GAP-2 — only the `examIsLocked` flag gates these; no role check** |
| **Parent portal** — my children, my fees, receipts | — | — | — | — | — | — | ◐ | route group `my_parent` + `userIsMyChild` + policies |
| **Dashboard** — role home | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | `auth`; content dispatched by role in `HomeController` |
| **Global search** | ✓ | ✓ | ✓ | — | — | — | — | in-action `abort_unless(teamSAT, 403)` |
| **AJAX lookups** (LGAs, class sections, class subjects) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | `auth` only — see note 3 |
| **My account** — edit own profile/password | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | `auth`; actions are self-scoped |

## Gaps & notes (read before quoting this matrix)

1. **GAP-1 (high) — timetable-record deletion is unauthenticated-role.**
   `DELETE /timetables/records/{ttr}` (`ttr.destroy`) is declared *outside*
   the `teamSA` subgroup in `routes/web.php`; `TimeTableController` has **no
   constructor middleware at all**, and `delete_record()` performs no role
   check. Any signed-in account — including a student or parent — can delete
   timetable records. Every neighbouring write action is teamSA-gated, so this
   reads as a placement mistake, not a design choice. *Fix: move the route
   into the teamSA group (one line).*
2. **GAP-2 (high) — PIN generation/deletion has no role check.**
   `PinController`'s constructor applies only `examIsLocked` (a global flag).
   `POST /pins` (mint scratch-card PINs), `DELETE /pins` (delete used PINs)
   and the PIN list/create pages are reachable by **any** signed-in role.
   A student can mint a PIN and use it in the verify flow to unlock results —
   defeating the PIN gate the product treats as a feature. *Fix: add
   teamSA/teamSAT middleware to the controller's management actions, leaving
   enter/verify open to students/parents.*
3. **AJAX lookup group is auth-only** (`get_lga`, `get_class_sections`,
   `get_class_subjects`). It returns structural reference data only (LGAs,
   section names, subject names) — no personal or financial data — so this is
   recorded as an accepted exposure, not a gap. It should be re-checked if
   any endpoint starts returning student data.
4. **Denial = redirect to login, not 403** (see header). Harmless for data
   protection — each middleware re-checks `Auth::check()`, which is also why
   the `super_admin` and `my_parent` route groups are safe despite sitting
   *outside* the `auth` group in the file — but it makes unauthorized-access
   attempts invisible in logs and confuses users who are already signed in.
5. **`MarkController`'s own teamSAT middleware line is commented out**
   (constructor, line 31 on master). Enforcement currently rests entirely on
   the route groups + in-action checks and was verified working — but any
   future marks route added outside those groups would ship unguarded.
   Defence-in-depth says restore the constructor line.
6. **Dead authorization code:** the `admin` middleware is registered but used
   by no route; `Student`/`Teacher` middleware classes exist in
   `app/Http/Middleware/Custom/` but are not registered in `Kernel.php`.
   Harmless, but they invite a future developer to assume coverage that
   isn't there.
7. **Robustness nit (not a bypass):** `StudentRecordController::show()`
   dereferences the record inside its ownership guard; a decodable but
   non-existent id produces an error page instead of a clean denial. The
   guard itself is sound (invalid hashes return early).
8. **Librarian assignment wart:** the `librarian` user type is commented out
   in `UserTypesTableSeeder` (`CASE_STUDY.md` #34). The role enforces
   correctly wherever a user carries the type string, but the type cannot be
   seeded/assigned cleanly from the types table today.
9. **Accountant & librarian have no "view student record" path** (the
   in-action guard admits teamSAT + family only), although
   `StudentRecordPolicy::view` grants accountants view. Route/method-level
   enforcement is the stricter of the two and wins in practice — the policy
   is broader than the reachable surface, not the reverse.


---

## Parent integration verification notes (2026-10-21, after this audit)

- **GAP-2 (PINs) — NOT a live hole.** A re-test against the deployed app
  showed `PinController`'s constructor applies
  `teamSA` middleware to every action except the student/parent
  `enter_pin`/`verify` pair; a PIN-mint attempt (`POST /pins`) from a
  parent session was denied (redirect). The gap above came from reading a
  stale local copy; the deployed controller is protected. Treat GAP-2 as
  closed by verification.
- **GAP-1 (timetable records) — CONFIRMED and FIXED the same day.** The
  `ttr.destroy` route sat outside the `teamSA` group while its sibling
  store/update routes were inside it; it has been moved into the
  protected group.
