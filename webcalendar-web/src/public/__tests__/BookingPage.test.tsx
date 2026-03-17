import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { BookingPage } from '../BookingPage';

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderPage(username = 'alice') {
  return render(
    <MemoryRouter initialEntries={[`/book/${username}`]}>
      <Routes>
        <Route path="/book/:username" element={<BookingPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('BookingPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders booking heading with username', () => {
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({ data: { date: '2026-04-01', username: 'alice', slots: [] }, error: null }),
    });

    renderPage('alice');
    expect(screen.getByText(/book.*alice/i)).toBeInTheDocument();
  });

  it('shows available time slots', async () => {
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({
        data: {
          date: '2026-04-01',
          username: 'alice',
          slots: [
            { start: '09:00', end: '09:30' },
            { start: '10:00', end: '10:30' },
            { start: '14:00', end: '14:30' },
          ],
        },
        error: null,
      }),
    });

    renderPage('alice');

    await waitFor(() => {
      expect(screen.getByText('09:00')).toBeInTheDocument();
      expect(screen.getByText('10:00')).toBeInTheDocument();
      expect(screen.getByText('14:00')).toBeInTheDocument();
    });
  });

  it('shows booking form fields', () => {
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({ data: { date: '2026-04-01', username: 'alice', slots: [] }, error: null }),
    });

    renderPage('alice');

    expect(screen.getByPlaceholderText(/your name/i)).toBeInTheDocument();
    expect(screen.getByPlaceholderText(/email/i)).toBeInTheDocument();
  });

  it('shows no slots message when empty', async () => {
    mockFetch.mockResolvedValue({
      ok: true, status: 200,
      json: async () => ({
        data: { date: '2026-04-01', username: 'alice', slots: [] },
        error: null,
      }),
    });

    renderPage('alice');

    await waitFor(() => {
      expect(screen.getByText(/no available/i)).toBeInTheDocument();
    });
  });
});
