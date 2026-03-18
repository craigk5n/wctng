import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { CategoryManagement } from '../CategoryManagement';

vi.mock('../../auth/auth-context', () => ({
  useAuth: () => ({ user: { login: 'admin', is_admin: true } }),
}));

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

describe('CategoryManagement', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders the page title', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<CategoryManagement />, { wrapper: createWrapper() });
    expect(screen.getByText(/categories/i)).toBeInTheDocument();
  });

  it('displays categories in a list', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { id: 1, name: 'Work', color: '#FF0000', is_global: true, owner: null },
            { id: 2, name: 'Hobbies', color: '#00FF00', is_global: false, owner: 'admin' },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    render(<CategoryManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Work')).toBeInTheDocument();
      expect(screen.getByText('Hobbies')).toBeInTheDocument();
    });
  });

  it('has a Create Category button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<CategoryManagement />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /new category|add category|create/i })).toBeInTheDocument();
  });

  it('shows create form when button clicked', async () => {
    const user = userEvent.setup();
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<CategoryManagement />, { wrapper: createWrapper() });
    await user.click(screen.getByRole('button', { name: /new category|add category|create/i }));

    expect(screen.getByLabelText(/name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/color/i)).toBeInTheDocument();
  });

  it('shows color swatch for each category', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          data: [
            { id: 1, name: 'Work', color: '#FF0000', is_global: true, owner: null },
          ],
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    );

    render(<CategoryManagement />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('Work')).toBeInTheDocument();
    });

    const swatch = screen.getByTestId('color-swatch-1');
    expect(swatch).toBeInTheDocument();
  });
});
