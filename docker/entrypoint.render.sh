#!/bin/bash
# Hackstra on Render (free demo): start the bundled MariaDB, prepare
# Laravel (migrate + seed the demo on an empty database), then Apache.
set -e

cd /var/www/html

# Port: make Apache listen on the host-provided port, explicitly.
export PORT="${PORT:-10000}"
sed -i "s|^Listen .*|Listen ${PORT}|" /etc/apache2/ports.conf || true
sed -i -E "s|<VirtualHost \*:[^>]+>|<VirtualHost *:${PORT}>|" /etc/apache2/sites-available/000-default.conf || true

# APP_KEY fallback: without a key, Laravel cannot encrypt cookies and
# every page errors. Generate an ephemeral key when none is provided.
if [ -z "$APP_KEY" ]; then
    export APP_KEY="$(php artisan key:generate --show 2>/dev/null || true)"
    echo "APP_KEY was not set - generated an ephemeral key for this boot."
fi

# Render tells each service its own public URL — use it as APP_URL.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

# Timezone (Batch 6, D18): the demo school is in India — greetings,
# "today" for attendance/periods and all Carbon dates must follow
# Asia/Kolkata, not the container's UTC. An APP_TIMEZONE set in the
# Render dashboard still wins; this is only the default. Exported
# before `config:cache` below so the cached config picks it up.
export APP_TIMEZONE="${APP_TIMEZONE:-Asia/Kolkata}"

# ---- Bundled MariaDB (demo data lives and dies with the container) ----
export DB_CONNECTION=mysql
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_DATABASE="${DB_DATABASE:-hackstra}"
export DB_USERNAME="${DB_USERNAME:-hackstra}"
export DB_PASSWORD="${DB_PASSWORD:-hackstra-demo-pass}"

mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld || true

if [ ! -d /var/lib/mysql/mysql ]; then
    echo "Initialising MariaDB data directory..."
    mariadb-install-db --user=mysql --datadir=/var/lib/mysql --auth-root-authentication-method=normal >/dev/null 2>&1 || \
    mysql_install_db --user=mysql --datadir=/var/lib/mysql >/dev/null 2>&1 || true
fi

echo "Starting bundled MariaDB..."
mysqld_safe --user=mysql >/dev/null 2>&1 &

for i in $(seq 1 30); do
    if mysqladmin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
        echo "MariaDB is up."
        break
    fi
    if [ "$i" = "30" ]; then
        echo "ERROR: MariaDB did not start in time."
        exit 1
    fi
    sleep 2
done

mysql --socket=/run/mysqld/mysqld.sock -uroot <<SQL || true
CREATE DATABASE IF NOT EXISTS \`${DB_DATABASE}\`;
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_DATABASE}\`.* TO '${DB_USERNAME}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

# ---- Laravel ----
# DEMO DEPLOYMENT: the database is ephemeral by design, so every boot
# rebuilds it from scratch and seeds the complete demo dataset. This
# guarantees the checking/demo site always shows full demo data and
# never a half-seeded state.
php artisan migrate:fresh --force || echo "WARNING: migrations failed - check logs."
echo "Seeding demo data..."
php artisan db:seed --force || echo "WARNING: seeding failed - check logs."

php artisan storage:link || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Owner fix 2026-10-25 (feedback attachments 500 on live): the
# seeders above run as root, so any storage directory they create
# (e.g. storage/app/public/uploads for the seeded logo) ends up
# root-owned — then Apache's www-data cannot create subdirectories
# inside it, and uploads into those paths fail. Hand the whole tree
# back to www-data after seeding, before Apache starts.
chown -R www-data:www-data storage bootstrap/cache || true

# mod_php needs exactly one MPM (prefork) — guard against AH00534.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

exec "$@"
