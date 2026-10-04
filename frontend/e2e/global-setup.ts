import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { E2E } from '../playwright.config';

/** Rebuilds the `offshore_e2e` database (migrate:fresh + seed) before any test or server starts using it. */
export default function globalSetup() {
  execFileSync('php', ['tools/e2e/prepare.php'], {
    cwd: path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../backend'),
    env: { ...process.env, DB_DATABASE: 'offshore_e2e', ADMIN_EMAIL: E2E.adminEmail, ADMIN_PASSWORD: E2E.adminPassword, CACHE_STORE: 'array', SESSION_DRIVER: 'array' },
    stdio: 'inherit',
  });
}
