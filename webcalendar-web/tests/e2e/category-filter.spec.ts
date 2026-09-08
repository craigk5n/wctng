import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';

const BASE = 'http://localhost:47180';

async function getToken(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post(`${BASE}/api/v2/auth/login`, {
    data: { username: 'admin', password: 'admin' },
  });
  const body = await response.json();
  return body?.data?.token ?? '';
}

test.describe('Category Filter E2E', () => {

  test('filter button opens popover with category checkboxes', async ({ page }) => {
    await loginAsAdmin(page);

    // Ensure at least one category exists
    const token = await getToken(page);
    await page.request.post(`${BASE}/api/v2/categories`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { name: `FilterTest-${Date.now().toString().slice(-6)}` },
    });

    await page.goto('/');
    await page.waitForSelector('.fc');

    // Click the Filter button in toolbar
    const filterBtn = page.getByRole('button', { name: /filter/i });
    await expect(filterBtn).toBeVisible({ timeout: 5000 });
    await filterBtn.click();

    // The sidebar CategoryFilter renders its own All/None buttons and
    // checkboxes carrying aria-label, so match within the popover that belongs to
    // this button rather than across the whole page.
    const popover = filterBtn.locator('xpath=..');

    await expect(popover.getByRole('button', { name: /^all$/i })).toBeVisible();
    await expect(popover.getByRole('button', { name: /^none$/i })).toBeVisible();

    const checkboxes = popover.locator('input[type="checkbox"][aria-label]');
    expect(await checkboxes.count()).toBeGreaterThan(0);
  });

  test('filter button has aria-label for accessibility', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    const filterBtn = page.getByRole('button', { name: /filter by category/i });
    await expect(filterBtn).toBeVisible({ timeout: 5000 });
    await expect(filterBtn).toHaveAttribute('aria-label', 'Filter by category');
  });

  test('clicking None then All toggles filter state', async ({ page }) => {
    await loginAsAdmin(page);
    await page.evaluate(() => localStorage.removeItem('wctng_category_filter'));

    await page.goto('/');
    await page.waitForSelector('.fc');

    // Open filter popover
    const filterBtn = page.getByRole('button', { name: /filter by category/i });
    await expect(filterBtn).toBeVisible({ timeout: 5000 });
    await filterBtn.click();

    // Scope to this popover: the sidebar filter duplicates every one of these
    // controls, including the Uncategorized checkbox.
    const popover = filterBtn.locator('xpath=..');

    // Click None — all checkboxes should uncheck
    await popover.getByRole('button', { name: /^none$/i }).click();
    await page.waitForTimeout(300);

    const uncategorizedCheckbox = popover.locator('input[aria-label="Uncategorized"]');
    await expect(uncategorizedCheckbox).not.toBeChecked();

    // Click All — all checkboxes should recheck
    await popover.getByRole('button', { name: /^all$/i }).click();
    await page.waitForTimeout(300);
    await expect(uncategorizedCheckbox).toBeChecked();
  });

  test('saved view creation form shows category filter section', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/views');
    await expect(page.getByRole('heading', { name: /saved views/i })).toBeVisible();

    await expect(page.getByText('Filter by Categories')).toBeVisible({ timeout: 5000 });
  });
});
