import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ControlLoginPage } from '../ControlLoginPage';
import { ControlProtectedRoute } from '../ControlProtectedRoute';
import { ControlLayout } from '../ControlLayout';
import { clearControlAuth, setControlAuth } from '../control-auth';

describe('Control Plane Dashboard', () => {
  const originalFetch = globalThis.fetch;

  beforeEach(() => {
    clearControlAuth();
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    clearControlAuth();
  });

  it('renders control login page', () => {
    render(
      <MemoryRouter>
        <ControlLoginPage />
      </MemoryRouter>,
    );

    expect(screen.getByText(/control panel/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in/i })).toBeInTheDocument();
  });

  it('shows error on failed login', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ error: { code: 401, message: 'Invalid credentials' } }), {
        status: 401,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(
      <MemoryRouter>
        <ControlLoginPage />
      </MemoryRouter>,
    );

    await user.type(screen.getByLabelText(/username/i), 'bad');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: /sign in/i }));

    await waitFor(() => {
      expect(screen.getByText(/invalid credentials/i)).toBeInTheDocument();
    });
  });

  it('redirects to login when not authenticated', () => {
    render(
      <MemoryRouter initialEntries={['/control']}>
        <ControlProtectedRoute>
          <div>Protected Content</div>
        </ControlProtectedRoute>
      </MemoryRouter>,
    );

    // Should not show protected content
    expect(screen.queryByText('Protected Content')).not.toBeInTheDocument();
  });

  it('shows protected content when authenticated', () => {
    setControlAuth('fake-token', { username: 'superadmin', role: 'super_admin' });

    render(
      <MemoryRouter initialEntries={['/control']}>
        <ControlProtectedRoute>
          <div>Protected Content</div>
        </ControlProtectedRoute>
      </MemoryRouter>,
    );

    expect(screen.getByText('Protected Content')).toBeInTheDocument();
  });

  it('renders dashboard layout with sidebar navigation', () => {
    setControlAuth('fake-token', { username: 'superadmin', role: 'super_admin' });

    render(
      <MemoryRouter initialEntries={['/control']}>
        <ControlLayout>
          <div>Dashboard Content</div>
        </ControlLayout>
      </MemoryRouter>,
    );

    expect(screen.getByText('Control Panel')).toBeInTheDocument();
    expect(screen.getByText('Tenants')).toBeInTheDocument();
    expect(screen.getByText('Stats')).toBeInTheDocument();
    expect(screen.getByText('superadmin')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /log out/i })).toBeInTheDocument();
  });
});
