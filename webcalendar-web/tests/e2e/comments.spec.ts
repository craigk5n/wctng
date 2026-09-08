import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './fixtures/auth';
import { cleanupEvents } from './fixtures/db';

const BASE = 'http://localhost:47180';

async function getToken(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post(`${BASE}/api/v2/auth/login`, {
    data: { username: 'admin', password: 'admin' },
  });
  const body = await response.json();
  return body?.data?.token ?? '';
}

test.describe('Event Comments E2E', () => {

  // Specs share one database, so an event left behind overlaps the next run's
  // event in the time grid and intercepts its click.
  const created: number[] = [];

  test.afterEach(async ({ request }) => {
    await cleanupEvents(request, created);
  });

  test('post a comment on an event and see it', async ({ page }) => {
    await loginAsAdmin(page);

    // Create event via API
    const token = await getToken(page);
    const title = `CommentEvt-${Date.now().toString().slice(-6)}`;
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const createRes = await page.request.post(`${BASE}/api/v2/events`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { title, start_date: today, start_time: '100000', duration: 60 },
    });
    const eventId = (await createRes.json())?.data?.id;
    created.push(eventId);
    expect(eventId).toBeTruthy();

    // Post a comment via API
    const commentText = `E2E comment ${Date.now()}`;
    const commentRes = await page.request.post(`${BASE}/api/v2/events/${eventId}/comments`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { text: commentText },
    });
    expect(commentRes.ok()).toBe(true);

    // Verify comment exists via API
    const listRes = await page.request.get(`${BASE}/api/v2/events/${eventId}/comments`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const comments = (await listRes.json())?.data;
    expect(comments?.length).toBeGreaterThan(0);
    expect(comments?.[comments.length - 1]?.text).toBe(commentText);
  });

  test('delete own comment via API', async ({ page }) => {
    const token = await getToken(page);

    // Create event
    const today = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const createRes = await page.request.post(`${BASE}/api/v2/events`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { title: 'DelComment', start_date: today, start_time: '110000', duration: 60 },
    });
    const eventId = (await createRes.json())?.data?.id;
    created.push(eventId);

    // Post comment
    const postRes = await page.request.post(`${BASE}/api/v2/events/${eventId}/comments`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { text: 'Delete me' },
    });
    const commentId = (await postRes.json())?.data?.id;

    // Delete
    const delRes = await page.request.delete(`${BASE}/api/v2/events/${eventId}/comments/${commentId}`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(delRes.status()).toBe(204);

    // Verify gone
    const listRes = await page.request.get(`${BASE}/api/v2/events/${eventId}/comments`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    const comments = (await listRes.json())?.data;
    const found = comments?.find((c: { id: number }) => c.id === commentId);
    expect(found).toBeUndefined();
  });

  test('empty comment rejected', async ({ page }) => {
    const token = await getToken(page);
    const res = await page.request.post(`${BASE}/api/v2/events/1/comments`, {
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      data: { text: '' },
    });
    expect(res.status()).toBe(400);
  });
});
