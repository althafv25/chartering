import { expect, test } from '@playwright/test';
import { E2E } from '../playwright.config';
import { api, apiToken, signIn, watchForProblems } from './helpers';

test('signing in shows the dashboard with live figures, and signing out returns to the login page', async ({ page }) => {
  const problems = watchForProblems(page);
  await signIn(page);
  await expect(page.getByText('Active Vessels')).toBeVisible();
  await expect(page.getByText('Active Voyages')).toBeVisible();
  await expect(page.getByText('Outstanding Invoices')).toBeVisible();   // the admin may see money figures

  await page.getByRole('button', { name: 'Account menu' }).click();
  await page.getByRole('menuitem', { name: /sign out|log out/i }).click();
  await expect(page).toHaveURL(/\/login/);
  expect(problems()).toEqual([]);
});

test('a wrong password is refused with a message and the user stays on the login page', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email').fill(E2E.adminEmail);
  await page.getByLabel('Password', { exact: true }).fill('not-the-password');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText(/do not match|invalid|incorrect/i)).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
});

test('protected pages send a signed-out visitor to the login page', async ({ page }) => {
  await page.goto('/commercial/invoices');
  await expect(page).toHaveURL(/\/login/);
});

test('a read-only user sees no admin menu and gets a 403 page on an admin URL, while still seeing their dashboard', async ({ page, request }) => {
  const token = await apiToken(request);
  await api(request, token, 'POST', '/users', {
    first_name: 'Read', last_name: 'Only', email: 'readonly@example.test', password: 'Read-Only-Pass-12345', password_confirmation: 'Read-Only-Pass-12345', roles: ['read-only'],
  });

  await signIn(page, 'readonly@example.test', 'Read-Only-Pass-12345');
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByText('Administration')).toHaveCount(0);
  await page.goto('/admin/users');
  await expect(page.getByText(/not allowed|forbidden|permission|403/i).first()).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Users', exact: true })).toHaveCount(0);
});
