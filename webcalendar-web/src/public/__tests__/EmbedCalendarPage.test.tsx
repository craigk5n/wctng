import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { EmbedCalendarPage } from '../EmbedCalendarPage';

vi.mock('@fullcalendar/react', () => ({
  default: ({ events }: { events: unknown[] }) => (
    <div data-testid="fullcalendar">
      <span data-testid="event-count">{Array.isArray(events) ? events.length : 0}</span>
    </div>
  ),
}));

vi.mock('@fullcalendar/daygrid', () => ({ default: {} }));
vi.mock('@fullcalendar/timegrid', () => ({ default: {} }));
vi.mock('@fullcalendar/list', () => ({ default: {} }));

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderPage(token = 'abc-123') {
  return render(
    <MemoryRouter initialEntries={[`/public/embed/${token}`]}>
      <Routes>
        <Route path="/public/embed/:token" element={<EmbedCalendarPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('EmbedCalendarPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders FullCalendar without header/nav', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: [], meta: { total: 0, page: 1, limit: 20 }, error: null }),
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByTestId('fullcalendar')).toBeInTheDocument();
    });

    // No header, no nav, no sidebar
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('shows error for expired token', async () => {
    mockFetch.mockResolvedValue({
      ok: false,
      status: 410,
      json: async () => ({ data: null, error: { code: 410, message: 'Share link has expired' } }),
    });

    renderPage('expired-token');

    await waitFor(() => {
      expect(screen.getByText(/expired/i)).toBeInTheDocument();
    });
  });
});
