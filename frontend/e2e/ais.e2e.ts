import { expect, test } from '@playwright/test';
import { api, apiToken, openSignedIn, watchForProblems } from './helpers';

test('with AIS off the fleet map is hidden from the menu; once on, a recorded position shows in the table and as a marker on the map', async ({ page, request }) => {
  const token = await apiToken(request);
  const types = await request.get('http://127.0.0.1:8011/api/v1/reference/vessel-types', { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } });
  const typeId = (await types.json()).data[0].id as number;
  await api(request, token, 'POST', '/vessels', { code: 'AISV', name: 'MV Tracker', vessel_type_id: typeId });
  const problems = watchForProblems(page);
  await openSignedIn(page, token);

  // Off by default: no menu entry, and the page explains why.
  const nav = page.getByRole('navigation', { name: 'Main navigation' });
  await nav.getByText('Fleet', { exact: true }).click();
  await expect(nav.getByRole('link', { name: 'Vessels' })).toBeVisible();
  await expect(nav.getByRole('link', { name: 'Fleet Map' })).toHaveCount(0);
  await page.goto('/fleet/map');
  await expect(page.getByText(/AIS is switched off/)).toBeVisible();

  // Switch it on (as an administrator would in Settings) and record a position in the browser.
  await api(request, token, 'PUT', '/settings', { settings: { 'ais.enabled': true } });
  await page.reload();
  await page.getByRole('button', { name: 'Record position' }).click();
  const dialog = page.getByRole('dialog', { name: 'Record position' });
  await dialog.getByLabel('Vessel').click();
  await page.getByRole('option', { name: 'MV Tracker' }).click();
  await dialog.getByLabel(/Latitude/).fill('25.2048');
  await dialog.getByLabel(/Longitude/).fill('55.2708');
  const observed = new Date(Date.now() - 30 * 60_000).toISOString().slice(0, 16);   // 30 minutes ago, entered as UTC
  await dialog.getByLabel('Observed (UTC)').fill(observed);
  await dialog.getByLabel('Speed (kn)').fill('11.5');
  await dialog.getByRole('button', { name: 'Record', exact: true }).click();

  const table = page.getByRole('table');
  await expect(table.getByText('MV Tracker')).toBeVisible();
  await expect(table.getByText('Fresh')).toBeVisible();
  await expect(page.getByRole('application', { name: 'Fleet map' })).toBeVisible();
  await expect(page.locator('.leaflet-interactive')).toHaveCount(1);     // one vessel marker drawn by Leaflet
  await expect(page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Fleet Map' })).toBeVisible();

  // Selecting the vessel draws its track and reports a distance.
  await table.getByText('MV Tracker').click();
  await expect(page.getByText(/Track: .* nm/)).toBeVisible();
  expect(problems()).toEqual([]);
});
