import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider } from '../AuthProvider';
import { LoginPage } from '../LoginPage';

function createWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return (
      <MemoryRouter>
        <QueryClientProvider client={qc}>
          <AuthProvider>{children}</AuthProvider>
        </QueryClientProvider>
      </MemoryRouter>
    );
  };
}

describe('OAuth Login', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('shows OAuth provider buttons when providers are available', async () => {
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/auth/oauth/providers')) {
        return Promise.resolve(
          new Response(JSON.stringify({
            data: [
              { id: 1, name: 'Google', type: 'oidc' },
              { id: 2, name: 'GitHub', type: 'oauth2' },
            ],
          }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        );
      }
      return Promise.resolve(new Response('{}', { status: 200 }));
    });

    render(<LoginPage />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/sign in with google/i)).toBeInTheDocument();
      expect(screen.getByText(/sign in with github/i)).toBeInTheDocument();
    });
  });

  it('shows "or" divider when providers exist', async () => {
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/auth/oauth/providers')) {
        return Promise.resolve(
          new Response(JSON.stringify({
            data: [{ id: 1, name: 'Google', type: 'oidc' }],
          }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        );
      }
      return Promise.resolve(new Response('{}', { status: 200 }));
    });

    render(<LoginPage />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText('or')).toBeInTheDocument();
    });
  });

  it('does not show OAuth section when no providers', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), {
        status: 200, headers: { 'Content-Type': 'application/json' },
      }),
    );

    render(<LoginPage />, { wrapper: createWrapper() });

    // Wait for fetch to complete
    await waitFor(() => {
      expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    });

    expect(screen.queryByText('or')).not.toBeInTheDocument();
  });

  it('still shows password form alongside OAuth buttons', async () => {
    globalThis.fetch = vi.fn().mockImplementation((url: string) => {
      if (String(url).includes('/auth/oauth/providers')) {
        return Promise.resolve(
          new Response(JSON.stringify({
            data: [{ id: 1, name: 'Google', type: 'oidc' }],
          }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        );
      }
      return Promise.resolve(new Response('{}', { status: 200 }));
    });

    render(<LoginPage />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/sign in with google/i)).toBeInTheDocument();
    });

    // Password form should still be present
    expect(screen.getByLabelText(/username/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/password/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /sign in$/i })).toBeInTheDocument();
  });
});
