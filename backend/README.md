# Offshore — Backend (Laravel 12)

Layering: route → controller (thin, `ApiResponse`) → FormRequest → Policy → Service → Model → API Resource.

- `app/Enums/Permission.php` — permission catalogue (`module.action`); run `php artisan db:seed --class=RolesAndPermissionsSeeder` after adding one.
- `app/Exceptions/ApiExceptionRenderer.php` — every API error becomes `{success:false, message, error_code, errors?, request_id}`.
- `config/offshore.php` — settings registry, document parents, base currency.
- Tests run on MySQL `offshore_test` (`phpunit.xml`).

See `../docs/02-SYSTEM-ARCHITECTURE.md` and `../docs/06-API-SPECIFICATION.md`.
