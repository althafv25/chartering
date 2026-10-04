import { defineConfig, devices } from '@playwright/test';

/**
 * Browser tests run against a throw-away stack: database `offshore_e2e` (rebuilt each run), the API on :8011 and the Vite
 * dev server on :5174. Nothing here touches the normal `offshore` database or the normal dev ports.
 *   npm run e2e
 */
export const E2E = {
  api: 'http://127.0.0.1:8011',
  web: 'http://127.0.0.1:5174',
  adminEmail: 'e2e-admin@example.test',
  adminPassword: 'E2e-Admin-Pass-12345',
};

const backendEnv = {
  DB_DATABASE: 'offshore_e2e',
  APP_ENV: 'local',
  APP_DEBUG: 'true',
  ADMIN_EMAIL: E2E.adminEmail,
  ADMIN_PASSWORD: E2E.adminPassword,
  API_RATE_LIMIT: '100000',
  FRONTEND_URL: E2E.web,
  CACHE_STORE: 'array',   // per request: a file cache would keep settings from a previous run after the database is rebuilt
  SESSION_DRIVER: 'array',
  QUEUE_CONNECTION: 'sync',
  PHP_CLI_SERVER_WORKERS: '4',
};

export default defineConfig({
  testDir: './e2e',
  testMatch: '*.e2e.ts',
  globalSetup: './e2e/global-setup.ts',
  workers: 1,
  fullyParallel: false,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list']],
  use: { baseURL: E2E.web, trace: 'retain-on-failure', screenshot: 'only-on-failure', ...devices['Desktop Chrome'] },
  webServer: [
    {
      // `php -S` (not `artisan serve`, which ignores the process environment for the database name).
      command: 'php -S 127.0.0.1:8011 -t public',
      cwd: '../backend',
      url: `${E2E.api}/up`,
      env: backendEnv,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: 'npm run dev -- --port 5174 --strictPort --host 127.0.0.1',
      url: E2E.web,
      env: { VITE_API_TARGET: E2E.api, VITE_API_URL: '/api/v1' },
      reuseExistingServer: false,
      timeout: 60_000,
    },
  ],
});
