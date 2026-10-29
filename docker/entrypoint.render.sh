#!/bin/bash
# Hackstra on Render: prepare Laravel, then Apache.
#
# DATABASE MODES (owner order 2026-10-29 — data must survive pushes):
#  * Default (bundled demo): MariaDB runs INSIDE this container and
#    every boot rebuilds + reseeds the demo dataset. The container
#    filesystem is ephemeral on Render, so bundled data can never
#    survive a deploy — that is a platform fact, not an app choice.
#  * Persistent: when the Render dashboard provides a DB_HOST that
#    points at an EXTERNAL MySQL/MariaDB (any hosted free/paid
#    MySQL), the bundled database is skipped entirely. The external
#    database is only MIGRATED (schema upgrades) — never wiped; the
#    demo seed runs once, on the very first boot, when it is empty.
#    School settings, users and records then survive every push.
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

# ---- Database mode selection ----
if [ -n "$DB_HOST" ] && [ "$DB_HOST" != "127.0.0.1" ] && [ "$DB_HOST" != "localhost" ]; then
    PERSISTENT_DB=1
else
    PERSISTENT_DB=0
fi

if [ "$PERSISTENT_DB" = "1" ]; then
    # ---- External database (persistent across deploys) ----
    export DB_CONNECTION="${DB_CONNECTION:-mysql}"
    export DB_PORT="${DB_PORT:-3306}"
    # DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD all come from
    # the Render dashboard env vars — nothing is hardcoded here.

    echo "External database configured (${DB_HOST}) - bundled MariaDB skipped."
    # The reachability checks below run through PHP's PDO — the
    # exact driver + TLS options Laravel itself uses (see
    # config/database.php) — and NOT through the mysql CLI. The
    # image ships the MariaDB client, whose TLS flags differ from
    # MySQL's: it rejects --ssl-mode outright, so the old
    # mysqladmin ping failed on its own flags for 90 seconds and
    # reported a healthy TiDB as "did not answer" (the failed
    # deploys of 2026-10-29 21:53 + 22:01 IST died exactly there,
    # with the real error swallowed by >/dev/null). The last PDO
    # error is now KEPT and printed on failure, so a bad password,
    # a TLS problem and a network problem are distinguishable.
    if [ "${DB_SSL_MODE:-}" = "required" ]; then
        echo "TLS required for the external database - PDO TLS on."
    fi
    echo "Waiting for the external database to accept connections..."
    DB_UP=0
    DB_CHECK_ERR=""
    for i in $(seq 1 45); do
        if DB_CHECK_ERR="$(php -r '
            $opts = [PDO::ATTR_TIMEOUT => 5];
            if (getenv("DB_SSL_MODE") === "required") {
                $opts[PDO::MYSQL_ATTR_SSL_CA] = getenv("DB_SSL_CA") ?: "/etc/ssl/certs/ca-certificates.crt";
            }
            try {
                new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), $opts);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
                exit(1);
            }
        ' 2>&1 >/dev/null)"; then
            echo "External database is up."
            DB_UP=1
            break
        fi
        sleep 2
    done

    if [ "$DB_UP" = "1" ]; then
        TABLE_COUNT="$(php -r '
            $opts = [PDO::ATTR_TIMEOUT => 10];
            if (getenv("DB_SSL_MODE") === "required") {
                $opts[PDO::MYSQL_ATTR_SSL_CA] = getenv("DB_SSL_CA") ?: "/etc/ssl/certs/ca-certificates.crt";
            }
            try {
                $pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"), $opts);
                $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?");
                $st->execute([getenv("DB_DATABASE")]);
                echo $st->fetchColumn();
            } catch (Throwable $e) {
                exit(1);
            }
        ' 2>/dev/null || true)"
        if [ "$TABLE_COUNT" = "0" ]; then
            # First boot against this database: create the schema and
            # seed the demo dataset ONCE. Later boots never reseed.
            echo "External database is empty - first boot: migrating and seeding once."
            # Fail closed (live-schema-parity rule, 2026-10-29): a
            # migration or seed failure must stop the deploy — a
            # half-migrated schema served as "healthy" is how the
            # leave 500 hid for a day. Render keeps the previous
            # deploy live when the new one fails to boot.
            php artisan migrate --force || { echo "FATAL: migrations failed - deploy aborted."; exit 1; }
            php artisan db:seed --force || { echo "FATAL: seeding failed - deploy aborted."; exit 1; }
        elif [ -n "$TABLE_COUNT" ]; then
            # Normal boot: schema upgrades only. Data is preserved.
            echo "External database has ${TABLE_COUNT} tables - migrating only, data preserved."
            php artisan migrate --force || { echo "FATAL: migrations failed - deploy aborted."; exit 1; }
        else
            echo "FATAL: could not inspect the external database - deploy aborted."
            exit 1
        fi
    else
        echo "FATAL: external database did not answer in time - last error: ${DB_CHECK_ERR} - deploy aborted."
        exit 1
    fi
else
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

    # ---- Laravel (bundled demo) ----
    # DEMO DEPLOYMENT: the database is ephemeral by design, so every boot
    # rebuilds it from scratch and seeds the complete demo dataset. This
    # guarantees the checking/demo site always shows full demo data and
    # never a half-seeded state.
    # Fail closed: a halted migration used to seed a partial schema
    # and serve it as healthy (the leave 500, 2026-10-29). Stop the
    # deploy instead — the previous deploy keeps serving.
    php artisan migrate:fresh --force || { echo "FATAL: migrations failed - deploy aborted."; exit 1; }
    echo "Seeding demo data..."
    php artisan db:seed --force || { echo "FATAL: seeding failed - deploy aborted."; exit 1; }
fi

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
