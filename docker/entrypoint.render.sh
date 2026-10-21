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
php artisan migrate --force || echo "WARNING: migrations failed - check logs."

# Seed the demo only when the users table is empty (fresh container).
USER_COUNT="$(php -r '$pdo=new PDO("mysql:host=127.0.0.1;dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();' 2>/dev/null || echo "0")"
if [ "$USER_COUNT" = "0" ]; then
    echo "Empty database - seeding demo data..."
    php artisan db:seed --force || true
    php artisan db:seed --class=DashboardDemoDataSeeder --force || true
fi

php artisan storage:link || true
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# mod_php needs exactly one MPM (prefork) — guard against AH00534.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
a2enmod mpm_prefork >/dev/null 2>&1 || true

exec "$@"
