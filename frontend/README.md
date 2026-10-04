# Offshore — Frontend (React + MUI)

- `src/api/` — axios client (token, 401 handling, error normalisation) and typed endpoints
- `src/auth/` — `AuthProvider`, `useAuth().can()`, route guards (`RequireAuth`, `RequirePermission`, `<Can>`)
- `src/components/` — shared UI: `DataTable`, `PageHeader`, `FilterBar`, `StatusChip`, `ConfirmDialog`, `LoadingButton`, `StatCard`
- `src/config/navigation.ts` — sidebar (permission-filtered; future modules shown disabled with their phase)
- `src/constants/permissions.ts` — mirrors backend `App\Enums\Permission`

Environment: copy `.env.example` → `.env`. `VITE_API_URL` is the browser API base; `VITE_API_TARGET` is the dev proxy target.

Business calculations are never done in React — the API returns computed values.
