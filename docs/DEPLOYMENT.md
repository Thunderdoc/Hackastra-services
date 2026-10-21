# Hackstra — Live Deployment on Railway

Railway is the chosen host: it runs a real long-running container plus a
managed MySQL service in the same project and deploys from GitHub.
(Vercel cannot host this app reliably — no official PHP runtime, no MySQL,
no persistent filesystem for uploads, no workers; see the audit discussion.)

**Cost:** new Railway accounts get a 30-day trial with a one-time $5 credit,
no card needed. After that, Hobby is $5/month including $5 of usage — enough
for a demo / small school deployment of this app.

## Files that make it work

- `Dockerfile` — PHP 8.4 + Apache (the lock file resolves packages that
  require PHP ≥ 8.4.1, e.g. symfony/css-selector v8 — do not downgrade the
  image or the build fails its platform check), all extensions the app
  needs, Composer production install, document root set to `public/`
- `docker/entrypoint.sh` — waits for the database, runs `php artisan migrate`
  on every boot, caches config/routes/views, starts Apache on `$PORT`
- `railway.json` — tells Railway to build the Dockerfile and health-check `/health`
- `/health` route — returns `{"status":"ok"}` for Railway's health check

## One-time setup (about 10 minutes, all clicks in Railway)

1. Go to **railway.com** → sign in with GitHub.
2. **New Project → Deploy from GitHub repo → `Thunderdoc/Hackastra-services`**
   (branch: `master`). Railway detects `railway.json` + `Dockerfile`.
3. In the same project: **New → Database → Add MySQL**. Railway creates a
   MySQL service with `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`,
   `MYSQLPASSWORD` variables automatically.
4. Click the **Hackastra app service → Variables** and add:

   | Variable | Value |
   |---|---|
   | `APP_NAME` | `Hackstra` |
   | `APP_ENV` | `production` |
   | `APP_DEBUG` | `false` |
   | `APP_KEY` | generate one (see below) |
   | `DB_CONNECTION` | `mysql` |
   | `SESSION_DRIVER` | `cookie` |
   | `SESSION_SECURE_COOKIE` | `true` |
   | `LOG_CHANNEL` | `stderr` |
   | `SEED_DEMO` | `true` for a demo deploy, remove later |

   The entrypoint translates Railway's `MYSQL*` variables into Laravel's
   `DB_*` automatically — you do **not** need to copy the DB password by hand.

   Generate `APP_KEY` locally (on any machine with the repo + PHP):
   `php artisan key:generate --show` — paste the `base64:...` value.
   Or use an online generator you trust; never reuse your local `.env` key.

5. Still in the app service: **Settings → Networking → Generate Domain**.
   Copy the `https://....up.railway.app` URL, then add one more variable:
   `APP_URL` = that URL, and redeploy (Deployments → Redeploy).
6. Open the URL → you should land on the new Hackstra login page.
   With `SEED_DEMO=true`, demo accounts from the readme work — **for demo
   only**. For a real school: remove `SEED_DEMO`, redeploy, and create a real
   Super Admin instead of using seeded passwords.

## Uploads note (important)

Railway containers have an **ephemeral filesystem**: files uploaded while the
app runs (profile photos etc.) are lost on redeploy unless you attach a
**Volume** to the app service mounted at `/var/www/html/storage/app/public`.
Add a volume before real users upload anything.

## Updating

Every push to `master` on GitHub redeploys automatically. Migrations run on
boot, so new tables arrive with the deploy. Watch the **Deploy Logs** tab in
Railway — a failed migration prints there first.

## After it is live

- [ ] Remove `SEED_DEMO` (or set `false`) and rotate every demo password
- [ ] Attach the storage volume (above)
- [ ] Confirm `APP_DEBUG=false` (open a bad URL — you must see a plain error page, no stack trace)
- [ ] Open `/health` — must return `{"status":"ok"}`
- [ ] Point a custom domain at it later if needed (Railway → Settings → Networking)
