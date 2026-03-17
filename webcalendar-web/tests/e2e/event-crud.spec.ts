import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';
import { getAdminToken, createTestEvent } from './fixtures/db';

test.describe('Event CRUD', () => {
  test('create event via dialog → verify on calendar → open detail', async ({ page, request }) => {
    await loginAsAdmin(page);

    // Click "New Event" button
    await page.getByRole('button', { name: /new event/i }).click();

    // Fill the form
    const uniqueTitle = 'E2E Create ' + Date.now();
    await page.getByLabel(/title/i).fill(uniqueTitle);

    // Set date to today
    const today = new Date();
    const dateValue = today.toISOString().slice(0, 10); // YYYY-MM-DD
    await page.getByLabel(/date/i).fill(dateValue);

    // Set time
    await page.getByLabel(/start time/i).fill('15:00');

    // Submit
    await page.getByRole('button', { name: /create event/i }).click();

    // Wait for dialog to close and event to appear
    await page.waitForTimeout(1000);

    // Switch to day view for today to see the event
    await page.locator('.fc-timeGridDay-button').click();

    // Verify event appears on the calendar grid
    await expect(page.locator('.fc-event-title', { hasText: uniqueTitle })).toBeVisible({ timeout: 10000 });

    // Click on the event to open detail dialog
    await page.locator('.fc-event-title', { hasText: uniqueTitle }).click();
    await expect(page.getByRole('heading', { name: uniqueTitle })).toBeVisible();
  });

  test('edit event via detail dialog', async ({ page, request }) => {
    const token = await getAdminToken(request);
    const today = new Date();
    const dateStr = today.toISOString().slice(0, 10).replace(/-/g, '');

    const originalTitle = 'E2E Edit ' + Date.now();
    await createTestEvent(request, token, {
      title: originalTitle,
      start_date: dateStr,
      start_time: '110000',
      duration: 30,
    });

    await loginAsAdmin(page);

    // Navigate to day view
    await page.locator('.fc-timeGridDay-button').click();
    await expect(page.locator('.fc-event-title', { hasText: originalTitle })).toBeVisible({ timeout: 10000 });

    // Click event to open detail
    await page.locator('.fc-event', { hasText: originalTitle }).click({ force: true });

    // Click Edit button
    await page.getByRole('button', { name: /^edit$/i }).click();

    // Change title
    const updatedTitle = 'E2E Updated ' + Date.now();
    const titleInput = page.getByLabel(/title/i);
    await titleInput.clear();
    await titleInput.fill(updatedTitle);

    // Save
    await page.getByRole('button', { name: /save changes/i }).click();
    await page.waitForTimeout(1000);

    // Verify updated title appears
    await expect(page.getByText(updatedTitle)).toBeVisible({ timeout: 10000 });
  });

  test('delete event removes it from calendar', async ({ page, request }) => {
    const token = await getAdminToken(request);
    const today = new Date();
    const dateStr = today.toISOString().slice(0, 10).replace(/-/g, '');

    const title = 'E2E Delete ' + Date.now();
    await createTestEvent(request, token, {
      title,
      start_date: dateStr,
      start_time: '160000',
      duration: 30,
    });

    await loginAsAdmin(page);
    await page.locator('.fc-timeGridDay-button').click();
    await expect(page.getByText(title)).toBeVisible({ timeout: 10000 });

    // Open detail and delete
    await page.getByText(title).click();
    await page.getByRole('button', { name: /delete/i }).click();

    // Confirm deletion
    await page.getByRole('button', { name: /confirm|yes|delete/i }).click();
    await page.waitForTimeout(1000);

    // Event should be gone
    await expect(page.getByText(title)).not.toBeVisible({ timeout: 5000 });
  });
});
