#!/usr/bin/env bash
# Hackstra — database backup (Workstream CR).
#
# Dumps the application database into a timestamped file and keeps
# only the most recent backups (rotation). No credentials are ever
# written into this script: MySQL settings come from the environment
# (or the app .env file, sourced automatically when present).
#
#   scripts/backup-db.sh [output-dir] [keep-count]
#
# Defaults: output-dir = storage/app/backups (already git-ignored),
#           keep-count = 14.
#
# MySQL: uses mysqldump with DB_HOST / DB_PORT / DB_DATABASE /
# DB_USERNAME / DB_PASSWORD from the environment or .env.
# SQLite: copies the file named by DB_DATABASE.
#
# Cron example (daily at 02:30, from the app root):
#   30 2 * * * cd /var/www/html && scripts/backup-db.sh >> /var/log/hackstra-backup.log 2>&1
set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-$APP_ROOT/storage/app/backups}"
KEEP="${2:-14}"
STAMP="$(date +%Y%m%d-%H%M%S)"

# Load DB_* from the app .env when the caller has not exported them.
if [ -f "$APP_ROOT/.env" ]; then
    while IFS='=' read -r key value; do
        case "$key" in
            DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD)
                if [ -z "${!key:-}" ]; then
                    export "$key=$(echo "$value" | tr -d '"' | tr -d "'")"
                fi
                ;;
        esac
    done < "$APP_ROOT/.env"
fi

DB_CONNECTION="${DB_CONNECTION:-mysql}"
mkdir -p "$OUT_DIR"

case "$DB_CONNECTION" in
    mysql)
        : "${DB_DATABASE:?DB_DATABASE is not set (env or .env)}"
        OUT="$OUT_DIR/hackstra-mysql-$STAMP.sql.gz"
        # Password goes through MYSQL_PWD so it never appears in the
        # process list. mysqldump itself reads the other DB_* values.
        MYSQL_PWD="${DB_PASSWORD:-}" mysqldump \
            --host="${DB_HOST:-127.0.0.1}" \
            --port="${DB_PORT:-3306}" \
            --user="${DB_USERNAME:-root}" \
            --single-transaction --skip-lock-tables \
            "$DB_DATABASE" | gzip > "$OUT"
        ;;
    sqlite)
        : "${DB_DATABASE:?DB_DATABASE is not set (env or .env)}"
        [ -f "$DB_DATABASE" ] || { echo "SQLite file not found: $DB_DATABASE" >&2; exit 1; }
        OUT="$OUT_DIR/hackstra-sqlite-$STAMP.sqlite"
        cp "$DB_DATABASE" "$OUT"
        ;;
    *)
        echo "Unsupported DB_CONNECTION: $DB_CONNECTION" >&2
        exit 1
        ;;
esac

# Rotation: newest $KEEP backups of each kind survive.
for kind in mysql sqlite; do
    # shellcheck disable=SC2012
    ls -1t "$OUT_DIR"/hackstra-"$kind"-* 2>/dev/null | tail -n "+$((KEEP + 1))" | while read -r old; do
        rm -f "$old"
    done || true
done

echo "Backup written: $OUT ($(du -h "$OUT" | cut -f1))"
