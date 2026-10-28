# Backup & Restore Runbook (Hackstra Wave 2, feature 8 of 8)

Companion to `docs/BACKUP_AND_MONITORING.md` (Wave 15), which states the
honest picture. This runbook is the operator's version: what is backed
up where on each host tier, the restore steps in order, what to verify
after a restore, and what automation is NOT possible on free Render.

## The one rule that does not change

There is deliberately **no in-app raw database download** — no dump
route, no database-file download, under any name. One database holds
all school data, so a browser download would hand out a portable file
of password hashes, and on the demo it would protect nothing (see
below). If a future host cannot do snapshots, the honest replacement
is a server-side scheduled dump to private storage, never a browser
download.

The admin-only Backup & Monitoring page (`backup.index`, teamSA) shows
only safe checks — database connectivity (ok/fail, no details), storage
writability per configured disk (disk names only, never credentials or
paths), the public `/health` status, and a "last verified" timestamp
the Admin refreshes. It contains no secrets and no config values.

## What is backed up, where — by host tier

### Free Render demo (current tier)

- **Database: nothing to back up.** The bundled MariaDB is ephemeral
  by design: every deploy runs `migrate:fresh` + `db:seed` and the
  database starts empty with demo data. The seeders ARE the backup of
  demo data — restoring the demo means redeploying, not restoring a
  file.
- **Uploaded files: nothing durable.** The container filesystem is
  ephemeral, so files written to local storage do not survive a
  deploy either.
- **What to tell a school, plainly:** the demo holds demo data only.
  Real school data must not go live on this tier.

### Paying school host (the trigger: the first paying school signs on)

This is the tier where backups actually exist, and they live at the
**host level, not in the app**:

1. A **persistent** database (not a container-local one) with
   **automated daily snapshots** and a tested restore. A backup
   nobody has restored is a hope, not a backup.
2. A **scheduled dump** (`mysqldump` / `pg_dump`, whichever matches
   the host database) stored **off the app server**, kept for at
   least 30 days.
3. **Uploaded files** (`storage/app/public/uploads`) backed up
   alongside the database. A database restore without the uploads
   folder restores records that point at missing photos, logos and
   attachments — restore both together, always.

## Restore steps, in order

1. **Stop writes.** Put the app in maintenance mode (or stop the
   app service) so nothing writes while the restore runs.
2. **Restore the database** from the host snapshot or the latest
   off-server dump, onto the persistent database.
3. **Restore the uploads folder** from the same point in time as
   the database backup. Do not mix a new database with old uploads,
   or old uploads with a new database.
4. **Bring the app back up** and clear the application cache.
5. **Run the verification checklist below.** Do not hand the system
   back to users until it passes.
6. **Record it:** note the restore date, which backup was used, and
   the verification result, and tell the school Admin it happened.

## What to verify after a restore

- `GET /health` returns 200 with database ok.
- An Admin can sign in, and the dashboard loads.
- Spot-check the areas that matter most: a student record, a fee
  record, and an uploaded file (photo, logo or attachment) actually
  opens — a missing uploads folder shows up here, not in the
  database check.
- The school name, session and other settings read correctly.
- The Backup & Monitoring page shows database OK and the storage
  disks writable, and the Admin refreshes the "last verified"
  timestamp so the record reflects the post-restore state.

## Monitoring (already live, Wave 15)

- **Endpoint:** `GET /health` — public on purpose (an uptime monitor
  cannot log in). It pings the database and returns ok (HTTP 200) or
  fail (HTTP 503). It exposes no versions, counts or configuration.
- **Hook it up:** point any uptime monitor (UptimeRobot, Better
  Stack, Render's own health check) at `https://<host>/health` and
  alert on anything but 200. Set the monitor timeout above 60
  seconds: on Render free tier the first hit after 15 idle minutes
  is slow (cold start, about a minute) — that is the tier sleeping,
  not an outage.
- **What a green /health does NOT mean:** only that the app boots
  and the database answers. It does not mean logins work, mail
  sends, or a deploy seeded correctly — those are covered by the
  deploy verification (fresh login + target page check) after every
  push.

## What automation is NOT possible on free Render

Plainly, on the free tier:

- **No scheduled backups.** There is no cron worker and no
  always-on process; the service sleeps after 15 minutes idle, and
  the filesystem is ephemeral, so anything a job wrote would vanish
  on the next deploy anyway.
- **No durable stored export.** Anything written to local storage
  (including an encrypted export file) disappears on deploy. An
  export that vanishes is worse than no export, because it looks
  like protection.
- **No in-app database download**, for the reasons at the top of
  this runbook.

## Why no encrypted config export was built in this feature

An encrypted export of non-sensitive configuration was considered
and **skipped, deliberately**:

- The settings table itself holds sensitive keys (mail password and
  AI API key fields exist there, even when empty in the demo). A
  denylist would have to stay perfect forever; one newly added
  sensitive key, missed by the list, would leak into the export.
- The only remote disk in `config/filesystems.php` (s3) is not
  provisioned with verified credentials in this app, and the local
  disks are ephemeral on free Render — so there is no already-
  configured, durable place for an export to live.
- On the demo tier the configuration is seed data, so an export
  would protect nothing while adding a new download surface.

The page + this runbook alone are the complete delivery for this
feature. If a paying host later provides durable private storage,
revisit an allowlisted (never denylisted) config export then — with
a fresh safety review, not by reusing this reasoning.

## Paid-host trigger, restated

When the first paying school signs on, leaving the Render free tier
is the trigger for: a persistent database, automated daily host
snapshots, a scheduled off-server dump kept 30+ days, uploads backed
up alongside the database, and a tested restore before real data
goes live. Until that host exists, the correct advice to any school
remains: the demo holds demo data only.
