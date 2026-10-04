import { expect, test } from '@playwright/test';
import { api, apiToken, openSignedIn, watchForProblems } from './helpers';

test('a Port DA is built in the browser, totalled by the server, and only a second person can approve it', async ({ browser, page, request }) => {
  const token = await apiToken(request);
  const problems = watchForProblems(page);
  await openSignedIn(page, token, '/operations/port-da');

  await page.getByRole('button', { name: 'New DA' }).click();
  const dialog = page.getByRole('dialog', { name: 'New port DA' });
  await dialog.getByLabel('Voyage').click();
  await page.getByRole('option', { name: /E2E-26-001/ }).click();
  await dialog.getByLabel('Port call').click();
  await page.getByRole('option', { name: /Jebel Ali/ }).click();
  await dialog.getByLabel('Type').click();
  await page.getByRole('option', { name: /Final/ }).click();
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(page).toHaveURL(/\/operations\/port-da\/(\d+)$/);
  const daId = /port-da\/(\d+)$/.exec(page.url())![1];

  // Two items; the first has an empty estimated cell.
  for (const [i, [description, actual]] of [['Agency fee', '1200.50'], ['Pilotage', '300']].entries()) {
    await page.getByRole('button', { name: 'Add item' }).click();
    await page.getByLabel('Category').nth(i).click();
    await page.getByRole('option').nth(i).click();
    await page.getByLabel('Item description').nth(i).fill(description);
    await page.getByLabel('Actual amount').nth(i).fill(actual);
  }
  await page.getByLabel('Actual amount').first().fill('12.345');                         // three decimals: refused by the form
  await expect(page.getByRole('button', { name: 'Save items' })).toBeDisabled();
  await page.getByLabel('Actual amount').first().fill('1200.50');
  await expect(page.getByRole('button', { name: 'Submit for approval' })).toBeDisabled();  // unsaved edits must be saved first
  await page.getByRole('button', { name: 'Save items' }).click();
  await expect(page.getByRole('cell', { name: '1,500.50' })).toBeVisible();              // 1200.50 + 300: the final DA totals actual amounts

  await page.getByRole('button', { name: 'Submit for approval' }).click();
  await expect(page.getByText('Submitted', { exact: true }).first()).toBeVisible();

  // The submitter is refused, even an administrator (APR-02).
  await page.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(page.getByText(/cannot approve|submitted/i).first()).toBeVisible();
  await expect(page.getByText('Approved', { exact: true })).toHaveCount(0);

  // A different user approves in their own browser session.
  await api(request, token, 'POST', '/users', {
    first_name: 'Dana', last_name: 'Approver', email: 'da-approver@example.test', password: 'Approver-Pass-12345', password_confirmation: 'Approver-Pass-12345', roles: ['commercial'],
  });
  const second = await browser.newContext();
  const other = await second.newPage();
  const otherToken = await apiToken(request, 'da-approver@example.test', 'Approver-Pass-12345');
  await openSignedIn(other, otherToken, `/operations/port-da/${daId}`);
  await other.getByRole('button', { name: 'Approve', exact: true }).click();
  await expect(other.getByText('Approved', { exact: true }).first()).toBeVisible();
  await expect(other.getByText(/booked as a voyage expense/)).toBeVisible();
  await second.close();

  // The approved final DA now shows as an expense on the ledger.
  await page.goto('/commercial/ledger');
  await page.getByRole('tab', { name: 'Expenses' }).click();
  await expect(page.getByText(/Agency fee|Pilotage|Port DA|DA-/).first()).toBeVisible();
  // The one expected console error is the browser logging the deliberate 403 when the submitter tried to approve.
  expect(problems().filter((p) => !/403.*port-das\/\d+\/approve/.test(p))).toEqual([]);
});

test('a laytime calculation is entered in port time, calculated by the server, and agreed; late exceptions keep counting once on demurrage', async ({ page, request }) => {
  const token = await apiToken(request);
  const problems = watchForProblems(page);
  await openSignedIn(page, token, '/operations/laytime');

  await page.getByRole('button', { name: 'New calculation' }).click();
  const dialog = page.getByRole('dialog', { name: 'New laytime calculation' });
  await dialog.getByLabel('Voyage').click();
  await page.getByRole('option', { name: /E2E-26-001/ }).click();
  await dialog.getByLabel('Port call').click();
  await page.getByRole('option', { name: /Jebel Ali/ }).click();
  await dialog.getByRole('button', { name: 'Create' }).click();
  await expect(page).toHaveURL(/\/operations\/laytime\/\d+$/);

  // Terms, with the port's own clock (Dubai): 72 h allowed, 01 Oct 06:00 -> 05 Oct 12:00 = 102 h.
  await expect(page.getByLabel('Laytime commenced (Dubai)')).toBeVisible();
  await page.getByLabel('Fixed hours').fill('72');
  await page.getByLabel('Laytime commenced (Dubai)').fill('2026-10-01T06:00');
  await page.getByLabel('Laytime completed (Dubai)').fill('2026-10-05T12:00');
  await page.getByLabel('Demurrage per day').fill('24000');
  await page.getByRole('button', { name: 'Save terms' }).click();
  await expect(page.getByRole('button', { name: 'Save terms' })).toBeDisabled();           // saved: nothing left to save
  await page.getByRole('button', { name: 'Calculate' }).click();
  await expect(page.getByText('Over allowed (h)')).toBeVisible();
  await expect(page.getByText('102', { exact: true })).toBeVisible();                       // used hours
  await expect(page.getByText(/30,000/)).toBeVisible();                                    // 30 h over x 24,000/day = 30,000

  // Weather stoppage AFTER laytime ran out: under "always on demurrage" it still counts, so the result does not change.
  await page.getByRole('tab', { name: /^Exceptions/ }).click();
  await page.getByLabel('From (Dubai)').fill('2026-10-04T06:00');
  await page.getByLabel('To (Dubai)').fill('2026-10-04T18:00');
  await page.getByLabel('Type').fill('weather');
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(page.getByRole('cell', { name: 'Weather' })).toBeVisible();
  await page.getByRole('button', { name: 'Calculate' }).click();
  await page.getByRole('tab', { name: 'Calculation trace' }).click();
  await expect(page.getByText(/on demurrage/).first()).toBeVisible();
  await expect(page.getByText('once-on-demurrage rule changed')).toBeVisible();

  // Submit and agree.
  await page.getByRole('button', { name: 'Submit', exact: true }).click();
  await expect(page.getByText('Submitted', { exact: true }).first()).toBeVisible();
  await page.getByRole('button', { name: 'Agree', exact: true }).click();
  await expect(page.getByText('Agreed', { exact: true }).first()).toBeVisible();
  await expect(page.getByText(/booked as voyage revenue/)).toBeVisible();
  await page.getByRole('tab', { name: 'Terms & times' }).click();
  await expect(page.getByLabel('Laytime commenced (Dubai)')).toBeDisabled();                // read-only once agreed
  expect(problems()).toEqual([]);
});
