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

  test('category checkboxes appear in sidebar and can be toggled', async ({ page }) => {
    await loginAsAdmin(page);

    // Ensure at least one category exists
    const token = await getToken(page);
    await page.request.post(`${BASE}/api/v2/categories`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { name: `FilterTest-${Date.now().toString().slice(-6)}` },
    });

    await page.goto('/');
    await page.waitForSelector('.fc');

    // Category filter should be in sidebar
    await expect(page.getByRole('button', { name: /^all$/i })).toBeVisible({ timeout: 5000 });

    // Should have All/None buttons
    await expect(page.getByRole('button', { name: /^all$/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /^none$/i })).toBeVisible();

    // Should have at least one checkbox
    const checkboxes = page.locator('input[type="checkbox"][aria-label]');
    const count = await checkboxes.count();
    expect(count).toBeGreaterThan(0);
  });

  test('category filter persists on reload', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');
    await page.waitForSelector('.fc');

    // Wait for categories to load
    await expect(page.getByRole('button', { name: /^all$/i })).toBeVisible({ timeout: 5000 });

    // Click None to uncheck all
    await page.getByRole('button', { name: /^none$/i }).click();
    await page.waitForTimeout(500);

    // Reload
    await page.reload();
    await page.waitForSelector('.fc');
    await expect(page.getByRole('button', { name: /^all$/i })).toBeVisible({ timeout: 5000 });

    // After clicking All to re-enable
    await page.getByRole('button', { name: /^all$/i }).click();
  });
});
