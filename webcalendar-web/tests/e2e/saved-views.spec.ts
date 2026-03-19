import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';

test.describe('Saved Views E2E', () => {

  test('create and list a saved view', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/views');

    await expect(page.getByRole('heading', { name: /saved views/i })).toBeVisible();

    const viewName = `Test View ${Date.now()}`;
    await page.getByLabel(/view name/i).fill(viewName);

    await page.waitForSelector('input[type="checkbox"]');
    await page.locator('input[type="checkbox"]').first().check();
    await page.getByRole('button', { name: /create view/i }).click();

    // Wait for toast to disappear, then check the view list
    await page.waitForTimeout(2000);
    await expect(page.getByText(viewName, { exact: true })).toBeVisible();
  });

  test('activate a saved view', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/views');
    await expect(page.getByRole('heading', { name: /saved views/i })).toBeVisible();

    const viewName = `Activate ${Date.now()}`;
    await page.getByLabel(/view name/i).fill(viewName);
    await page.waitForSelector('input[type="checkbox"]');
    await page.locator('input[type="checkbox"]').first().check();
    await page.getByRole('button', { name: /create view/i }).click();

    await page.waitForTimeout(2000);

    // Click the first Activate button
    await page.getByRole('button', { name: /activate/i }).first().click();

    // Toast appears in the notification region
    await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 5000 });
  });

  test('delete a saved view', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/views');
    await expect(page.getByRole('heading', { name: /saved views/i })).toBeVisible();

    const viewName = `Delete ${Date.now()}`;
    await page.getByLabel(/view name/i).fill(viewName);
    await page.waitForSelector('input[type="checkbox"]');
    await page.locator('input[type="checkbox"]').first().check();
    await page.getByRole('button', { name: /create view/i }).click();

    await page.waitForTimeout(2000);

    // Click the last Delete button (most recently created)
    await page.getByRole('button', { name: /^delete$/i }).last().click();

    await expect(page.getByText(/deleted/i)).toBeVisible({ timeout: 5000 });
  });

  test('layer panel shows user dropdown', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/');

    const layersButton = page.getByRole('button', { name: /layers/i });
    if (await layersButton.isVisible()) {
      const expanded = await layersButton.getAttribute('aria-expanded');
      if (expanded === 'false') {
        await layersButton.click();
      }
    }

    const select = page.locator('select[aria-label="Select user for new layer"]');
    await expect(select).toBeVisible({ timeout: 5000 });
    await expect(select.locator('option').first()).toHaveText('Add user...');
  });
});
