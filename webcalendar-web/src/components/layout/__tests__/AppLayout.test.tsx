import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { AuthContext, type AuthContextValue } from '../../../auth/auth-context';
import { ThemeProvider } from '../../theme/ThemeProvider';
import { AppLayout } from '../AppLayout';

function renderWithAuth(authValue: Partial<AuthContextValue>, initialRoute = '/') {
  const defaultAuth: AuthContextValue = {
    user: null,
    token: null,
    isAuthenticated: false,
    login: () => {},
    logout: () => {},
    ...authValue,
  };

  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={[initialRoute]}>
        <AuthContext.Provider value={defaultAuth}>
          <AppLayout>
            <div data-testid="content">Page Content</div>
          </AppLayout>
        </AuthContext.Provider>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

describe('AppLayout', () => {
  it('renders header with app name', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: false },
    });

    const appNames = screen.getAllByText(/webcalendar/i);
    expect(appNames.length).toBeGreaterThan(0);
  });

  it('renders sidebar with Calendar link', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: false },
    });

    const calLinks = screen.getAllByText(/calendar/i);
    expect(calLinks.length).toBeGreaterThan(0);
  });

  it('renders child content area', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: false },
    });

    expect(screen.getByTestId('content')).toBeInTheDocument();
  });

  it('shows user display name in header', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'john', firstname: 'John', lastname: 'Doe', email: 'j@b.com', is_admin: false },
    });

    expect(screen.getByText(/john/i)).toBeInTheDocument();
  });

  it('shows admin links for admin users', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: true },
    });

    const userLinks = screen.getAllByText(/users/i);
    expect(userLinks.length).toBeGreaterThan(0);
  });

  it('hides admin links for non-admin users', () => {
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'user', firstname: 'Regular', lastname: 'User', email: 'u@b.com', is_admin: false },
    });

    // Should not show "Users" admin link
    const links = screen.queryAllByRole('link');
    const adminLink = links.find((l) => l.textContent?.toLowerCase().includes('users'));
    expect(adminLink).toBeUndefined();
  });

  it('has a logout button', async () => {
    const logoutFn = vi.fn();
    renderWithAuth({
      isAuthenticated: true,
      user: { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'a@b.com', is_admin: false },
      logout: logoutFn,
    });

    const logoutBtn = screen.getByRole('button', { name: /log\s*out|sign\s*out/i });
    expect(logoutBtn).toBeInTheDocument();

    await userEvent.click(logoutBtn);
    expect(logoutFn).toHaveBeenCalled();
  });
});
