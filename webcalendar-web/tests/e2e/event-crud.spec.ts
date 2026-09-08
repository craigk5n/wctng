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
    await page.locator('#event-title').fill(uniqueTitle);

    // Set date to today
    const today = new Date();
    const dateValue = today.toISOString().slice(0, 10); // YYYY-MM-DD
    // Target the field by id: /date/i also matches any calendar event whose
    // aria-label contains "Updated", which this spec itself creates.
    await page.locator('#event-date').fill(dateValue);

    // Set time
    // 19:00: specs share today's calendar and none clean up, so an event at a
    // time another spec uses (SearchableEvent at 15:00, E2E LogCheck at 16:00)
    // overlaps in the time grid and intercepts the click on this one.
    await page.locator('#event-time').fill('19:00');

    // Submit
    // Exact match: QuickAddInput's sparkle button is labelled "Parse and
    // create event", so a loose /create event/i matches two buttons.
    await page.getByRole('button', { name: 'Create Event', exact: true }).click();

    // Wait for dialog to close and event to appear
    await page.waitForTimeout(1000);

    // Switch to day view for today to see the event
    await page.locator('.fc-timeGridDay-button').click();

    // Verify event appears on the calendar grid
    // FullCalendar can render one event in more than one region, so assert
    // on the first match rather than requiring a single node.
    await expect(page.locator('.fc-event-title', { hasText: uniqueTitle }).first()).toBeVisible({ timeout: 10000 });

    // Click on the event to open detail dialog
    await page.locator('.fc-event-title', { hasText: uniqueTitle }).first().click();
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
    await expect(page.locator('.fc-event-title', { hasText: originalTitle }).first()).toBeVisible({ timeout: 10000 });

    // Click event to open detail
    await page.locator('.fc-event', { hasText: originalTitle }).click({ force: true });

    // Click Edit button
    await page.getByRole('button', { name: /^edit$/i }).click();

    // Change title
    const updatedTitle = 'E2E Updated ' + Date.now();
    const titleInput = page.locator('#event-title');
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
      start_time: '200000', // see the note on 19:00 above — avoid shared slots
      duration: 30,
    });

    await loginAsAdmin(page);
    await page.locator('.fc-timeGridDay-button').click();
    await expect(page.getByText(title).first()).toBeVisible({ timeout: 10000 });

    // Open detail and delete
    await page.getByText(title).first().click();
    await page.getByRole('button', { name: /delete/i }).click();

    // Confirm. Deletion is a soft cancel, so ConfirmDeleteDialog labels the
    // confirm button "Cancel Event" (or "Cancel All Occurrences" / "Decline"),
    // never Confirm/Yes/Delete.
    await page
      .getByRole('button', { name: /^(cancel event|cancel all occurrences|decline)$/i })
      .click();
    await page.waitForTimeout(1000);

    // Event should be gone
    // Gone entirely: assert the count drops to zero rather than calling
    // not.toBeVisible on a locator that may match several nodes.
    await expect(page.getByText(title)).toHaveCount(0, { timeout: 5000 });
  });
});
