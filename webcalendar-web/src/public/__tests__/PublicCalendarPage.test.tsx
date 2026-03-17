import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { PublicCalendarPage } from '../PublicCalendarPage';

// Mock FullCalendar - it doesn't render in jsdom
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

// Mock fetch for API calls
const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderPage(username = 'alice') {
  return render(
    <MemoryRouter initialEntries={[`/public/${username}`]}>
      <Routes>
        <Route path="/public/:username" element={<PublicCalendarPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('PublicCalendarPage', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders loading state initially', () => {
    // Mock fetch to never resolve
    mockFetch.mockImplementation(() => new Promise(() => {}));
    renderPage();

    expect(screen.getByText(/loading/i)).toBeInTheDocument();
  });

  it('shows calendar with display name after loading', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { username: 'alice', display_name: 'Alice Smith' },
        ],
        error: null,
      }),
    });

    renderPage('alice');

    await waitFor(() => {
      expect(screen.getByText("Alice Smith's Calendar")).toBeInTheDocument();
    });
  });

  it('shows 404 when user has no public calendar', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [],
        error: null,
      }),
    });

    renderPage('nobody');

    await waitFor(() => {
      expect(screen.getByText(/not found/i)).toBeInTheDocument();
    });
  });

  it('renders FullCalendar component', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { username: 'alice', display_name: 'Alice Smith' },
        ],
        error: null,
      }),
    });

    renderPage('alice');

    await waitFor(() => {
      expect(screen.getByTestId('fullcalendar')).toBeInTheDocument();
    });
  });

  it('does not show login or navigation', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { username: 'alice', display_name: 'Alice Smith' },
        ],
        error: null,
      }),
    });

    renderPage('alice');

    await waitFor(() => {
      expect(screen.getByText("Alice Smith's Calendar")).toBeInTheDocument();
    });

    // No login form or sidebar nav
    expect(screen.queryByLabelText(/username/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/logout/i)).not.toBeInTheDocument();
  });

  it('is mobile responsive with container classes', async () => {
    mockFetch.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        data: [
          { username: 'alice', display_name: 'Alice Smith' },
        ],
        error: null,
      }),
    });

    const { container } = renderPage('alice');

    await waitFor(() => {
      expect(screen.getByText("Alice Smith's Calendar")).toBeInTheDocument();
    });

    // Should have responsive padding
    const wrapper = container.querySelector('.mx-auto');
    expect(wrapper).toBeInTheDocument();
  });
});
