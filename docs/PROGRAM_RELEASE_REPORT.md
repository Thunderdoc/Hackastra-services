# Hackstra — Audit Program Release Report

**Date:** 2026-10-23 · **Release:** v1.0.0 · **Tree:** master (rewritten history, 161 commits, single author)
**Live demo:** https://hackastra.onrender.com · **Image:** `ghcr.io/thunderdoc/hackastra-services:latest` (public)

This report closes the 11-phase audit program run against
Hackstra between 2026-10-21 and 2026-10-23: a full-site audit,
a scheduling-engine build-out, targeted defect phases, and a
disciplined break-it mandate (actively attack every module,
record what surfaces, fix it, and prove the fix live).

## What the program shipped

**Engines (new)**
- **Scheduling suite** — teacher eligibility (Slice A), staffing
  demand/load dashboard (B), allocation with a hard eligibility
  rule and audited cap overrides (C), a timetable generator that
  treats periods/week as a hard target with double-period
  support (D), a clash validator reporting per-record (E), and
  exam invigilation auto-assignment with clash/balance rules (F).
  Subjects can now be **vacant** (nullable teacher) and surface
  on the Allocation page as problems to solve.
- **Library fines** — per-day rate from Settings (₹10 default),
  auto-imposed on late return, mirrored into the student's fee
  ledger, settled automatically when the fee record is paid,
  waivable by the librarian.
- **School Readiness** — 12 live checks incl. a fee-ledger
  reconciliation scanner (LedgerCheck) that reconciles the demo
  ledger to the rupee and runs as a standing readiness check.
- **Student portal** — own Fees (statement + policy-guarded
  receipts), own Library (loans + fines), School Events.

**Defects found and fixed (register: docs/PROGRAM_DEFECT_REGISTER.md)**
REG-01 is the only High left open, by owner decision (below).
Fixed: ADM-01 (admission-number collisions), TT-01 (timetable
deletion vs locked attendance), TT-02 (slot copy/delete vs
placed periods, incl. the closing-round cell-timestamp sync),
LED-01 (fee-record reset zeroing balances — broke defaulter
reporting), LED-02 (fee-definition deletion orphaning the
ledger), GEN-01 (single-class regeneration double-booking other
classes' teachers — 10 clashes to 0). Verified clean with live
probes: cross-role data scoping, hash-id tampering, attendance
replay, marks integrity, content-module scoping (CONTENT-01).

**Platform & repo**
Laravel 12.70.1 · 311 routes · PHP 8.4 Docker image published
to GHCR · GitHub Actions CI (migrate + seed + phpunit) · premium
README with light-mode screenshots · Release v1.0.0 · demo with
8 role logins incl. Principal (all `@hackstra.app`).

## Verification evidence

- **Tests:** 25 phpunit tests / 86 assertions, OK — engine
  regressions (ProgramEnginesTest) plus the closing round
  (ProgramFixesTest: slot sync, vacancy, portal scoping).
  Baseline at program start was 14 tests.
- **qa-smoke:** PASS against the seeded app.
- **Live probes:** every fix above was exercised through real
  HTTP flows with per-role session jars (including negative
  tests: foreign records 403, tampered ids 404, role redirects).
- **Ledger anchors hold on a fresh seed:** 99 receipts /
  ₹10,31,300 collected; 11 defaulters / ₹2,79,700 outstanding.

## Known residuals (honest list)

- **PERF-01:** timings are dev-server baselines only; production
  profiling is a post-launch task. Nothing at demo scale is slow.
- **REG-01 (High, owner decision):** public registration renders
  but its POST 500s (stock scaffold never sets `users.code`).
  Close registration, or fix it into pending accounts — owner's
  call, untouched by design.
- **Slice E publication half** (draft/published timetables) is
  designed but unbuilt, held for the owner's nod.
- Production hardening for a paying school (persistent hosting,
  backups, monitoring) is staged: the Render demo reseeds its
  ephemeral database on every deploy by design.

## What remains, and whose move it is

**Owner decisions:** REG-01 · Slice E publication · fee-reminder
channel (SMS/WhatsApp/email) · teacher marksheet printing ·
SaaS decisions D-1…D-6 · Phase 10 break-even inputs (pricing and
costs — never invented) · Railway token/tier (optional; Render
hosts the demo) · two dead-file deletions + favicon upload via
the GitHub web UI.

**Next build wave (backlog, in priority order):** the
ops-extension backlog (admissions pipeline, approvals engine,
exception center, transport, HR leave, inventory, compliance
registers) and Bulk Phase B (xlsx import, staff bulk edit,
rollback). These start on the owner's word.
