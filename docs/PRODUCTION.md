# Hackstra — Going to Production (a real school)

The default deployment is the **demo**: it seeds a full sample school
(demo users such as `cj@cj.com` / `cj`) so the product can be checked.
A real school must run **without** demo data. This document is the
complete switch-over checklist. Everything here was verified against
the production entrypoint (`docker/entrypoint.sh`) on 2026-10-22.

> The Render free demo (`Dockerfile.render` / `entrypoint.render.sh`)
> is demo-only by design — it rebuilds and re-seeds its bundled
> database on every boot and is **not** a production path.

## 1. Turn demo seeding OFF

`docker/entrypoint.sh` runs migrations on every boot, and seeds **only**
when the host environment sets:

```
SEED_DEMO=true        # demo deployments ONLY
```

For production: **remove `SEED_DEMO`, or set it to anything other than
`true`.** The container then boots migrate-only: schema up to date,
zero users, zero demo records.

Verified behaviour with seeding off (fresh database, migrate only):

- `GET /login` → 200.
- Demo credentials (`cj@cj.com` / `cj`) are rejected — they do not exist.
- `/api/health` → 200 `{"status":"ok","app":"Hackstra"}`.

## 2. Required production environment

Set these in the host's variable panel (Railway / server env), never in
a committed file:

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...            # generate once: php artisan key:generate --show
APP_URL=https://your-domain   # the real public URL
DB_CONNECTION=mysql           # + DB_HOST / DB_PORT / DB_DATABASE /
                              #   DB_USERNAME / DB_PASSWORD for your MySQL
SESSION_DRIVER=cookie         # recommended behind a single-host deploy
APP_TIMEZONE=Asia/Kolkata     # the school's timezone
```

Notes:

- If `APP_KEY` is missing, the entrypoint generates an **ephemeral** one
  for that boot so the site still runs — but sessions/encrypted data
  will not survive a restart. Always set a real `APP_KEY` in production.
- `APP_DEBUG` must be `false`: debug mode exposes stack traces and
  environment details to visitors.

## 3. Real email (SMTP)

Password-reset and notification mail needs a real mailbox. Set:

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@your-school-domain
MAIL_FROM_NAME="Your School Name"
```

Until SMTP is configured, tell staff that "Forgot password" email
cannot be delivered; an Admin can reset any user's password from the
Users screen instead.

## 4. Create the first Super Admin (required, one time)

With seeding off there are **no users at all** — nobody can sign in
until the platform owner is created. Run once, against the production
database:

```bash
php artisan hackstra:create-super-admin \
    --name="Owner Name" --email=owner@school.example --password='a-strong-password'
```

or with environment variables (better for scripts — the password never
appears in the process list):

```bash
HACKSTRA_ADMIN_NAME="Owner Name" \
HACKSTRA_ADMIN_EMAIL=owner@school.example \
HACKSTRA_ADMIN_PASSWORD='a-strong-password' \
php artisan hackstra:create-super-admin
```

Safety properties (by design):

- Password must be at least 8 characters; email must be valid and unused.
- The command **refuses to run if any Super Admin already exists** —
  it is a first-run tool, not a backdoor. Everyone else (school Admin,
  teachers, …) is created from inside the app under **Users** after the
  Super Admin signs in and creates the school Admin.
- Verified end-to-end: empty DB → command → `/login` sign-in → SA home 200.

## 5. First sign-in checklist (inside the app)

1. Sign in as the Super Admin → **Settings**: school/system name,
   current session, logo, contact details.
2. Create the school **Admin** account (Users menu) and hand it over —
   the Admin runs the school day-to-day; the Super Admin stays
   view-only inside the school by design.
3. Change nothing else about demo data — there is none. Classes,
   sections, subjects and students are created (or bulk-imported)
   by the Admin.

## 6. Uptime / health checks

Point any uptime monitor (UptimeRobot, Better Stack, Railway's own
checks, …) at:

```
GET /api/health   →   200 {"status":"ok","app":"Hackstra"}
```

No authentication needed; it reveals nothing sensitive.

## 7. Backups

`scripts/backup-db.sh` dumps the database with rotation (newest 14
kept by default). It reads `DB_*` from the environment or the app
`.env` — **no credentials are stored in the script** (the MySQL
password is passed via `MYSQL_PWD`, so it never shows in `ps`).

```bash
scripts/backup-db.sh                 # → storage/app/backups/ (git-ignored)
scripts/backup-db.sh /mnt/backups 30 # custom dir, keep 30
```

Schedule it daily, e.g. cron on the host:

```
30 2 * * * cd /var/www/html && scripts/backup-db.sh >> /var/log/hackstra-backup.log 2>&1
```

Supports `DB_CONNECTION=mysql` (mysqldump, gzipped) and `sqlite`
(file copy). Copy the output directory off the host regularly — a
backup that lives only on the same disk is not a backup.

## 8. Uploaded files

User photos upload to the `public` disk (`storage/app/public`) and are
served through the `public/storage` symlink. Both Docker entrypoints
run `php artisan storage:link` on every boot, so the symlink is always
in place in container deploys; on a manual server run it once after
deploy. Include `storage/app/public` in your file backups alongside
the database.
