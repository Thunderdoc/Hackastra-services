# Hackstra — Deployment

The live demo runs on **Render** (free tier) at
https://hackastra.onrender.com. Production runs the same code as a Docker
container against an external MySQL database, on any Docker host.
(Vercel cannot host this app reliably — no official PHP runtime, no MySQL,
no persistent filesystem for uploads, no workers.)

> Retired: the old Railway mirror (`hackastra-services-production.up.railway.app`)
> is gone — its trial ended in November 2026 and the domain is no longer
> provisioned (confirmed 21 Nov 2026: Railway returns its own 404 page).
> `railway.json` was deleted with it. Do not re-add Railway config; Render
> is the demo host and plain Docker is the production path.

## Files that make it work

- `render.yaml` — Render Blueprint: one free web service (`hackastra`),
  Docker runtime, `Dockerfile.render`. Environment variables live in the
  Render dashboard, never in this file (`APP_KEY` is `sync: false`).
- `Dockerfile.render` — PHP 8.4 + Apache demo image with a small MariaDB
  bundled inside the container (Render's free plan has no MySQL).
- `docker/entrypoint.render.sh` — picks the database mode on boot (below),
  runs migrations, caches config/routes/views, starts Apache on `$PORT`.
- `Dockerfile` — production image (PHP 8.4 + Apache, external MySQL).
  The lock file resolves packages that require PHP ≥ 8.4.1 (e.g.
  symfony/css-selector v8) — do not downgrade the image or the build fails
  its platform check.
- `docker/entrypoint.sh` — waits for the database, runs `php artisan migrate`
  on every boot, seeds demo data only when `SEED_DEMO=true`, caches
  config/routes/views, starts Apache on `$PORT`.
- `.github/workflows/package.yml` — publishes the production image to
  GitHub Packages (`ghcr.io/thunderdoc/hackastra-services:latest`) on every
  push to `master`.
- Health checks: `GET /health` (web) and `GET /api/health` → 200
  `{"status":"ok","app":"Hackstra"}` — no login needed.

## Demo on Render (what the live site runs)

### Database modes

`docker/entrypoint.render.sh` chooses on boot:

- **Bundled demo (default):** no external `DB_HOST` is set, so MariaDB runs
  inside the container and every boot rebuilds + reseeds the demo dataset
  (`migrate:fresh` + `db:seed`). Data is **ephemeral by design** — it never
  survives a deploy. This is the mode the public demo runs in.
- **Persistent:** when the Render dashboard provides a `DB_HOST` pointing at
  an external MySQL/MariaDB, the bundled database is skipped entirely. The
  external database is only **migrated** (schema upgrades) — never wiped;
  the demo seed runs once, on the very first boot, when the database is
  empty. School settings, users and records then survive every push.
  TLS is supported via `DB_SSL_MODE=required` (e.g. TiDB Cloud).

### One-time setup (Render dashboard)

1. **New → Web Service → Build and deploy from a Git repository** →
   `Thunderdoc/Hackastra-services` (branch: `master`). Or use the Blueprint:
   **New → Blueprint** and point it at the repo — `render.yaml` defines the
   service.
2. Confirm the service uses `Dockerfile.render` (the Blueprint sets this).
3. In the service's **Environment** tab, set:

   | Variable | Value |
   |---|---|
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_KEY` | generate one (see below) |
   | `SESSION_DRIVER` | `cookie` |

   The Blueprint already sets `APP_ENV`, `APP_DEBUG` and `SESSION_DRIVER`;
   `APP_KEY` must be set in the dashboard (`sync: false` — never commit a
   real key).

   Generate `APP_KEY` locally (on any machine with the repo + PHP):
   `php artisan key:generate --show` — paste the `base64:...` value.
   Or use an online generator you trust; never reuse your local `.env` key.

4. For the persistent mode, also set `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
   `DB_USERNAME`, `DB_PASSWORD` (and `DB_SSL_MODE=required` when the database
   needs TLS).
5. Deploy. Open the service URL → you should land on the Hackstra login
   page. With the bundled demo mode, demo accounts from the readme work —
   **for demo only**.

### Render notes

- Free-tier services **sleep after 15 minutes idle** — the first visit after
  a sleep takes ~30–60 seconds (cold start). That is a platform fact, not
  an app fault.
- Every push to `master` redeploys automatically. Watch the **Logs** tab —
  a failed migration prints there first, and the entrypoint fails the deploy
  closed (the previous deploy keeps serving) rather than serving a
  half-migrated schema.
- `APP_URL` is picked up automatically from Render's `RENDER_EXTERNAL_URL`;
  `APP_TIMEZONE` defaults to `Asia/Kolkata` (the demo school is in India).

## Production (Docker + external MySQL, any host)

### Option A — GHCR image (no build needed)

```bash
docker pull ghcr.io/thunderdoc/hackastra-services:latest
docker run -p 8080:80 \
  -e APP_KEY=<your-app-key> -e APP_ENV=production -e APP_DEBUG=false \
  -e DB_HOST=<mysql-host> -e DB_DATABASE=<db> -e DB_USERNAME=<user> -e DB_PASSWORD=<pass> \
  ghcr.io/thunderdoc/hackastra-services:latest
```

### Option B — build from source

```bash
docker build -t hackstra .
docker run -p 8080:80 \
  -e APP_KEY=<your-app-key> -e APP_ENV=production -e APP_DEBUG=false \
  -e DB_HOST=<mysql-host> -e DB_DATABASE=<db> -e DB_USERNAME=<user> -e DB_PASSWORD=<pass> \
  hackstra
```

Migrations run automatically on boot. Set `SEED_DEMO=true` **only** for
demos — never on a real school deployment; for a real school, create a real
Super Admin instead of using seeded passwords. See
[docs/PRODUCTION.md](PRODUCTION.md) for the full production checklist.

Some hosts inject DB settings as `MYSQL*` variables instead of `DB_*` —
`docker/entrypoint.sh` translates `MYSQLHOST` / `MYSQLPORT` / `MYSQLDATABASE` /
`MYSQLUSER` / `MYSQLPASSWORD` automatically when `DB_*` are unset.

## Uploads note (important)

Container filesystems are **ephemeral**: files uploaded while the app runs
(profile photos, attachments, the school logo) are lost on redeploy unless
the host persists them. On a production Docker host, mount a volume at
`/var/www/html/storage/app/public` before real users upload anything. (On
the Render demo this does not apply — the whole dataset reseeds every boot
by design.)

## Updating

Every push to `master` on GitHub redeploys the Render demo automatically and
publishes a fresh GHCR image. Migrations run on boot, so new tables arrive
with the deploy.

## After it is live

- [ ] Remove `SEED_DEMO` (or set `false`) and rotate every demo password
- [ ] Attach the storage volume (above) on production Docker hosts
- [ ] Confirm `APP_DEBUG=false` (open a bad URL — you must see a plain error page, no stack trace)
- [ ] Open `/api/health` — must return `{"status":"ok","app":"Hackstra"}`
- [ ] Point a custom domain at it later if needed (Render → Settings → Custom Domains)
