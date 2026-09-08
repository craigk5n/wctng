import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthContext, type AuthContextValue } from '../../auth/auth-context';
import { UserManagement } from '../UserManagement';

const mockAuthValue: AuthContextValue = {
  user: {
    login: 'admin',
    firstname: 'Admin',
    lastname: 'User',
    email: 'admin@example.com',
    is_admin: true,
  },
  token: 'test-token',
  isAuthenticated: true,
  login: vi.fn(),
  logout: vi.fn(),
};

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, staleTime: 0 } },
  });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <AuthContext.Provider value={mockAuthValue}>
          <ToastProvider>{children}</ToastProvider>
        </AuthContext.Provider>
      </QueryClientProvider>
    );
  };
}

const mockUsers = [
  {
    login: 'admin',
    firstname: 'Admin',
    lastname: 'User',
    email: 'admin@example.com',
    is_admin: true,
    enabled: true,
  },
  {
    login: 'john',
    firstname: 'John',
    lastname: 'Doe',
    email: 'john@example.com',
    is_admin: false,
    enabled: true,
  },
  {
    login: 'disabled_user',
    firstname: 'Jane',
    lastname: 'Doe',
    email: 'jane@example.com',
    is_admin: false,
    enabled: false,
  },
];

function mockFetchWith(data: unknown[]) {
  globalThis.fetch = vi
    .fn()
    .mockResolvedValue(
      new Response(JSON.stringify({ data, meta: { total: data.length, page: 1, limit: 100 } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );
}

describe('UserManagement', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders the page title', () => {
    mockFetchWith([]);
    render(<UserManagement />, { wrapper: createWrapper() });
    expect(screen.getByText(/user management/i)).toBeInTheDocument();
  });

  it('displays users in a table', async () => {
    mockFetchWith(mockUsers);
    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('admin')).toBeInTheDocument();
      expect(screen.getByText('john')).toBeInTheDocument();
    });
  });

  it('has a Create User button', () => {
    mockFetchWith([]);
    render(<UserManagement />, { wrapper: createWrapper() });
    expect(
      screen.getByRole('button', { name: /create user|new user|add user/i }),
    ).toBeInTheDocument();
  });

  it('shows create user form when button clicked', async () => {
    const user = userEvent.setup();
    mockFetchWith([]);
    render(<UserManagement />, { wrapper: createWrapper() });

    await user.click(screen.getByRole('button', { name: /create user|new user|add user/i }));

    expect(screen.getByLabelText(/login/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/email/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
  });

  it('shows "You" label for the current user row instead of action buttons', async () => {
    mockFetchWith(mockUsers);
    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('You')).toBeInTheDocument();
    });
  });

  it('shows Disable and Delete buttons for non-self users', async () => {
    mockFetchWith(mockUsers);
    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      const disableButtons = screen.getAllByRole('button', { name: /disable/i });
      expect(disableButtons.length).toBeGreaterThanOrEqual(1);
      const deleteButtons = screen.getAllByRole('button', { name: /^delete$/i });
      expect(deleteButtons.length).toBeGreaterThanOrEqual(1);
    });
  });

  it('shows Enable button for disabled users', async () => {
    mockFetchWith(mockUsers);
    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /^enable$/i })).toBeInTheDocument();
    });
  });

  it('shows confirmation dialog when Delete is clicked', async () => {
    const user = userEvent.setup();
    mockFetchWith(mockUsers);
    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('john')).toBeInTheDocument();
    });

    const deleteButtons = screen.getAllByRole('button', { name: /^delete$/i });
    await user.click(deleteButtons[0]);

    expect(screen.getByText(/permanently delete user/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cancel/i })).toBeInTheDocument();
  });

  it('canceling delete closes the dialog without API call', async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ data: mockUsers, meta: { total: 3, page: 1, limit: 100 } }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    globalThis.fetch = fetchMock;

    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('john')).toBeInTheDocument();
    });

    const deleteButtons = screen.getAllByRole('button', { name: /^delete$/i });
    await user.click(deleteButtons[0]);

    expect(screen.getByText(/permanently delete user/i)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /cancel/i }));

    expect(screen.queryByText(/permanently delete user/i)).not.toBeInTheDocument();
    // Only the initial GET call should have been made, no DELETE
    const calls = fetchMock.mock.calls as Array<[string | URL, RequestInit?]>;
    const deleteCalls = calls.filter((c) => c[1]?.method === 'DELETE');
    expect(deleteCalls).toHaveLength(0);
  });

  it('calls PUT with enabled:false when Disable is clicked', async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValue(
        new Response(JSON.stringify({ data: mockUsers, meta: { total: 3, page: 1, limit: 100 } }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    globalThis.fetch = fetchMock;

    render(<UserManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('john')).toBeInTheDocument();
    });

    const disableButtons = screen.getAllByRole('button', { name: /^disable$/i });
    await user.click(disableButtons[0]);

    await waitFor(() => {
      const calls = fetchMock.mock.calls as Array<[string | URL, RequestInit?]>;
      const putCalls = calls.filter((c) => c[1]?.method === 'PUT');
      expect(putCalls.length).toBeGreaterThanOrEqual(1);
      const body = JSON.parse(putCalls[0][1]?.body as string) as Record<string, unknown>;
      expect(body.enabled).toBe(false);
    });
  });
});
