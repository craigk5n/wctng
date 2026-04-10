import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { ToastProvider } from '../../components/toast/ToastProvider';
import { AuthProvider } from '../../auth/AuthProvider';
import { AccessSettings } from '../AccessSettings';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>
          <AuthProvider>
            <ToastProvider>{children}</ToastProvider>
          </AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>
    );
  };
}

describe('AccessSettings', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('renders page heading', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<AccessSettings />, { wrapper: createWrapper() });
    expect(screen.getByText(/access control/i)).toBeInTheDocument();
  });

  it('displays users with checkboxes', async () => {
    const fetchMock = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/access/users')) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [
                { login: 'alice', can_view: true, can_edit: false, see_time_only: false },
                { login: 'bob', can_view: true, can_edit: true, see_time_only: false },
              ],
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          ),
        );
      }
      // users list
      return Promise.resolve(
        new Response(
          JSON.stringify({
            data: [
              { login: 'alice' },
              { login: 'bob' },
              { login: 'admin' },
            ],
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
      );
    });
    globalThis.fetch = fetchMock;

    render(<AccessSettings />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
      expect(screen.getByText('bob')).toBeInTheDocument();
    });

    // Should have checkboxes
    const checkboxes = screen.getAllByRole('checkbox');
    expect(checkboxes.length).toBeGreaterThanOrEqual(2);
  });

  it('has a save button', () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<AccessSettings />, { wrapper: createWrapper() });
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
  });

  it('saves permissions on button click', async () => {
    const user = userEvent.setup();
    const fetchMock = vi.fn().mockImplementation((url: string, opts?: RequestInit) => {
      if (opts?.method === 'PUT') {
        return Promise.resolve(
          new Response(
            JSON.stringify({ data: { login: 'alice', can_view: true, can_edit: true } }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          ),
        );
      }
      if (String(url).includes('/access/users')) {
        return Promise.resolve(
          new Response(
            JSON.stringify({
              data: [{ login: 'alice', can_view: true, can_edit: false, see_time_only: false }],
            }),
            { status: 200, headers: { 'Content-Type': 'application/json' } },
          ),
        );
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({ data: [{ login: 'alice' }, { login: 'admin' }] }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        ),
      );
    });
    globalThis.fetch = fetchMock;

    render(<AccessSettings />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('alice')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => {
      const putCalls = fetchMock.mock.calls.filter(
        (c: unknown[]) => (c[1] as RequestInit | undefined)?.method === 'PUT',
      );
      expect(putCalls.length).toBeGreaterThan(0);
    });
  });
});
