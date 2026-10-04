import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { E2E } from '../playwright.config';

const tokens = new Map<string, string>();

/** One login per account per run: the API throttles logins (5 per minute per account), which is itself a behaviour we keep. */
export async function apiToken(request: APIRequestContext, email = E2E.adminEmail, password = E2E.adminPassword): Promise<string> {
  const cached = tokens.get(email);
  if (cached) return cached;
  const res = await request.post(`${E2E.api}/api/v1/auth/login`, { data: { email, password, device_name: 'e2e' } });
  expect(res.ok(), `login as ${email}: ${await res.text()}`).toBeTruthy();
  const token = (await res.json()).data.token as string;
  tokens.set(email, token);

  return token;
}

/** Calls the API directly to prepare data; fails the test with the server's message if it refuses. */
export async function api<T = Record<string, unknown>>(request: APIRequestContext, token: string, method: 'POST' | 'PUT', path: string, data: object): Promise<T> {
  const res = await request.fetch(`${E2E.api}/api/v1${path}`, { method, data, headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  expect(res.ok(), `${method} ${path} → ${res.status()} ${await res.text()}`).toBeTruthy();
  return (await res.json()).data as T;
}

export async function signIn(page: Page, email = E2E.adminEmail, password = E2E.adminPassword): Promise<void> {
  await page.goto('/login');
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByRole('heading', { name: /^Welcome,/ })).toBeVisible();
}

/** Problems a real user would hit: uncaught errors, console errors, and server errors from our own API. Map-tile failures are ignored. */
export function watchForProblems(page: Page): () => string[] {
  const problems: string[] = [];
  page.on('pageerror', (e) => problems.push(`uncaught: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const url = m.location().url;
    if (url.includes('tile.openstreetmap.org') || url.includes('favicon')) return;
    problems.push(`console: ${m.text()} (${url})`);
  });
  page.on('response', (r) => {
    if (r.url().includes('/api/v1/') && r.status() >= 500) problems.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`);
  });
  return () => problems;
}

/** Opens the app as an already signed-in user (token stored the way the login page stores it), without going through the login form. */
export async function openSignedIn(page: Page, token: string, path = '/'): Promise<void> {
  await page.addInitScript((t) => localStorage.setItem('offshore_token', t), token);
  await page.goto(path);
  await expect(page.locator('h1').first()).toBeVisible();
}
