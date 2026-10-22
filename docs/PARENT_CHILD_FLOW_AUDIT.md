# Parent ↔ Child Account Flow — Security Audit

**Date:** 2026-10-22 · **Tree:** `audit/parent-child-flow` (base `integrate/wave9-myaccount` @ b7e38b5) · **Method:** code inspection + live probes against a disposable seeded instance (port 8922). All probe mutations were made on the throwaway seed and reverted / reseeded afterwards; **no application behaviour was changed by this audit.**
**Seed anchors used:** parent `parent@hackstra.app` (id 4, children = students 18 + 19) · parent `parent1@parent.com` (id 9, **no** linked children) · student 18 = Aarav Sharma (`student@hackstra.app`) · student 20 = Ariane Von (**no** parent link) · session 2018-2019.

---

## 1. The flow as it actually works

**Creation.** Parent accounts are created by staff only, in practice: Admin/SA via **Users → Create** (`UserController@store`, teamSA-gated, with a server-side level check), or implicitly when a student is admitted (the admission form picks an existing parent). The public **`/register` route exists** (`Auth::routes()` + stock `RegisterController`) but is unusable — see F4.

**Passwords at creation.** If the creator does not type a password, the account's password **is its user-type string**: `UserController@store` does `$pass = $req->password ?: $user_type` → a parent gets `parent`, a teacher `teacher`, etc. Student admissions hard-code it: `StudentRecordController` sets `Hash::make('student')` on create and on student password reset. An **Admin password reset** (`users/reset_pass`, POST, hashed id) sets the password to the literal string **`user`** (`UserController.php:104`). There is **no forced password change** anywhere — no flag, column, or middleware; an account can run forever on its default. The seeded demo accounts follow the same pattern (`parent@hackstra.app` / `hackstra` for the demo set, factory/seeded parents on `parent`).

**The link.** One untyped integer: `student_records.my_parent_id` → `users.id`. No foreign-key constraint, no type marker. It is written by:
- **Admin**, via the student create/edit forms (`StudentRecordCreate` / `StudentRecordUpdate`, teamSAWrite-gated). Validation: `'my_parent_id' => 'sometimes|nullable|exists:users,id'` — existence only, **never that the user is a parent**. The form's dropdown lists only parents (`getUserByType('parent')`), but the server accepts any user id (proven in F3). Both requests `decodeHash` the submitted value (the app-wide `{id}` hash contract).
- **CSV bulk import**, matching a `parent_email` column — this path **does** filter `->where('user_type', 'parent')` and errors the row otherwise. The import is stricter than the form.

**Consumption — the link is the *only* factor.** Every parent surface trusts `my_parent_id = Auth::id()` alone: children list (`MyParent\MyController`), parent dashboard + per-child attendance summaries (`HomeController`), My Account's children strip (`Qs::findMyChildren`), fees + receipts + online payment (`PaymentRecordPolicy` / `StudentRecordPolicy`: `(int) $record->my_parent_id === (int) $user->id`), admit cards, marksheet ownership gate (`Qs::userIsMyChild`), study materials and assignments child-scoping. The portal route group additionally requires `user_type = 'parent'` (`MyParent` middleware). Marksheets have a second, independent gate: per-exam `results_published` (currently OFF for the seed's only exam).

**Self-service profile changes.** Any signed-in user can change their own **email, phone, address** in My Account (`MyAccountController@update_profile` + `UserUpdate` request) — **no current-password confirmation, no verification of the new address**. Password recovery is the stock Laravel broker (`POST /password/email` → token row in `password_resets` → emailed link → `POST /password/reset`).

**Detection.** Audit logging covers student *deletion* and CSV import, but **not** student-record updates (link changes are invisible in the audit log) and **not** My Account profile/email changes.

---

## 2. Findings

### F1 — Shared, predictable default passwords → trivial parent takeover by the student — **EXPLOITABLE · Critical**
The "cheating child" scenario needs no skill: a student who knows their parent's email (their own parent's!) signs in as the parent with the password **`parent`** whenever the school created the account without typing a password — the product's own default. Live proof on the seed:
- `parent1@parent.com` / `parent` → **302 → /home** (wrong-password control `hackstra` → rejected).
- Admin created a brand-new parent with the password field left blank → login as that parent with `parent` → **302 → /home**.
- Admin `reset_pass` on parent3 → login with **`user`** → **302 → /home**; the previous password stopped working. The Users UI even labels the action *"Reset this user's password to the default?"*.
A student in as "parent" gets the full portal over **every** linked child (fees, admit cards, attendance, payment initiation — F3 lists the surfaces), can pay or not pay, and — combined with F2 — can make the takeover permanent. Login throttling (F7) does not help: the password is *known*, not guessed. This also applies to staff defaults (`teacher`, `accountant`, …) and to student accounts themselves (`student`).

