<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
| The API uses bearer tokens (no cookies), but it still only answers cross-origin browser calls from
| the configured SPA origin(s) instead of "*". CORS_ALLOWED_ORIGINS is a comma-separated list; it
| defaults to FRONTEND_URL. In development the Vite proxy makes requests same-origin anyway.
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Request-Id', 'Idempotency-Key'],
    'exposed_headers' => ['X-Request-Id', 'Content-Disposition', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
