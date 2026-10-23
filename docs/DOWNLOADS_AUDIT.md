# Downloads Audit — every CSV / Excel / PDF / print surface

Owner directive (2026-10-23): every Excel/CSV download must be systematic
and well-titled; where "Download" and "Download PDF" do the same thing,
keep one button or make the other genuinely useful. Method: every
download route probed live against a fresh seed (status, content type,
payload), CSV bodies inspected, and every blade scanned for two buttons
hitting the same route with different promises.

## Verdict summary

- **35 download routes probed: all return their real payload** (200 +
  correct content type; PDFs carry `%PDF` magic). No dead download
  button exists on the seeded paths.
- **One duplication found and fixed** (circulars, below).
- All CSV exports open with a UTF-8 BOM (Excel-safe) and a header row;
  the attendance family additionally carries a full title block
  (school, report title, period, class scope, generated-on, totals).

## CSV / Excel exports

| Export | Shape | Verdict |
|---|---|---|
| Attendance report (`attendance/report/export`) | Title block + Student, Adm No, Class, Section, P/A/L, Marked, %, Status + TOTAL row | Rebuilt 2026-10-23 on the screen's own data assembly |
| Attendance defaulters CSV | Student, Adm No, Class, Section, P/A/L, Marked, %, Status | OK |
| Student attendance CSV | Title block (student, adm no, class, period) + summary row + register | New 2026-10-23 |
| Period sessions CSV (`attendance/periods/export`) | Date, Class, Subject, Time, Teacher, Topic, P/A/L, Students Marked | OK — exports the class's session log; the date parameter selects the log's starting point |
| Session register CSV (`attendance/periods/session/{id}/export`) | Per-student register for one submitted session | OK |
| Students export (class / class+section) | Name, Adm No, Class, Section, Gender, Parent Name/Phone/Email | OK |
| Payments per class (`payments/export/{class}`) | Class payment sheet | OK |
| Student payments (`payments/export_student`) | One student's payment history | OK |
| Collection report CSV | Daily summary rows + Total row (Receipts, Amount, Average) — figures reconcile with the receipts table (99 receipts / ₹10,31,300 on seed) | OK |
| Fee defaulters CSV | The defaulter set, same assembly as the page | OK |
| Bulk templates + job reports | Column templates with an example row; job report = Row / Field / Value / Problem | OK (machine-facing by design; no title block needed) |

## PDF / print documents

| Document | Verdict |
|---|---|
| Fee receipt (web + PDF) | 1 page, Settings branding, watermark 0.05, due row highlighted; amount-in-words = amount received |
| Fee statement / invoice | Student-hash addressed; a wrong id type redirects safely to the dashboard with a danger flash (verified — not a broken link) |
| Collection report PDF | OK |
| Attendance report PDF / print | Same document partial as the screen; range + all-classes aware (2026-10-23) |
| Low-attendance PDF | New 2026-10-23, landscape, from the page's own data |
| Student attendance PDF | New 2026-10-23 |
| Annual report card PDF / print, term marksheet print, tabulation print | OK (verified figures against seed anchors) |
| Admit cards print | OK |
| Exam date sheet PDF / print | OK |
| Circular designed PDF + print | OK |
| Circular official signed PDF | Auto-generated at publish; **canonical** (see fix below) |
| Timetable record print, My Timetable print + PDF | OK |
| Payslips (single + download-all) | OK |
| Assignment / material file downloads | Stored-file downloads, OK |
| My Account data download (JSON) | Personal data export, OK |

## Fix applied — circulars carried two different PDFs

The circular page offered a header "Download PDF" (an on-demand render,
route `circulars.pdf`) **and** an "Official Signed PDF" card (the stored,
signed copy generated at publish, route `circulars.official`). Two PDFs of
one circular can diverge (signature/seal), which is exactly the owner's
complaint pattern. Now: when the official signed copy exists, the header
download points at **that same file**; the on-demand render remains only
for circulars with no official copy yet (unpublished). The card's
View (inline) vs Download (forced) pair is behaviourally distinct and
stays.

## Pattern kept deliberately

Preview / Print / Download PDF trios (attendance report, marks) are
three genuinely different surfaces of one document — preview renders,
print auto-opens the print dialog, PDF downloads a file. They share one
data assembly so they cannot disagree; that is the house standard, not
duplication.
