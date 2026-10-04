#!/bin/bash
# Database + private document recovery set. Configure through exported environment variables.
set -euo pipefail
umask 077

PROJECT_ROOT="${PROJECT_ROOT:-$(dirname "$(dirname "$(realpath "$0")")")}"
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_ROOT/backups}"
DOCUMENTS_ROOT="${DOCUMENTS_ROOT:-$PROJECT_ROOT/uploads}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-${DB_USERNAME:-root}}"
DB_NAME="${DB_NAME:-${DB_DATABASE:-offshore}}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"

[[ "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ ]] || { echo 'Invalid database name.' >&2; exit 1; }
[[ "$RETENTION_DAYS" =~ ^[0-9]+$ ]] || { echo 'RETENTION_DAYS must be an integer.' >&2; exit 1; }
[[ -d "$DOCUMENTS_ROOT" ]] || { echo "Document directory missing: $DOCUMENTS_ROOT" >&2; exit 1; }
mkdir -p "$BACKUP_DIR"
LOG_FILE="$BACKUP_DIR/backup.log"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

NAME="${DB_NAME}_$(date '+%Y%m%d_%H%M%S')_$$"
STAGING="$BACKUP_DIR/.$NAME.partial"
mkdir "$STAGING"
cleanup() {
    local status=$?
    if [[ $status -ne 0 ]]; then log "ERROR: backup failed (exit $status); no recovery set published."; fi
    if [[ -d "$STAGING" ]]; then rm -rf -- "$STAGING"; fi
}
trap cleanup EXIT

log "Backing up $DB_NAME and private documents"
MYSQL_OPTIONS=("--host=$DB_HOST" "--port=$DB_PORT" "--user=$DB_USER")
if [[ -n "${MYSQL_DEFAULTS_FILE:-}" ]]; then
    MYSQL_OPTIONS=("--defaults-extra-file=$MYSQL_DEFAULTS_FILE" "${MYSQL_OPTIONS[@]}")
fi
if [[ -n "${DB_PASSWORD:-}" ]]; then export MYSQL_PWD="$DB_PASSWORD"; fi
mysqldump "${MYSQL_OPTIONS[@]}" --single-transaction --quick --no-tablespaces "$DB_NAME" | gzip > "$STAGING/database.sql.gz"
tar -czf "$STAGING/documents.tar.gz" -C "$DOCUMENTS_ROOT" .
gzip -t "$STAGING/database.sql.gz"
tar -tzf "$STAGING/documents.tar.gz" > /dev/null

# Publish only complete, verified pairs. Retention never removes a partial set or unrelated file.
mv "$STAGING" "$BACKUP_DIR/$NAME"
log "Verified recovery set: $BACKUP_DIR/$NAME"
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name "${DB_NAME}_[0-9]*" -mtime +"$RETENTION_DAYS" -exec rm -rf -- {} +
log 'Backup completed successfully'
