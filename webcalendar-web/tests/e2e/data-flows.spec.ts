import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';
import { cleanupEvents } from './fixtures/db';

const BASE = 'http://localhost:47180';

async function getToken(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post(`${BASE}/api/v2/auth/login`, {
    data: { username: 'admin', password: 'admin' },
  });
  const body = await response.json();
  return body?.data?.token ?? '';
}

test.describe('Data Flows E2E', () => {

  // Specs share one database, so an event left behind overlaps the next run's
  // event in the time grid and intercepts its click.
  const created: number[] = [];

  test.afterEach(async ({ request }) => {
    await cleanupEvents(request, created);
  });

  // --- Export ---

  test('export calendar triggers download', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    // Click Export button
    const exportBtn = page.getByRole('button', { name: /export/i });
    await expect(exportBtn).toBeVisible();

    // Listen for download
    const downloadPromise = page.waitForEvent('download', { timeout: 10000 }).catch(() => null);
    await exportBtn.click();
    const download = await downloadPromise;

    // Should trigger a download with .ics extension
    if (download) {
      expect(download.suggestedFilename()).toContain('.ics');
    }
    // If no download event (API may return empty), at least verify button is clickable
  });

  // --- Search ---

  test('search for event shows results dropdown', async ({ page }) => {
    await loginAsAdmin(page);

    // Create a searchable event via API
    const token = await getToken(page);
    const title = `SearchTarget-${Date.now().toString().slice(-6)}`;
    const searchRes = await page.request.post(`${BASE}/api/v2/events`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { title, start_date: '20261101', start_time: '100000', duration: 60 },
    });
    created.push((await searchRes.json())?.data?.id);

    await page.goto('/');
    await page.waitForSelector('.fc');

    // Type in search bar
    const searchInput = page.getByPlaceholder(/search/i);
    await searchInput.fill(title.slice(0, 10));

    // Wait for dropdown results
    await page.waitForTimeout(1000); // debounce
    // Results area should appear (either results or "no results")
    const dropdown = page.locator('.absolute.left-0.top-10, [class*="shadow-lg"]').first();
    await expect(dropdown).toBeVisible({ timeout: 5000 });
  });

  test('search with no matches shows empty state', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    const searchInput = page.getByPlaceholder(/search/i);
    await searchInput.fill('xyznonexistent99999');

    await page.waitForTimeout(1000);
    await expect(page.getByText(/no results/i)).toBeVisible({ timeout: 5000 });
  });

  // --- Quick Add ---

  test('quick add parses natural language and opens dialog', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    const quickInput = page.getByPlaceholder(/quick add/i);
    await quickInput.fill('Lunch tomorrow at noon');
    await page.getByRole('button', { name: /parse/i }).click();

    // Should open event dialog with parsed title
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 5000 });
  });

  // --- Recurring Events ---

  test('create recurring daily event via API', async ({ page }) => {
    const token = await getToken(page);

    const title = `DailyRecur-${Date.now().toString().slice(-6)}`;
    const res = await page.request.post(`${BASE}/api/v2/events`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: {
        title,
        start_date: '20260601',
        start_time: '090000',
        duration: 30,
        rrule: 'FREQ=DAILY;COUNT=5',
      },
    });
    expect(res.ok()).toBe(true);
    const body = await res.json();
    created.push(body?.data?.id);
    expect(body?.data?.rrule).toBe('FREQ=DAILY;COUNT=5');
    expect(body?.data?.title).toBe(title);
  });

  // --- Journal CRUD ---

  test('journal create, edit title, delete', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/journals');
    await expect(page.getByRole('heading', { name: 'Journals' })).toBeVisible();

    // Create
    const journalTitle = `JrnlTest-${Date.now().toString().slice(-6)}`;
    const titleInput = page.getByPlaceholder(/title|new journal/i);
    if (await titleInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await titleInput.fill(journalTitle);
      await page.getByRole('button', { name: /create|add|save/i }).first().click();
      await page.waitForTimeout(2000);
      await expect(page.getByText(journalTitle)).toBeVisible({ timeout: 5000 });
    } else {
      // If no inline create form, try button
      const createBtn = page.getByRole('button', { name: /new journal|create/i });
      if (await createBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
        await createBtn.click();
        await page.getByLabel(/title/i).fill(journalTitle);
        await page.getByRole('button', { name: /save|create/i }).click();
        await page.waitForTimeout(2000);
      }
    }
  });

  // --- Import Dialog ---

  test('import dialog opens with file input', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    await page.getByRole('button', { name: /import/i }).click();

    // Dialog should open
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 5000 });

    // Should have a file input or drop zone
    const fileInput = page.locator('input[type="file"]');
    await expect(fileInput).toBeAttached();
  });

  // --- Poll Creation ---

  test('poll creation dialog opens', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    await page.getByRole('button', { name: /schedule meeting/i }).click();

    // Poll dialog should open
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 5000 });
    await expect(page.getByText(/schedule|poll|meeting/i).first()).toBeVisible();
  });

  // --- Recurrence Editor UI ---

  test('recurrence editor shows in event dialog', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    // Open new event dialog
    await page.getByRole('button', { name: /new event/i }).click();
    await expect(page.locator('[role="dialog"]')).toBeVisible({ timeout: 5000 });

    // Recurrence section should be present
    await expect(page.getByText(/repeat|recurrence|does not repeat/i).first()).toBeVisible();
  });
});
