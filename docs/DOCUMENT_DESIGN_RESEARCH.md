# Document Design Research — Report Card / Admit Card / ID Card (Workstream CY)

Owner directive (2026-10-22): the current PDF documents are not premium
enough. Documents must carry the school logo as a background watermark,
proper indexing, no stray spacing, everything aligned — "research how a
real report card looks." This note records what the winners do; the
Loop 68 design system in `public/assets/css/qs.css` plus the upgraded
document partials implement it.

Sources studied: CBSE CCE report-card format and grading system
(icbse.com CCE guidelines; CBSE 9-point scholastic scale A1–E2), a real
Indian private-school report card (spdl.nascorptechnologies.com sample:
component columns, grade legend A 85–100 …, attendance line, remarks +
signature rows, promotion note), OpenEduCat's CBSE marksheet section
map (Class IX–X vs XI–XII), DAV/Silver Hills public-school evaluation
pages (S.No-indexed domain tables, point grading scale table), board
hall-ticket/admit-card layouts, and CR80 PVC school ID card designs.

## Anatomy — Report Card (what every credible card has)

1. **Header band**: school logo at one side, school name large and
   centred, address on the next line, phone/email line under it; an
   affiliation/board line where applicable. Student photo at the
   opposite side, boxed.
2. **Document title band**: a ruled/banded strip — "REPORT SHEET" +
   exam name, term, session — visually separated from the header.
3. **Student identity grid**: Name / Admission No / Class / Section /
   Academic Year (+ House, Age, DOB where kept) in a strict multi-
   column grid with fixed column widths — labels never wrap, values
   never drift.
4. **Scholastic table**: **S.No index column first**, then Subject,
   the assessment-component columns (Theory / Internal / Viva / CA /
   Exam — whatever the school's marking scheme defines), Total (100),
   Grade, Subject Position, Remarks. Thin ruled borders, header row
   shaded, numbers centred, subject left.
5. **Summary strip**: Total obtained, Final Average, Class Average,
   Position — one aligned row directly under the table.
6. **Grade-scale legend** ("KEY TO THE GRADING"): every band on one
   disciplined line/strip, ranges unbroken (A — 91–100 …), never
   wrapping mid-range; outcome counts (Distinctions / Credits /
   Passes / Failures / Subjects Offered) in the same strip family.
7. **Co-scholastic annex** where used: traits tables in fixed two-
   column layout with their rating KEY as a legend line.
8. **Remarks block**: Class Teacher's comment, Principal's comment,
   next-term information — label column fixed width so labels sit on
   one line.
9. **Attendance summary** (present on CBSE-style cards) — NOTE for
   Hackstra: the ReportCard assembly carries no attendance source, so
   v2 does NOT invent one; the slot is documented here and stays out
   until attendance is wired into the assembly honestly.
10. **Signature row**: Class Teacher and Principal, ruled lines with
    printed names beneath; school stamp only when a real stamp image
    is uploaded (never a drawn placeholder — owner decision D4).
11. **Watermark**: the school crest/logo large, centred, very light
    (≈8–12% opacity) behind the body of the document — the single
    strongest "premium/official" signal on real cards, and an anti-
    forgery cue. On letterhead-style cards it sits behind the marks
    table, never competing with the figures.

## Anatomy — Admit Card / Hall Ticket

- Header: logo + school name + contacts; photo box at the right.
- Title band: "ADMIT CARD" letterspaced, exam name, term/session line.
- Identity fields grid (Name, Admission No, Class, Section) in label/
  value columns with fixed label width.
- **Subject schedule table with S/N indexing**, Date, Time, and an
  Invigilator's Sign column with real row height for handwriting.
- Instructions list (numbered, compact).
- Signature row: Class Teacher / Principal + a SCHOOL SEAL box.
- Print behaviour: cards must NEVER be content-clipped. (Baseline
  audit found the current sheet clips the instructions + signatures
  off every card via a fixed-height overflow rule — a defect v2
  removes: cards size to content, `break-inside: avoid`.)

## Anatomy — Student ID Card (CR80, 85.6 × 54 mm)

- Header band in the school's accent colour: logo (or monogram tile
  when no logo is uploaded) + school name + "STUDENT IDENTITY CARD"
  micro-label.
- Photo left (portrait, ~22 × 28 mm on paper), name prominent, then
  label/value rows (Class · Section, Adm No, DOB when set).
- Footer band: "Valid for session YYYY-YYYY".
- A faint watermark monogram behind the body is the premium touch
  real PVC cards get from their background artwork.

## The v2 system (Loop 68)

One token family across all four documents: accent navy `#10263b`
(the app/Loop 31 navy), hairline `#d7e0ec`, label grey `#718096`,
shaded head `#eef2f7`; `print-color-adjust: exact` on every document
surface; watermark = uploaded logo (≤12% opacity) or, when no logo
exists, a large acronym monogram from the settings app code at ~6%
opacity, rotated −18°, centred behind the content — so every school
looks official on day one and the watermark becomes their real logo
the moment they upload one. All identity strings read from Settings;
nothing is hardcoded to a school.
