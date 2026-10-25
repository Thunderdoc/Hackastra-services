# Backup & Monitoring (Wave 15, 2026-10-25)

## The honest picture

**On the Render demo, there is nothing to back up.** The demo's
bundled MariaDB is ephemeral by design: every deploy runs
`migrate:fresh` + `db:seed` and the database starts empty with demo
data. A backup of demo data would be a backup of seed data — the
seeders already are that backup. This is why no in-app "Download
backup" button was built: on the demo it would hand the Admin a
false sense of safety (and a file containing password hashes),
while the real data still vanishes on the next deploy.

**For a paying school, backups live at the host level, not in the
app.** When the first school signs on (the trigger for leaving
Render free tier), the host must provide:

1. A **persistent** database (not a container-local one) with
   **automated daily snapshots** and a tested restore (a backup
   nobody has restored is a hope, not a backup).
2. A **scheduled dump** (`mysqldump` / `pg_dump`) stored off the
   app server, kept for at least 30 days.
3. **Uploaded files** (`storage/app/public/uploads`) backed up
   alongside the database — a DB restore without the uploads
   folder restores records that point at missing photos, logos
   and attachments.

Until that host exists, the correct advice to any school is: the
demo holds demo data only; real school data goes live only on the
persistent host.

## Monitoring

- **Endpoint:** `GET /health` — public on purpose (an uptime
  monitor cannot log in). It pings the database and returns
  `{"status":"ok","database":"ok"}` (HTTP 200), or
  `{"status":"fail","database":"fail"}` (HTTP 503) when the DB is
  unreachable. It deliberately exposes no versions, counts or
  configuration.
- **Hook it up:** point any uptime monitor (UptimeRobot, Better
  Stack, Render's own health check) at `https://<host>/health`
  and alert on anything but 200. On Render free tier, expect
  the first hit after 15 idle minutes to be slow (cold start,
  ~1 minute) — that is the tier sleeping, not an outage; set
  the monitor's timeout above 60 seconds.
- **What it does NOT cover:** a green `/health` means the app
  boots and the DB answers. It does not mean logins work, mail
  sends, or a deploy seeded correctly — those are covered by
  the deploy verification (fresh login + target page check)
  after every push.

## What was deliberately NOT built

- No in-app database download. Reason: it would export password
  hashes into a portable file, and on the demo it protects
  nothing (see above). If a future host cannot do snapshots,
  the honest replacement is a server-side cron dump to private
  storage, never a browser download.
- No in-app uptime dashboard. The monitor belongs outside the
  thing being monitored.
