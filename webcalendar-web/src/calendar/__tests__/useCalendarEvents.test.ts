import { describe, it, expect, vi, afterEach } from 'vitest';
import { fetchCalendarEvents } from '../useCalendarEvents';

describe('fetchCalendarEvents', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('fetches events for given date range and transforms them', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            {
              id: 1,
              title: 'Test Event',
              description: '',
              start_date: '20260315',
              start_time: '100000',
              end_date: '20260315',
              end_time: '110000',
              duration: 60,
              location: '',
              access: 'P',
              type: 'E',
              created_by: 'admin',
              all_day: false,
            },
          ],
          meta: { total: 1, page: 1, limit: 20 },
          error: null,
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const events = await fetchCalendarEvents('20260301', '20260331');

    expect(events).toHaveLength(1);
    expect(events[0].id).toBe('1');
    expect(events[0].title).toBe('Test Event');
    expect(events[0].start).toBe('2026-03-15T10:00:00');
  });

  it('returns empty array when no events', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({ data: [], meta: { total: 0 }, error: null }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });

  it('returns empty array on API error', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response('Server Error', { status: 500 }),
    );

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });

  it('returns empty array on network error', async () => {
    globalThis.fetch = vi.fn().mockRejectedValue(new Error('Network error'));

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });
});
