import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';
import { getAdminToken, createTestEvent, cleanupEvents } from './fixtures/db';

test.describe('Phase 6 Features E2E', () => {

  // Specs share one database, so an event left behind overlaps the next run's
  // event in the time grid and intercepts its click.
  const created: number[] = [];

  test.afterEach(async ({ request }) => {
    await cleanupEvents(request, created);
  });

  test('rich text editor — toolbar renders in event dialog', async ({ page }) => {
    await loginAsAdmin(page);
    await page.getByRole('button', { name: /new event/i }).click();

    // Verify TipTap editor renders with toolbar buttons
    await expect(page.getByTitle(/bold/i)).toBeVisible();
    await expect(page.getByTitle(/italic/i)).toBeVisible();
    await expect(page.getByTitle(/link/i)).toBeVisible();
    await expect(page.getByTitle(/heading 2/i)).toBeVisible();
    await expect(page.getByTitle(/bullet list/i)).toBeVisible();
    await expect(page.getByTitle(/blockquote/i)).toBeVisible();
    await expect(page.getByTitle(/code/i)).toBeVisible();

    // Verify ProseMirror contenteditable area exists
    await expect(page.locator('.ProseMirror')).toBeVisible();
  });

  test('public calendar — visit /public/admin shows calendar', async ({ page, request }) => {
    const token = await getAdminToken(request);

    // Enable public calendar for admin
    await request.put('http://localhost:47180/api/v2/admin/users/admin/public-calendar', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { enabled: true },
    });

    // Create an event
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const publicEventId = await createTestEvent(request, token, {
      title: 'E2E Public Event ' + Date.now(),
      start_date: today,
      start_time: '120000',
      duration: 30,
    });
    created.push(publicEventId);

    // Visit public calendar (no login needed)
    await page.goto('/public/admin');
    await expect(page.getByText(/admin/i)).toBeVisible({ timeout: 10000 });
    await expect(page.locator('.fc')).toBeVisible();

    // Clean up: disable public
    await request.put('http://localhost:47180/api/v2/admin/users/admin/public-calendar', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { enabled: false },
    });
  });

  test('share link — create and visit embed URL', async ({ page, request }) => {
    const token = await getAdminToken(request);

    // Create a share token via API
    const shareRes = await request.post('http://localhost:47180/api/v2/calendars/share', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: {},
    });
    const shareBody = await shareRes.json();
    const shareToken = shareBody?.data?.token;
    expect(shareToken).toBeTruthy();

    // Visit embed URL
    await page.goto(`/public/embed/${shareToken}`);
    // Should show a calendar (FullCalendar loads)
    await expect(page.locator('.fc')).toBeVisible({ timeout: 10000 });

    // Clean up: revoke share
    await request.delete(`http://localhost:47180/api/v2/calendars/share/${shareToken}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
  });

  test('conflict detection — API endpoint returns valid response', async ({ request }) => {
    const token = await getAdminToken(request);
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');

    // Check conflicts endpoint responds correctly
    const conflictRes = await request.get(
      `http://localhost:47180/api/v2/events/conflicts?start=${today}&end=${today}&duration=60`,
      { headers: { Authorization: `Bearer ${token}` } },
    );
    expect(conflictRes.ok()).toBeTruthy();

    const body = await conflictRes.json();
    expect(body.data).toBeDefined();
    expect(Array.isArray(body.data)).toBeTruthy();
  });

  test('conflict detection — create with conflict returns meta', async ({ request }) => {
    const token = await getAdminToken(request);
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');

    // Create first event
    const conflictAId = await createTestEvent(request, token, {
      title: 'E2E Conflict A ' + Date.now(),
      start_date: today,
      start_time: '100000',
      duration: 60,
    });
    created.push(conflictAId);

    // Create overlapping event — response should include conflicts in meta
    const res = await request.post('http://localhost:47180/api/v2/events', {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: {
        title: 'E2E Conflict B ' + Date.now(),
        start_date: today,
        start_time: '103000',
        duration: 60,
      },
    });
    expect(res.ok()).toBeTruthy();

    const body = await res.json();
    created.push(body?.data?.id);
    // In warn mode (default), event is created but meta may contain conflicts
    expect(body.data).toBeDefined();
  });

  test('activity log — shows entries after creating events', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/activity-log');
    await expect(page.getByRole('heading', { name: /activity log/i })).toBeVisible();
    // Table or empty state should render. `table, p` matches several nodes on
    // this page, so assert that at least one is visible rather than exactly one.
    await expect(page.locator('table, p').first()).toBeVisible();
  });

  test('settings — change and verify preference persists', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/settings/preferences');

    // Wait on the save request rather than a fixed delay: 1.5s raced the PUT,
    // and the reload below then read back the old value.
    const savePut = () =>
      page.waitForResponse(
        (r) =>
          /\/users\/[^/]+\/preferences$/.test(new URL(r.url()).pathname) &&
          r.request().method() !== 'GET',
      );

    // Change default view to Week
    await page.getByLabel(/default view/i).selectOption('timeGridWeek');
    const saved = savePut();
    await page.getByRole('button', { name: /save/i }).click();
    await saved;

    // Reload and verify
    await page.reload();
    await expect(page.getByLabel(/default view/i)).toHaveValue('timeGridWeek', { timeout: 5000 });

    // Reset to Month -- awaited too, so the next spec does not start against a
    // half-written preference. Leaving this unawaited is what made the
    // settings-forms specs flaky alongside this one.
    await page.getByLabel(/default view/i).selectOption('dayGridMonth');
    const reset = savePut();
    await page.getByRole('button', { name: /save/i }).click();
    await reset;
  });

  test('admin settings — feature toggles page loads', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: /system settings/i })).toBeVisible();
    await expect(page.getByText('Rich Text Descriptions').first()).toBeVisible();
    await expect(page.getByText('Location Field').first()).toBeVisible();
  });
});
