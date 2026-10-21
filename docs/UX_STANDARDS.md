# Hackstra UX Standards, Live Audit & Top‑20 Fix List

**Loop 8 · Workstream L — UI/UX best‑practices research → standards + audit (docs only)**
Date: 2026‑10‑21 · Branch: `feature/loop8-ux-research` · Status: for the next visual loop to execute

## Why this document exists

Hackstra is a product we **sell** to schools. The people who evaluate it
(principals, trust admins) judge it in the first ten minutes; the people who
must love it (office staff, teachers, accountants) use it for hours daily and
are mostly **non‑technical**. This document does three things:

1. **Part 1 — Standards.** The UI/UX rules Hackstra will be built to, distilled
   from named, authoritative design systems and research — not taste.
2. **Part 2 — Audit.** Where the current product stands against those rules,
   measured on the **live deployment** (`https://hackastra.onrender.com`,
   Super Admin session, 2026‑10‑21) and in the code (`public/assets/css/qs.css`
   on master + key Blade templates). Every finding was observed, not assumed.
3. **Part 3 — Fix list.** The top 20 fixes, ranked by
   *(user pain × buyer impression) ÷ effort*, each specified concretely
   enough (files, classes) for a CSS/view agent to execute without re‑deciding.

**Method note.** Live pages crawled: dashboard, student admit (create),
student list, users list, user create, payments index/create/manage,
settings, attendance, classes, exams, marks, my account, events, books,
login — 16 pages, with HTML size, response time, heading census, form census
(fields/labels/`for` associations/placeholders), button variants, image alts,
inline styles and asset counts measured per page. Two live form submissions
were made (empty student create, empty payment create) to observe the real
error flow. Contrast ratios were computed exactly (WCAG relative‑luminance
formula), not eyeballed.

---

# PART 1 — THE STANDARDS

Each standard states the rule, its source, and what it means concretely for
Hackstra. Sources are named inline; full list at the end.

## Navigation & orientation

**S1 · Shallow, stable navigation; the user always knows where they are.**
Primary tasks must be reachable within 2 clicks from the sidebar; the sidebar
is one stable list grouped by job (People, Academics, Money, Operations),
never an index of data (a menu entry per class is data, not navigation).
Every inner page carries a page‑context header (title + breadcrumb or parent
label). *Sources: NN/g heuristic #1 (visibility of system status) and #6
(recognition rather than recall); Material Design navigation patterns
(drawer for many destinations, grouped destinations); Carbon left‑nav
grouping.* Hackstra: role menus in `resources/views/pages/*/menu.blade.php`
+ the `partials/header` context area.

**S2 · One primary action per screen.**
Each page has exactly one visually dominant action (filled primary button);
everything else is secondary (outline) or tertiary (text/icon in a menu).
*Sources: NN/g heuristic #8 (aesthetic & minimalist design) and visual‑
hierarchy research; Carbon common‑actions pattern (primary vs ghost vs
danger).* Hackstra: the button standard in `docs/UI_UX_SWEEP_PLAN.md`
already says this — Part 2 shows where it is violated.

## Data tables (the product's core surface)

**S3 · Tables are tools: one density, sortable, searchable, paginated.**
Pick one row density (Carbon's *normal* ≈ 48px rows; *compact* ≈ 32px is
for finance‑grade density) and use it everywhere, header row included.
Numeric columns right‑align, text left‑aligns. Any list that can exceed
~25 rows ships with search + sort + pagination (server‑side or DataTables).
Tables never overflow the phone screen: they scroll inside a wrapper or
reformat. Row actions live in one labelled "Actions" menu — never a spray
of unexplained icons. *Sources: IBM Carbon Design System, Data Table usage
(four row sizes, pagination toolbar, header/body height match); Carbon 2x
grid; NN/g #6/#7 (recognition; flexibility & efficiency of use).*

## Forms (where staff spend their day)

**S4 · Labels are sacred: visible, top‑aligned, programmatically attached.**
Every field has a text label above the input, attached with `for`/`id`
(never placeholder‑only — placeholders vanish while typing and fail as
labels). Helper text sits under the label, errors under the field. Fields
are single‑column except truly paired short fields (start/end date). When
most fields are required, mark the *optional* ones "(optional)" instead of
asterisking everything. Long forms are grouped into named sections; a
section never exceeds ~8 fields without a sub‑heading. *Sources: Carbon
Form usage + Text Input accessibility (labels associated via `for`,
helper/error surfaced via `aria-describedby`, top‑aligned labels for
scanability); NN/g form research; WCAG 3.3.2 Labels or Instructions,
1.3.1 Info and Relationships.*

