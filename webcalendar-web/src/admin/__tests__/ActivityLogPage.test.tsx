import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { ActivityLogPage } from '../ActivityLogPage';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

describe('ActivityLogPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders heading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [],
        meta: { total: 0, page: 1, limit: 50 },
        error: null,
      }),
    });

    render(<ActivityLogPage />);
    expect(screen.getByRole('heading', { name: /activity log/i })).toBeInTheDocument();
  });

  it('shows log entries in table', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 1, entry_id: 10, user: 'alice', user_cal: null, action: 'create', action_code: 'C', timestamp: '2026-04-01T10:00:00', text: 'Created event: Meeting' },
          { id: 2, entry_id: 11, user: 'bob', user_cal: null, action: 'update', action_code: 'U', timestamp: '2026-04-01T11:00:00', text: 'Updated event: Standup' },
        ],
        meta: { total: 2, page: 1, limit: 50 },
        error: null,
      }),
    });

    render(<ActivityLogPage />);

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
      expect(screen.getByText('bob')).toBeInTheDocument();
      // Action badges
      expect(screen.getAllByText(/create/i).length).toBeGreaterThan(0);
      expect(screen.getAllByText(/update/i).length).toBeGreaterThan(0);
    });
  });

  it('shows empty state when no entries', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [],
        meta: { total: 0, page: 1, limit: 50 },
        error: null,
      }),
    });

    render(<ActivityLogPage />);

    await waitFor(() => {
      expect(screen.getByText(/no activity/i)).toBeInTheDocument();
    });
  });

  it('shows entry detail text', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { id: 1, entry_id: 10, user: 'alice', user_cal: null, action: 'create', action_code: 'C', timestamp: '2026-04-01T10:00:00', text: 'Created event: Important Meeting' },
        ],
        meta: { total: 1, page: 1, limit: 50 },
        error: null,
      }),
    });

    render(<ActivityLogPage />);

    await waitFor(() => {
      expect(screen.getByText(/Important Meeting/)).toBeInTheDocument();
    });
  });
});
