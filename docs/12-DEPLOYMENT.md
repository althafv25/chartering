# 12 — Deployment

Status: DRAFT v0.1. Mirrors the Crew Management production layout (`crew-management/docs/PRODUCTION_DEPLOYMENT.md`), with storage and security fixes.

## 1. Local development

| Item | Value |
|---|---|
| Web server | ServBay (macOS), project at `/Applications/ServBay/www/offshore` |
| Database | MySQL **5.7.44** (ServBay), database **`offshore`** already created by the user. Local credentials: user `root`, password `root` (**local only — never commit, never use in staging/production**) |
| Test database | `offshore_test` (created; used by `php artisan test`) |
| Backend | `cd backend && php artisan serve` (port 8001 to avoid clashing with CM on 8000) |
| Frontend | `cd frontend && npm run dev` → http://localhost:5173 (Vite proxies `/api` → `VITE_API_TARGET`, default `http://localhost:8001`) |
| First admin (dev only) | `admin@offshore.local` / `Admin@12345` — created by `AdminUserSeeder`; in production set `ADMIN_EMAIL` / `ADMIN_PASSWORD` (seeder refuses to run without them) |
| Documents | `uploads/` (project root) = private `documents` disk (`DOCUMENTS_ROOT` overrides). Never alias it in Nginx |
| Queue / scheduler | `php artisan queue:listen` / `php artisan schedule:work` |

`backend/.env` (created in Phase 2, git-ignored; `backend/.env.example` is the template):
```
APP_NAME="Offshore Chartering"
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=offshore
DB_USERNAME=root
DB_PASSWORD=root
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
APP_URL=http://localhost:8001
FRONTEND_URL=http://localhost:5173      # used in password-reset links
DOCUMENTS_DISK=documents
DOCUMENTS_MAX_KB=51200
SANCTUM_TOKEN_EXPIRATION=720           # minutes
BASE_CURRENCY=USD
```

`frontend/.env` (template `frontend/.env.example`, same idea as Crew Management):
```
VITE_API_URL=/api/v1                 # production: https://<domain>/api/v1
VITE_API_TARGET=http://localhost:8001  # dev proxy target (may point to staging)
VITE_BASE_URL=/
VITE_APP_NAME="Offshore Chartering"
```

## 2. Production (Ubuntu 22.04/24.04, Nginx, PHP-FPM 8.2+, MySQL 8)

```
/var/www/<domain>/
  backend/            Laravel (storage/, bootstrap/cache writable by www-data)
  frontend/           built dist/ only
  uploads/            private documents (DOCUMENTS_ROOT) — NOT served by Nginx
```

- Nginx: `/` → `frontend` (SPA fallback to `index.html`); `/api/` → `backend/public/index.php`. **No `/uploads` alias.** Security headers as in CM, plus a CSP. `client_max_body_size 50M`.
- Frontend env at build time: `VITE_API_URL=https://<domain>/api/v1`. No secrets in `VITE_*`.
- Nginx must `deny all` for any path resolving into `uploads/` if the project root is ever exposed.
- Supervisor: `php artisan queue:work --queue=default,notifications,ais,exports --tries=3 --max-time=3600`, plus `queue:restart` after each deploy.
- Cron: `* * * * * php artisan schedule:run`.
- Deploy steps: `composer install --no-dev -o`, `php artisan migrate --force` (after a backup), `config:cache route:cache view:cache event:cache`, `queue:restart`.
- Backups: nightly `mysqldump` + document storage sync, 30-day retention, restore tested quarterly.
- Logs: `daily` channel, 14 days; `request_id` in context.

## 3. AWS (when hosted on AWS)

| Concern | Choice |
|---|---|
| Compute | EC2 (same Nginx/PHP-FPM layout), or later ECS |
| DB | RDS MySQL 8, Multi-AZ for production, automated backups |
| Documents | S3 **private** bucket, Block Public Access on, SSE-S3/KMS; downloads via 5-minute pre-signed URLs issued after the policy check; IAM role on the instance (no static keys) |
| Cache/queue | database drivers (CM parity); ElastiCache Redis optional |
| Mail | SES or existing SMTP |
| Secrets | `.env` populated from SSM Parameter Store / Secrets Manager at deploy |
| TLS | ACM on ALB or Let's Encrypt on instance |

## 4. Environments

local → staging (production-like, anonymised data) → production. Migrations never edit applied files. Destructive schema changes need explicit sign-off.

## 5. Production security checklist (phase 14 review)

Verified by automated tests in `backend/tests/Feature/Security` and `Performance`; items marked **config** are deployment settings the tests cannot check.

