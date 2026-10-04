import { expect, test } from '@playwright/test';
import { api, apiToken, openSignedIn, watchForProblems } from './helpers';

const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);

test('create, fill and issue an invoice in the browser, then find it in the aging report', async ({ page, request }) => {
  const token = await apiToken(request);
  await api(request, token, 'POST', '/companies', { legal_name: 'Delta Flow Charterers', roles: ['customer'], country: 'AE' });
  const problems = watchForProblems(page);
  await openSignedIn(page, token);

  // New invoice dialog
  await page.goto('/commercial/invoices');
  await page.getByRole('button', { name: 'New invoice' }).click();
  const dialog = page.getByRole('dialog', { name: 'New invoice' });
  await dialog.getByLabel('Customer').fill('Delta Flow');
  await page.getByRole('option', { name: /Delta Flow Charterers/ }).click();
  await dialog.getByLabel('Issue date').fill(inDays(-20));
  await dialog.getByLabel('Due date').fill(inDays(-5));
  await dialog.getByRole('button', { name: 'Create' }).click();

  // Draft invoice page: add a line
  await expect(page).toHaveURL(/\/commercial\/invoices\/\d+$/);
  await expect(page.getByText('Draft', { exact: true }).first()).toBeVisible();
  await page.getByRole('button', { name: 'Add line' }).click();
  const line = page.getByRole('dialog', { name: 'Add line' });
  await line.getByLabel('Description').fill('Freight Jebel Ali to Ras Tanura');
  await line.getByLabel('Amount (override)').fill('12345.67');
  await line.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.getByText('Freight Jebel Ali to Ras Tanura')).toBeVisible();
  await expect(page.getByText('12,345.67').first()).toBeVisible();      // line total, grouped by the UI, calculated by the server

  // Issue
  await page.getByRole('button', { name: 'Issue', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByText('Issued', { exact: true }).first()).toBeVisible();
  await expect(page.getByRole('heading', { name: /^INV-\d{4}-\d{5}$/ })).toBeVisible();   // the number is assigned at issue
  await expect(page.getByRole('button', { name: 'Issue', exact: true })).toHaveCount(0);    // no second issue

  // It is overdue (due 5 days ago) and appears in the receivables aging under the right bucket.
  await page.goto('/commercial/aging');
  const row = page.getByRole('row', { name: /Delta Flow Charterers/ });
  await expect(row).toBeVisible();
  await expect(row).toContainText('12,345.67');
  await row.click();
  await expect(page.getByRole('cell', { name: '1–30 days' }).last()).toBeVisible();   // the expanded invoice row names its bucket

  // And in the balancing account view.
  await page.goto('/commercial/balancing');
  await expect(page.getByRole('row', { name: /Delta Flow Charterers/ })).toContainText('12,345.67');
  expect(problems()).toEqual([]);
});

test('a payment can be recorded and allocated to the invoice, which becomes paid', async ({ page, request }) => {
  const token = await apiToken(request);
  const customer = await api<{ id: number }>(request, token, 'POST', '/companies', { legal_name: 'Epsilon Shipping Co', roles: ['customer'], country: 'AE' });
  const inv = await api<{ id: number }>(request, token, 'POST', '/invoices', { invoice_type: 'freight', customer_company_id: customer.id, issue_date: inDays(-3), due_date: inDays(27), currency: 'USD' });
  await api(request, token, 'POST', `/invoices/${inv.id}/lines`, { lines: [{ description: 'Hire', amount: '800.00' }] });
  await api(request, token, 'POST', `/invoices/${inv.id}/issue`, {});
  const problems = watchForProblems(page);
  await openSignedIn(page, token);

  await page.goto('/commercial/payments');
  await page.getByRole('button', { name: 'Record payment' }).click();
  const dialog = page.getByRole('dialog', { name: 'Record payment' });
  await dialog.getByLabel('Company').fill('Epsilon');
  await page.getByRole('option', { name: /Epsilon Shipping Co/ }).click();
  await dialog.getByLabel('Payment date').fill(inDays(0));
  await dialog.getByLabel('Amount').fill('800.00');
  await dialog.getByRole('button', { name: 'Create' }).click();

  await expect(page).toHaveURL(/\/commercial\/payments\/\d+$/);
  await page.getByRole('button', { name: 'Allocate', exact: true }).click();
  const allocate = page.getByRole('dialog', { name: 'Allocate payment' });
  await allocate.getByRole('button', { name: 'Select' }).first().click();
  await allocate.getByRole('button', { name: 'Allocate', exact: true }).click();

  await expect(page.getByText('Invoice INV-', { exact: false }).first()).toBeVisible();
  await page.goto(`/commercial/invoices/${inv.id}`);
  await expect(page.getByText('Paid', { exact: true }).first()).toBeVisible();
  expect(problems()).toEqual([]);
});
