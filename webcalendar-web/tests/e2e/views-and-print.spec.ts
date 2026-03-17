import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';

test.describe('Calendar Views & Print', () => {
  test('switch to year view', async ({ page }) => {
    await loginAsAdmin(page);

    // Click the year view button
    await page.locator('.fc-multiMonthYear-button').click();
    await expect(page.locator('.fc-multiMonthYear-view, .fc-multimonth')).toBeVisible({ timeout: 5000 });
  });

  test('navigate all views: month → week → day → year → list', async ({ page }) => {
    await loginAsAdmin(page);

    // Month (default)
    await expect(page.locator('.fc-dayGridMonth-view')).toBeVisible();

    // Week
    await page.locator('.fc-timeGridWeek-button').click();
    await expect(page.locator('.fc-timeGridWeek-view')).toBeVisible();

    // Day
    await page.locator('.fc-timeGridDay-button').click();
    await expect(page.locator('.fc-timeGridDay-view')).toBeVisible();

    // Year
    await page.locator('.fc-multiMonthYear-button').click();
    await expect(page.locator('.fc-multiMonthYear-view, .fc-multimonth')).toBeVisible({ timeout: 5000 });

    // List
    await page.locator('.fc-listWeek-button').click();
    await expect(page.locator('.fc-listWeek-view, .fc-list')).toBeVisible();
  });

  test('print button exists in toolbar', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page.getByTitle(/print/i)).toBeVisible();
  });
});