| Area | Status |
|---|---|
| Authentication | 289 of 292 API routes sit behind `auth:sanctum`; the 3 public ones are login / forgot / reset, all throttled (login 5 per minute per account+IP). Test: `RouteAccessTest` fails if a new route answers an anonymous request. |
| Authorization | A signed-in user with no permissions gets no 2xx and no 5xx from any route (`RouteAccessTest`). Deliberate exceptions, each with a reason, are listed in that test (own profile and notifications, dropdown lookups that return active items only). |
| Error output | `APP_DEBUG=false` hides exception details (`ApiHardeningTest`). **config:** set `APP_ENV=production` and `APP_DEBUG=false`; lazy-loading guards are only active outside production. |
| Response headers | API responses, including errors, send `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store, private`. Document downloads keep their own `private, no-store`. **config:** add the same headers plus HSTS and a CSP for the SPA in Nginx (the SPA is not served by Laravel). |
| CORS | Only `CORS_ALLOWED_ORIGINS` (default `FRONTEND_URL`) instead of `*`. **config:** set it to the production SPA origin. |
| Tokens | Bearer tokens expire after `SANCTUM_TOKEN_EXPIRATION` minutes (default 720). |
| Injection | All raw SQL uses constants, no request input. Sort columns are allow-listed (`ListQuery`, `UserService`). CSV exports neutralise formula cells. |
| Uploads | Private disk, extension allow-list, MIME sniffing, authorised and audited downloads. No web alias to `uploads/`. |
| Performance | `QueryCountTest` and `ListEndpointsMultiRowTest` fail if a list, report or dashboard endpoint runs more queries for 10 rows than for 2. |
| Dependencies | `composer audit`: no advisories. `npm audit --omit=dev`: none. `npm audit` reports 2 moderate issues in `vitest` (dev-only test runner, not in the shipped bundle); the fix is a major upgrade, left for a planned bump. |
| SPA token storage | The bearer token is kept in `localStorage` (readable by any script that runs in the page). The SPA has no `innerHTML` / `dangerouslySetInnerHTML` / `eval`, and map popups are built from text nodes, so the main defence is a strict CSP in Nginx (above). Moving to an httpOnly cookie would be a larger change and is not done. |
| Map tiles | The fleet map loads OpenStreetMap tiles by default. **config:** use a licensed or self-hosted tile service in production via `VITE_MAP_TILE_URL`. |
| Scheduler | **config:** the cron `schedule:run` must run every minute (contract expiry, AIS ingest and stale check). Nothing in the application is queued (no jobs, no queued mail or notifications), so no queue worker is needed; `failed_jobs` stays empty. |
| Mail | **config:** `MAIL_MAILER=log` (the template default) only writes mail to a log file. Password-reset mail is sent synchronously, so production must set a real mailer or users will never receive reset links. |
| Report limits | On screen a report shows at most `REPORT_VIEW_LIMIT` rows (2,000) and says when it is cut; CSV/Excel allow `REPORT_EXPORT_LIMIT` (50,000) and PDF `REPORT_PDF_LIMIT` (3,000); a file over its limit is refused with `report_too_large` instead of being silently cut. |
| Schema drift | After every deploy run `php artisan migrate:status` (nothing pending). Applied migrations must never be edited: one was extended after it had been applied, so some databases lacked `laytime_sof_events` / `laytime_exceptions`; `2026_10_04_120000_create_missing_laytime_child_tables` repairs that. To compare a database with a fresh install, migrate a scratch database and diff `information_schema.columns` and `information_schema.statistics` for both. |
| Passwords | **config:** production `ADMIN_EMAIL` / `ADMIN_PASSWORD` must be set; the dev credentials in section 1 are local only. |


## 6. Load test and recovery drill (phase 14)

Run on this machine (MySQL 5.7, PHP, warm cache, application in `local` mode with lazy-loading checks on, so real production should be a little faster) against a scratch database of about 790,000 rows: 60,000 invoices, 25,000 payables, 25,000 payments, 160,000 revenue and expense lines, 4,000 voyages, 40,000 captain reports, 400,000 AIS positions, 300,000 audit entries, 30,000 documents. Repeat with `backend/tools/loadtest/` (the scripts refuse any database not named `offshore_load`).

| Endpoint group | Second call | Note |
|---|---|---|
| Paginated lists (invoices, payables, payments, voyages, revenues, expenses, captain reports, audit logs, documents) | 35–130 ms | filters and search included |
| Dashboard | ~175 ms | one pass over open invoices/payables |
| Receivables aging, balancing accounts, cash flow | 180–360 ms | were 2.5–5.7 s before the rewrite to grouped SQL |
| Outstanding-invoices / revenue / expense reports | 50–60 ms | row-capped |
| Vessel utilization / performance, bunker consumption | 125–375 ms | |
| Statistics (revenue by month / customer) | ~290 ms | were up to 1 s |
| AIS track, 31 days, 10,000 points | ~270 ms | was ~1 s |
| Voyage P&L, estimated vs actual, vessel profitability | ~1.2 s | two aggregates over 160,000 ledger lines; grows with the ledger |
| Average voyage profit | ~0.65 s | same cause |

Known limit: the voyage-P&L family is linear in the number of ledger lines. If the ledger grows about tenfold, store a per-voyage totals table (updated when lines are confirmed) rather than tuning further. A covering index on `voyage_revenues` was tried: MySQL ignores it unless forced and it halves one query at best, so it was not added.

Backup and restore drill: `mysqldump --single-transaction` of the loaded database took 5 s (15 MB gzipped); restoring into an empty database took 61 s; all 84 tables, row counts and the full content of the compared tables were identical (only `CHECKSUM TABLE` differs for tables with JSON columns), and the application returned byte-identical responses for aging, balancing, vessel profitability and the dashboard from the restored copy. Production should automate the dump and test a restore on a schedule.
