import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CalendarPage } from '../CalendarPage';
import { ToastProvider } from '../../components/toast/ToastProvider';

// Mock FullCalendar
vi.mock('@fullcalendar/react', async () => {
  const React = await import('react');
  return { default: React.forwardRef(() => React.createElement('div', { 'data-testid': 'fc' })) };
});
vi.mock('@fullcalendar/daygrid', () => ({ default: {} }));
vi.mock('@fullcalendar/timegrid', () => ({ default: {} }));
vi.mock('@fullcalendar/list', () => ({ default: {} }));
vi.mock('@fullcalendar/interaction', () => ({ default: {} }));
vi.mock('@fullcalendar/multimonth', () => ({ default: {} }));

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({
    user: { login: 'admin', is_admin: true },
    logout: vi.fn(),
  }),
}));

vi.mock('../../hooks/useMercure', () => ({
  useMercure: () => null,
}));

vi.mock('../../hooks/useTenant', () => ({
  useTenant: () => ({ tenant: null }),
}));

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function renderCalendar() {
  mockFetch.mockResolvedValue({
    ok: true, status: 200,
    json: async () => ({ data: [], error: null, meta: null }),
  });

  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <ToastProvider>
          <CalendarPage />
        </ToastProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('CalendarPage Toolbar', () => {
  beforeEach(() => {
    mockFetch.mockReset();
  });

  it('renders print button', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getByTitle(/print/i)).toBeInTheDocument();
    });
  });

  it('renders import button', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getByText(/import/i)).toBeInTheDocument();
    });
  });

  it('renders schedule meeting button', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getByText(/schedule meeting/i)).toBeInTheDocument();
    });
  });

  it('renders new event button', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getByText(/new event/i)).toBeInTheDocument();
    });
  });

  it('renders quick-add input', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getByPlaceholderText(/quick add/i)).toBeInTheDocument();
    });
  });

  it('renders layers toggle', async () => {
    renderCalendar();
    await waitFor(() => {
      expect(screen.getAllByText(/layers/i).length).toBeGreaterThan(0);
    });
  });
});
