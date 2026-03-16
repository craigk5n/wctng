import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthContext, type AuthContextValue } from '../../auth/auth-context';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { CalendarPage } from '../CalendarPage';

// Mock FullCalendar
vi.mock('@fullcalendar/react', () => ({
  default: function MockFullCalendar() {
    return <div data-testid="fullcalendar">FullCalendar Mock</div>;
  },
}));

function renderCalendarPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const auth: AuthContextValue = {
    user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: true },
    token: 'fake-token',
    isAuthenticated: true,
    login: () => {},
    logout: () => {},
  };

  return render(
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <MemoryRouter>
          <AuthContext.Provider value={auth}>
            <CalendarPage />
          </AuthContext.Provider>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

describe('CalendarPage', () => {
  it('renders the FullCalendar component', () => {
    renderCalendarPage();
    expect(screen.getByTestId('fullcalendar')).toBeInTheDocument();
  });

  it('renders a page container', () => {
    const { container } = renderCalendarPage();
    expect(container.firstChild).toBeTruthy();
  });
});
