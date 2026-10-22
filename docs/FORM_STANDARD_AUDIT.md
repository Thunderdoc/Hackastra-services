# Form Standard Audit — Loop 75 design pass (2026-10-22)

**The standard.** The app's top form standard is the shared pattern
(`partials/patterns/form_page.blade.php`, Loops 28/39 + BW sweep + Loop 73
selects): sectioned cards with icon/number section titles, a 2–3 column
field grid, styled inputs with focus tokens, and a sticky action bar.
Lists use `partials/patterns/list_page.blade.php`. Anything still wearing
the legacy stacked-card look (`.form-group` piles in a plain card, raw
native file inputs, generic footers) is classified **PLAIN**.

**Method.** Every blade under `resources/views/pages` carrying a form was
inventoried (96 pages) and classified by pattern usage, then the PLAIN
set was read individually. This pass (branch `feature/new-pages-design`,
Loop 75 in `public/assets/css/qs.css`) upgraded the new-module pages, the
circulars module end-to-end, the flash/toast system, and Platform Mail.
Legacy people/academics forms are a parallel pass (Loop 76) and are
listed for the record, not touched here.

## 1. Upgraded in this pass (Loop 75)

| Page | What changed |
|---|---|
| Feedback — Give Feedback (`feedback/create`) | Pattern-form finish: icon-chip section title, styled attachment drop-zone (real input, file-name preview), elevated sticky action bar |
| Feedback — lists & detail (`feedback/index`, `mine`, `sa_index`, `show`, `sa_show`, `show_mine`, `_chips`, `_autolog`, `_attachments`) | Pill badges, report-title links, forward bar, document-style detail, styled empty states and attachment thumbnails |
| Circulars — Create/Edit (`circulars/create`, `edit`, `_form`, `_preview`) | Two-column composer: numbered sections (1 Details, 2 Audience & Visibility), plain-text formatting strip + character counts, sticky action bar with Save as Draft / Cancel / Publish Circular, live preview panel mirroring the feed item from the real field values (settings-driven school name) |
| Circulars — List (`circulars/index`, `feed`, `_browser`, controller `index`) | One browser for managers + readers: 4 summary chips over the viewer's scoped set, combinable server-side filters (search/audience/priority/status/date range, persist across pagination), priority-accent cards with status pills, kebab actions + Create button only for write roles. Audience scoping unchanged and applied before any filter |
| Circulars — Detail (`circulars/show`, `print`, `pdf`, `_document`) | Breadcrumb, hero card (full title, priority + Published/Draft + Active/Scheduled/Expired pills), meta grid, escaped message card, Quick Information rail (Circular ID as plain `#<id>`), Audience card, read-only info note. New print view + PDF download (dompdf is in the tree) sharing one document partial, gated exactly like `show` |
| Ask Hackstra (`ask/index`) | Chat treatment: composer bar, user/bot message bubbles (refusals in warning tone), citation chips for sources |
| Parent pay checkout (`parent/pay_checkout`) | Premium summary panel: medallion, amount display, invoice/order/paying-to rows, test-mode banner, secure note. Both callback forms and the Razorpay flow byte-identical |
| Platform Mail (`super_admin/platform_mail/create`, `index`) | Compose rebuilt on the form pattern (Audience + Message sections, same fields/ids/audience-toggle script); sent list scoped to the pill/typography standard |
| Staff Online Payments (`payments/gateway_transactions`) | Scoped to the list standard: pill status badges, mono gateway IDs, tabular amounts |
| Flash confirmations (app-wide) | Floating PNotify corner stack removed. `flash()` now renders ONE slim banner docked under the topbar (`.content-wrapper` top): a new message replaces the old; success/info fade after ~4 s; warning/danger stay until closed. The home event/teacher-reminder strip (`.lav-toast-stack`) is de-floated into the same docked treatment. Call sites and session keys unchanged |

## 2. At standard already (pattern forms — verified by inventory)

Create/edit forms on `form_page`: payments create/edit, books create/edit,
book requests create, events create/edit, classes/sections/subjects/
grades/dorms edit + their index-embedded forms, exams edit, timetables
edit + time-slot edit, marks batch-fix + select-year, students add/edit,
users create/edit, super-admin settings, circulars create/edit (§1).
**LMS pages (assignments/*, materials/*) are covered by the dedicated
LMS squad** (owner carve-out 2026-10-22) — inventoried here for the
record (their create/edit forms already sit on the pattern), not part
of this pass.
My Account is at standard via its own v3 build (Loop 74).
Lists on `list_page`: students list/graduated, users, books, payments
(index/manage/invoice/collection/defaulters), attendance reports,
assignments/materials index, feedback and gateway lists (§1).

## 3. Working screens (not stacked forms — no action needed)

Marks entry and review grids (`marks/edit`, `marks/random`,
`marks/selector`, `marks/tabulation`, `marks/show/comments`,
`marks/show/skills`, `exams/index` publish controls), attendance taking
(`attendance/index`, `attendance/my`, `attendance_periods/take`;
LMS grading screens belong to the LMS squad) — dense
working grids already covered by the earlier attendance/marks redesign
loops; their forms are inline row editors, not stacked create/edit forms.

## 4. PLAIN — remaining

**In the parallel Loop 76 pass (people/academics legacy forms):**
students import (`students/import`), promotion flow
(`students/promotion/promote`, `reset`, `selector`), timetable sub-forms
(`timetables/subjects/add`, `subjects/edit`, `time_slots/add`,
`time_slots/manage`), exam scheme edit (`exams/scheme/edit`).

**Plain, not yet scheduled:**
PIN management (`pins/create`, `pins/enter`, `pins/index` — small legacy
card forms), payroll generation controls (`payroll/index`), admit-card
generator (`exams/admit_cards`), book-request decision actions
(`book_requests/index` inline forms), user profile quick forms
(`users/show`). These are small utility forms; recommend folding them
into the next pattern sweep.

## 5. Honest notes / deferred by design

- **Circular message formatting is plain-text.** The message is stored
  as plain text and rendered escaped everywhere (feed, detail, print),
  so the composer's toolbar inserts plain-text constructs (bullets,
  numbering, a divider) instead of HTML rich text. True rich text
  needs a storage/rendering change and belongs to the circular
  features workstream, with attachments (composer section 3), official
  reference numbers and signatory fields — the composer layout leaves
  natural room for that section.
- **Reader list vs. detail gate.** The redesigned list shows readers
  every published circular addressed to them (Active/Upcoming/Expired
  pills, chips count the same set), while `show` keeps its existing
  gate — readers can open Live circulars; Scheduled/Expired cards
  render without a Read link rather than linking to a 404. If the
  owner wants readers to open upcoming/expired circulars too, that is
  a one-line change to `Circular::isVisibleTo` — a product decision,
  not made here.
- **Print/PDF gate** follows `show` exactly (managers: any circular;
  readers: currently-visible only), so a parents-only circular 404s
  for a teacher on screen, in print and in PDF alike.
