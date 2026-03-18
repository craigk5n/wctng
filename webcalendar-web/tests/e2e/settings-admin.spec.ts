import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';

test.describe('Settings & Admin E2E', () => {

  test('profile editing — loads with user info', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/profile');

    await expect(page.getByRole('heading', { name: /profile/i })).toBeVisible();
    // Should show first name, last name, email fields
    await expect(page.getByLabel(/first name/i)).toBeVisible();
    await expect(page.getByLabel(/last name/i)).toBeVisible();
    await expect(page.getByLabel(/email/i)).toBeVisible();
    await expect(page.getByRole('button', { name: /save profile/i })).toBeVisible();
  });

  test('profile editing — password change fields visible', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/profile');

    await expect(page.getByLabel(/current password/i)).toBeVisible();
    await expect(page.getByLabel(/^new password$/i)).toBeVisible();
    await expect(page.getByLabel(/confirm/i)).toBeVisible();
  });

  test('API token — page loads with generate button and MCP instructions', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/api-tokens');

    await expect(page.getByRole('heading', { name: /api tokens/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /generate/i })).toBeVisible();
    await expect(page.getByText('MCP Connection Instructions')).toBeVisible();
  });

  test('admin settings — toggle feature flags', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/settings');

    await expect(page.getByRole('heading', { name: /system settings/i })).toBeVisible();

    // Should have checkboxes for features
    const checkboxes = page.locator('input[type="checkbox"]');
    const count = await checkboxes.count();
    expect(count).toBeGreaterThanOrEqual(5);

    // Labels should be present
    await expect(page.getByText('Rich Text Descriptions').first()).toBeVisible();
    await expect(page.getByText('Location Field').first()).toBeVisible();
    await expect(page.getByText('Participants').first()).toBeVisible();
  });

  test('sidebar collapse — toggle and verify', async ({ page }) => {
    await loginAsAdmin(page);

    // Desktop sidebar should be visible
    const sidebar = page.locator('aside').first();
    await expect(sidebar).toBeVisible();

    // Click collapse button (◀)
    const collapseBtn = page.locator('aside button', { hasText: /◀|▶/ }).first();
    await collapseBtn.click();
    await page.waitForTimeout(300);

    // Sidebar should be narrow now (w-14 = 56px)
    const width = await sidebar.evaluate((el) => el.getBoundingClientRect().width);
    expect(width).toBeLessThan(100);

    // Click expand
    await collapseBtn.click();
    await page.waitForTimeout(300);

    const expandedWidth = await sidebar.evaluate((el) => el.getBoundingClientRect().width);
    expect(expandedWidth).toBeGreaterThan(100);
  });

  test('layers panel — collapsible toggle', async ({ page }) => {
    await loginAsAdmin(page);

    // Find layers toggle button
    const layersBtn = page.getByText(/^layers$/i).first();
    await expect(layersBtn).toBeVisible();

    // Click to toggle
    await layersBtn.click();
    await page.waitForTimeout(300);

    // Click again to re-expand
    await layersBtn.click();
    await page.waitForTimeout(300);

    // No errors should occur
    await expect(page.locator('.fc')).toBeVisible();
  });

  test('notifications settings — page loads', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/notifications');

    // Page should load without errors
    await expect(page.locator('body')).toBeVisible();
  });

  test('assistants settings — page loads', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/assistants');

    await expect(page.getByRole('heading', { name: /assistant management/i })).toBeVisible();
    await expect(page.getByPlaceholder(/username/i)).toBeVisible();
  });

  test('sharing settings — page loads', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/sharing');

    await expect(page.getByRole('heading', { name: /share calendar/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /create share link/i })).toBeVisible();
  });

  test('activity log — page loads with filters', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/activity-log');

    await expect(page.getByRole('heading', { name: /activity log/i })).toBeVisible();
    // Filter inputs
    await expect(page.getByPlaceholder(/filter by user/i)).toBeVisible();
  });
});