**S5 · Validation: early help, errors at the field, in plain words.**
Validate on blur and on submit. An error is shown **next to its field**
(red border + icon + one sentence that says what to fix, e.g. "Amount must
be a number"), stays until fixed, and a top summary — if present — is an
*index with anchor links to the fields*, never the only indication. Never
validate a field before the user has finished with it. Error text is plain
language, no codes, no blame. *Sources: NN/g, "10 Design Guidelines for
Reporting Errors in Forms" (guidelines 1, 3, 7, 8, 9 are the operative
ones) and heuristic #9; Carbon Form — validation & errors ("the validation
label below the field should be as informative as possible").*

**S6 · Every action answers back — once, in one voice.**
Create/update/delete ends with a toast that names what happened ("Payment
of ₹5,000 recorded for Aarav Kumar"), not a generic "Success". Destructive
actions confirm first (high‑risk deletes may require typing the name —
Carbon), and after a delete the user lands back on the list with the item
gone + a success notification. There is exactly **one** notification
system product‑wide. *Sources: NN/g #1; Carbon common actions — Delete /
Error patterns ("After the user deletes data, return to the page that lists
the data… present a success notification").*

**S7 · No dead ends: empty, loading and error states are designed.**
An empty list explains itself in one line and offers the primary action
("No payments yet — Record the first payment"). Operations over ~1s show
progress (button spinner / skeleton); a failed page (404/500) is branded,
says what happened in plain words, and offers a way back. A workflow must
never depend on JavaScript to avoid a server error — forms work, and fail
gracefully, without JS (progressive enhancement). *Sources: NN/g #1, #9,
#10; Material Design empty‑state guidance; Carbon error notifications
("provide context of what happened and a clear path to continue").*

## Accessibility floor (WCAG 2.2 AA — a selling point for institutions)

**S8 · The non‑negotiables.**
- Text contrast ≥ **4.5:1** (≥ 3:1 only for large text ≥ 18.66px bold /
  24px). Non‑text UI (borders, icons, focus) ≥ 3:1. *(WCAG 1.4.3, 1.4.11)*
- Pointer targets ≥ **24×24 CSS px** (WCAG 2.5.8, AA floor); design target
  is **44–48px** for primary/touch controls. *(Material: 48×48dp targets
  with 8dp separation; Apple HIG: 44pt.)*
- Visible keyboard focus on every interactive element, never hidden behind
  sticky chrome. *(WCAG 2.4.7, 2.4.11)*
- One `<h1>` per page; headings in order; `<header>/<nav>/<main>`
  landmarks; a skip‑to‑content link. *(WCAG 1.3.1, 2.4.1, 2.4.6)*
- State is never carried by colour alone (status = colour **+** word/icon).
  *(WCAG 1.4.1)*
- Don't make users re‑enter data the system already has (prefill, carry
  selections across steps). *(WCAG 3.3.7 Redundant Entry; also NN/g #6.)*
- Help lives in one consistent place on every page. *(WCAG 3.2.6.)*
- Meaningful images get meaningful alt text (a student's photo is alt =
  the student's name, or empty if purely decorative). *(WCAG 1.1.1.)*

## Look & feel system

**S9 · One type scale, one spacing rhythm, tokens not literals.**
Type scale: 12 / 13 / 14 / 16 / 20 / 24 px (captions 12 minimum — **nothing
below 12px anywhere**, including sidebars and badges). Spacing on a 4/8px
rhythm. Colours, radii and shadows are CSS variables in `:root`; a page may
not introduce a new hex value. *Sources: Material Design 8dp grid +
typography scale; Carbon 2x grid & token model; NN/g #4 (consistency and
standards).*

**S10 · Colour earns its place.**
Brand colour is for actions and selection, status colours for status, grey
for structure. Every text/background pair in the palette is pre‑verified
against S8 contrast (the verified token values are in Part 3, Fix 3).

## Mobile & responsive

**S11 · Design the phone flow, don't shrink the desktop.**
Breakpoints are one fixed set (1200 / 992 / 768 / 480). On phones: the
sidebar becomes an off‑canvas drawer, page headers stack (title → action),
stat cards go 1‑up, tables scroll in wrappers, primary form actions are
reachable without precision tapping (S8 targets), and sticky save bars
replace end‑of‑form buttons on long forms. *Sources: Material adaptive
navigation (drawer ↔ rail ↔ bar by width); WCAG 1.4.10 Reflow; the user's
standing requirement (mobile responsive, properly).*

## Speed (perceived quality = response)

**S12 · Performance budgets.**
At the 75th percentile: **LCP ≤ 2.5s, INP ≤ 200ms, CLS ≤ 0.1** (web.dev /
Core Web Vitals "good"). Practical budgets for this stack: server response
< 800ms warm; ≤ 6 stylesheets and ≤ 15 script files on list pages (plugins
load only on pages that use them); list HTML < 60KB before assets; images
sized to their slot. A school office PC on a weak connection is the
reference device. *Source: web.dev, Core Web Vitals thresholds.*

## Print (a first‑class output in a school)

**S13 · Anything a school prints must look printed‑on‑purpose.**
Marksheet, tabulation, receipt, admit card and reports print from the
browser with: school header (name/logo/session from settings), no app
chrome, deliberate page breaks, aligned numeric columns. Print is tested
as a feature, not an accident of `@media print`. *Source: product
requirement (user directive: minimize pen‑and‑paper work; Loop 4 print
baseline) — stated as a product standard, not borrowed research.*

## Governance

**S14 · One design layer, or the drift returns.**
All shared styling lives in `public/assets/css/qs.css` (tokens + components)
and shared partials; per‑page inline `style=` and one‑off classes are
defects. One button system, one table system, one notification library, one
confirm pattern (`data-confirm`). New screens are assembled from these
parts or the parts get extended — never forked. *Source: NN/g #4; every
mature design system above (Carbon/Material) is token‑and‑component based
for exactly this reason.*


---

# PART 2 — AUDIT OF THE CURRENT UI (live, 2026‑10‑21)

## Page inventory (measured, Super Admin session, warm server)

| Page | HTML | Resp. | `<h1>` | Main form census | Notes |
|---|---|---|---|---|---|
| Dashboard `/home` | 54.5 KB | ~1s | 0 (h2,h3, then 9×h5) | — | 69 internal links on page |
| Admit Student `/students/create` | 73.0 KB | 1.1s | 0 | 21 fields, 20 labels, **10 with `for`** | 3‑step AJAX wizard |
| Student Information `/students/list/1` | 74.3 KB | 0.9s | 0 | — | 3 tables, 17 imgs, 16 inline styles |
| Manage Users `/users` | **121.9 KB** | 1.1s | 0 | (create form: 16 fields, 15 labels, 7 `for`) | 5 tables, no pagination |
| Create User `/users/create` | — | **500** | — | — | stock Laravel error page |
| Manage Payments `/payments` | 34.1 KB | 0.9s | 0 | class‑select form only | |
| Create Payment `/payments/create` | 36.9 KB | 1.4s | 0 | 6 fields, 5 labels, 4 `for` | error flow tested live |
| Student Payments `/payments/manage` | 35.2 KB | 0.7s | 0 | — | |
| Settings `/super_admin/settings` | 42.2 KB | 0.9s | 0 | **18 fields, 16 labels, 2 with `for`**, 14 placeholders | 1 form, 1 fieldset, 1 save |
| Attendance `/attendance` | 38.7 KB | 0.9s | 0 | picker form: 2 labels, **0 `for`** | |
| Manage Classes `/classes` | 56.4 KB | 0.6s | 0 | — | |
| Manage Exams `/exams` | 36.3 KB | 0.7s | 0 | 3 fields, 2 labels, 1 `for` | |
| Marks `/marks` | 36.6 KB | 0.9s | 0 | selector: 5 fields, 4 labels, 4 `for` | best label score |
| My Account `/my_account` | 39.8 KB | 0.7s | 0 | 5 fields, 3 labels, 3 `for` | |
| Events `/events` | 33.1 KB | 1.1s | 0 | — | |
| Books `/books` | 33.6 KB | 1.6s | 0 | — | |

Every inner page loads the same **9 stylesheets + 30 script files**,
regardless of what the page does. Every page title is `"<Page> | Laravel"`.
No page has a breadcrumb (0 of 16). No page has `<main>` or `<nav>`
landmarks (0 found in the dashboard document) or a skip link.

## Findings (severity: ● critical · ● high · ● medium · ● low)

**A1 ● Create User is dead — and fails ugly.** `/users/create` returns
HTTP 500 with the **stock Laravel "Server Error" page** (no branding, no
explanation, no way back). A core admin flow is broken, and the failure
itself violates S7. (Root cause is a code bug — routed to a bug fix in
Part 3; the *error page* is the UX half.)

**A2 ● The admission form only works through its AJAX path.** A plain
(non‑AJAX) POST of the Admit Student form to `/students` returns HTTP 500.
The wizard silently assumes JavaScript + its exact request shape; any
failure in that chain (old browser, blocked script, session hiccup) becomes
a server error instead of a form with errors. Violates S7 (graceful
failure / progressive enhancement).

**A3 ● Validation errors are summary‑only.** Live test — submitting Create
Payment empty returns the form with one top `alert-danger` containing flat
sentences: *"The title field is required. The amount field is required."*
Measured on the returned page: **0 fields highlighted** (no `is-invalid`
anywhere), no per‑field messages, no anchor links from the summary to the
fields. This is the exact pattern NN/g's form‑error guidelines prohibit
(guideline 8: don't use summaries as the only indication; guideline 3:
keep messages next to fields). On a 21‑field admission form or the 18‑field
settings form this pattern makes error‑fixing a scavenger hunt. Violates S5.

**A4 ● Half the labels aren't attached to their fields.** Programmatic
label association (label `for` → input `id`): Admit Student 10/20, Settings
**2/16**, Attendance picker 0/2, Exams 1/2, Create User 7/15. Clicking a
label doesn't focus its field; screen readers can't name the fields.
Placeholders are doing label duty in places (19 placeholders on Admit
Student, 14 on Settings) and disappear on typing. Violates S4 / WCAG
1.3.1 + 3.3.2.

**A5 ● Core text colours fail WCAG contrast — measured.** Computed ratios
for the design system's own pairs (small text needs 4.5:1):
muted `#718096` on white **4.02:1 ✗** (used for labels, table headers,
meta everywhere); meta `#9aa7b5` on white **2.45:1 ✗** (timestamps,
sub‑text, chart axes at 2.35:1); white label on primary buttons `#3478f6`
**4.07:1 ✗**; success pill text `#188b66` on `#e7f7f0` **3.85:1 ✗**;
warning pill `#b7791f` on `#fff3df` **3.32:1 ✗**. Body text `#44546a`
(7.71:1 ✓) and danger pill (4.76:1 ✓) pass. The failing colours are
precisely the ones used at the smallest sizes. Violates S8/S10.

**A6 ● Heading structure is broken on every page.** No page has an `<h1>`;
inner‑page titles render as `<h4>`; the dashboard runs h2 → h3 → **nine
`<h5>` card titles**. Keyboard/screen‑reader users get no document outline,
and visual hierarchy is carried by font size alone. Violates S8 (1.3.1,
2.4.6).

**A7 ● Row actions are mystery icons; photos are all "photo".** Student
Information contains **19 icon‑only action links with no text, no `title`,
no `aria-label`** (measured `title=` count: 0) — a new staff member must
hover‑guess each one, and assistive tech gets nothing. All 15 student
photos on the page carry `alt="photo"`. Violates S3 (labelled actions),
S8 (1.1.1, 4.1.2).

**A8 ● Every page pays for every plugin.** 30 script files on all pages —
including the form‑wizard, uniform, inputmask and validation plugins on
read‑only list pages — plus **two competing notification libraries**
(SweetAlert2 **and** PNotify) and jQuery‑UI. Manage Users ships 121.9 KB of
HTML before a single asset. This works directly against INP/LCP (S12) and
against S14 (one notification system).

**A9 ● The sidebar is an index, not a navigation.** 48 sidebar links,
including **one entry per class** (10 links of the form
`/students/list/{id}`) — data living in navigation, which will grow with
every class a school adds. With no breadcrumbs anywhere (A‑inventory), the
only orientation cue on inner pages is the `<h4>` title. Violates S1.

**A10 ● Settings is one 18‑field wall.** A single form, one `<fieldset>`,
no section headings, one save button at the very end, 2/16 labels attached
(A4). Changing one phone number means loading, finding and re‑saving the
school's entire configuration. Violates S4 (grouping) and S5's spirit
(errors in an 18‑field form are unfindable under A3's summary pattern).

**A11 ● Lists don't scale.** Manage Users renders **5 separate tables** on
one page with **zero pagination** markup; no list page uses DataTables or
any search/sort (0 occurrences across list pages). At a real school's
300–2,000 users this becomes an unusable scroll. No list table is wrapped
for horizontal scrolling on mobile (`.table‑responsive` occurrences on
Student Information: **0**) — wide tables will clip/overflow at 375px.
Violates S3/S11.

**A12 ● No design tokens; type runs tiny.** `qs.css` (27.9 KB, master)
contains **33 distinct hex colours** and just **2 `var(--…)` usages** — no
token layer. Its font sizes include 8px (×5 rules), 10px (×12), 11px (×4)
and fractional sizes down to `.6rem` (≈9.6px) — sidebar session text is
set at 8px. Body/table text is consistently *below* the 12px floor of S9.
Breakpoints are improvised (both `max-width:767px` and `768px` appear, plus
480/1024/1199). Violates S9/S14.

**A13 ● Small targets in the daily‑use chrome.** `.btn-sm` min‑height is
32px; sidebar links are min‑height 35px; table row‑action icons (A7) have
no sized hit area at all. These pass the WCAG 2.5.8 floor (24px) but miss
the S8 design target (44–48px) and the app's own 40px button standard —
on the phones/tablets staff actually use. Violates S8/S11.

**A14 ● The admission wizard's copy and structure undercut it.** Step 1's
title is a truncated instruction sentence ("Please fill The form Below To
Admit A Ne…"); the visible steps are just "Personal data" / "Student Data"
for 21 fields; there is no review‑before‑submit step and no draft
autosave — lose the tab, lose 21 fields of typing (WCAG 3.3.7's redundant‑
entry concern, and a daily‑pain issue during admission season). Required‑
field marking is inconsistent (9 `required` attributes vs 20 labels).
Violates S4/S5/S8.

**A15 ● Three different "are you sure?" behaviours.** The app has a clean
`data-confirm` pattern in `hackstra-ux.js`, but list pages don't use it
(measured `data-confirm`: 0 on Students and Users) — Manage Users instead
carries **18 inline `onclick` handlers** driving SweetAlert directly. Same
product, three confirm experiences, plus inline JS that blocks any future
CSP hardening. Violates S6/S14.

**A16 ● The product's name is missing from the product.** Every browser tab
reads "… | Laravel"; the login page `<title>` is literally "Laravel". For
a white‑label product sold to schools, the framework's name — not the
school's — is what appears in tabs, history and bookmarks. Violates S1's
branding/orientation intent and basic buyer impression. (Settings‑driven
school name exists in the data — Workstream G's territory; the `<title>`
pattern is the UX fix here.)

**A17 ● A theme toggle with no theme.** The topbar ships a "Toggle theme"
button (`aria-label` present), but `qs.css` contains **zero dark‑theme
rules**. Either the mode doesn't exist behind the button or it exists only
in the base theme partially — a control that promises what the design
layer doesn't deliver. Needs a product decision (Part 3, PD3).

**A18 ● Inline styles keep leaking into pages.** 16 `style=` attributes on
Student Information, 19 on Manage Users — one‑off presentation in markup,
the classic S14 drift that makes every future restyle a scavenger hunt.

**What already meets the standard (keep, don't regress):** global search
in the topbar with Ctrl+K and a descriptive `aria-label`; a real
`:focus-visible` ring in `qs.css`; a `prefers-reduced-motion` block; an
`@media print` baseline (Loop 4); topbar controls carry `aria-label`s
(Search, Recent activity, Toggle navigation…); the dashboard's SVG chart
has an `aria-label` and its donuts/cards follow one card system; the
double‑submit guard and `data-confirm` pattern exist in `hackstra-ux.js`;
Loop 7 added honest empty states to 9 list views. The foundation is real —
the gaps are consistency, scale and the failure paths.


---

# PART 3 — THE TOP‑20 FIX LIST (ranked)

Ranking = (daily user pain × buyer impression) ÷ effort. Effort: **S** ≤ ½
day, **M** 1–2 days, **L** 3+ days. Each fix names its standard (S#),
audit findings (A#), and the concrete files/classes to touch.

**1. Resurrect Create User + brand the failure pages.** *(S7 · A1 · S)*
Fix the `/users/create` 500 (bug — controller/view), then replace the stock
error pages: `resources/views/errors/404.blade.php` + `500.blade.php` with
school‑branded pages (plain‑language message, "Back to dashboard" button,
what to tell support). A buyer who hits one raw Laravel error page stops
trusting the demo.

**2. Put the school's name in every title.** *(S1 · A16 · S)*
Layout `<title>` becomes `{{ $page_title }} | {{ Qs::getSetting('system_name') }}`
(fallback "Hackstra"); login title "Sign in | {school}". Touches the layout
partial that emits `<title>` (partials/inc_top or layout head) and
`resources/views/auth/login.blade.php`. White‑label credibility for free.

**3. Contrast token pass — verified values, one file.** *(S8/S10 · A5 · S)*
In `public/assets/css/qs.css` only, retune the failing tokens (ratios
computed and verified):
`--muted #718096 → #5c6f84` (4.02 → **5.17:1**);
meta `#9aa7b5 → #66788c` (2.45 → **4.54:1**);
primary button bg `#3478f6 → #2867dc` for button fills (white text 4.07 →
**5.17:1**; #3478f6 may stay for large graphics/links‑on‑white at ≥3:1 only
where paired with underline);
success text `#188b66 → #14714f` (3.85 → **5.41:1** on its tint);
warning text `#b7791f → #97590f` (3.32 → **5.10:1** on its tint).
Because these colours are currently literals in 33 hex values, do Fix 15's
`:root` variables in the same pass and swap the literals for `var()`.

**4. Inline field errors, everywhere forms validate.** *(S5 · A3 · M)*
Add a shared partial `resources/views/partials/errors/field.blade.php`
(renders `@error('field')` as `.field-error` text under the input) + a
`.form-control.is-invalid` border rule and `.field-error` style in qs.css;
the top summary, where kept, becomes a linked index (`href="#field_id"`).
Apply first to: `payments/create`, `students/create`, `super_admin/settings`,
`users/create`. Acceptance: repeating the Part‑2 live test shows each error
beside its field, field outlined, summary links jumping to fields.

**5. Label association sweep.** *(S4 · A4 · S–M, mechanical)*
Give every input/select an `id` and its label a matching `for`, in all form
blades (worst first: settings 2/16 → 16/16, student create 10/20 → 20/20,
attendance picker 0/2). Placeholders become examples only ("e.g. 98xxxxxx01"),
never the label. Where most fields are required, mark optional fields
"(optional)" per Carbon instead of asterisking all.

**6. Labelled row actions + honest photo alts.** *(S3/S8 · A7 · M)*
Replace icon‑only action links in list tables with the existing dropdown
pattern: an "Actions" button opening Edit / View / Delete **text** items
(or icon+text buttons where space allows); any icon that remains gets
`title` + `aria-label`. Student/user photo `alt` becomes the person's name.
Files: `students/list`, `users` list blades + the shared dropdown markup
they already use.

**7. Landmarks & one `<h1>`.** *(S8 · A6 · M)*
Layout partials: wrap chrome in `<header>`, sidebar in
`<nav aria-label="Primary">`, content in `<main id="main-content">`; add a
skip link as the first focusable element. Promote each page's title to the
single `<h1>` (restyle via a `.page-title` class in qs.css so visuals don't
change), card titles become `<h2>`. Files: `partials/header`, layouts,
qs.css heading rules.

**8. Asset diet — plugins only where used, one notifier.** *(S12/S14 · A8 · M)*
In `partials/inc_top` / `inc_bottom`: load wizard/steps, uniform, inputmask
and jquery‑validate **only** on form pages that instantiate them; remove
PNotify entirely (standardize on SweetAlert2 toasts — see Fix 17); add
`defer` where safe. Target: list pages ≤ 6 CSS / ≤ 15 JS (from 9/30).
Measure before/after with the Part‑2 inventory method.

**9. A table standard that scales.** *(S3 · A11 · M)*
qs.css: one `.data-table` density (row padding token), numeric columns
`.text-right`, sticky header inside wrappers. Blades: wrap every list table
in `.table-responsive` (students list today: 0 wrappers); add server‑side
pagination or DataTables to Students, Users, Payments lists; split Manage
Users' 5 stacked tables behind its existing role tabs (one table per view,
paginated). Acceptance: 2,000‑row dataset stays < 60KB HTML per page and
375px shows no page‑level horizontal scroll.

**10. Sidebar becomes navigation again + breadcrumbs.** *(S1 · A9 · M)*
Collapse the 10 per‑class sidebar links into the Students section as a
"By class" submenu (or move class choice into the Students page filter —
see PD2); cap top‑level entries per role menu; add a breadcrumb/context
bar under the topbar on inner pages (`partials/header` + qs.css
`.page-context`). Files: `pages/*/menu.blade.php` partials.

**11. Settings in sections, saved in sections.** *(S4 · A10 · M)*
Split `super_admin/settings` into 4 grouped cards with `<h2>`s — School
profile / Session & terms / Fees / Exam & system — each with its own save
button (or one sticky save bar on scroll); labels fixed per Fix 5. If
per‑section endpoints are needed, that's a small controller addition —
flagged here so it isn't discovered mid‑loop.

**12. Admission wizard: honest steps, a review, a memory.** *(S4/S5/S8 · A14 · M)*
Retitle steps ("Student details", "Parent & guardian", "Review & admit");
step 1 stops being an instruction sentence. Add a Review step rendering all
21 fields read‑only before submit; autosave the in‑progress form to
`localStorage` (restore on return, clear on success); on a step error, jump
to the step **and** field (pairs with Fix 4). Files: `students/create`
blade + its wizard init in the page JS / `partials/js`.

**13. Touch targets pass (CSS only).** *(S8/S11 · A13 · S)*
In qs.css add `@media (pointer: coarse)` rules: `.btn-sm` min‑height 40px,
sidebar links min‑height 40px, table row‑action hit area ≥ 40px via
padding, topbar icons stay 40px. Zero markup change; immediate phone win.

**14. One confirm to rule them all.** *(S6 · A15 · M)*
Delete inline `onclick` confirms (18 on Manage Users); route every
destructive action through `data-confirm` + the `hackstra-ux.js`
SweetAlert handler; student/user delete upgrades to type‑the‑name for
Super Admin (Carbon pattern). After delete: land on the list with a named
toast (Fix 17).

**15. Tokens & type scale in qs.css.** *(S9/S14 · A12 · M)*
Introduce `:root` variables (colours incl. Fix 3 values, spacing 4/8/12/16/
24, radii, the S9 type scale 12/13/14/16/20/24); migrate the 33 hex
literals to `var()`; delete sub‑12px text (sidebar 11px → 12.5px, brand
sub‑text 8px → 10.5px minimum, `.6rem` sizes → scale steps); freeze the
breakpoint set to 1200/992/768/480 (retire the 767/768 duplication).

**16. One empty‑state component.** *(S7 · — · S)*
Promote Loop 7's per‑list empty rows into a shared partial
`partials/empty_state.blade.php` (icon, one‑line why, primary CTA) and use
it on every list incl. search‑no‑results and filtered‑to‑zero. Files:
list blades + the partial.

**17. Feedback standard: named toasts, visible progress.** *(S6 · A8 · S–M)*
Convention: controllers flash a specific message ("Fee of ₹5,000 recorded
for Aarav Kumar — Receipt #123"); the single toast system (Fix 8) renders
it; submits keep the existing double‑submit guard + spinner, extended to
the wizard and payment flows; any operation > 1s shows progress text, not
a frozen button.

**18. Print pack for the paper‑replacement promise.** *(S13 · — · M)*
Extend the Loop‑4 `@media print` block in qs.css: consistent school header
(from settings) on marksheet/tabulation/receipt/admit‑card prints,
`break-inside: avoid` on result blocks, page‑break rules for tabulation,
hide all chrome classes uniformly. Coordinate with Loop 8's print
workstream so templates and CSS land together.

**19. Focus management on errors & dialogs.** *(S8 · A3/A6 · S)*
On failed submit, move focus to the error summary, then to the first field
on link click (Fix 4 anchors); modals trap focus and return it on close;
add `scroll-margin-top` (≈ topbar height) to focusable sections so the
sticky topbar never covers the focused element (WCAG 2.4.11).

**20. Filter bars on every list.** *(S3 · A11 · M)*
One `.filter-bar` pattern (qs.css + a Blade partial): search input +
context selects (class / status / session) + Reset, applied to Students,
Users, Payments, Books, Attendance report. Recognition over recall —
staff filter instead of scanning. Server‑side params so filters survive
pagination (pairs with Fix 9).

## Needs a product decision (flagged, not in the 20)

- **PD1 · White‑label depth.** How far does school branding go — name/logo
  in sidebar + login + titles (Fix 2) only, or also school‑chosen accent
  colour and print letterheads? Accent‑colour theming multiplies the
  contrast‑verification work in Fix 3.
- **PD2 · Sidebar IA change (Fix 10).** Moving per‑class links changes a
  navigation habit early adopters may already have; choose submenu vs
  in‑page filter before the visual loop starts.
- **PD3 · The theme toggle (A17).** Fund a real dark theme (a second token
  set — cheap *after* Fix 15, expensive before) or remove the button.
  Recommendation: tokens first, decide after.
- **PD4 · Table density default.** Carbon offers comfortable vs compact;
  accountants love compact, teachers on shared PCs need comfortable.
  Choose one default (a per‑user toggle is a later luxury).
- **PD5 · Language.** UI is English‑only today; staff/parent fluency in
  the target market may justify Hindi/regional i18n — a product‑scope
  decision with real cost, raised here because "non‑technical staff" is
  the core user.
- **PD6 · Wizard vs one long form (Fix 12).** Office staff admit students
  in bulk during admission season — expert‑user research (NN/g #7) often
  favours one well‑sectioned form over steps for repeat data entry. Keep
  the wizard for now (it exists), but validate with the first pilot school.

---

## Appendix A — Contrast measurements (computed, WCAG formula)

| Pair (fg on bg) | Used for | Ratio | AA small text (4.5:1) |
|---|---|---|---|
| `#44546a` on `#ffffff` | body text | 7.71:1 | ✓ |
| `#718096` on `#ffffff` | muted/labels/headers | 4.02:1 | ✗ → `#5c6f84` = 5.17:1 |
| `#718096` on `#f4f7fb` | muted on app bg | 3.74:1 | ✗ |
| `#9aa7b5` on `#ffffff` | meta/timestamps | 2.45:1 | ✗ → `#66788c` = 4.54:1 |
| `#9aa7b5` on `#f8faff` | chart axis text | 2.35:1 | ✗ |
| `#ffffff` on `#3478f6` | primary button label | 4.07:1 | ✗ → bg `#2867dc` = 5.17:1 |
| `#188b66` on `#e7f7f0` | success pill | 3.85:1 | ✗ → `#14714f` = 5.41:1 |
| `#b7791f` on `#fff3df` | warning pill | 3.32:1 | ✗ → `#97590f` = 5.10:1 |
| `#c0392b` on `#fdecec` | danger pill | 4.76:1 | ✓ |

## Appendix B — Evidence method (so the next loop can re‑measure)

Authenticated curl session against the live site (login field `identity`),
pages saved and parsed for: title/lang/viewport, heading census, per‑form
field/label/`for`/placeholder counts, button‑variant census, table & image
counts, `alt` values, inline `style=` counts, stylesheet/script counts,
`data-confirm`/`onclick` counts, pagination markers. Two controlled
submissions (empty Create Payment → observed summary‑only errors, 0
highlighted fields; empty Admit Student POST → HTTP 500). Contrast computed
with the WCAG relative‑luminance formula. Code evidence from
`public/assets/css/qs.css` on master (27,864 bytes; 33 distinct hex values;
2 `var()` usages) and the live HTML `<head>` asset lists.

## Sources

- **Nielsen Norman Group** — *10 Usability Heuristics for User Interface
  Design* (Nielsen, 1994; NN/g) — heuristics #1, #4, #6, #7, #8, #9, #10.
- **Nielsen Norman Group** — *10 Design Guidelines for Reporting Errors in
  Forms* (NN/g) — inline validation, messages next to fields, no
  summary‑only reporting, no premature validation, no tooltips for errors.
- **IBM Carbon Design System** — *Data Table (usage)*; *Form (usage)*;
  *Text Input (accessibility)*; *Common actions pattern*; *2x Grid* —
  density scale, pagination toolbar, top‑aligned `for`‑associated labels,
  error text below the field, delete/undo/notification pattern, tokens.
- **Material Design (Google)** — layout & accessibility guidance: 8dp
  grid, 48×48dp touch targets with 8dp separation, adaptive navigation.
- **WCAG 2.2 (W3C)** — SC 1.1.1, 1.3.1, 1.4.1, 1.4.3, 1.4.10, 1.4.11,
  2.4.1, 2.4.6, 2.4.7, 2.4.11, 2.5.8 (Target Size Minimum, 24×24 CSS px,
  AA), 3.2.6, 3.3.2, 3.3.7, 3.3.8; 44×44pt corroborated by Apple HIG.
- **web.dev (Google)** — *Core Web Vitals*: LCP ≤ 2.5s, INP ≤ 200ms,
  CLS ≤ 0.1 at the 75th percentile.
- Product‑internal: `docs/UI_UX_SWEEP_PLAN.md` (button standard, design
  palette), `docs/LOOP7_PLAN.md`, `docs/LOOP7_QA.md` (Loop 7 live crawl).

*End of Workstream L deliverable. Companion: Workstream D's
`docs/CASE_STUDY.md` covers **what** features top school software ships;
this document covers **how well** ours must behave.*

## Layout regression rules (Loop 17, 2026-10-21 — mandatory for all future edits)

Root cause of the "every page is broken" incident: all portals share
`public/assets/css/qs.css` and the same dashboard components, so two CSS
defect classes broke every page at once. They must never be reintroduced:

1. **Unshrinkable grid tracks.** `grid-template-columns: repeat(N, 1fr)`
   means `minmax(auto, 1fr)` — tracks cannot shrink below their content,
   so cards and hero buttons run off the right edge on laptop widths.
   Always write `repeat(N, minmax(0, 1fr))` and `min-width: 0` on grid
   children.
2. **Forced panel height.** Never set a fixed/`calc(100% - …)` height on
   `.dashboard-panel` for columns that stack multiple panels; stacked
   panels must hug their content (long lists scroll inside the panel).
   Equal-height is only for a column holding a single panel.
3. **No dead controls.** Every visible button/select must work; decorative
   heading icons that look clickable are forbidden.
4. **Fit at 1366px.** Every role home must render hero + stats + first
   panel without horizontal scrolling at 1366px and without screens of
   empty vertical space.

Enforcement: `scripts/qa-smoke.sh` runs before every push. It lints all
PHP, compiles all blades, asserts these CSS guards, and (with `BASE_URL`
set) logs in as every role and checks the homes return 200. A red run
blocks the push.
