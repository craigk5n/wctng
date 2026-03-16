import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';
import { getAdminToken, createTestEvent } from './fixtures/db';

test('calendar displays in month view by default', async ({ page }) => {
  await loginAsAdmin(page);
  await expect(page.locator('.fc-dayGridMonth-view')).toBeVisible();
});

test('calendar shows toolbar with navigation', async ({ page }) => {
  await loginAsAdmin(page);
  await expect(page.locator('.fc-toolbar')).toBeVisible();
  await expect(page.locator('.fc-prev-button')).toBeVisible();
  await expect(page.locator('.fc-next-button')).toBeVisible();
  await expect(page.locator('.fc-today-button')).toBeVisible();
});

test('calendar switch to week view', async ({ page }) => {
  await loginAsAdmin(page);
  await page.locator('.fc-timeGridWeek-button').click();
  await expect(page.locator('.fc-timeGridWeek-view')).toBeVisible();
});

test('calendar switch to day view', async ({ page }) => {
  await loginAsAdmin(page);
  await page.locator('.fc-timeGridDay-button').click();
  await expect(page.locator('.fc-timeGridDay-view')).toBeVisible();
});

test('calendar switch to list view', async ({ page }) => {
  await loginAsAdmin(page);
  await page.locator('.fc-listWeek-button').click();
  await expect(page.locator('.fc-listWeek-view')).toBeVisible();
});

test('calendar navigate previous and next', async ({ page }) => {
  await loginAsAdmin(page);
  const titleBefore = await page.locator('.fc-toolbar-title').textContent();
  await page.locator('.fc-prev-button').click();
  const titleAfter = await page.locator('.fc-toolbar-title').textContent();
  expect(titleBefore).not.toBe(titleAfter);

  await page.locator('.fc-next-button').click();
  const titleRestored = await page.locator('.fc-toolbar-title').textContent();
  expect(titleRestored).toBe(titleBefore);
});

test('calendar today button returns to current date', async ({ page }) => {
  await loginAsAdmin(page);
  await page.locator('.fc-prev-button').click();
  await page.locator('.fc-prev-button').click();
  await page.locator('.fc-today-button').click();
  await expect(page.locator('.fc-day-today')).toBeVisible();
});

test('API-created event appears on calendar', async ({ page, request }) => {
  const token = await getAdminToken(request);

  const today = new Date();
  const dateStr =
    String(today.getFullYear()) +
    String(today.getMonth() + 1).padStart(2, '0') +
    String(today.getDate()).padStart(2, '0');

  const uniqueTitle = 'E2E Cal ' + Date.now();
  await createTestEvent(request, token, {
    title: uniqueTitle,
    start_date: dateStr,
    start_time: '140000',
    duration: 60,
  });

  await loginAsAdmin(page);
  await expect(page.locator('.fc-dayGridMonth-view')).toBeVisible();

  // Switch to day view for today — shows all events for the current day
  await page.locator('.fc-timeGridDay-button').click();
  await expect(page.locator('.fc-timeGridDay-view')).toBeVisible();

  await expect(page.getByText(uniqueTitle)).toBeVisible({ timeout: 10000 });
});
