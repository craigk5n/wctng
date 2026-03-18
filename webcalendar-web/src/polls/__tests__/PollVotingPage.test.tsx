import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { PollVotingPage } from '../PollVotingPage';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'alice', is_admin: false } }),
}));

function renderPage(pollId = '1') {
  return render(
    <MemoryRouter initialEntries={[`/polls/${pollId}`]}>
      <Routes>
        <Route path="/polls/:id" element={<PollVotingPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('PollVotingPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders poll title', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          id: 1, creator: 'admin', title: 'Team Standup', description: '', status: 'open', created_at: '2026-04-01',
          options: [
            { id: 10, start: '2026-06-01 10:00:00', end: '2026-06-01 11:00:00', votes: [], yes_count: 0 },
            { id: 11, start: '2026-06-02 14:00:00', end: '2026-06-02 15:00:00', votes: [], yes_count: 0 },
          ],
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Team Standup')).toBeInTheDocument();
    });
  });

  it('shows time options', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          id: 1, creator: 'admin', title: 'Meeting', description: '', status: 'open', created_at: '2026-04-01',
          options: [
            { id: 10, start: '2026-06-01 10:00:00', end: '2026-06-01 11:00:00', votes: [], yes_count: 0 },
            { id: 11, start: '2026-06-02 14:00:00', end: '2026-06-02 15:00:00', votes: [{ voter: 'bob', vote: 'yes' }], yes_count: 1 },
          ],
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      // Should show vote count
      expect(screen.getByText('1')).toBeInTheDocument();
    });
  });

  it('shows closed status for finalized poll', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true, status: 200,
      json: async () => ({
        data: {
          id: 1, creator: 'admin', title: 'Done Poll', description: '', status: 'closed', created_at: '2026-04-01',
          options: [{ id: 10, start: '2026-06-01 10:00:00', end: '2026-06-01 11:00:00', votes: [], yes_count: 0 }],
        },
        error: null,
      }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText(/closed|finalized/i)).toBeInTheDocument();
    });
  });

  it('shows 404 for missing poll', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: false, status: 404,
      json: async () => ({ data: null, error: { code: 404, message: 'Not found' } }),
    });

    renderPage('999');

    await waitFor(() => {
      expect(screen.getByText(/not found/i)).toBeInTheDocument();
    });
  });
});
