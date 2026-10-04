# Offshore Chartering & Vessel Operations: Deployment Runbook

**Version:** 1.0  
**Last Updated:** 2026-10-04  
**Status:** Phase 14 (Testing Hardening)

---

## Table of Contents
1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Deployment Steps](#deployment-steps)
3. [Post-Deployment Verification](#post-deployment-verification)
4. [Rollback Procedures](#rollback-procedures)
5. [Known Issues](#known-issues)
6. [On-Call Playbook](#on-call-playbook)

---

## Pre-Deployment Checklist

### 48 Hours Before
- [ ] Notify stakeholders of deployment window
- [ ] Confirm change advisory board approval
- [ ] Schedule war room (Slack channel: #offshore-deploy)
- [ ] Prepare rollback plan and test it in staging

### 24 Hours Before
- [ ] Run full test suite: `cd backend && php artisan test`
- [ ] Run linters: `./vendor/bin/pint --test && ./vendor/bin/phpstan analyse`
- [ ] Run frontend tests: `cd frontend && npm run test && npm run build`
- [ ] Run E2E tests: `cd frontend && npm run e2e` (Chromium)
- [ ] Backup production database (see BACKUP-SETUP.md)
- [ ] Create deployment branch: `git checkout -b deploy/YYYY-MM-DD`

### 1 Hour Before
- [ ] Verify database backup completed successfully
- [ ] Clear application caches: `php artisan cache:clear`
- [ ] Create maintenance window (if needed): `php artisan down`
- [ ] Have SSH access credentials ready
- [ ] Verify VPN access to production

---

## Deployment Steps

### Phase 1: Pre-Deployment (5 minutes)

1. **Enter maintenance mode** (if downtime acceptable)
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   php artisan down --render=errors::503
   ```

2. **Create a deployment log**
   ```bash
   DEPLOY_LOG="/tmp/offshore-deploy-$(date +%Y%m%d_%H%M%S).log"
   echo "Deployment started at $(date)" > "$DEPLOY_LOG"
   ```

### Phase 2: Backend Deployment (10-15 minutes)

1. **Pull latest code**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   git fetch origin
   git checkout main  # or your deploy branch
   git pull origin main
   ```

2. **Install/update dependencies**
   ```bash
   composer install --no-dev --no-interaction
   ```

3. **Run database migrations**
   ```bash
   php artisan migrate --force
   echo "Migrations complete at $(date)" >> "$DEPLOY_LOG"
   ```

4. **Clear application cache**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan cache:clear
   ```

5. **Warm up caches** (for performance)
   ```bash
   php artisan optimize
   ```

### Phase 3: Frontend Deployment (5-10 minutes)

1. **Build frontend**
   ```bash
   cd /Applications/ServBay/www/offshore/frontend
   npm ci --no-optional
   npm run build
   ```

2. **Verify build output**
   ```bash
   ls -lh dist/
   # Should see main.[hash].js and other bundled files
   ```

### Phase 4: Post-Deployment (5 minutes)

1. **Exit maintenance mode**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   php artisan up
   ```

2. **Health check**
   ```bash
   curl -s http://localhost:8001/api/v1/up | head -20
   curl -s http://localhost:5173 | head -20
   ```

3. **Log deployment completion**
   ```bash
   echo "Deployment completed successfully at $(date)" >> "$DEPLOY_LOG"
   ```

### Total Estimated Time: 25-40 minutes

---

## Post-Deployment Verification

### Immediate (First 5 minutes)

1. **API health checks**
   ```bash
   # Health endpoint
   curl -s http://localhost:8001/api/v1/up
   
   # Login test
   curl -X POST http://localhost:8001/api/v1/auth/login \
     -H "Content-Type: application/json" \
     -d '{"email":"admin@offshore.local","password":"Admin@12345"}'
   ```

2. **Frontend loading**
   - Open http://localhost:5173 in browser
   - Verify login page renders
   - Clear browser cache (Cmd+Shift+R)

3. **Database connectivity**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   php artisan tinker --execute="echo \Illuminate\Support\Facades\DB::connection()->getPDO() ? 'DB OK' : 'DB FAIL';"
   ```

### Short-term (Within 1 hour)

1. **Smoke tests**
   - [ ] Login as admin
   - [ ] Navigate to Dashboard
   - [ ] View a voyage
   - [ ] View an invoice
   - [ ] Check statistics page loads

2. **Monitor logs**
   ```bash
   tail -50 /Applications/ServBay/www/offshore/backend/storage/logs/laravel-*.log
   tail -50 /Applications/ServBay/www/offshore/frontend/logs/*.log (if applicable)
   ```

3. **Performance baseline**
   - Check page load times are within 500ms
   - Monitor CPU/memory usage
   - Verify no n+1 queries in logs

### Long-term (Throughout the day)

1. **Monitor critical flows**
   - [ ] Create and issue an invoice
   - [ ] Update a voyage
   - [ ] Generate a report
   - [ ] Create a contract

2. **Check for errors**
   ```bash
   # Look for exceptions
   grep -i "exception\|error" /Applications/ServBay/www/offshore/backend/storage/logs/laravel-*.log | head -20
   ```

3. **Performance monitoring**
   - Average response time: < 500ms
   - Error rate: < 0.1%
   - Database query time: < 200ms per request

---

## Rollback Procedures

### Scenario 1: Critical Bug Found (Immediate Rollback)

1. **Enter maintenance mode**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   php artisan down --render=errors::503
   ```

2. **Restore database** (if migrations failed)
   ```bash
   # From latest backup
   gunzip -c /Applications/ServBay/www/offshore/backups/offshore_YYYYMMDD_HHMMSS.sql.gz | \
     mysql -h 127.0.0.1 -u root -p offshore
   ```

3. **Revert code to previous commit**
   ```bash
   cd /Applications/ServBay/www/offshore
   git checkout HEAD~1 -- backend/
   git checkout HEAD~1 -- frontend/
   ```

4. **Redeploy using previous steps**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   composer install --no-dev --no-interaction
   php artisan migrate --force
   php artisan up
   ```

5. **Post-rollback verification**
   - [ ] API responds to requests
   - [ ] Login works
   - [ ] Dashboard loads
   - [ ] Critical business flows work

### Scenario 2: Partial Rollback (Backend only)

1. **If frontend is fine**
   ```bash
   cd /Applications/ServBay/www/offshore/backend
   git checkout HEAD~1
   composer install --no-dev
   php artisan migrate:rollback --step=1
   php artisan cache:clear
   ```

2. **Verify and exit maintenance**
   ```bash
   php artisan up
   ```

### Scenario 3: Slow Rollback (Planned, after investigation)

1. **Create feature branch for bugfix**
   ```bash
   git checkout -b hotfix/issue-name
   # Make fixes
   git commit -m "Fix: [issue description]"
   ```

2. **Tag the bad release for investigation**
   ```bash
   git tag -a bad/2026-10-04-v1 -m "Rolled back due to [reason]"
   ```

3. **Deploy corrected version**
   - Run through full deployment steps
   - Run full test suite
   - Deploy to staging first

### Rollback Checklist

After any rollback:
- [ ] Database integrity verified (SHOW TABLE STATUS)
- [ ] All tables present and accessible
- [ ] User sessions still valid
- [ ] No data loss (compare row counts with backup)
- [ ] Audit logs show rollback event
- [ ] Stakeholders notified

---

## Known Issues

### Issue #1: Database Migration Timeout
**Symptoms:** Migration hangs after 30 seconds  
**Cause:** Large data migration or table lock  
**Fix:**
```bash
# Check table locks
SHOW OPEN TABLES WHERE in_use > 0;
# Kill blocking query if needed
KILL QUERY process_id;
# Restart migration with increased timeout
php artisan migrate --force --step=1
```

### Issue #2: Cache Coherency After Deployment
**Symptoms:** Old config values displayed despite cache clear  
**Cause:** Op-cache not cleared  
**Fix:**
```bash
# Restart PHP-FPM
brew services restart php@8.2
# Or if using Laravel Sail: sail artisan cache:clear
```

### Issue #3: Frontend Build Fails
**Symptoms:** `npm run build` exits with code 1  
**Cause:** Missing dependency or TypeScript error  
**Fix:**
```bash
cd /Applications/ServBay/www/offshore/frontend
npm ci --force  # Clean install
npm run build
# If still fails, check for TS errors
npm run type-check
```

### Issue #4: Permission Errors After Migration
**Symptoms:** "You do not have permission to perform this action"  
**Cause:** Seeder didn't run or role cache stale  
**Fix:**
```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan cache:clear
# Verify user has role assigned
php artisan tinker
# In tinker: User::find(1)->roles;
```

---

## On-Call Playbook

### Emergency Response (during/after deployment)

**Time: T+0 to T+5 minutes**
- [ ] Alert received - acknowledge in #offshore-deploy
- [ ] Determine severity: Critical / High / Medium / Low
- [ ] Identify affected component (backend / frontend / database)
- [ ] Collect error logs and screenshots

**Time: T+5 to T+15 minutes**
- [ ] Run health check API
- [ ] Check database connectivity
- [ ] Review recent deployment log
- [ ] Check error rate spike in logs
- [ ] Determine if rollback needed

**Time: T+15+ minutes**
- If **minor issue**: Create hotfix branch, fix, test, deploy
- If **critical issue**: Initiate rollback (see Rollback Procedures)
- If **unclear**: Stand up war room call with engineering lead

### On-Call Contacts

- **Primary Engineer:** TBD
- **Backup Engineer:** TBD
- **Product Owner:** TBD
- **Database Admin:** TBD

### Escalation Path

1. Page on-call engineer
2. Alert engineering lead if no response in 5 minutes
3. Alert product owner if revenue-impacting
4. Alert exec on-call if P1 severity

### Communication Template

**For Slack #offshore-deploy channel:**
```
🚨 DEPLOYMENT INCIDENT - [TIME]
Severity: [CRITICAL|HIGH|MEDIUM]
Component: [Backend|Frontend|Database]
Status: [INVESTIGATING|MITIGATING|RESOLVED]
Impact: [Description]
ETA: [Estimated time to resolution]
```

---

## Performance Benchmarks (Post-Deployment)

### Expected Metrics

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Page Load Time | < 500ms | > 1000ms |
| API Response (avg) | < 200ms | > 500ms |
| Database Query | < 50ms | > 100ms |
| Error Rate | < 0.1% | > 1% |
| CPU Usage | < 60% | > 80% |
| Memory Usage | < 70% | > 85% |

### Monitoring Tools

```bash
# Watch real-time metrics
watch -n 2 'free -h; echo "---"; ps aux | grep php'

# Monitor error log growth
watch -n 5 'wc -l /Applications/ServBay/www/offshore/backend/storage/logs/laravel-*.log'

# Monitor database
mysql -h 127.0.0.1 -u root -p -e "SHOW STATUS LIKE 'Threads%';"
```

---

## Documentation References

- **Architecture:** `docs/03-ARCHITECTURE.md`
- **Database Schema:** `docs/04-DATABASE-SCHEMA.md`
- **API Documentation:** `docs/06-API.md`
- **Deployment:** `docs/12-DEPLOYMENT.md`
- **Backup Procedures:** `docs/BACKUP-SETUP.md`
- **Business Rules:** `docs/07-BUSINESS-RULES.md`

---

**Last Updated:** 2026-10-04  
**Next Review:** 2026-10-11  
**Owner:** DevOps Team
