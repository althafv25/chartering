# Offshore Chartering & Vessel Operations

Single-company system covering chartering → estimation → fixture → contract → voyage/offshore operations → invoicing → P&L.

```
backend/    Laravel 12 REST API (/api/v1, Sanctum, Spatie permission + activitylog)
frontend/   React 19 + TypeScript + Vite + MUI SPA
uploads/    private document storage (never web-served)
docs/       requirements, architecture, ERD, API, business rules, progress
```

## Local setup (ServBay / macOS)

Requires PHP 8.2+, Composer, Node 20+, MySQL (database `offshore`).

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate   # set DB_PASSWORD
php artisan migrate --seed
php artisan serve --port=8001

# Frontend (second terminal)
cd frontend
npm install
cp .env.example .env        # VITE_API_URL / VITE_API_TARGET
npm run dev                 # http://localhost:5173
```

Dev login: `admin@offshore.local` / `Admin@12345` (local only).

## Quality checks

```bash
cd backend  && php artisan test && ./vendor/bin/phpstan analyse && ./vendor/bin/pint --test
cd frontend && npm run lint && npm run build && npm test
```

**Status:** Phases 2–12 complete and tested (292 backend tests, 34 frontend tests). Phase 13 CII blocked on business approval. Phase 14 testing hardening in progress.

Progress: `docs/13-DEVELOPMENT-PROGRESS.md`. Final status: `docs/14-FINAL-STATUS.md`. Decisions: `docs/14-DECISIONS.md`.
