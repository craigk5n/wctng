import { describe, it, expect, vi, afterEach } from 'vitest';

// Mock the api client module
vi.mock('../../api/client', () => ({
  api: {
    GET: vi.fn(),
  },
  TOKEN_STORAGE_KEY: 'wctng_token',
  createApiClient: vi.fn(),
}));

import { fetchCalendarEvents } from '../useCalendarEvents';
import { api } from '../../api/client';

const mockGet = vi.mocked(api.GET);

describe('fetchCalendarEvents', () => {
  afterEach(() => {
    vi.clearAllMocks();
  });

  it('fetches events for given date range and transforms them', async () => {
    mockGet.mockResolvedValue({
      data: {
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
      },
      error: undefined,
      response: new Response(),
    } as never);

    const events = await fetchCalendarEvents('20260301', '20260331');

    expect(events).toHaveLength(1);
    expect(events[0].id).toBe('1');
    expect(events[0].title).toBe('Test Event');
    expect(events[0].start).toBe('2026-03-15T10:00:00');
  });

  it('returns empty array when no events', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [],
        meta: { total: 0, page: 1, limit: 20 },
        error: null,
      },
      error: undefined,
      response: new Response(),
    } as never);

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });

  it('returns empty array on API error', async () => {
    mockGet.mockResolvedValue({
      data: undefined,
      error: { code: 500, message: 'Server error' },
      response: new Response(),
    } as never);

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });

  it('returns empty array on network error', async () => {
    mockGet.mockRejectedValue(new Error('Network error'));

    const events = await fetchCalendarEvents('20260301', '20260331');
    expect(events).toEqual([]);
  });
});
