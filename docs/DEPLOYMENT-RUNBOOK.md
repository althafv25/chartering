# Offshore deployment runbook

Updated: 2026-10-04. Current repair evidence and remaining product scope are in [SYSTEM-AUDIT-2026-10-04.md](SYSTEM-AUDIT-2026-10-04.md).

## Release checks

The GitHub Actions workflow `.github/workflows/checks.yml` runs the following against committed Composer/npm locks and isolated databases:

```bash
# backend/
composer install --no-interaction --prefer-dist
APP_ENV=testing DB_DATABASE=offshore DB_URL='' vendor/bin/phpunit
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1024M --no-progress
composer audit

# frontend/
npm ci
npm run lint
npm run build
npm test
npm audit
```

`npm run build` includes TypeScript validation; `npm run type-check` runs it separately.

## Deploy

Use the actual release path and HTTPS origins for the target environment. `/Applications/ServBay/www/offshore`, API port `8001`, and frontend port `5173` are local-development examples, not production addresses.

1. Capture a recoverable database **and documents** set using [BACKUP-SETUP.md](BACKUP-SETUP.md). Record the current release revision, matching `APP_KEY`, document root, and recovery-set location.
2. Install the chosen release and its locked dependencies. From its `backend/` directory:

   ```bash
   php artisan down
   composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   php artisan migrate --force
   php artisan db:seed --class=RolesAndPermissionsSeeder --force
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan queue:restart
   ```

   The role seeder restores the shipped built-in role matrix. Preserve deployment-specific overrides separately. Review `INVOICES_REQUIRE_APPROVAL` in the deployment environment; `true` requires approval before invoice issue. Detailed amount thresholds are not implemented.

   Migration `2026_10_04_000000_preserve_released_invoice_revenue_links` preserves historical invoice lines and changes uniqueness to active billing links. It also releases links on existing cancelled/deleted invoices. Once revenue has been re-invoiced, restoring the old lifetime-unique schema is intentionally refused to avoid losing billing history.

3. From `frontend/`, run `npm ci && npm run build`. Publish `dist/` through the production web server. Keep optional platform dependencies enabled: Vite/Rollup use them.
4. Restart/reload PHP workers as appropriate for the deployment, then run `php artisan up` from `backend/`.

## Verify the deployed release

```bash
API_ORIGIN=https://api.example.com
WEB_ORIGIN=https://offshore.example.com
curl --fail --silent --show-error "$API_ORIGIN/up"
curl --fail --silent --show-error "$WEB_ORIGIN/"
```

Laravel's public health endpoint is **`/up`**. `/api/v1/up` requires authentication. Check database connectivity separately through the deployment's normal observability tooling; a health response alone is not a full workflow test.

Use a deployment account to sign in, reload an authenticated page, open a voyage, and verify expected role restrictions. Exercise draft billing and a representative calculation on designated test records. Authentication responses place the token at `data.token`; subsequent requests use `Authorization: Bearer <token>`.

Monitor application/worker logs, failed jobs, response errors, and the scheduled task runner. Confirm backup monitoring and off-site replication in the actual environment.

## Rollback and recovery

- Record the exact prior release; do not infer it from `HEAD~1`.
- Enter maintenance mode and stop writes before recovery.
- Prefer a forward repair when the new schema contains historical billing relationships that the previous schema cannot represent.
- Restore a matched database/document pair into an isolated target first, as described in the backup guide. Validate it before switching the application to that recovered state.
- Redeploy the matching code and lockfiles, rebuild caches, restart workers, and repeat the session/role/workflow smoke checks.
- Never run `migrate:fresh`, PHPUnit, or the E2E preparer against the live database.

## References

- [03 — Modules](03-MODULES.md)
- [04 — Database design](04-DATABASE-ERD.md)
- [06 — API specification](06-API-SPECIFICATION.md)
- [07 — Business rules](07-BUSINESS-RULES.md)
- [12 — Deployment](12-DEPLOYMENT.md)
