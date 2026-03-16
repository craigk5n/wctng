import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { UserManagement } from '../UserManagement';

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <ToastProvider>{children}</ToastProvider>
      </QueryClientProvider>
    );
  };
}

describe('UserManagement', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders the page title', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { total: 0, page: 1, limit: 100 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<UserManagement />, { wrapper: createWrapper() });
    expect(screen.getByText(/user management/i)).toBeInTheDocument();
  });

  it('displays users in a table', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { login: 'admin', firstname: 'Admin', lastname: 'User', email: 'admin@example.com', is_admin: true, enabled: true },
            { login: 'john', firstname: 'John', lastname: 'Doe', email: 'john@example.com', is_admin: false, enabled: true },
          ],
          meta: { total: 2, page: 1, limit: 100 },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('admin')).toBeInTheDocument();
      expect(screen.getByText('john')).toBeInTheDocument();
    });
  });

  it('has a Create User button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { total: 0, page: 1, limit: 100 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<UserManagement />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /create user|new user|add user/i })).toBeInTheDocument();
  });

  it('shows create user form when button clicked', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [], meta: { total: 0, page: 1, limit: 100 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<UserManagement />, { wrapper: createWrapper() });

    await user.click(screen.getByRole('button', { name: /create user|new user|add user/i }));

    expect(screen.getByLabelText(/login/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
  });
});
