import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';

// Helper: get JWT token for direct API calls
async function getToken(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post('http://localhost:47180/api/v2/auth/login', {
    data: { username: 'admin', password: 'admin' },
  });
  const body = await response.json();
  return body?.data?.token ?? '';
}

test.describe('Admin CRUD E2E', () => {

  // --- User Management ---

  test('create user via + New User button', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/users');
    await expect(page.getByRole('heading', { name: /user management/i })).toBeVisible();

    await page.getByRole('button', { name: /new user/i }).click();

    const suffix = Date.now().toString().slice(-6);
    await page.getByLabel(/login|username/i).fill(`e2euser${suffix}`);
    await page.getByLabel(/first name/i).fill('E2E');
    await page.getByLabel(/last name/i).fill('Tester');
    await page.getByLabel(/email/i).fill(`e2e${suffix}@test.com`);
    await page.getByLabel(/password/i).first().fill('Test1234!');

    await page.getByRole('button', { name: /create user/i }).click();
    await page.waitForTimeout(2000); // Wait for toast to fade

    await expect(page.getByRole('cell', { name: new RegExp(`e2euser${suffix}`) })).toBeVisible({ timeout: 5000 });
  });

  // --- Category Management ---

  test('create category via + New Category', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/categories');
    await expect(page.getByRole('heading', { name: /categor/i })).toBeVisible();

    await page.getByRole('button', { name: /new category/i }).click();

    const catName = `E2ECat-${Date.now().toString().slice(-6)}`;
    await page.getByLabel(/name/i).fill(catName);
    await page.getByRole('button', { name: /^create$|^save$/i }).click();

    await expect(page.getByText(catName)).toBeVisible({ timeout: 5000 });
  });

  test('delete category removes it', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/categories');

    // Create via UI first
    const catName = `DelCat-${Date.now().toString().slice(-6)}`;
    await page.getByRole('button', { name: /new category/i }).click();
    await page.getByLabel(/name/i).fill(catName);
    await page.getByRole('button', { name: /^create$|^save$/i }).click();
    await page.waitForTimeout(3000);

    // Reload to clear any toasts
    await page.reload();
    await expect(page.getByText(catName, { exact: true })).toBeVisible({ timeout: 5000 });

    // Use API to delete — more reliable than DOM traversal with 300+ categories
    const token = await getToken(page);
    // Get category ID
    const listRes = await page.request.get('http://localhost:47180/api/v2/categories', {
      headers: { Authorization: `Bearer ${token}` },
    });
    const cats = (await listRes.json())?.data as Array<{ id: number; name: string }> | undefined;
    const cat = cats?.find((c) => c.name === catName);
    expect(cat).toBeTruthy();

    await page.request.delete(`http://localhost:47180/api/v2/categories/${cat!.id}`, {
      headers: { Authorization: `Bearer ${token}` },
    });

    await page.reload();
    await expect(page.getByText(catName)).not.toBeVisible({ timeout: 5000 });
  });

  // --- Group Management ---

  test('create group via + New Group', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/groups');
    await expect(page.getByRole('heading', { name: /group/i })).toBeVisible();

    await page.getByRole('button', { name: /new group/i }).click();

    const groupName = `E2ETeam-${Date.now().toString().slice(-6)}`;
    await page.getByLabel(/name/i).fill(groupName);
    await page.getByRole('button', { name: /^create$|^save$/i }).click();

    await page.waitForTimeout(2000);
    await expect(page.getByText(groupName, { exact: true })).toBeVisible({ timeout: 5000 });
  });

  // --- Dashboard ---

  test('dashboard shows stat cards and system info', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/dashboard');
    await expect(page.getByText('System Dashboard')).toBeVisible();
    await expect(page.getByText('Total Users')).toBeVisible();
    await expect(page.getByText('Total Events')).toBeVisible();
    await expect(page.getByText('Upcoming (7d)')).toBeVisible();
    await expect(page.getByText('PHP Version')).toBeVisible();
    // Should show actual PHP version
    await expect(page.getByText(/8\.\d/)).toBeVisible({ timeout: 5000 });
  });

  // --- Backup page loads ---

  test('backup page renders with create button and restore form', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/backup');
    await expect(page.getByText('Database Backup & Restore')).toBeVisible();
    await expect(page.getByRole('button', { name: /create backup/i })).toBeVisible();
    await expect(page.getByText('Restore from Backup')).toBeVisible();
    await expect(page.getByLabel(/backup file/i)).toBeVisible();
    await expect(page.getByLabel(/type restore/i)).toBeVisible();
  });

  // --- Custom HTML ---

  test('custom HTML save and preview', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/custom-html');
    await expect(page.getByText(/Custom Header/)).toBeVisible();

    const headerTextarea = page.getByLabel(/header html/i);
    await headerTextarea.clear();
    await headerTextarea.fill('<div class="e2e-header">E2E Header Test</div>');

    await page.getByRole('button', { name: /save/i }).click();
    await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 5000 });

    await page.getByRole('button', { name: /show preview/i }).click();
    await expect(page.getByTestId('custom-html-preview')).toBeVisible();
    await expect(page.getByTestId('custom-html-preview').getByText('E2E Header Test')).toBeVisible();

    // Clean up
    await headerTextarea.clear();
    await page.getByRole('button', { name: /save/i }).click();
  });

  // --- Feature Flags ---

  test('feature flag toggle persists on reload', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/settings');
    await expect(page.getByText('System Settings')).toBeVisible();

    // Find the Journals toggle
    const journalsLabel = page.locator('label', { hasText: 'Journals' });
    const journalsCheckbox = journalsLabel.locator('input[type="checkbox"]');
    const wasChecked = await journalsCheckbox.isChecked();

    await journalsCheckbox.click();
    await page.waitForTimeout(1500);

    await page.reload();
    await expect(page.getByText('System Settings')).toBeVisible();
    const newState = await journalsLabel.locator('input[type="checkbox"]').isChecked();
    expect(newState).toBe(!wasChecked);

    // Restore original state
    await journalsLabel.locator('input[type="checkbox"]').click();
    await page.waitForTimeout(1000);
  });

  test('disable Tasks hides sidebar link', async ({ page }) => {
    await loginAsAdmin(page);
    const token = await getToken(page);

    // Disable Tasks
    await page.request.put('http://localhost:47180/api/v2/admin/config', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { DISABLE_TASKS: 'Y' },
    });

    await page.goto('/');
    await page.waitForSelector('.fc');

    // Tasks should NOT be in sidebar
    await expect(page.locator('nav a', { hasText: 'Tasks' })).not.toBeVisible({ timeout: 3000 });

    // Re-enable
    await page.request.put('http://localhost:47180/api/v2/admin/config', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { DISABLE_TASKS: 'N' },
    });
  });

  // --- Activity Log ---

  test('activity log shows entries after event creation', async ({ page }) => {
    await loginAsAdmin(page);

    // Create event via API to generate log entry
    const token = await getToken(page);
    await page.request.post('http://localhost:47180/api/v2/events', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: {
        title: `LogTest-${Date.now()}`,
        start_date: '20260601',
        start_time: '100000',
        duration: 60,
      },
    });

    await page.goto('/admin/activity-log');
    await expect(page.getByRole('heading', { name: /activity log/i })).toBeVisible();

    // Should have at least one row in the table
    await expect(page.locator('table tbody tr').first()).toBeVisible({ timeout: 5000 });
  });
});