### F2 — Email change (no password confirm, no verification) → recovery hijack — **EXPLOITABLE · High**
Live proof: signed in as parent1, `PUT /my_account` with `email=attacker-fin@example.com` → **302, DB updated** — no password prompt, no confirmation mail. Consequences, all verified:
- The account's login identity moves immediately: `attacker-fin@example.com` + the (unchanged, default) password → **302 → /home**; the old address `parent1@parent.com` no longer authenticates at all. The school also loses its only contact channel to the real parent.
- `POST /password/email` for the new address **minted a broker token** (`password_resets` row for `attacker-fin@example.com`, 2026-10-22 23:04) — whoever controls that mailbox receives the reset link and can set a password the school/parent never chose, then repeat the trick at will. *Completion of the final mail click could not be executed in this demo environment because SMTP is unconfigured (the send 500s after the token is stored); the flow is the stock Laravel broker, so with production mail configured — the product's intended state — the chain completes.* Initial access to change the email needs the parent's session or password — which F1 hands to the student for free.

### F3 — The link itself: untyped validation + single-factor trust → a wrong link exposes a child completely — **EXPLOITABLE (via admin error / a persuaded admin) · High**
Link *writes* are Admin-only (teamSAWrite), so a student cannot link themself — but nothing checks the link is *right*, and everything downstream trusts it absolutely. Live proof (T8): as Admin, edited student 20 (Ariane Von, no parent) and set Parent = parent1 (id 9, a parent with **no** children) through the real form → **HTTP 200, `my_parent_id = 9` stored**. Then, as parent1:
- `/my_children` → Ariane listed; **parent dashboard** shows her (incl. attendance summaries); **My Account** children strip shows her.
- `GET /my_fees/20` → **200 with her real fee figures** (₹18,000 / ₹6,000 records) — before the link: **403**.
- `GET /my-admit-cards/1/20` → **200** — before: **403**.
- `POST /my_fees/pay/{record}` → authorization **passes** (302 → the child's fee page; the record was fully paid so it stopped there) — before the link, with the gateway enabled: **403**. A mis-linked parent can initiate real online payments against another family's child.
- Marksheets: `GET /marks/show/{hash}/2018-2019` and `/marks/annual/…` → **200** (the ownership gate passes on the wrong link) — the actual marks stay hidden only because results are currently **unpublished**; the legit parent gets the identical "Not Published" page. The day the school publishes results, a mis-linked parent sees the child's full marksheet and annual report.
- Control held throughout: parent1 remained **403** on student 18 (a different family's child) — scoping is exact; it is the *link data* that is wrong, and the system has no second check, no parent-side acceptance step, and no notification to either family. The realistic trigger is mundane: two parents with similar names, a rushed office, or an admin socially engineered by a student ("my father's account is the other Mr Sharma").
- **Untyped half:** the same form accepted `my_parent_id` = a **student** user (id 18) → **200, stored**. Exploitation through that target is **GUARDED** — the `MyParent` middleware requires `user_type='parent'`, and the student's My Account shows no child strip. It remains a data-integrity hole (a nonsense link, silently stored) and a latent risk if that account's type ever changes.

### F4 — Public registration route: live but crashes — **GUARDED (by breakage) · Medium (fragility)**
`GET /register` renders (200). `POST /register` → **500** (`NOT NULL constraint failed: users.code` — the stock `RegisterController::create()` sets only name/email/password, while `users.code` and `users.user_type` are NOT NULL with no default). No account is created (verified in DB). There is therefore **no self-registration today** — but the safety is an accident of schema, not a decision: anyone who later "fixes" registration naively opens account creation with an undefined role. The route should be explicitly disabled.

### F5 — Parent per-endpoint scoping (direct URLs) — **GUARDED**
As parent1 (no children), against another family's child (18): `/my_fees/18` → **403** · `/my_fees/receipt/1` → **403** · `/my-admit-cards/1/18` → **403** · `/marks/show/{hash(18)}/2018-2019` → **302 → /dashboard** (ownership gate) · online-pay POST → **403** (policy `authorize('view', $pr)` runs before any gateway order is created; proven with the mock gateway enabled). As the legit parent (id 4): own children **200**, unlinked child 20 → **403** on every surface. Student accounts hitting the parent portal → **302 → /login** (`MyParent` middleware), including when a record was (mis)linked *to* the student (F3).

### F6 — Sibling visibility — **BY-DESIGN**
A parent sees **all** children linked to their account (id 4: fees for both 18 and 19 → 200). Intended behaviour; it is also why F1/F3 blast radius is "the whole family", not one child.

### F7 — Login throttling — **GUARDED (but moot under F1)**
Stock Laravel throttling is active: after 5 failed attempts the login page shows *"Too many login attempts. Please try again in 15 seconds."* and even the **correct** password is refused during the lockout (verified). Effective against guessing; irrelevant when the password is the known default (F1).

### F8 — Duplicate-email change — **GUARDED by the database (with a wart)**
The form-request rule is malformed in `UserUpdate` and `StudentRecordUpdate` (`unique:users,id` — it checks the email value against the `id` column, i.e. it never fires). Live test: parent2 setting their email to the already-used `parent@hackstra.app` → **500**, and the DB still holds exactly one row with that address — the **`users_email_unique` index** backstops the broken rule. No duplicate identities can be stored; the failure mode should still be a clean validation error, not a 500.

### F9 — Role overlap — **GUARDED / BY-DESIGN**
`user_type` is a single column: one account = one role; nobody is simultaneously student and parent on one account. Admin creating a parent with an existing student's email → validation bounce (302 → create form), still exactly one account with that email (B7 probe). (The same *person* can of course hold two separate accounts with different emails — nothing links them; each account's scoping still holds.)

### F10 — Defaulter document block — **NOT PRESENT in this tree (untestable)**
This base predates the defaulter-block work (batch126): `MarkController` and `AdmitCardController` contain **no fee/defaulter check**, so there is no block here to bypass. What this audit establishes for the day it lands: the block will sit behind the same single-factor link gate (F3) and the publish gate — a mis-linked parent is indistinguishable from a real one to both.

### Minor notes
- `users/reset_pass/{id}` with a **raw** (unhashed) id → 500 `TypeError` in `Qs::headSA()`; via the UI's hashed id it works (E2). Consistent with the app-wide hashed-`{id}` contract, but a robustness wart.
- Student email uniqueness on the student *update* path has the same malformed rule as F8 (DB index backstops it).

---

## 3. Recommendations — decisions for the owner (ranked)

1. **Kill the shared defaults (fixes F1).** Generate a random per-account password at creation (or a one-time setup link), and **force a password change at first login**. Admin reset must issue a random one-time password + forced change — never the literal `user`. This single decision closes the student-as-parent takeover.
2. **Verify contact changes (fixes F2).** Changing the account email should require the current password, take effect only after a verification link sent to the **new** address, and send a notice to the **old** address. Log the change.
3. **Type and watch the link (fixes F3).** Validate `my_parent_id` against `user_type='parent'` server-side in both student form requests (the CSV import already does exactly this); add a real foreign key; write link create/change/remove to the audit log; show admins a confirmation naming the parent *and* the child before saving a re-link. Optionally notify the parent when a child is linked to their account.
4. **Decide registration explicitly (F4).** Disable `/register` in routes (school-provisioned accounts are the product model) so its safety no longer depends on a schema accident.
5. **Fix the malformed unique rules (F8).** `unique:users,email,{id}` in `UserUpdate` / `StudentRecordUpdate` so duplicates fail as form errors, not 500s.
6. **Parent-visible linkage page.** Let a parent see exactly which children are linked to their account (they effectively can, via My Children) and give schools a periodic "unlinked students / parents with no children" report to catch mis-links early.

## 4. What could not be tested
- **Reset-mail completion** (F2's final click): SMTP is unconfigured in the demo environment; token issuance was proven, delivery was not.
- **Real Razorpay checkout** behind the pay endpoint: authorization was proven with the app's mock gateway mode; no live gateway exists here.
- **The defaulter document block** (F10): not present in this tree.
- Production mail/SMS behaviour generally — this audit ran entirely on the local seeded demo.
