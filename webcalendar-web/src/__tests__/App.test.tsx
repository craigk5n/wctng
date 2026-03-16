import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { TOKEN_STORAGE_KEY } from '../api/client';
import { ToastProvider } from '../components/toast/ToastProvider';
import { ThemeProvider } from '../components/theme/ThemeProvider';
import App from '../App';

function renderApp(initialRoute = '/') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <ThemeProvider>
      <QueryClientProvider client={qc}>
        <ToastProvider>
          <MemoryRouter initialEntries={[initialRoute]}>
            <App />
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>
    </ThemeProvider>,
  );
}

describe('App Routing', () => {
  beforeEach(() => {
    localStorage.clear();
  });

  it('redirects unauthenticated users to /login', () => {
    renderApp('/');

    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
  });

  it('renders 404 for unknown routes when authenticated', () => {
    // Set a valid fake token
    const payload = btoa(
      JSON.stringify({
        username: 'admin',
        is_admin: true,
        exp: Math.floor(Date.now() / 1000) + 3600,
        iat: Math.floor(Date.now() / 1000),
      }),
    );
    localStorage.setItem(TOKEN_STORAGE_KEY, `eyJhbGciOiJSUzI1NiJ9.${payload}.sig`);

    renderApp('/some-nonexistent-route');

    expect(screen.getByText('404')).toBeInTheDocument();
  });

  it('renders calendar page at / when authenticated', () => {
    const payload = btoa(
      JSON.stringify({
        username: 'admin',
        is_admin: true,
        exp: Math.floor(Date.now() / 1000) + 3600,
        iat: Math.floor(Date.now() / 1000),
      }),
    );
    localStorage.setItem(TOKEN_STORAGE_KEY, `eyJhbGciOiJSUzI1NiJ9.${payload}.sig`);

    renderApp('/');

    // Should show the layout with calendar content
    const appNames = screen.getAllByText(/webcalendar/i);
    expect(appNames.length).toBeGreaterThan(0);
  });

  it('shows login page at /login', () => {
    renderApp('/login');

    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
  });
});
