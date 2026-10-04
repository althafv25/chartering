#!/bin/bash
#
# Offshore System: Automated Database Backup Script
# Purpose: Daily backup of MySQL database with automatic cleanup of old backups
# Usage: Run via cron (e.g., 0 2 * * * /path/to/backup-database.sh)
#

set -e  # Exit on error

# Configuration
BACKUP_DIR="/Applications/ServBay/www/offshore/backups"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_USER="root"
DB_PASSWORD="${DB_PASSWORD:-}"  # Read from environment or empty
DB_NAME="offshore"
RETENTION_DAYS="30"  # Keep backups for 30 days
LOG_FILE="${BACKUP_DIR}/backup.log"

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Log function
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Error handler
error_exit() {
    log "ERROR: $1"
    exit 1
}

log "=== Starting Database Backup ==="

# Generate backup filename with timestamp
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_${TIMESTAMP}.sql.gz"

# Perform backup
log "Backing up database '$DB_NAME' to $BACKUP_FILE"

if [ -n "$DB_PASSWORD" ]; then
    mysqldump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --password="$DB_PASSWORD" \
        --single-transaction \
        --quick \
        --result-file="${BACKUP_FILE%.gz}" \
        "$DB_NAME" || error_exit "mysqldump failed"
else
    mysqldump \
        --host="$DB_HOST" \
        --port="$DB_PORT" \
        --user="$DB_USER" \
        --single-transaction \
        --quick \
        --result-file="${BACKUP_FILE%.gz}" \
        "$DB_NAME" || error_exit "mysqldump failed"
fi

# Compress backup
log "Compressing backup..."
gzip "${BACKUP_FILE%.gz}" || error_exit "gzip compression failed"

# Get backup size
BACKUP_SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
log "Backup completed successfully. Size: $BACKUP_SIZE"

# Cleanup old backups
log "Cleaning up backups older than $RETENTION_DAYS days..."
find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" -mtime +"$RETENTION_DAYS" -delete

# Count backups
BACKUP_COUNT=$(find "$BACKUP_DIR" -name "${DB_NAME}_*.sql.gz" | wc -l)
log "Backups retained: $BACKUP_COUNT"

# Verify backup integrity
log "Verifying backup integrity..."
if gzip -t "$BACKUP_FILE" 2>/dev/null; then
    log "Backup integrity verified: OK"
else
    error_exit "Backup integrity check failed"
fi

log "=== Backup Completed Successfully ==="
