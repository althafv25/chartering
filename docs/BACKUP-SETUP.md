# Offshore System: Backup Setup Guide

## Automated Database Backups

The system includes an automated backup script to ensure database recovery capability.

### Location
- Script: `/Applications/ServBay/www/offshore/scripts/backup-database.sh`
- Backups: `/Applications/ServBay/www/offshore/backups/`
- Log: `/Applications/ServBay/www/offshore/backups/backup.log`

### Setup

#### 1. Create Backup Directory
```bash
mkdir -p /Applications/ServBay/www/offshore/backups
chmod 755 /Applications/ServBay/www/offshore/backups
```

#### 2. Install Cron Job

Edit your crontab:
```bash
crontab -e
```

Add this line to run backups daily at 2:00 AM:
```cron
0 2 * * * /Applications/ServBay/www/offshore/scripts/backup-database.sh
```

For every 6 hours:
```cron
0 */6 * * * /Applications/ServBay/www/offshore/scripts/backup-database.sh
```

#### 3. Set Database Password (Optional)

If password is required, set it as an environment variable in crontab:
```cron
DB_PASSWORD=your_mysql_password
0 2 * * * /Applications/ServBay/www/offshore/scripts/backup-database.sh
```

Or store it in `.env.backup`:
```bash
DB_PASSWORD=your_mysql_password
DB_HOST=127.0.0.1
DB_PORT=3306
```

Then source it in cron:
```cron
0 2 * * * source ~/.env.backup && /Applications/ServBay/www/offshore/scripts/backup-database.sh
```

### Configuration

Edit the script to modify:
- `BACKUP_DIR`: Location where backups are stored
- `DB_HOST`, `DB_PORT`, `DB_USER`: MySQL connection details
- `DB_NAME`: Database name to backup (default: "offshore")
- `RETENTION_DAYS`: How many days to keep backups (default: 30)

### Backup Naming Convention

Backups are named: `offshore_YYYYMMDD_HHMMSS.sql.gz`

Example: `offshore_20261004_020000.sql.gz` (Oct 4, 2026 at 2:00 AM)

### Monitoring

View recent backups:
```bash
ls -lh /Applications/ServBay/www/offshore/backups/*.sql.gz | tail -10
```

View backup log:
```bash
tail -100 /Applications/ServBay/www/offshore/backups/backup.log
```

### Restore a Backup

```bash
# List available backups
ls -lh /Applications/ServBay/www/offshore/backups/

# Restore from a backup (creates offshore_restored)
gunzip -c /Applications/ServBay/www/offshore/backups/offshore_20261004_020000.sql.gz | \
  mysql -h 127.0.0.1 -u root -p offshore

# Or restore to a test database first
gunzip -c /Applications/ServBay/www/offshore/backups/offshore_20261004_020000.sql.gz | \
  mysql -h 127.0.0.1 -u root -p offshore_test
```

### Verification

The backup script automatically verifies backup integrity after creation using `gzip -t`.

For manual verification:
```bash
gzip -t /Applications/ServBay/www/offshore/backups/offshore_YYYYMMDD_HHMMSS.sql.gz
echo $?  # 0 = OK, non-zero = corrupted
```

### S3 Upload (Optional)

For off-site backups, add to cron after backup completes:
```bash
aws s3 cp /Applications/ServBay/www/offshore/backups/offshore_*.sql.gz \
  s3://your-bucket/backups/offshore/ --exclude "*" --include "offshore_*.sql.gz"
```

### Maintenance

- **Weekly**: Verify at least one backup can be restored
- **Monthly**: Test restore to staging environment
- **Quarterly**: Verify S3 backups (if configured)
- **Annually**: Review retention policy against compliance requirements

### Troubleshooting

#### Backup fails: "Access denied for user"
- Verify DB_USER has correct permissions: `SHOW GRANTS FOR 'root'@'127.0.0.1';`
- Ensure password is correctly set

#### "No space left on device"
- Check disk space: `df -h /Applications/ServBay/www/offshore/`
- Reduce RETENTION_DAYS or increase disk size

#### Cron job not running
- Verify cron syntax: `crontab -l`
- Check cron logs: `log stream --predicate 'process == "cron"' --level debug`
- Ensure script is executable: `ls -l /Applications/ServBay/www/offshore/scripts/backup-database.sh`
