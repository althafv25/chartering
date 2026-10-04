# Database and private-document backups

Run `bash scripts/backup-database.sh` with the application's database connection and document-root settings exported. The script does not source Laravel's `.env` automatically.

Each successful run publishes one private directory containing **both** `database.sql.gz` and `documents.tar.gz`. It verifies both archives before publishing; a failed dump or document archive produces no completed recovery set and does not remove previous backups.

## Configuration

| Environment variable | Default |
|---|---|
| `PROJECT_ROOT` | Repository containing the script |
| `BACKUP_DIR` | `$PROJECT_ROOT/backups` |
| `DOCUMENTS_ROOT` | `$PROJECT_ROOT/uploads`, matching the default Laravel documents disk |
| `DB_HOST`, `DB_PORT` | `127.0.0.1`, `3306` |
| `DB_USER` / `DB_USERNAME` | `root` |
| `DB_NAME` / `DB_DATABASE` | `offshore` |
| `DB_PASSWORD` | Client default if unset; passed through the process environment when set |
| `MYSQL_DEFAULTS_FILE` | Optional private MySQL client option file for credentials |
| `RETENTION_DAYS` | `30` |

Use absolute paths for cron. For a remote/object-storage documents disk, use its provider's versioned backup and recovery procedure; this script archives a local document directory. Keep `APP_KEY` and deployment secrets in your secret manager so restored encrypted application data remains readable.

Example protected configuration file `/etc/offshore/backup.env`:

```bash
PROJECT_ROOT=/srv/offshore
BACKUP_DIR=/srv/backups/offshore
DOCUMENTS_ROOT=/srv/offshore/uploads
DB_DATABASE=offshore
DB_HOST=127.0.0.1
DB_USERNAME=offshore_backup
MYSQL_DEFAULTS_FILE=/etc/offshore/mysql-backup.cnf
```

Give configuration and credentials files mode `600`. Install a cron entry that exports the settings:

```cron
0 2 * * * /bin/bash -c 'set -a; . /etc/offshore/backup.env; set +a; exec bash /srv/offshore/scripts/backup-database.sh'
```

Monitor exit status and `$BACKUP_DIR/backup.log`. Copy completed directories to monitored off-site storage. Local retention applies only to completed directories for the selected database.

## Restore rehearsal

Choose one completed set. The target database must be new and the document destination empty. These commands create **`offshore_restored`**, not the live database:

```bash
BACKUP_SET=/srv/backups/offshore/offshore_YYYYMMDD_HHMMSS_PID
RESTORE_DOCUMENTS=/srv/restore/offshore-documents
gzip -t "$BACKUP_SET/database.sql.gz"
tar -tzf "$BACKUP_SET/documents.tar.gz"
mysql -h 127.0.0.1 -u root -p -e 'CREATE DATABASE offshore_restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
gunzip -c "$BACKUP_SET/database.sql.gz" | mysql -h 127.0.0.1 -u root -p offshore_restored
mkdir -p "$RESTORE_DOCUMENTS"
tar -xzf "$BACKUP_SET/documents.tar.gz" -C "$RESTORE_DOCUMENTS"
```

Point an isolated application instance at `DB_DATABASE=offshore_restored` and `DOCUMENTS_ROOT=$RESTORE_DOCUMENTS`, using the matching application key. Check representative records, authenticated document downloads, and stored document hashes. Record the rehearsal outcome and recovery duration.

For a consistent database/document cutoff, pause document-writing requests and workers while capturing the pair. MySQL's `--single-transaction` provides a consistent InnoDB database snapshot; it is not a cross-filesystem transaction.

The script's regression checks use temporary fixtures, including a deliberately failed dump:

```bash
bash -n scripts/backup-database.sh
python3 scripts/test-backup.py
```

Archive checks and these regression tests do not substitute for a production restore rehearsal.
