import { expect, test, type APIRequestContext } from '@playwright/test';
import { api, apiToken, openSignedIn, watchForProblems } from './helpers';

const today = () => new Date().toISOString().slice(0, 10);
const inDays = (n: number) => new Date(Date.now() + n * 86_400_000).toISOString().slice(0, 10);

/** Enough rows in every finance list that "two or more rows" code paths (eager loading, grouping) actually run. */
async function seedFinance(request: APIRequestContext, token: string) {
  const customers: number[] = [];
  for (const name of ['Alpha Charterers LLC', 'Beta Offshore Services', 'Gamma Marine Trading']) {
    const c = await api<{ id: number }>(request, token, 'POST', '/companies', { legal_name: name, roles: ['customer', 'supplier'], country: 'AE' });
    customers.push(c.id);
  }
  for (const [i, customer] of customers.entries()) {
    const inv = await api<{ id: number }>(request, token, 'POST', '/invoices', {
      invoice_type: 'freight', customer_company_id: customer, issue_date: inDays(-40), due_date: inDays(-10 * (i + 1)), currency: 'USD',
    });
    await api(request, token, 'POST', `/invoices/${inv.id}/lines`, { lines: [{ description: `Freight ${i + 1}`, amount: `${(i + 1) * 1000}.00` }] });
    await api(request, token, 'POST', `/invoices/${inv.id}/issue`, {});
    await api(request, token, 'POST', '/payables', { supplier_company_id: customer, supplier_invoice_ref: `SUP-${i}`, issue_date: today(), due_date: inDays(20), currency: 'USD', subtotal: '250.00', tax: '0' });
    await api(request, token, 'POST', '/payments', { direction: 'received', company_id: customer, payment_date: today(), amount: '100.00', currency: 'USD' });
  }
  const types = await request.get('http://127.0.0.1:8011/api/v1/reference/vessel-types', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  const typeId = (await types.json()).data[0].id as number;
  for (const [code, name] of [['ALF', 'MV Alfa'], ['BRV', 'MV Bravo']]) await api(request, token, 'POST', '/vessels', { code, name, vessel_type_id: typeId });
}

test('every menu entry opens without server errors, console errors or an error banner', async ({ page, request }) => {
  test.setTimeout(300_000);
  const token = await apiToken(request);
  await seedFinance(request, token);
  const problems = watchForProblems(page);

  await openSignedIn(page, token);
  const nav = page.getByRole('navigation', { name: 'Main navigation' });
  // Open every collapsed group so all of its links are in the page.
  for (let i = 0; i < 12; i++) {
    const closed = nav.locator('[aria-expanded="false"]');
    if ((await closed.count()) === 0) break;
    await closed.first().click();
  }
  const hrefs = [...new Set(await nav.locator('a[href]').evaluateAll((els) => els.map((e) => (e as HTMLAnchorElement).getAttribute('href') ?? '')))].filter((h) => h.startsWith('/'));
  expect(hrefs.length, `menu links found: ${hrefs.join(', ')}`).toBeGreaterThan(25);

  const failures: string[] = [];
  for (const href of hrefs) {
    const before = problems().length;
    await page.goto(href);
    const heading = await page.locator('h1').first().waitFor({ timeout: 15_000 }).then(() => true, () => false);
    if (!heading) {
      failures.push(`${href}: no page heading after 15 s (body: ${((await page.locator('body').innerText()).replace(/\s+/g, ' ')).slice(0, 160)})`);
      continue;
    }
    await page.waitForLoadState('networkidle');
    if (await page.getByText('Could not load data').count()) failures.push(`${href}: error banner`);
    if (await page.getByText(/something went wrong|unexpected error/i).count()) failures.push(`${href}: crash message`);
    for (const p of problems().slice(before)) failures.push(`${href}: ${p}`);
  }
  expect(failures, `${hrefs.length} pages checked`).toEqual([]);
});
