#!/bin/bash
# Hackstra container entrypoint: prepare Laravel, then start Apache.
set -e

cd /var/www/html

# Railway (and most hosts) inject DB settings as env vars. Translate the
# MYSQL* variables Railway's MySQL service provides, when DB_* are unset.
if [ -z "$DB_HOST" ] && [ -n "$MYSQLHOST" ]; then
    export DB_HOST="$MYSQLHOST"
    export DB_PORT="${MYSQLPORT:-3306}"
    export DB_DATABASE="${MYSQLDATABASE:-railway}"
    export DB_USERNAME="${MYSQLUSER:-root}"
    export DB_PASSWORD="${MYSQLPASSWORD:-}"
fi

# Wait for the database to accept connections (max ~60s)
if [ -n "$DB_HOST" ]; then
    echo "Waiting for database at $DB_HOST:${DB_PORT:-3306}..."
    for i in $(seq 1 30); do
        if php -r "exit(@fsockopen(getenv('DB_HOST'), (int)(getenv('DB_PORT') ?: 3306)) ? 0 : 1);" 2>/dev/null; then
            echo "Database is reachable."
            break
        fi
        if [ "$i" = "30" ]; then
            echo "WARNING: database not reachable after 60s — starting anyway; check DB_* env vars."
        fi
        sleep 2
    done
fi

# Run migrations on every boot: idempotent, and this is how new tables
# (school_events, attendances, ...) reach production safely.
php artisan migrate --force || echo "WARNING: migrations failed — check logs and DB credentials."

# Demo data is opt-in: set SEED_DEMO=true in the host's env vars for a
# demo deployment only. Never seed demo accounts on a real school deploy.
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force || true
    php artisan db:seed --class=DashboardDemoDataSeeder --force || true
fi

php artisan storage:link || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

exec "$@"
